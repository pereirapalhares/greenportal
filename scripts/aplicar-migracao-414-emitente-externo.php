<?php
declare(strict_types=1);

/*
 * Migração 414 — Controle de documentos (25/11/2026): campo "Emitido por (externo)".
 * Desde 25/11 o Emitente é preenchido pelo sistema com o usuário que criou o documento; para documentos de origem Externa
 * (norma de cliente, laudo de terceiro), quem emitiu de fato fica em documentos.EMITENTE_EXTERNO (VARCHAR(150)).
 * Cada empresa: a coluna (se faltar) e as duas views do Controle refeitas como na 409 (<view>_base408 + tipo + campos
 * da 409 + EMITENTE_EXTERNO + DATA_REACAO_CONTROLE). Treinamento e Infraestrutura não são tocados.
 * Pode ser executada mais de uma vez. Depois: scripts\aplicar-migracao-407-responsaveis-legal.php (views "Minhas").
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-414-emitente-externo.php [--simular] [--banco=gcrown_dbp]
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
$rel = ['ok' => true, 'migracao' => '414', 'modo' => 'APLICAR' . ($simular ? ' (SIMULACAO)' : ''), 'bancos' => []];

$tab = 'documentos'; $pk = 'CODDOCUMENTO'; $col = 'CODTIPOCONTROLE'; $catalogo = 'ml_tipos_controle'; $dsc = 'DSCTIPOCONTROLE';
$views = ['vw_controle_documentos_grid', 'vw_meus_controles_grid'];
$campos = ['VERSAO', 'ORIGEM', 'EMITENTE', 'EMITENTE_EXTERNO', 'REVISOR', 'APROVADOR', 'DOCUMENTO_SUPERIOR', 'DATA_EMISSAO', 'DATA_VENCIMENTO',
    'DIAS_REACAO', 'NECESSITA_TREINAMENTO', 'NOTIFICAR_ALTERACAO', 'PRAZO_GUARDA', 'FORMA_DESCARTE', 'OBSERVACAO', 'CONTROLE_INFLUENCIA'];
$extras = ['DATA_REACAO_CONTROLE' => "CASE WHEN x.`DATA_VENCIMENTO` IS NOT NULL AND x.`DIAS_REACAO` IS NOT NULL THEN DATE_SUB(x.`DATA_VENCIMENTO`, INTERVAL x.`DIAS_REACAO` DAY) END"];

$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('menus', 'usuarios') AND TABLE_SCHEMA LIKE 'g%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 2 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
$bancos = array_values(array_filter($bancos, static fn($b) => $b !== $bancoUniversal && !str_ends_with((string)$b, '_seguranca_dbp')
    && $b !== 'glegislacao_dbp' && ($soBanco === null || $b === $soBanco)));

foreach ($bancos as $banco) {
    $r = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4');
        ResponsaveisRegras::limparCache();
        $c = ResponsaveisRegras::colunas($pdo, $tab);
        if (!isset($c[strtolower($pk)])) { $rel['bancos'][$banco] = 'ignorado: sem a tabela documentos'; continue; }
        if (!isset($c['versao']) || !isset($c[strtolower($col)])) { $rel['bancos'][$banco] = 'ignorado: rode antes as migrações 408 e 409'; continue; }
        if (!isset($c['emitente_externo'])) {
            if (!$simular) $pdo->exec("ALTER TABLE `$tab` ADD COLUMN `EMITENTE_EXTERNO` VARCHAR(150) NULL AFTER `EMITENTE`");
            $r['coluna'] = 'EMITENTE_EXTERNO ' . ($simular ? 'a criar' : 'criada');
        } else $r['coluna'] = 'EMITENTE_EXTERNO já existe';
        ResponsaveisRegras::limparCache();
        foreach ($views as $v) {
            $base = $v . '_base408';
            if (!ResponsaveisRegras::existe($pdo, $base)) { $r['views'][$v] = 'sem ' . $base . ' (rode a 408)'; continue; }
            $bc = ResponsaveisRegras::colunas($pdo, $base);
            $sel = ["x.`$col` AS `$col`", "t.`$dsc` AS `$dsc`"];
            foreach ($campos as $n) if (!isset($bc[strtolower($n)])) $sel[] = "x.`$n` AS `$n`";
            foreach ($extras as $n => $expr) if (!isset($bc[strtolower($n)])) $sel[] = "$expr AS `$n`";
            if ($simular) { $r['views'][$v] = 'será refeita com ' . (count($sel) - 2) . ' campo(s)'; continue; }
            $pdo->exec("CREATE OR REPLACE VIEW `$v` AS SELECT b.*, " . implode(', ', $sel) . "
  FROM `$base` b LEFT JOIN `$tab` x ON x.`$pk` = b.`" . $bc[strtolower($pk)] . "` LEFT JOIN `$catalogo` t ON t.`$col` = x.`$col`");
            $q = (int)$pdo->query("SELECT COUNT(*) FROM `$v`")->fetchColumn();
            $r['views'][$v] = sprintf('ok (%d campo(s); %d linha(s))', count($sel) - 2, $q);
        }
    } catch (Throwable $e) { $rel['ok'] = false; $r['erro'] = $e->getMessage(); }
    $rel['bancos'][$banco] = $r;
}
$rel['proximo_passo'] = 'rodar scripts\\aplicar-migracao-407-responsaveis-legal.php (refaz as views "Minhas" com o campo novo)';
$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@file_put_contents($logDir . DIRECTORY_SEPARATOR . 'migracao_414_emitente_externo' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
