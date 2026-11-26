<?php
declare(strict_types=1);

/*
 * Migração 417 — Não conformidades: Risco da ocorrência (Revisão G item 158, 26/11/2026).
 * A coluna nc_indicacao_ocorrencia.RISCO já existe (veio do legado, mostrada na Lista geral de ocorrências), mas o formulário
 * não a gravava. Agora o gestor informa o risco (Baixo, Moderado, Alto ou Crítico) na aba "Avaliação do gestor", e a Home de
 * Não conformidades ganha o gráfico "Por risco".
 * Em cada empresa com o módulo de NC: sp_nc_gestor_salvar passa a gravar RISCO (a procedure atual é lida com SHOW CREATE
 * PROCEDURE e recebe só a linha nova) e a coluna é alargada para VARCHAR(30) se for menor.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-417-nc-risco.php [--simular] [--banco=gcrown_dbp]
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$soBanco = null;
foreach ($args as $a) if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$relatorio = ['ok' => true, 'migracao' => '417', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('menus', 'perfis', 'permissoes', 'usuarios') AND TABLE_SCHEMA LIKE '%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 4 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
$bancos = array_values(array_filter($bancos, static fn($b) => $b !== $bancoUniversal && !str_ends_with((string)$b, '_seguranca_dbp')
    && preg_match('/^g[a-z0-9_]+_dbp$/', (string)$b) && ($soBanco === null || $b === $soBanco)));

$ancora = "RECUSA_DO_GESTOR = IF(fn_nc_tem(p_dados, 'RECUSA_DO_GESTOR'), LEFT(fn_nc_txt(p_dados, 'RECUSA_DO_GESTOR'), 5000), RECUSA_DO_GESTOR)";
// sem comparação de texto (NULLIF com '' dava "Illegal mix of collations" entre o texto do JSON e o literal)
$expr = "IF(fn_nc_tem(p_dados, 'RISCO'), IF(CHAR_LENGTH(TRIM(fn_nc_txt(p_dados, 'RISCO'))) = 0, NULL, LEFT(TRIM(fn_nc_txt(p_dados, 'RISCO')), 30)), RISCO)";
$linhaAntiga = ",\n    RISCO = IF(fn_nc_tem(p_dados, 'RISCO'), NULLIF(LEFT(fn_nc_txt(p_dados, 'RISCO'), 30), ''), RISCO)";
$linha = ",\n    RISCO = " . $expr;
foreach ($bancos as $banco) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $col = $pdo->query("SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nc_indicacao_ocorrencia' AND COLUMN_NAME = 'RISCO'")->fetch(PDO::FETCH_ASSOC);
        $temProc = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'sp_nc_gestor_salvar'")->fetchColumn() > 0;
        if (!$col || !$temProc) { $relatorio['bancos'][$banco] = ['ignorado' => !$col ? 'sem nc_indicacao_ocorrencia.RISCO' : 'sem sp_nc_gestor_salvar (rode a 321)']; continue; }
        $info['coluna'] = $col['DATA_TYPE'] . ($col['CHARACTER_MAXIMUM_LENGTH'] ? '(' . $col['CHARACTER_MAXIMUM_LENGTH'] . ')' : '');
        if (in_array(strtolower((string)$col['DATA_TYPE']), ['char', 'varchar'], true) && (int)$col['CHARACTER_MAXIMUM_LENGTH'] < 30) {
            if (!$simular) $pdo->exec('ALTER TABLE `nc_indicacao_ocorrencia` MODIFY `RISCO` VARCHAR(30) NULL');
            $info['coluna'] .= $simular ? ' → VARCHAR(30)' : ' → VARCHAR(30) (alterada)';
        } elseif (!in_array(strtolower((string)$col['DATA_TYPE']), ['char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext'], true)) {
            throw new RuntimeException('RISCO não é texto (' . $col['DATA_TYPE'] . '): confira antes de aplicar');
        }
        $ddl = (string)($pdo->query('SHOW CREATE PROCEDURE `sp_nc_gestor_salvar`')->fetch(PDO::FETCH_ASSOC)['Create Procedure'] ?? '');
        if ($ddl === '') throw new RuntimeException('sem permissão para ler sp_nc_gestor_salvar');
        if (str_contains($ddl, $expr)) $info['sp_nc_gestor_salvar'] = 'já grava RISCO';
        else {
            // primeira versão desta migração (26/11 14h): troca a linha com NULLIF pela nova
            if (str_contains($ddl, $linhaAntiga)) $base = str_replace($linhaAntiga, '', $ddl); else $base = $ddl;
            if (substr_count($base, $ancora) !== 1 || str_contains($base, "'RISCO'")) throw new RuntimeException('sp_nc_gestor_salvar diferente da migração 321: confira antes de aplicar');
            $nova = preg_replace('/^CREATE\s+DEFINER=\S+\s+PROCEDURE/i', 'CREATE PROCEDURE', str_replace($ancora, $ancora . $linha, $base));
            if (!$simular) {
                $pdo->exec('DROP PROCEDURE IF EXISTS `sp_nc_gestor_salvar`');
                try { $pdo->exec($nova); }
                catch (Throwable $e) { $pdo->exec(preg_replace('/^CREATE\s+DEFINER=\S+\s+PROCEDURE/i', 'CREATE PROCEDURE', $ddl)); throw $e; } // volta a anterior
            }
            $info['sp_nc_gestor_salvar'] = $simular ? 'receberá RISCO' : 'grava RISCO';
        }
        if (!$simular) $info['ocorrencias_com_risco'] = (int)$pdo->query("SELECT COUNT(*) FROM `nc_indicacao_ocorrencia` WHERE TRIM(COALESCE(`RISCO`, '')) <> ''")->fetchColumn();
    } catch (Throwable $e) { $relatorio['ok'] = false; $info['erro'] = $e->getMessage(); }
    $relatorio['bancos'][$banco] = $info;
}
echo json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($relatorio['ok'] ? 0 : 1);
