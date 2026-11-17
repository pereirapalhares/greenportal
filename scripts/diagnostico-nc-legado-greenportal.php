<?php
declare(strict_types=1);

/*
 * Diagnóstico (SÓ LEITURA) — Não Conformidades: legado × greenportal (20/10/2026).
 * Para o banco informado (padrão gcrown_dbp) e o legado dele (HomologacaoBancos::legado):
 *  1. tabelas nc_* nos dois lados, com nº de linhas; as que só existem de um lado;
 *  2. colunas diferentes nas tabelas comuns;
 *  3. ocorrências (nc_indicacao_ocorrencia): por tipo, etapa/situação e ano, nos dois; códigos só de um lado;
 *  4. plano de ação (nc_planodeacao) por status; causas; histórico;
 *  5. menus de NC do legado (green_seguranca.menus) com os grupos que os têm, e as permissões de NC no greenportal por perfil.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-nc-legado-greenportal.php [--banco=gcrown_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

$banco = 'gcrown_dbp';
foreach ($argv ?? [] as $a) if (preg_match('/^--banco=(g[a-z0-9_]+_dbp)$/', $a, $m)) $banco = $m[1];
$leg = (string)HomologacaoBancos::legado($banco);
if ($leg === '') { fwrite(STDERR, "Banco sem legado conhecido: $banco\n"); exit(1); }
$seg = $leg . '_seguranca';
$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
$q = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$v = static fn(string $sql, array $p = []) => ($r = $q($sql, $p)) ? array_values($r[0])[0] : null;
$tenta = static function (callable $f) { try { return $f(); } catch (Throwable $e) { return ['erro' => mb_substr($e->getMessage(), 0, 220)]; } };
$tabelas = static fn(string $db): array => array_column($q("SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND (TABLE_NAME LIKE 'nc\\_%' OR TABLE_NAME LIKE 'vnc%' OR TABLE_NAME LIKE 'vw\\_nc%' OR TABLE_NAME LIKE 'vlistadeocorrencias%' OR TABLE_NAME LIKE '%naoconform%') ORDER BY TABLE_NAME", [$db]), 'TABLE_TYPE', 'TABLE_NAME');
$colunas = static fn(string $db, string $t): array => array_map('strtolower', array_column($q('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$db, $t]), 'COLUMN_NAME'));
$conta = static fn(string $db, string $t) => $tenta(fn() => (int)$v("SELECT COUNT(*) FROM `$db`.`$t`"));

$r = ['gerado_em' => date('c'), 'banco' => $banco, 'legado' => $leg];
$tl = $tabelas($leg); $tg = $tabelas($banco);
$todas = array_unique(array_merge(array_keys($tl), array_keys($tg))); sort($todas);
$lin = [];
foreach ($todas as $t) {
    $lin[$t] = [
        'legado' => isset($tl[$t]) ? ($tl[$t] === 'VIEW' ? 'view ' : '') . (is_int($c = $conta($leg, $t)) ? $c : json_encode($c)) : '—',
        'greenportal' => isset($tg[$t]) ? ($tg[$t] === 'VIEW' ? 'view ' : '') . (is_int($c = $conta($banco, $t)) ? $c : json_encode($c)) : '—',
    ];
    if (isset($tl[$t], $tg[$t]) && $tl[$t] === 'BASE TABLE' && $tg[$t] === 'BASE TABLE') {
        $cl = $colunas($leg, $t); $cg = $colunas($banco, $t);
        $so_l = array_values(array_filter(array_diff($cl, $cg), static fn($c) => !str_ends_with($c, '_en')));
        $so_g = array_values(array_diff($cg, $cl));
        if ($so_l) $lin[$t]['colunas_so_legado'] = $so_l;
        if ($so_g) $lin[$t]['colunas_so_greenportal'] = $so_g;
    }
}
$r['tabelas'] = $lin;

// Ocorrências
$oc = 'nc_indicacao_ocorrencia';
$resumo = static function (string $db) use ($q, $colunas, $tenta, $oc): array {
    $cols = $colunas($db, $oc);
    if (!$cols) return ['erro' => 'sem a tabela'];
    $out = ['colunas' => count($cols)];
    foreach (['tipo', 'etapa', 'status', 'situacao', 'tipoocorrencia', 'codtipoocorrencia', 'origem'] as $c)
        if (in_array($c, $cols, true)) $out['por_' . $c] = $tenta(fn() => array_column($q("SELECT COALESCE(NULLIF(TRIM(CAST(`$c` AS CHAR)), ''), '(vazio)') AS k, COUNT(*) AS n FROM `$db`.`$oc` GROUP BY 1 ORDER BY n DESC LIMIT 25"), 'n', 'k'));
    $data = null; foreach (['datadaocorrencia', 'data_ocorrencia', 'dataocorrencia', 'datacadastro', 'data_abertura', 'data'] as $c) if (in_array($c, $cols, true)) { $data = $c; break; }
    if ($data) $out['por_ano_' . $data] = $tenta(fn() => array_column($q("SELECT COALESCE(YEAR(`$data`), 0) AS k, COUNT(*) AS n FROM `$db`.`$oc` GROUP BY 1 ORDER BY 1 DESC LIMIT 15"), 'n', 'k'));
    $out['colunas_lista'] = $cols;
    return $out;
};
$r['ocorrencias'] = ['legado' => $resumo($leg), 'greenportal' => $resumo($banco)];
$pk = 'codocorrencia';
if (in_array($pk, $colunas($leg, $oc), true) && in_array($pk, $colunas($banco, $oc), true)) {
    $r['ocorrencias']['codigos'] = $tenta(fn() => [
        'so_no_legado' => (int)$v("SELECT COUNT(*) FROM `$leg`.`$oc` l WHERE NOT EXISTS (SELECT 1 FROM `$banco`.`$oc` g WHERE g.codocorrencia = l.codocorrencia)"),
        'so_no_greenportal' => (int)$v("SELECT COUNT(*) FROM `$banco`.`$oc` g WHERE NOT EXISTS (SELECT 1 FROM `$leg`.`$oc` l WHERE l.codocorrencia = g.codocorrencia)"),
        'exemplos_so_greenportal' => array_column($q("SELECT g.codocorrencia FROM `$banco`.`$oc` g WHERE NOT EXISTS (SELECT 1 FROM `$leg`.`$oc` l WHERE l.codocorrencia = g.codocorrencia) ORDER BY 1 DESC LIMIT 10"), 'codocorrencia'),
        'ultimo_legado' => $v("SELECT MAX(codocorrencia) FROM `$leg`.`$oc`"),
        'ultimo_greenportal' => $v("SELECT MAX(codocorrencia) FROM `$banco`.`$oc`"),
    ]);
}
// Plano de ação
foreach (['nc_planodeacao'] as $t) {
    foreach (['legado' => $leg, 'greenportal' => $banco] as $lado => $db) {
        $cols = $colunas($db, $t);
        foreach (['status', 'situacao', 'percentual'] as $c) if (in_array($c, $cols, true))
            $r['plano_de_acao'][$lado]['por_' . $c] = $tenta(fn() => array_column($q("SELECT COALESCE(NULLIF(TRIM(CAST(`$c` AS CHAR)), ''), '(vazio)') AS k, COUNT(*) AS n FROM `$db`.`$t` GROUP BY 1 ORDER BY n DESC LIMIT 15"), 'n', 'k'));
        $r['plano_de_acao'][$lado]['colunas'] = $cols;
    }
}
// Menus de NC no legado e grupos
$r['menus_legado'] = $tenta(function () use ($q, $seg) {
    $m = [];
    foreach ($q('SELECT CODMENU, DSCMENU, URL, CODMENUSUPERIOR FROM green_seguranca.menus') as $l) $m[(int)$l['CODMENU']] = $l;
    $raiz = static function (int $id) use ($m): string { $g = 0; $p = []; while ($id > 0 && isset($m[$id]) && $g++ < 10) { array_unshift($p, trim((string)$m[$id]['DSCMENU'])); $prox = (int)$m[$id]['CODMENUSUPERIOR']; $id = $prox === $id ? 0 : $prox; } return implode(' › ', $p); };
    $grupos = [];
    foreach ($q("SELECT gm.CODMENU, g.DSCGRUPO FROM `$seg`.gruposxmenus gm JOIN `$seg`.grupos g ON g.CODGRUPO = gm.CODGRUPO") as $l) $grupos[(int)$l['CODMENU']][] = (string)$l['DSCGRUPO'];
    $out = [];
    foreach ($m as $id => $l) {
        $c = $raiz($id);
        if (!preg_match('/n[aã]o conformidade|planejamento|assist[eê]ncia t[eé]cnica|clientes n[aã]o/iu', $c)) continue;
        if (!isset($grupos[$id])) continue;
        $out[] = $id . ' | ' . $c . ' | ' . preg_replace('/[?#].*$/', '', basename((string)$l['URL'])) . ' | grupos: ' . implode(', ', array_unique($grupos[$id]));
    }
    sort($out);
    return $out;
});
$r['permissoes_greenportal'] = $tenta(fn() => $q("SELECT p.codigo AS perfil, x.menu_codigo AS menu,
        CONCAT_WS('/', IF(x.pode_visualizar,'V',NULL), IF(x.pode_visualizar_total,'VT',NULL), IF(x.pode_incluir,'I',NULL), IF(x.pode_editar,'E',NULL), IF(x.pode_excluir,'X',NULL)) AS acoes
      FROM vw_permissoes_grid x JOIN perfis p ON p.codperfil = x.codperfil
     WHERE UPPER(x.menu_codigo) LIKE 'NAO\\_CONFORMIDADE%' AND (x.pode_visualizar OR x.pode_visualizar_total) ORDER BY 1, 2"));
$r['usuarios_com_ocorrencia'] = $tenta(function () use ($v, $leg, $banco, $oc, $colunas) {
    $out = [];
    foreach (['codusuario', 'codemissor', 'codusuario_emissor', 'codresponsavel', 'codgestor', 'codusuario_responsavel'] as $c)
        if (in_array($c, $colunas($leg, $oc), true)) $out[$c] = ['legado_distintos' => (int)$v("SELECT COUNT(DISTINCT `$c`) FROM `$leg`.`$oc`")];
    return $out;
});

$json = json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'diagnostico_nc_' . $banco . '.json', $json);
echo $json . PHP_EOL;
