<?php
declare(strict_types=1);

/*
 * Migração 288 — Licenças em protocolo ("espelho"), apresentação Atlas Schindler 2026.
 * Licença vencida com a renovação protocolada no órgão dentro do prazo continua valendo até a resposta do órgão:
 * aparece como "Válida (em protocolo)", com o número e a data do protocolo (classes/LicencaSituacao.php).
 * Em cada empresa com li_licencas:
 *   1. coluna NUMERO_PROTOCOLO em li_licencas (número do protocolo de renovação no órgão);
 *   2. view vw_licencas_situacao: situação de cada licença (VALIDA, PROTOCOLO, VENCIDA, SEM_DATA, ENTREGA), descrição,
 *      motivo, número do protocolo e se foi protocolada no prazo;
 *   3. grids de licenças (vw_licencas_grid e vw_minhas_licencas_grid) com as colunas SITUACAO_LICENCA,
 *      SITUACAO_LICENCA_DESC, MOTIVO_SITUACAO e NUMERO_PROTOCOLO: a view atual vira <view>_base e a nova a acrescenta
 *      às colunas existentes (se outra migração recriar o grid depois, basta rodar esta de novo);
 *   4. no fim roda a migração 285 (vw_dash_licencas com a situação, para os dashboards e o relatório).
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-288-licencas-protocolo.php [--simular] [--banco=gatlas_dbp]
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';
require_once dirname(__DIR__) . '/classes/LicencaSituacao.php';

const M288_REGEXP_PERIODICO = 'RAPP|RELAT.RIO ANUAL|INVENT.RIO|DECLARA..O ANUAL|MAPA DE PRODUTOS|MAPA MENSAL|MAPA TRIMESTRAL|(^|[^A-Z])DMR([^A-Z]|$)';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$soBanco = null;
foreach ($args as $a) if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$relatorio = ['ok' => true, 'migracao' => '288', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('menus', 'perfis', 'permissoes', 'usuarios') AND TABLE_SCHEMA LIKE '%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 4 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
$bancos = array_values(array_filter($bancos, static fn($b) => $b !== $bancoUniversal && !str_ends_with((string)$b, '_seguranca_dbp')
    && preg_match('/^g[a-z0-9_]+_dbp$/', (string)$b) && ($soBanco === null || $b === $soBanco)));

foreach ($bancos as $banco) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $colunas = static function () use ($pdo): array {
            $cols = [];
            foreach ($pdo->query('SELECT LOWER(TABLE_NAME) t, LOWER(COLUMN_NAME) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_ASSOC) as $r) $cols[$r['t']][$r['c']] = true;
            return $cols;
        };
        $cols = $colunas();
        $tem = static function (string $t, string ...$c) use (&$cols): bool {
            if (!isset($cols[strtolower($t)])) return false;
            foreach ($c as $x) if (!isset($cols[strtolower($t)][strtolower($x)])) return false;
            return true;
        };
        if (!$tem('li_licencas', 'CODLICENCA', 'DATA_VALIDADE')) { $relatorio['bancos'][$banco] = ['ignorado' => 'sem li_licencas']; continue; }

        // 1. Número do protocolo
        if (!$tem('li_licencas', 'NUMERO_PROTOCOLO')) {
            $depois = $tem('li_licencas', 'PROTOCOLADO_EM') ? ' AFTER `PROTOCOLADO_EM`' : '';
            if (!$simular) $pdo->exec("ALTER TABLE `li_licencas` ADD COLUMN `NUMERO_PROTOCOLO` VARCHAR(60) NULL$depois");
            $info['numero_protocolo'] = $simular ? 'coluna a criar' : 'coluna criada';
            $cols = $colunas();
        }

        // 2. Situação de cada licença
        $c = static fn(string $col, string $padrao = 'NULL'): string => $tem('li_licencas', $col) ? "l.`$col`" : $padrao;
        $porNome = $tem('li_licencas', 'DSCLICENCA')
            ? "(UPPER(CONVERT(COALESCE(l.`DSCLICENCA`, '') USING utf8mb4)) COLLATE utf8mb4_unicode_ci REGEXP '" . M288_REGEXP_PERIODICO . "')" : 'FALSE';
        $periodico = $porNome; $joinTipo = '';
        if ($tem('li_licencas', 'CODTIPODOCUMENTO') && $tem('li_tipos_documentos', 'CODTIPODOCUMENTO', 'DSCTIPODOCUMENTO')) {
            $joinTipo = "\n  LEFT JOIN `li_tipos_documentos` td ON td.`CODTIPODOCUMENTO` = l.`CODTIPODOCUMENTO`";
            if ($tem('li_tipos_documentos', 'NATUREZA'))
                $periodico = "(CASE WHEN td.`CODTIPODOCUMENTO` IS NULL OR UPPER(td.`DSCTIPODOCUMENTO`) LIKE 'N%O CLASSIFICADO%' THEN $porNome ELSE UPPER(td.`NATUREZA`) = 'ENTREGA_PERIODICA' END)";
        }
        $s = LicencaSituacao::sql($c, $periodico);
        $ddl = "CREATE OR REPLACE VIEW `vw_licencas_situacao` AS
SELECT l.`CODLICENCA`, " . $c('codunidade', '0') . " AS `CODUNIDADE`, {$s['validade']} AS `DATA_VALIDADE`,
       {$s['situacao']} AS `SITUACAO_LICENCA`, {$s['rotulo']} AS `SITUACAO_LICENCA_DESC`, {$s['motivo']} AS `MOTIVO_SITUACAO`,
       IF({$s['em_protocolo']}, 'S', 'N') AS `EM_PROTOCOLO`, {$s['protocolado_em']} AS `PROTOCOLADO_EM`,
       {$s['no_prazo']} AS `PROTOCOLO_NO_PRAZO`, {$s['numero']} AS `NUMERO_PROTOCOLO`
  FROM `li_licencas` l$joinTipo";
        if (!$simular) $pdo->exec($ddl);

        // 3. Grids de licenças: <view>_base = view atual; <view> = base + situação
        $views = [];
        foreach ($pdo->query("SELECT TABLE_NAME, VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC) as $r) $views[strtolower($r['TABLE_NAME'])] = (string)$r['VIEW_DEFINITION'];
        foreach (['vw_licencas_grid', 'vw_minhas_licencas_grid'] as $v) {
            if (!isset($views[$v])) { $info['grids'][$v] = 'não existe'; continue; }
            $base = $v . '_base';
            $jaEmbrulhada = str_contains($views[$v], "`$base`") && isset($views[$base]);
            if (!$jaEmbrulhada) {
                // Original (ou recriada por outra migração depois da 288): passa a ser a base
                if (!$simular) {
                    if (isset($views[$base])) $pdo->exec("DROP VIEW `$base`");
                    $pdo->exec("RENAME TABLE `$v` TO `$base`");
                }
            }
            $colsBase = [];
            if (!$simular || $jaEmbrulhada) {
                foreach ($pdo->query("SELECT LOWER(COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($base))->fetchAll(PDO::FETCH_COLUMN) as $col) $colsBase[$col] = true;
            } else {
                foreach ($pdo->query("SELECT LOWER(COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($v))->fetchAll(PDO::FETCH_COLUMN) as $col) $colsBase[$col] = true;
            }
            if (!isset($colsBase['codlicenca'])) throw new RuntimeException("$v sem a coluna CODLICENCA");
            $extra = [];
            foreach (['SITUACAO_LICENCA', 'SITUACAO_LICENCA_DESC', 'MOTIVO_SITUACAO', 'NUMERO_PROTOCOLO'] as $col)
                if (!isset($colsBase[strtolower($col)])) $extra[] = "s.`$col`";
            if (!$simular) $pdo->exec("CREATE OR REPLACE VIEW `$v` AS SELECT b.*" . ($extra ? ', ' . implode(', ', $extra) : '') . "
  FROM `$base` b LEFT JOIN `vw_licencas_situacao` s ON s.`CODLICENCA` = b.`CODLICENCA`");
            $info['grids'][$v] = ($jaEmbrulhada ? 'atualizada' : 'base criada') . ($extra ? ' + ' . count($extra) . ' colunas' : '');
        }

        if (!$simular) {
            if ($criadas = MigracaoCrud::garantir($pdo)) $info['procedures_crud_criadas'] = $criadas;
            foreach (['vw_licencas_situacao', 'vw_licencas_grid', 'vw_minhas_licencas_grid'] as $v) {
                if (($info['grids'][$v] ?? '') === 'não existe') continue;
                $st = $pdo->prepare('CALL sp_crud_select(:t, NULL, NULL, 1, 0)'); $st->execute(['t' => $v]); $st->fetchAll(); $st->closeCursor();
            }
            $info['situacao'] = $pdo->query("SELECT s.`SITUACAO_LICENCA_DESC`, COUNT(*) FROM `vw_licencas_situacao` s
                JOIN `li_licencas` l ON l.`CODLICENCA` = s.`CODLICENCA`" . ($tem('li_licencas', 'STATUS_VERSAO') ? " WHERE COALESCE(l.`STATUS_VERSAO`, 'ATIVA') = 'ATIVA'" : '') . "
                GROUP BY s.`SITUACAO_LICENCA_DESC` ORDER BY 2 DESC")->fetchAll(PDO::FETCH_KEY_PAIR);
            $info['protocoladas_fora_do_prazo'] = (int)$pdo->query("SELECT COUNT(*) FROM `vw_licencas_situacao` WHERE `SITUACAO_LICENCA` = 'VENCIDA' AND `EM_PROTOCOLO` = 'S'")->fetchColumn();
        }
    } catch (Throwable $e) { $relatorio['ok'] = false; $info['erro'] = $e->getMessage(); }
    $relatorio['bancos'][$banco] = $info;
}
echo json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

// 4. Dashboards e relatório: vw_dash_licencas com a situação
if ($relatorio['ok'] && !$simular) {
    echo PHP_EOL . '== Migração 285 (views dos dashboards) ==' . PHP_EOL;
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . 'aplicar-migracao-285-dashboard-abas.php') . ($soBanco ? ' ' . escapeshellarg('--banco=' . $soBanco) : ''), $codigo);
    if ($codigo !== 0) exit(1);
}
exit($relatorio['ok'] ? 0 : 1);
