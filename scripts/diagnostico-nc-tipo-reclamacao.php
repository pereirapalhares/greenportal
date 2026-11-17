<?php
declare(strict_types=1);

/*
 * Diagnóstico (somente leitura) — nc_tipo_de_reclamacao × nc_tiporeclamacao no legado de uma empresa.
 * Na MODELO a migração 107 descartou nc_tipo_de_reclamacao como cópia de nc_tiporeclamacao. Antes de fazer o mesmo
 * em outra empresa, confere se as ocorrências usam códigos que só existem em nc_tipo_de_reclamacao (seriam perdidos).
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-nc-tipo-reclamacao.php --origem=green_crown
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$origem = '';
foreach ($argv ?? [] as $a) if (str_starts_with($a, '--origem=')) $origem = substr($a, 9);
if (!preg_match('/^[A-Za-z0-9_]+$/', $origem)) { fwrite(STDERR, "Uso: --origem=green_crown\n"); exit(1); }
$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$q = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$o = '`' . $origem . '`';
$col = static function (string $t, string $padrao) use ($q, $origem): array {
    return array_column($q('SELECT COLUMN_NAME c FROM COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND LOWER(COLUMN_NAME) LIKE ? ORDER BY ORDINAL_POSITION', [$origem, $t, $padrao]), 'c');
};

$rel = ['diagnostico' => 'nc_tipo_de_reclamacao', 'origem' => $origem, 'gerado_em' => date('c')];
$codA = $col('nc_tipo_de_reclamacao', 'cod%')[0] ?? null; $dscA = $col('nc_tipo_de_reclamacao', 'dsc%')[0] ?? null;
$codB = $col('nc_tiporeclamacao', 'cod%')[0] ?? null; $dscB = $col('nc_tiporeclamacao', 'dsc%')[0] ?? null;
$rel['tabelas'] = ['nc_tipo_de_reclamacao' => [$codA, $dscA], 'nc_tiporeclamacao' => [$codB, $dscB]];
if (!$codA || !$codB) { $rel['erro'] = 'uma das tabelas não existe ou não tem coluna cod*'; echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL; exit(1); }
$A = "$o.`nc_tipo_de_reclamacao`"; $B = "$o.`nc_tiporeclamacao`";
$rel['linhas'] = ['nc_tipo_de_reclamacao' => (int)$q("SELECT COUNT(*) c FROM $A")[0]['c'], 'nc_tiporeclamacao' => (int)$q("SELECT COUNT(*) c FROM $B")[0]['c']];
$rel['codigos'] = $q("SELECT
    (SELECT COUNT(*) FROM $A a WHERE NOT EXISTS (SELECT 1 FROM $B b WHERE b.`$codB` = a.`$codA`)) AS so_em_tipo_de_reclamacao,
    (SELECT COUNT(*) FROM $B b WHERE NOT EXISTS (SELECT 1 FROM $A a WHERE a.`$codA` = b.`$codB`)) AS so_em_tiporeclamacao,
    (SELECT COUNT(*) FROM $A a JOIN $B b ON b.`$codB` = a.`$codA`" . ($dscA && $dscB ? " WHERE NOT (TRIM(a.`$dscA`) <=> TRIM(b.`$dscB`))" : ' WHERE 1=0') . ") AS mesmo_codigo_descricao_diferente")[0];
// Colunas das ocorrências que podem apontar para o tipo de reclamação
$usos = [];
foreach ($col('nc_indicacao_ocorrencia', '%reclam%') as $c) {
    $usos[$c] = $q("SELECT
        COUNT(*) AS ocorrencias_com_valor,
        SUM(EXISTS (SELECT 1 FROM $A a WHERE a.`$codA` = o.`$c`) AND NOT EXISTS (SELECT 1 FROM $B b WHERE b.`$codB` = o.`$c`)) AS usam_codigo_so_de_tipo_de_reclamacao,
        SUM(EXISTS (SELECT 1 FROM $B b WHERE b.`$codB` = o.`$c`)) AS usam_codigo_de_tiporeclamacao,
        SUM(NOT EXISTS (SELECT 1 FROM $A a WHERE a.`$codA` = o.`$c`) AND NOT EXISTS (SELECT 1 FROM $B b WHERE b.`$codB` = o.`$c`)) AS codigo_em_nenhuma
        FROM $o.`nc_indicacao_ocorrencia` o WHERE o.`$c` IS NOT NULL AND TRIM(o.`$c`) NOT IN ('', '0')")[0];
}
$rel['uso_nas_ocorrencias'] = $usos ?: 'nenhuma coluna de nc_indicacao_ocorrencia com "reclam" no nome';
// Views do legado que usam a tabela
$rel['views_do_legado_que_usam'] = array_column($q("SELECT TABLE_NAME v FROM VIEWS WHERE TABLE_SCHEMA = ? AND VIEW_DEFINITION LIKE '%nc_tipo_de_reclamacao%'", [$origem]), 'v');
$soA = 0; foreach ((array)$usos as $u) if (is_array($u)) $soA += (int)$u['usam_codigo_so_de_tipo_de_reclamacao'];
$rel['conclusao'] = $soA === 0 && (int)$rel['codigos']['mesmo_codigo_descricao_diferente'] === 0
    ? 'nenhuma ocorrência depende de nc_tipo_de_reclamacao: pode ser desconsiderada'
    : 'HÁ ocorrências ou descrições que dependem de nc_tipo_de_reclamacao: não desconsiderar sem tratar';
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
