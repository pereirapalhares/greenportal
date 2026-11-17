<?php
declare(strict_types=1);

/*
 * Migração 286 — Requisitos inativados (Módulo Legal, apresentação Atlas Schindler 2026).
 * Em cada empresa com módulo legal (legislacaoxempresa):
 *   1. database/286_requisitos_inativados.sql — tabela legislacaoxempresa_inativos (mesma estrutura de legislacaoxempresa),
 *      histórico requisitos_inativacao_historico e as procedures sp_requisito_inativar / sp_requisito_reativar;
 *   2. colunas de controle em legislacaoxempresa_inativos (inativado_em, inativado_por, inativado_motivo);
 *   3. view vw_requisitos_inativados_grid (tela "Requisitos inativados") e vw_requisitos_inativacao_historico.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-286-requisitos-inativados.php [--simular] [--banco=gatlas_dbp]
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$soBanco = null;
foreach ($args as $a) if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$relatorio = ['ok' => true, 'migracao' => '286', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('menus', 'perfis', 'permissoes', 'usuarios') AND TABLE_SCHEMA LIKE '%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 4 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
$bancos = array_values(array_filter($bancos, static fn($b) => $b !== $bancoUniversal && !str_ends_with((string)$b, '_seguranca_dbp')
    && preg_match('/^g[a-z0-9_]+_dbp$/', (string)$b) && ($soBanco === null || $b === $soBanco)));
// Banco das leis (glegislacao_dbp), usado pelas views do Módulo Legal
$bancoLeis = (string)($env['GLEGISLACAO_DB_DATABASE'] ?? 'glegislacao_dbp');
$temLeis = (bool)$geral->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = " . $geral->quote($bancoLeis) . " AND TABLE_NAME = 'legislacao'")->fetchColumn();
$sql = (string)file_get_contents(dirname(__DIR__) . '/database/286_requisitos_inativados.sql');

foreach ($bancos as $banco) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $cols = [];
        foreach ($pdo->query('SELECT LOWER(TABLE_NAME) t, LOWER(COLUMN_NAME) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_ASSOC) as $r) $cols[$r['t']][$r['c']] = true;
        $tem = static fn(string $t, string ...$c): bool => isset($cols[strtolower($t)]) && !array_diff(array_map('strtolower', $c), array_keys($cols[strtolower($t)]));
        if (!$tem('legislacaoxempresa', 'codlegislacao', 'codunidade')) { $relatorio['bancos'][$banco] = ['ignorado' => 'sem módulo legal (legislacaoxempresa)']; continue; }
        if (!$simular) {
            foreach (preg_split('/^-- @@\s*$/m', $sql) as $bloco) {
                $cmd = trim((string)preg_replace('/^--(?! @@).*$/m', '', $bloco));
                if ($cmd !== '') $pdo->exec($cmd);
            }
            $existentes = array_map('strtolower', $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'legislacaoxempresa_inativos'")->fetchAll(PDO::FETCH_COLUMN));
            foreach (['inativado_em' => 'DATETIME NULL', 'inativado_por' => 'INT NULL', 'inativado_motivo' => 'VARCHAR(2000) NULL'] as $c => $tipo)
                if (!in_array($c, $existentes, true)) $pdo->exec("ALTER TABLE `legislacaoxempresa_inativos` ADD COLUMN `$c` $tipo");
            // A cópia não deve gerar chaves sozinha: a chave é a mesma da linha original
            $pk = $pdo->query("SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'legislacaoxempresa_inativos' AND EXTRA LIKE '%auto_increment%'")->fetch(PDO::FETCH_ASSOC);
            if ($pk) $pdo->exec("ALTER TABLE `legislacaoxempresa_inativos` MODIFY `{$pk['COLUMN_NAME']}` {$pk['COLUMN_TYPE']} NOT NULL");
            $info['tabelas_procedures'] = 'ok';
        }
        // Views da tela
        $leis = $temLeis ? "LEFT JOIN `$bancoLeis`.`legislacao` l ON l.`CODLEGISLACAO` = i.`CODLEGISLACAO`\n  LEFT JOIN `$bancoLeis`.`tipos` t ON t.`CODTIPO` = l.`CODTIPO`" : '';
        $camposLei = $temLeis ? "COALESCE(l.`CODIGO`, '') AS `CODIGO`, TRIM(CONCAT(COALESCE(t.`DSCTIPO`, ''), ' ', COALESCE(l.`NR`, ''), IF(COALESCE(l.`ANO`, '') <> '', CONCAT('/', l.`ANO`), ''))) AS `NORMA`, COALESCE(l.`PADRAO`, '') AS `PADRAO`"
            : "'' AS `CODIGO`, '' AS `NORMA`, '' AS `PADRAO`";
        $views = [
            'vw_requisitos_inativados_grid' => "CREATE OR REPLACE VIEW `vw_requisitos_inativados_grid` AS
SELECT i.`CODLEGISLACAO`, i.`codunidade` AS `CODUNIDADE`, COALESCE(u.`DSCUNIDADE`, '') AS `UNIDADE`, $camposLei,
       i.`inativado_em` AS `INATIVADO_EM`, i.`inativado_por` AS `INATIVADO_POR`, COALESCE(NULLIF(TRIM(us.`nome`), ''), us.`userid`, '') AS `INATIVADO_POR_NOME`,
       COALESCE(i.`inativado_motivo`, '') AS `MOTIVO`
  FROM `legislacaoxempresa_inativos` i
  LEFT JOIN `unidades` u ON u.`CODUNIDADE` = i.`codunidade`
  LEFT JOIN `usuarios` us ON us.`codusuario` = i.`inativado_por`
  $leis",
            'vw_requisitos_inativacao_historico' => "CREATE OR REPLACE VIEW `vw_requisitos_inativacao_historico` AS
SELECT h.`codhistorico` AS `CODHISTORICO`, h.`CODLEGISLACAO`, h.`CODUNIDADE`, COALESCE(u.`DSCUNIDADE`, '') AS `UNIDADE`, h.`acao` AS `ACAO`, COALESCE(h.`motivo`, '') AS `MOTIVO`,
       h.`codusuario` AS `CODUSUARIO`, COALESCE(NULLIF(TRIM(us.`nome`), ''), us.`userid`, '') AS `USUARIO`, h.`em` AS `EM`
  FROM `requisitos_inativacao_historico` h
  LEFT JOIN `unidades` u ON u.`CODUNIDADE` = h.`CODUNIDADE`
  LEFT JOIN `usuarios` us ON us.`codusuario` = h.`codusuario`",
        ];
        if (!$simular) {
            foreach ($views as $ddl) $pdo->exec($ddl);
            if ($criadas = MigracaoCrud::garantir($pdo)) $info['procedures_crud_criadas'] = $criadas;
            foreach (array_keys($views) as $v) { $st = $pdo->prepare('CALL sp_crud_select(:t, NULL, NULL, 1, 0)'); $st->execute(['t' => $v]); $st->fetchAll(); $st->closeCursor(); }
            $info['inativados'] = (int)$pdo->query('SELECT COUNT(*) FROM `legislacaoxempresa_inativos`')->fetchColumn();
        }
        $info['views'] = count($views) . ($simular ? ' a gravar' : ' gravadas');
        if (!$temLeis) $info['aviso'] = "banco $bancoLeis não encontrado: a tela mostra só lei e unidade";
    } catch (Throwable $e) { $relatorio['ok'] = false; $info['erro'] = $e->getMessage(); }
    $relatorio['bancos'][$banco] = $info;
}
echo json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($relatorio['ok'] ? 0 : 1);
