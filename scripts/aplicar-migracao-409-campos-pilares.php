<?php
declare(strict_types=1);

/*
 * Migração 409 — Onda 3 da Revisão G (24/11/2026), 2ª parte: novos campos de Controle (CON-89), Treinamento (TRE-104) e
 * Infraestrutura (INF-119), depois do Tipo (408). Unidade, responsável, risco (341), histórico (359) e arquivos (GED) já existiam.
 *   Controle (documentos): versão, origem, emitente, revisor, aprovador, documento de nível superior, data de emissão, data de
 *     vencimento, dias de reação, necessita treinamento?, notificar alteração?, prazo de guarda, forma de descarte, observação,
 *     controle/influência.
 *   Treinamento (ml_treinamentos): carga horária (h), período de reciclagem (meses), observação, controle/influência.
 *   Infraestrutura (ml_infraestrutura): setor, condições, classe (A/B/C), propriedade, situação, observação, controle/influência.
 * Cada empresa: colunas novas (só as que faltam) e as views das telas (as 6 da 408) refeitas: <view>_base408 + tipo + campos novos
 * (+ DATA_REACAO_CONTROLE calculada = vencimento − dias de reação). Só colunas novas e views; nada é apagado.
 * Pode ser executada mais de uma vez.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-409-campos-pilares.php [--simular] [--banco=gcrown_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/ResponsaveisRegras.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$soBanco = null;
foreach ($args as $a) if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$logDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
$rel = ['ok' => true, 'migracao' => '409', 'modo' => 'APLICAR' . ($simular ? ' (SIMULACAO)' : ''), 'bancos' => []];

// área => [tabela, chave, coluna do tipo, catálogo, descrição do tipo, views, colunas novas => DDL, expressões extras da view]
$MOD = [
    'CONTROLES' => ['documentos', 'CODDOCUMENTO', 'CODTIPOCONTROLE', 'ml_tipos_controle', 'DSCTIPOCONTROLE', ['vw_controle_documentos_grid', 'vw_meus_controles_grid'], [
        'VERSAO' => 'VARCHAR(30) NULL', 'ORIGEM' => 'VARCHAR(20) NULL', 'EMITENTE' => 'VARCHAR(150) NULL', 'REVISOR' => 'VARCHAR(150) NULL',
        'APROVADOR' => 'VARCHAR(150) NULL', 'DOCUMENTO_SUPERIOR' => 'VARCHAR(200) NULL', 'DATA_EMISSAO' => 'DATE NULL', 'DATA_VENCIMENTO' => 'DATE NULL',
        'DIAS_REACAO' => 'INT NULL', 'NECESSITA_TREINAMENTO' => 'VARCHAR(3) NULL', 'NOTIFICAR_ALTERACAO' => 'VARCHAR(3) NULL', 'PRAZO_GUARDA' => 'VARCHAR(100) NULL',
        'FORMA_DESCARTE' => 'VARCHAR(200) NULL', 'OBSERVACAO' => 'TEXT NULL', 'CONTROLE_INFLUENCIA' => 'VARCHAR(20) NULL'],
        ['DATA_REACAO_CONTROLE' => "CASE WHEN x.`DATA_VENCIMENTO` IS NOT NULL AND x.`DIAS_REACAO` IS NOT NULL THEN DATE_SUB(x.`DATA_VENCIMENTO`, INTERVAL x.`DIAS_REACAO` DAY) END"]],
    'TREINAMENTOS' => ['ml_treinamentos', 'CODTREINAMENTO', 'CODTIPOTREINAMENTO', 'ml_tipos_treinamento', 'DSCTIPOTREINAMENTO', ['vw_modulolegal_treinamentos_grid', 'vw_minhas_treinamentos_grid'], [
        'CARGA_HORARIA' => 'DECIMAL(6,1) NULL', 'PERIODO_RECICLAGEM' => 'INT NULL', 'OBSERVACAO' => 'TEXT NULL', 'CONTROLE_INFLUENCIA' => 'VARCHAR(20) NULL'], []],
    'INFRAESTRUTURA' => ['ml_infraestrutura', 'CODINFRAESTRUTURA', 'CODTIPOINFRAESTRUTURA', 'ml_tipos_infraestrutura', 'DSCTIPOINFRAESTRUTURA', ['vw_modulolegal_infraestrutura_grid', 'vw_minhas_infraestrutura_grid'], [
        'SETOR' => 'VARCHAR(150) NULL', 'CONDICOES' => 'VARCHAR(300) NULL', 'CLASSE' => 'VARCHAR(5) NULL', 'PROPRIEDADE' => 'VARCHAR(20) NULL',
        'SITUACAO_INFRA' => 'VARCHAR(30) NULL', 'OBSERVACAO' => 'TEXT NULL', 'CONTROLE_INFLUENCIA' => 'VARCHAR(20) NULL'], []],
];

$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('menus', 'usuarios') AND TABLE_SCHEMA LIKE 'g%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 2 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
$bancos = array_values(array_filter($bancos, static fn($b) => $b !== $bancoUniversal && !str_ends_with((string)$b, '_seguranca_dbp')
    && $b !== 'glegislacao_dbp' && ($soBanco === null || $b === $soBanco)));

foreach ($bancos as $banco) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4');
        ResponsaveisRegras::limparCache();
        foreach ($MOD as $area => [$tab, $pk, $col, $catalogo, $dsc, $views, $novas, $extras]) {
            $r = [];
            $c = ResponsaveisRegras::colunas($pdo, $tab);
            if (!isset($c[strtolower($pk)])) { $info[$area] = 'ignorado: sem a tabela ' . $tab; continue; }
            if (!isset($c[strtolower($col)]) || !ResponsaveisRegras::existe($pdo, $catalogo)) { $info[$area] = 'ignorado: rode antes a migração 408'; continue; }
            $faltam = array_filter($novas, static fn($ddl, $n) => !isset($c[strtolower($n)]), ARRAY_FILTER_USE_BOTH);
            if ($faltam && !$simular) $pdo->exec("ALTER TABLE `$tab` " . implode(', ', array_map(static fn($n, $ddl) => "ADD COLUMN `$n` $ddl", array_keys($faltam), $faltam)));
            $r['colunas'] = $faltam ? count($faltam) . ' ' . ($simular ? 'a criar' : 'criada(s)') . ': ' . implode(', ', array_keys($faltam)) : 'todas já existem';
            ResponsaveisRegras::limparCache();
            foreach ($views as $v) {
                $base = $v . '_base408';
                if (!ResponsaveisRegras::existe($pdo, $base)) { $r['views'][$v] = 'sem ' . $base . ' (rode a 408)'; continue; }
                $bc = ResponsaveisRegras::colunas($pdo, $base);
                $sel = ["x.`$col` AS `$col`", "t.`$dsc` AS `$dsc`"];
                foreach (array_keys($novas) as $n) if (!isset($bc[strtolower($n)])) $sel[] = "x.`$n` AS `$n`";
                foreach ($extras as $n => $expr) if (!isset($bc[strtolower($n)])) $sel[] = "$expr AS `$n`";
                if ($simular) { $r['views'][$v] = 'será refeita com ' . (count($sel) - 2) . ' campo(s) novo(s)'; continue; }
                $pdo->exec("CREATE OR REPLACE VIEW `$v` AS SELECT b.*, " . implode(', ', $sel) . "
  FROM `$base` b LEFT JOIN `$tab` x ON x.`$pk` = b.`" . $bc[strtolower($pk)] . "` LEFT JOIN `$catalogo` t ON t.`$col` = x.`$col`");
                $t0 = microtime(true); $q = (int)$pdo->query("SELECT COUNT(*) FROM `$v`")->fetchColumn();
                $r['views'][$v] = sprintf('ok (%d campo(s) novo(s); %d linha(s), %.2fs)', count($sel) - 2, $q, microtime(true) - $t0);
            }
            $info[$area] = $r;
        }
    } catch (Throwable $e) { $rel['ok'] = false; $info['erro'] = $e->getMessage(); }
    $rel['bancos'][$banco] = $info;
}
$rel['proximo_passo'] = 'rodar scripts\\aplicar-migracao-407-responsaveis-legal.php (refaz as views "Minhas" da 361 com os campos novos)';
$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@file_put_contents($logDir . DIRECTORY_SEPARATOR . 'migracao_409_campos_pilares' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
