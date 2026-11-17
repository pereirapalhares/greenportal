<?php
declare(strict_types=1);

/*
 * Diagnóstico (só leitura) das licenças vencidas e do protocolo de renovação (migração 288, "licenças em protocolo").
 * Para uma empresa: quantas licenças há em cada situação (vw_licencas_situacao) e, para cada licença vencida, os campos
 * que decidem se ela fica "Válida (em protocolo)": Em protocolo?, Data do protocolo (prazo para protocolar),
 * Protocolado em, Protocolado no prazo? e o número do protocolo. Mostra também as outras versões da mesma licença
 * (renovação já cadastrada) e as colunas de li_licencas ligadas a protocolo, versão e situação. Não grava nada.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-licencas-protocolo.php --banco=gatlas_dbp
 * Resultado também em storage\logs\diagnostico_licencas_protocolo_<banco>.json
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$banco = 'gatlas_dbp';
foreach ($argv ?? [] as $a) if (str_starts_with($a, '--banco=')) $banco = substr($a, 8);
if (!preg_match('/^g[a-z0-9_]+_dbp$/', $banco)) { fwrite(STDERR, "banco inválido\n"); exit(1); }
$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_general_ci');
$linhas = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$corta = static fn($v, int $n = 120): string => mb_strimwidth(trim(preg_replace('/\s+/u', ' ', (string)$v) ?? ''), 0, $n, '…', 'UTF-8');

$rel = ['banco' => $banco, 'gerado_em' => date('d/m/Y H:i')];
try {
    // Colunas de li_licencas que interessam (protocolo, versão, situação, descrição)
    $cols = array_column($linhas("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'li_licencas' ORDER BY ORDINAL_POSITION"), 'COLUMN_NAME');
    $rel['colunas_li_licencas'] = count($cols);
    $interesse = array_values(array_filter($cols, static fn($c) => preg_match('/protoc|prazo|vers|base|origem|anterior|renov|ativ|status|situac|exclu/i', $c)));
    $rel['colunas_de_interesse'] = $interesse;
    $temCol = static fn(string $c): bool => in_array(strtolower($c), array_map('strtolower', $cols), true);
    $descr = null; foreach (['DESCRICAO', 'NOMELICENCA', 'NUMERO', 'NUMEROLICENCA', 'NUMERO_LICENCA'] as $c) if ($temCol($c)) { $descr = $c; break; }

    $rel['por_situacao'] = $linhas("SELECT SITUACAO_LICENCA, SITUACAO_LICENCA_DESC, COUNT(*) AS qtd FROM vw_licencas_situacao GROUP BY SITUACAO_LICENCA, SITUACAO_LICENCA_DESC ORDER BY qtd DESC");
    $rel['vencidas_por_motivo'] = $linhas("SELECT CASE WHEN EM_PROTOCOLO = 'S' THEN 'marcada em protocolo, mas fora do prazo (ou sem as datas e sem \"Protocolado no prazo?\")' ELSE 'sem \"Em protocolo?\" marcado' END AS motivo, COUNT(*) AS qtd
        FROM vw_licencas_situacao WHERE SITUACAO_LICENCA = 'VENCIDA' GROUP BY 1");

    // Licenças vencidas, com os campos crus que decidem a situação
    $campos = ['EMPROTOCOLO', 'DATA_PROTOCOLO', 'PROTOCOLADO_EM', 'PROTOCOLADO_NO_PRAZO', 'NUMERO_PROTOCOLO'];
    $sel = [];
    foreach (array_unique(array_merge($campos, $interesse)) as $c) if ($temCol($c)) $sel[] = 'l.`' . $c . '`';
    if ($descr) $sel[] = 'l.`' . $descr . '` AS DESCRICAO_LICENCA';
    $chave = (int)$linhas("SELECT COUNT(*) AS n FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vw_ml_licenca_chave'")[0]['n'] > 0;
    $sql = "SELECT s.CODLICENCA, s.CODUNIDADE, DATE_FORMAT(s.DATA_VALIDADE, '%d/%m/%Y') AS VENCEU_EM, s.MOTIVO_SITUACAO, s.EM_PROTOCOLO, s.PROTOCOLO_NO_PRAZO"
        . ($sel ? ', ' . implode(', ', $sel) : '')
        . " FROM vw_licencas_situacao s JOIN li_licencas l ON l.CODLICENCA = s.CODLICENCA WHERE s.SITUACAO_LICENCA = 'VENCIDA' ORDER BY s.DATA_VALIDADE DESC LIMIT 200";
    $vencidas = $linhas($sql);
    foreach ($vencidas as &$v) {
        foreach ($v as $k => $x) if (is_string($x)) $v[$k] = $corta($x);
        if ($chave) {
            // Outras versões da mesma licença (renovação cadastrada como nova versão)
            $outras = $linhas("SELECT k2.CODLICENCA, DATE_FORMAT(s2.DATA_VALIDADE, '%d/%m/%Y') AS VALIDADE, s2.SITUACAO_LICENCA
                FROM vw_ml_licenca_chave k1 JOIN vw_ml_licenca_chave k2 ON k2.CHAVE = k1.CHAVE AND k2.CODLICENCA <> k1.CODLICENCA
                JOIN vw_licencas_situacao s2 ON s2.CODLICENCA = k2.CODLICENCA WHERE k1.CODLICENCA = ? ORDER BY s2.DATA_VALIDADE DESC", [(int)$v['CODLICENCA']]);
            $v['outras_versoes'] = $outras ?: 'nenhuma';
        }
    }
    unset($v);
    $rel['vencidas'] = $vencidas;
    $rel['vencidas_com_versao_mais_nova_valida'] = count(array_filter($vencidas, static fn($v) => is_array($v['outras_versoes'] ?? null)
        && array_filter($v['outras_versoes'], static fn($o) => in_array($o['SITUACAO_LICENCA'], ['VALIDA', 'PROTOCOLO'], true))));
    if (!$chave) $rel['aviso'] = 'vw_ml_licenca_chave não existe: versões não conferidas';

    // Valores encontrados nos campos de protocolo (todas as licenças)
    foreach (['EMPROTOCOLO', 'PROTOCOLADO_NO_PRAZO'] as $c) if ($temCol($c)) $rel['valores_' . strtolower($c)] = $linhas("SELECT COALESCE(NULLIF(TRIM(`$c`), ''), '(vazio)') AS valor, COUNT(*) AS qtd FROM li_licencas GROUP BY 1 ORDER BY qtd DESC");
    foreach (['DATA_PROTOCOLO', 'PROTOCOLADO_EM', 'NUMERO_PROTOCOLO'] as $c) if ($temCol($c)) $rel['preenchidos_' . strtolower($c)] = (int)$linhas("SELECT COUNT(*) AS n FROM li_licencas WHERE NULLIF(TRIM(`$c`), '') IS NOT NULL AND `$c` NOT LIKE '0000%'")[0]['n'];
} catch (Throwable $e) { $rel['erro'] = $e->getMessage(); }

$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$logs = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logs)) @mkdir($logs, 0775, true);
@file_put_contents($logs . DIRECTORY_SEPARATOR . 'diagnostico_licencas_protocolo_' . $banco . '.json', $json);
echo $json . PHP_EOL;
