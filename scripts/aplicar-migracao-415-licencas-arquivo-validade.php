<?php
declare(strict_types=1);

/*
 * Migração 415 — Licenças (Revisão G, 26/11/2026):
 *   item 48  "Arquivar licença?"      li_licencas.ARQUIVADA CHAR(1) 'N'/'S': a licença arquivada continua no grid (situação
 *                                     "Arquivada"), mas sai dos indicadores (vw_dash_licencas), da Home, das pendências e
 *                                     dos avisos de prazo;
 *   item 55  "Validade inexistente"   li_licencas.VALIDADE_INEXISTENTE CHAR(1): situação "Sem validade (não vence)" (NAO_VENCE),
 *                                     diferente de "Sem data de vencimento" (data não preenchida);
 *   item 56  Validade recorrente      li_licencas.VALIDADE_RECORRENTE CHAR(1) + RECORRENCIA_MESES INT: depois do vencimento
 *                                     gravado vale o próximo ciclo (DATA_VALIDADE + k × N meses), coluna "Próximo vencimento".
 * Regras em classes/LicencaSituacao.php. Em cada empresa com li_licencas:
 *   1. cria as 4 colunas (só as que faltam);
 *   2. roda a migração 288 (vw_licencas_situacao e grids de licenças com as colunas novas), que roda a 285 (dashboards);
 *   3. roda a migração 361 (avisos de prazo sem as licenças arquivadas ou sem validade).
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-415-licencas-arquivo-validade.php [--simular] [--banco=gcrown_dbp]
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$soBanco = null;
foreach ($args as $a) if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$relatorio = ['ok' => true, 'migracao' => '415', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('menus', 'perfis', 'permissoes', 'usuarios') AND TABLE_SCHEMA LIKE '%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 4 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
$bancos = array_values(array_filter($bancos, static fn($b) => $b !== $bancoUniversal && !str_ends_with((string)$b, '_seguranca_dbp')
    && preg_match('/^g[a-z0-9_]+_dbp$/', (string)$b) && ($soBanco === null || $b === $soBanco)));

$colunas = [
    'ARQUIVADA' => "CHAR(1) NOT NULL DEFAULT 'N'",
    'VALIDADE_INEXISTENTE' => "CHAR(1) NOT NULL DEFAULT 'N'",
    'VALIDADE_RECORRENTE' => "CHAR(1) NOT NULL DEFAULT 'N'",
    'RECORRENCIA_MESES' => 'INT NULL',
];
foreach ($bancos as $banco) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $tem = [];
        foreach ($pdo->query("SELECT UPPER(COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'li_licencas'")->fetchAll(PDO::FETCH_COLUMN) as $c) $tem[$c] = true;
        if (!isset($tem['CODLICENCA'], $tem['DATA_VALIDADE'])) { $relatorio['bancos'][$banco] = ['ignorado' => 'sem li_licencas']; continue; }
        $depois = 'DATA_VALIDADE';
        foreach ($colunas as $col => $tipo) {
            if (!isset($tem[$col])) {
                if (!$simular) $pdo->exec("ALTER TABLE `li_licencas` ADD COLUMN `$col` $tipo AFTER `$depois`");
                $info['colunas'][$col] = $simular ? 'a criar' : 'criada';
            } else $info['colunas'][$col] = 'já existe';
            $depois = $col;
        }
        if (!$simular && isset($tem['ARQUIVADA'])) {
            $info['arquivadas'] = (int)$pdo->query("SELECT COUNT(*) FROM `li_licencas` WHERE `ARQUIVADA` = 'S'")->fetchColumn();
            $info['sem_validade'] = (int)$pdo->query("SELECT COUNT(*) FROM `li_licencas` WHERE `VALIDADE_INEXISTENTE` = 'S'")->fetchColumn();
            $info['recorrentes'] = (int)$pdo->query("SELECT COUNT(*) FROM `li_licencas` WHERE `VALIDADE_RECORRENTE` = 'S'")->fetchColumn();
        }
    } catch (Throwable $e) { $relatorio['ok'] = false; $info['erro'] = $e->getMessage(); }
    $relatorio['bancos'][$banco] = $info;
}
echo json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
if (!$relatorio['ok'] || $simular) exit($relatorio['ok'] ? 0 : 1);

$extra = $soBanco ? ' ' . escapeshellarg('--banco=' . $soBanco) : '';
foreach (['aplicar-migracao-288-licencas-protocolo.php' => '288 (situação e grids de licenças) + 285 (dashboards)',
          'aplicar-migracao-361-responsaveis-regras.php' => '361 (avisos de prazo)'] as $script => $titulo) {
    echo PHP_EOL . "== Migração $titulo ==" . PHP_EOL;
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . $script) . $extra, $codigo);
    if ($codigo !== 0) exit(1);
}
exit(0);
