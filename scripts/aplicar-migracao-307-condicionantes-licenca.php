<?php
declare(strict_types=1);

/*
 * Migração 307 — Condicionantes da licença como no legado.
 * No legado (cadastros/li_condicionantes_s.php), excluir uma condicionante apaga os planos de ação dela
 * ("delete from li_planodeacao where CODCONDICIONANTE not in (select CODCONDICIONANTE from li_condicionantes)").
 * Aqui: gatilho trg_li_condicionantes_ad_planos em cada banco de empresa com li_condicionantes e li_planodeacao.
 * Não age durante o sincronismo (@gp_sinc_legado = 1): lá os planos excluídos no legado saem com backup.
 * Não mexe em dados. Pode ser executada mais de uma vez.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-307-condicionantes-licenca.php [--simular] [--banco=gcrown_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$simular = in_array('--simular', $argv ?? [], true);
$soBanco = null;
foreach ($argv ?? [] as $a) if (str_starts_with($a, '--banco=')) $soBanco = array_filter(array_map('trim', explode(',', substr($a, 8))));
$env = GreenPortalDatabase::env();
$is = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$is->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$bancos = $is->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('li_condicionantes', 'li_planodeacao') AND TABLE_SCHEMA LIKE 'g%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 2 ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
if ($soBanco) $bancos = array_values(array_intersect($bancos, $soBanco));
$rel = ['ok' => true, 'migracao' => '307', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
foreach ($bancos as $banco) {
    if ($simular) { $rel['bancos'][$banco] = 'gatilho será criado'; continue; }
    try {
        $pdo = GreenPortalDatabase::connect($env, (string)$banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('DROP TRIGGER IF EXISTS `trg_li_condicionantes_ad_planos`');
        $pdo->exec('CREATE TRIGGER `trg_li_condicionantes_ad_planos` AFTER DELETE ON `li_condicionantes` FOR EACH ROW
            DELETE FROM `li_planodeacao` WHERE `CODCONDICIONANTE` = OLD.`CODCONDICIONANTE` AND @gp_sinc_legado IS NULL');
        $orfaos = (int)$pdo->query('SELECT COUNT(*) FROM `li_planodeacao` p WHERE NOT EXISTS (SELECT 1 FROM `li_condicionantes` c WHERE c.`CODCONDICIONANTE` = p.`CODCONDICIONANTE`)')->fetchColumn();
        $rel['bancos'][$banco] = 'gatilho criado' . ($orfaos ? " (aviso: $orfaos plano(s) de ação já sem condicionante; não foram apagados)" : '');
    } catch (Throwable $e) { $rel['ok'] = false; $rel['bancos'][$banco] = 'ERRO: ' . $e->getMessage(); }
}
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
