<?php
declare(strict_types=1);

/*
 * Migração 380 — Transferência de responsabilidades (GER-8). Decisão do usuário (16/11/2026): REDIRECIONAR, sem alterar os
 * registros (até a virada o sincronismo com o legado desfaria a troca do responsável).
 *
 * Cada empresa: database/380_transferencia_responsabilidades.sql (transferencias_responsabilidade, _log, views _ativas,
 * _grid, _log e o gatilho trg_alertas_bi_transferencia na tabela alertas).
 * Universal: Ajuda da tela (ajuda_transacoes transferencia_responsabilidades, trecho <!-- ml380 -->).
 * Telas e classes (já na pasta): principal/transferencia_responsabilidades.php (aba Transferências da Home),
 * classes/TransferenciaResponsabilidade.php, classes/MinhasPendencias.php, classes/PainelLateralService.php,
 * classes/PendenciasService.php (redirecionamento) e principal/home.php (aba).
 * Pode ser executada mais de uma vez.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-380-transferencia-responsabilidades.php [--simular] [--banco=gcrown_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$soBanco = null;
foreach ($args as $a) if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$rel = ['ok' => true, 'migracao' => '380', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'universal' => [], 'bancos' => []];

// ---------------------------------------------------------------- 1) empresas
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('menus', 'usuarios') AND TABLE_SCHEMA LIKE 'g%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 2 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
$bancos = array_values(array_filter($bancos, static fn($b) => $b !== $bancoUniversal && !str_ends_with((string)$b, '_seguranca_dbp')
    && $b !== 'glegislacao_dbp' && ($soBanco === null || $b === $soBanco)));
if (!$bancos) $rel['bancos'] = ['aviso' => 'nenhuma empresa encontrada' . ($soBanco ? " ($soBanco)" : '')];
$sql = (string)file_get_contents(dirname(__DIR__) . '/database/380_transferencia_responsabilidades.sql');
$blocos = array_values(array_filter(array_map(static fn($b) => trim((string)preg_replace('/^--(?! @@).*$/m', '', $b)), preg_split('/^-- @@\s*$/m', $sql)), static fn($b) => $b !== ''));

foreach ($bancos as $banco) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $tem = static function (string $t) use ($pdo): bool { $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'); $s->execute([$t]); $n = (int)$s->fetchColumn(); $s->closeCursor(); return $n > 0; };
        if (!$tem('alertas') || !$tem('usuarios')) throw new RuntimeException('sem as tabelas alertas/usuarios');
        $info['antes'] = $tem('transferencias_responsabilidade') ? 'tabelas já existiam' : 'novo';
        if ($simular) { $info['acao'] = count($blocos) . ' bloco(s) seriam executados'; $rel['bancos'][$banco] = $info; continue; }
        // Migração 366: a colação da conexão precisa ser a do banco (views e gatilho comparam textos de tabelas diferentes)
        $pdo->exec('SET NAMES utf8mb4 COLLATE ' . (string)$pdo->query('SELECT @@collation_database')->fetchColumn());
        foreach ($blocos as $i => $b) {
            try { $pdo->exec($b); }
            catch (Throwable $e) { throw new RuntimeException('bloco ' . ($i + 1) . ' (' . mb_substr((string)preg_replace('/\s+/', ' ', $b), 0, 70) . '…): ' . $e->getMessage()); }
        }
        $c = $pdo->query("SELECT (SELECT COUNT(*) FROM vw_transferencias_responsabilidade_ativas) a, (SELECT COUNT(*) FROM transferencias_responsabilidade_log) l,
            (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'trg_alertas_bi_transferencia') g")->fetch(PDO::FETCH_ASSOC);
        $info['acao'] = count($blocos) . ' bloco(s): tabelas transferencias_responsabilidade e _log, views _ativas, _grid e _log, gatilho dos alertas';
        $info['conferencia'] = (int)$c['a'] . ' transferência(s) em vigor, ' . (int)$c['l'] . ' registro(s) no log, gatilho ' . ((int)$c['g'] === 1 ? 'ok' : 'FALTANDO');
        if ((int)$c['g'] !== 1) throw new RuntimeException('o gatilho trg_alertas_bi_transferencia não ficou criado');
    } catch (Throwable $e) {
        $rel['ok'] = false;
        $info['erro'] = $e->getMessage();
    }
    $rel['bancos'][$banco] = $info;
}

// ---------------------------------------------------------------- 2) universal: Ajuda da tela
try {
    $u = GreenPortalDatabase::connect($env, $bancoUniversal, 'GUNIVERSAL');
    $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if (!MigracaoCrud::existe($u, 'ajuda_transacoes')) throw new RuntimeException('sem a tabela ajuda_transacoes no universal');
    foreach (require dirname(__DIR__) . '/database/380_ajuda_transferencia.php' as $transacao => $d) {
        $atual = null;
        foreach (MigracaoCrud::selecionar($u, 'ajuda_transacoes', ['transacao' => $transacao]) as $r) if (strcasecmp((string)($r['transacao'] ?? ''), $transacao) === 0) { $atual = $r; break; }
        $dados = [];
        foreach (['pt', 'en', 'es'] as $lg) {
            $velho = trim((string)preg_replace('/\s*<!-- ml380 -->.*?<!-- \/ml380 -->/s', '', (string)($atual['texto_' . $lg] ?? '')));
            $dados['texto_' . $lg] = ($velho === '' ? '' : $velho . "\n") . "<!-- ml380 -->\n" . $d['texto'][$lg] . "\n<!-- /ml380 -->";
            if ($atual === null || trim((string)($atual['titulo_' . $lg] ?? '')) === '') $dados['titulo_' . $lg] = $d['titulo'][$lg];
        }
        if ($atual !== null) {
            $igual = (int)($atual['ativo'] ?? 1) === 1;
            foreach ($dados as $k => $v) if ((string)($atual[$k] ?? '') !== $v) $igual = false;
            if ($igual) { $rel['universal']['ajuda'][$transacao] = 'igual'; continue; }
        }
        if ($simular) { $rel['universal']['ajuda'][$transacao] = $atual === null ? 'será incluída' : 'será atualizada'; continue; }
        if ($atual === null) MigracaoCrud::inserir($u, 'ajuda_transacoes', ['transacao' => $transacao] + $dados + ['ativo' => 1]);
        else {
            $chave = ['transacao' => (string)$atual['transacao']];
            foreach (['id', 'codajuda', 'codigo', 'codajudatransacao'] as $pk) if (isset($atual[$pk]) && $atual[$pk] !== '') { $chave = [$pk => $atual[$pk]]; break; }
            MigracaoCrud::atualizar($u, 'ajuda_transacoes', $chave, $dados + ['ativo' => 1]);
        }
        $rel['universal']['ajuda'][$transacao] = $atual === null ? 'incluída' : 'atualizada';
    }
} catch (Throwable $e) {
    $rel['ok'] = false;
    $rel['universal']['erro'] = $e->getMessage();
}

$logDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (is_dir($logDir)) @file_put_contents($logDir . DIRECTORY_SEPARATOR . 'migracao_380_transferencia_responsabilidades' . ($simular ? '_simulacao' : '') . '.json', json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
