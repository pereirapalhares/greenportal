<?php
declare(strict_types=1);

/*
 * Diagnóstico (SÓ LEITURA) — cronograma da auditoria de homologação (20/10/2026).
 * Legado: aba Auditoria (configuracoes/fo_auditoriatipos.php) → cadastros/ev_schedule_c.php?codauditoria=<codfornecedorxtipofornecedores>;
 * eventos em ev_eventos (codauditoria, codcategoria 2 = Auditoria, auditoria_facilidades_necessarias, codusuarios_auditores,
 * codchecklists → pa_checklist). Greenportal: principal/eventos.php (hoje só por codmonitoramento).
 * Para cada banco: ev_eventos no legado e no greenportal (existe? colunas de auditoria? linhas), eventos de auditoria
 * (codauditoria > 0) e se o código bate com fornecedor × tipo (fo_fornecedoresxtipofornecedores), auditores e checklists
 * preenchidos, pa_checklist, e se os eventos do legado estão no greenportal.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-cronograma-auditoria.php [--banco=gatlas_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

try { $alvos = HomologacaoBancos::lista($argv ?? []); } catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }
$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$q = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$v = static fn(string $sql, array $p = []) => ($r = $q($sql, $p)) ? array_values($r[0])[0] : null;
$existe = static fn(string $db, string $t): bool => (int)$v('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$db, $t]) > 0;
$cols = static fn(string $db, string $t): array => array_map('strtolower', array_column($q('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$db, $t]), 'COLUMN_NAME'));
$tenta = static function (callable $f) { try { return $f(); } catch (Throwable $e) { return ['erro' => mb_substr($e->getMessage(), 0, 200)]; } };

$lado = static function (string $db) use ($existe, $cols, $q, $v, $tenta): array {
    if (!$existe($db, 'ev_eventos')) return ['ev_eventos' => 'não existe'];
    $c = $cols($db, 'ev_eventos');
    $r = ['colunas' => $c, 'linhas' => (int)$v("SELECT COUNT(*) FROM `$db`.ev_eventos"), 'maior_codevento' => (int)$v("SELECT MAX(codevento) FROM `$db`.ev_eventos")];
    $r['por_categoria'] = $tenta(fn() => array_column($q("SELECT COALESCE(codcategoria, 0) AS c, COUNT(*) AS n FROM `$db`.ev_eventos GROUP BY 1"), 'n', 'c'));
    if (in_array('codauditoria', $c, true)) {
        $r['com_codauditoria'] = (int)$v("SELECT COUNT(*) FROM `$db`.ev_eventos WHERE codauditoria > 0");
        if ($existe($db, 'fo_fornecedoresxtipofornecedores'))
            $r['codauditoria_que_e_fornecedor_x_tipo'] = (int)$v("SELECT COUNT(*) FROM `$db`.ev_eventos e WHERE e.codauditoria > 0 AND EXISTS (SELECT 1 FROM `$db`.fo_fornecedoresxtipofornecedores ft WHERE ft.codfornecedorxtipofornecedores = e.codauditoria)");
        $r['exemplos_auditoria'] = $tenta(fn() => $q("SELECT codevento, codcategoria, codauditoria, datainicial, datafim, LEFT(dscevento, 60) AS dscevento" . (in_array('codusuarios_auditores', $c, true) ? ", codusuarios_auditores, codchecklists, LEFT(auditoria_facilidades_necessarias, 40) AS facilidades" : '') . " FROM `$db`.ev_eventos WHERE codauditoria > 0 ORDER BY codevento DESC LIMIT 5"));
    }
    $r['pa_checklist'] = $existe($db, 'pa_checklist') ? (int)$v("SELECT COUNT(*) FROM `$db`.pa_checklist") : 'não existe';
    return $r;
};

$saida = ['gerado_em' => date('c'), 'bancos' => []];
foreach ($alvos as $banco) {
    $leg = (string)HomologacaoBancos::legado($banco);
    $r = ['legado' => $leg, 'no_legado' => $tenta(fn() => $lado($leg)), 'no_greenportal' => $tenta(fn() => $lado($banco))];
    if ($existe($leg, 'ev_eventos') && $existe($banco, 'ev_eventos')) {
        $r['eventos_do_legado_fora_do_greenportal'] = $tenta(fn() => (int)$v("SELECT COUNT(*) FROM `$leg`.ev_eventos s WHERE NOT EXISTS (SELECT 1 FROM `$banco`.ev_eventos d WHERE d.codevento = s.codevento)"));
        $r['eventos_so_no_greenportal'] = $tenta(fn() => (int)$v("SELECT COUNT(*) FROM `$banco`.ev_eventos d WHERE NOT EXISTS (SELECT 1 FROM `$leg`.ev_eventos s WHERE s.codevento = d.codevento)"));
    }
    $r['tipos_que_exigem_auditoria'] = $tenta(fn() => (int)$v("SELECT COUNT(*) FROM `$banco`.fo_fornecedoresxtipofornecedores ft JOIN `$banco`.fo_tipofornecedores t ON t.codtipofornecedor = ft.codtipofornecedor WHERE t.necessita_auditoria = 'Sim'"));
    $saida['bancos'][$banco] = $r;
}
$json = json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'diagnostico_cronograma_auditoria.json', $json);
echo $json . PHP_EOL;
