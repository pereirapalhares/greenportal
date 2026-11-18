<?php
declare(strict_types=1);

/*
 * Diagnóstico (só leitura) para a tela "Tipos × Palavras-chave" no greenportal (pedido de 18/11/2026):
 * estrutura e conteúdo de tiposxpalavras_chaves e da tabela de tipos, onde mais esses tipos aparecem (tabelas, views,
 * procedures) no greenportal e no legado, e quais arquivos do greenportal citam a tabela.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-tipos-palavras.php
 * Grava também storage\logs\diagnostico_tipos_palavras.json
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/LegislacaoEmpresas.php';

$env = GreenPortalDatabase::env();
$leg = GreenPortalDatabase::connect($env, (string)($env['GLEGISLACAO_DB_DATABASE'] ?? 'glegislacao_dbp'), 'GLEGISLACAO');
$leg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$q = static function (string $sql, array $p = []) use ($leg): array { $s = $leg->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };

$out = [];
// bancos visíveis por este usuário (greenportal e legado) que têm algo de "palavra"
$bancos = array_column($q("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'g%' OR SCHEMA_NAME LIKE 'green%'"), 'SCHEMA_NAME');
$out['tabelas_palavra'] = $q("SELECT TABLE_SCHEMA, TABLE_NAME, TABLE_TYPE, TABLE_ROWS FROM information_schema.TABLES
    WHERE TABLE_SCHEMA IN ('" . implode("','", array_map('addslashes', $bancos)) . "') AND TABLE_NAME NOT LIKE 'bkp%'
      AND (TABLE_NAME LIKE '%palavra%' OR TABLE_NAME LIKE 'tipos%palav%') ORDER BY TABLE_NAME, TABLE_SCHEMA");

// estrutura de tiposxpalavras_chaves e das tabelas de tipos de palavra, onde existirem
foreach ($out['tabelas_palavra'] as $t) {
    $n = strtolower($t['TABLE_NAME']);
    if (!str_starts_with($n, 'tipos')) continue;
    $k = $t['TABLE_SCHEMA'] . '.' . $t['TABLE_NAME'];
    $out['estrutura'][$k] = $q('SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_KEY, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$t['TABLE_SCHEMA'], $t['TABLE_NAME']]);
    $out['amostra'][$k] = $q('SELECT * FROM `' . $t['TABLE_SCHEMA'] . '`.`' . $t['TABLE_NAME'] . '` LIMIT 30');
    $out['total'][$k] = (int)$q('SELECT COUNT(*) n FROM `' . $t['TABLE_SCHEMA'] . '`.`' . $t['TABLE_NAME'] . '`')[0]['n'];
}

// contagem por tipo no greenportal e no legado (se a coluna codtipo existir)
foreach (['glegislacao_dbp', 'green_legislacao', 'guniversal_dbp', 'green_universal'] as $b) {
    if (!in_array($b, $bancos, true)) continue;
    if (!$q("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'tiposxpalavras_chaves' AND COLUMN_NAME = 'codtipo'", [$b])) continue;
    $out['por_tipo'][$b] = $q("SELECT codtipo, COUNT(*) palavras FROM `$b`.tiposxpalavras_chaves GROUP BY codtipo ORDER BY codtipo");
}

// colunas que parecem apontar para o tipo de palavra em outras tabelas (ex.: codtipopalavrachave)
$out['colunas_tipo_palavra'] = $q("SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA IN ('" . implode("','", array_map('addslashes', $bancos)) . "') AND TABLE_NAME NOT LIKE 'bkp%'
      AND (COLUMN_NAME LIKE '%tipopalavra%' OR COLUMN_NAME LIKE '%tipo_palavra%' OR COLUMN_NAME LIKE '%tipospalavra%') ORDER BY 1, 2");

// views e procedures que citam a tabela
$out['views'] = $q("SELECT TABLE_SCHEMA, TABLE_NAME FROM information_schema.VIEWS WHERE VIEW_DEFINITION LIKE '%tiposxpalavras%' OR VIEW_DEFINITION LIKE '%tipos_palavras%' OR VIEW_DEFINITION LIKE '%tipospalavras%'");
$out['rotinas'] = $q("SELECT ROUTINE_SCHEMA, ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_DEFINITION LIKE '%tiposxpalavras%' OR ROUTINE_DEFINITION LIKE '%tipos_palavras%' OR ROUTINE_DEFINITION LIKE '%tipospalavras%'");

// arquivos do greenportal que citam a tabela
$raiz = dirname(__DIR__); $arqs = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = $f->getPathname();
    if (preg_match('#[\\\\/](vendor|backups|storage|node_modules|\.git)[\\\\/]#', $p) || !preg_match('/\.(php|js|sql)$/', $p) || $f->getSize() > 3000000) continue;
    $c = (string)@file_get_contents($p);
    if (stripos($c, 'tiposxpalavras') !== false || stripos($c, 'tipos_palavras') !== false || stripos($c, 'tipospalavras') !== false) $arqs[] = substr($p, strlen($raiz) + 1);
}
$out['arquivos_greenportal'] = $arqs;

$json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
@file_put_contents($raiz . '/storage/logs/diagnostico_tipos_palavras.json', $json);
echo $json, "\n";
