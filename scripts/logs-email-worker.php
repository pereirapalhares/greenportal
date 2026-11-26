<?php
declare(strict_types=1);

/*
 * Envio automático dos logs por e-mail (Revisão G item 16; migração 421, 26/11/2026).
 * Para cada empresa com LOG_EMAIL_FREQUENCIA = SEMANAL (segundas-feiras: semana anterior) ou MENSAL (dia 1: mês anterior) e
 * LOG_EMAIL_DESTINO (Administração › Logs do sistema): gera o CSV do período (ações e alterações de dados) em
 * storage/logs_export/<banco>/ e põe na fila de e-mails do portal (email_fila) o link para baixar pela tela de Logs.
 * Apaga os CSVs que passaram da retenção (LOG_RETENCAO_DIAS). Não envia duas vezes no mesmo dia (LOG_EMAIL_ULTIMO).
 * Uso (agendador do Windows, uma vez por dia): E:\wamp\bin\php\php8.4.15\php.exe scripts\logs-email-worker.php [--simular] [--forcar]
 */
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MenuCatalogoService.php';
require_once dirname(__DIR__) . '/classes/EmailFilaService.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$forcar = in_array('--forcar', $args, true);
$env = GreenPortalDatabase::env();
$urlPortal = rtrim((string)($env['GREENPORTAL_URL'] ?? ''), '/');
$hoje = new DateTimeImmutable('today');
$rel = ['ok' => true, 'data' => $hoje->format('Y-m-d'), 'modo' => $simular ? 'SIMULACAO' : 'EXECUTAR', 'bancos' => []];
$call = static function (PDO $p, string $sql, array $par = []): array { $s = $p->prepare($sql); $s->execute($par); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };

foreach ((new MenuCatalogoService($env))->empresas() as $banco => $pdo) {
    $info = [];
    try {
        $cfg = [];
        try { foreach ($call($pdo, 'CALL sp_crud_select(?, ?, NULL, 200, 0)', ['vw_portal_config', '{}']) as $r) $cfg[strtoupper((string)($r['CHAVE'] ?? $r['chave'] ?? ''))] = (string)($r['VALOR'] ?? $r['valor'] ?? ''); }
        catch (Throwable) { $rel['bancos'][$banco] = 'sem migração 421'; continue; }
        $retencao = max(30, min(3650, (int)($cfg['LOG_RETENCAO_DIAS'] ?? 365)));
        $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs_export' . DIRECTORY_SEPARATOR . preg_replace('/[^A-Za-z0-9_]/', '', $banco);
        // retenção dos CSVs
        if (is_dir($dir)) foreach (glob($dir . DIRECTORY_SEPARATOR . 'logs_*.csv') ?: [] as $f) if (filemtime($f) < $hoje->modify('-' . $retencao . ' days')->getTimestamp()) { if (!$simular) @unlink($f); $info['apagados'][] = basename($f); }
        $freq = strtoupper($cfg['LOG_EMAIL_FREQUENCIA'] ?? 'NENHUM'); $destino = trim($cfg['LOG_EMAIL_DESTINO'] ?? '');
        if ($freq === 'NENHUM' || $destino === '' || !filter_var($destino, FILTER_VALIDATE_EMAIL)) { $info['envio'] = 'desligado'; $rel['bancos'][$banco] = $info; continue; }
        $dia = $freq === 'SEMANAL' ? (int)$hoje->format('N') === 1 : (int)$hoje->format('j') === 1;
        if (!$forcar && (!$dia || ($cfg['LOG_EMAIL_ULTIMO'] ?? '') === $hoje->format('Y-m-d'))) { $info['envio'] = 'não é dia de envio'; $rel['bancos'][$banco] = $info; continue; }
        $de = $freq === 'SEMANAL' ? $hoje->modify('-7 days') : $hoje->modify('first day of last month');
        $ate = $hoje->modify('-1 day');
        $nome = 'logs_' . $de->format('Y-m-d') . '_' . $ate->format('Y-m-d') . '.csv';
        $linhas = 0;
        if (!$simular) {
            if (!is_dir($dir)) mkdir($dir, 0775, true);
            $out = fopen($dir . DIRECTORY_SEPARATOR . $nome, 'w'); fwrite($out, "\xEF\xBB\xBF");
            foreach (['vw_logs_acoes' => 'ACAO', 'vw_logs_alteracoes' => 'ALTERACAO'] as $view => $tipo) {
                try { $rows = $call($pdo, 'SELECT * FROM `' . $view . '` WHERE `DATA_EVENTO` >= ? AND `DATA_EVENTO` < DATE_ADD(?, INTERVAL 1 DAY) ORDER BY `DATA_EVENTO`', [$de->format('Y-m-d'), $ate->format('Y-m-d')]); } catch (Throwable) { continue; }
                if ($rows) fputcsv($out, array_merge(['TIPO'], array_keys($rows[0])), ';');
                foreach ($rows as $r) { fputcsv($out, array_merge([$tipo], array_map(static fn($v) => (string)$v, $r)), ';'); $linhas++; }
            }
            fclose($out);
            $link = $urlPortal . '/principal/administracao_logs.php?arquivo=' . rawurlencode($nome);
            $assunto = 'Logs do GreenPortal: ' . $de->format('d/m/Y') . ' a ' . $ate->format('d/m/Y');
            $texto = "Os logs do período (ações dos usuários e alterações de dados, $linhas registro(s)) estão prontos para baixar:\n" . $link
                . "\n\nO arquivo fica disponível por $retencao dias (retenção contratada). Para mudar o envio, use Administração › Logs do sistema.";
            $call($pdo, 'CALL sp_crud_insert(?, ?)', ['email_fila', json_encode(['destinatario' => $destino, 'nome_destinatario' => $destino, 'assunto' => $assunto,
                'corpo' => EmailFilaService::html(preg_replace('/^g|_dbp$/', '', $banco), $assunto, $texto, 'pt'), 'origem' => 'LOGS', 'referencia' => $nome, 'status' => 'PENDENTE'], JSON_UNESCAPED_UNICODE)]);
            $existe = $call($pdo, 'CALL sp_crud_select(?, ?, NULL, 1, 0)', ['portal_config', json_encode(['CHAVE' => ['value' => 'LOG_EMAIL_ULTIMO', 'exact' => true]])]);
            $call($pdo, $existe ? 'CALL sp_crud_update(?, ?, ?)' : 'CALL sp_crud_insert(?, ?)', $existe ? ['portal_config', json_encode(['CHAVE' => 'LOG_EMAIL_ULTIMO']), json_encode(['VALOR' => $hoje->format('Y-m-d')])] : ['portal_config', json_encode(['CHAVE' => 'LOG_EMAIL_ULTIMO', 'VALOR' => $hoje->format('Y-m-d')])]);
        }
        $info['envio'] = ($simular ? 'seria enviado' : 'na fila') . " para o e-mail configurado: $nome ($linhas registro(s))";
    } catch (Throwable $e) { $rel['ok'] = false; $info['erro'] = $e->getMessage(); }
    $rel['bancos'][$banco] = $info;
}
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
