<?php
declare(strict_types=1);

/*
 * Diagnóstico (SÓ LEITURA) — maiores códigos de NC no legado e no greenportal, para a faixa de códigos própria do greenportal
 * (decisão do usuário em 21/10/2026: NCs criadas no greenportal ficam numa faixa que o sincronismo não toca).
 * Por banco: MAX e quantidade de CODOCORRENCIA, CODCAUSA e CODPLANOACAO, e quantos códigos o legado criou nos últimos 12 meses
 * (para estimar quanto tempo o legado leva para chegar à faixa). Uso: ...\php.exe scripts\diagnostico-nc-codigos.php
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

$pdo = GreenPortalDatabase::connect(GreenPortalDatabase::env(), 'information_schema', 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$v = static function (string $sql) use ($pdo) { try { $s = $pdo->query($sql); $r = $s->fetchColumn(); $s->closeCursor(); return $r; } catch (Throwable $e) { return 'erro: ' . mb_substr($e->getMessage(), 0, 120); } };
$tabelas = ['nc_indicacao_ocorrencia' => 'CODOCORRENCIA', 'nc_causas' => 'CODCAUSA', 'nc_planodeacao' => 'CODPLANOACAO'];
$r = [];
foreach (HomologacaoBancos::BANCOS as $banco => $leg) {
    foreach (['legado' => $leg, 'greenportal' => $banco] as $lado => $db) {
        foreach ($tabelas as $t => $c) {
            $r[$banco][$t][$lado] = ['max' => $v("SELECT MAX(`$c`) FROM `$db`.`$t`"), 'linhas' => $v("SELECT COUNT(*) FROM `$db`.`$t`")];
        }
    }
    $r[$banco]['nc_indicacao_ocorrencia']['legado']['criadas_12_meses'] = $v("SELECT COUNT(*) FROM `$leg`.nc_indicacao_ocorrencia WHERE data_de_criacao >= DATE_SUB(NOW(), INTERVAL 12 MONTH)");
    $r[$banco]['nc_planodeacao']['legado']['criadas_12_meses'] = $v("SELECT COUNT(*) FROM `$leg`.nc_planodeacao WHERE data_de_criacao >= DATE_SUB(NOW(), INTERVAL 12 MONTH)");
}
echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
