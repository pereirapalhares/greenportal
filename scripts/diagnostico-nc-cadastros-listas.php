<?php
declare(strict_types=1);

/*
 * Diagnóstico para a fase 1 da Administração de NC (cadastros gerais e listas de distribuição, 22/10/2026). SOMENTE LEITURA.
 * Confere no banco do greenportal e no legado: tabelas e colunas dos cadastros, maior código (faixa 50001 do greenportal),
 * AUTO_INCREMENT, listas de distribuição, categorias, e como estão os participantes externos (usuários sem login, legado
 * usuarios.ativado = 'X') nos dois lados.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-nc-cadastros-listas.php [--banco=gcrown_dbp] [--origem=green_crown]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$banco = 'gcrown_dbp'; $origem = 'green_crown';
foreach ($argv ?? [] as $a) {
    if (preg_match('/^--banco=(g[a-z0-9_]+_dbp)$/', $a, $m)) $banco = $m[1];
    if (preg_match('/^--origem=([A-Za-z0-9_]+)$/', $a, $m)) $origem = $m[1];
}
$pdo = GreenPortalDatabase::connect(GreenPortalDatabase::env(), 'information_schema', 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('SET NAMES utf8mb4');
$q = static function (string $sql, array $p = []) use ($pdo): array { try { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(); $s->closeCursor(); return $r; } catch (Throwable $e) { return ['erro' => mb_substr($e->getMessage(), 0, 250)]; } };
$v = static function (string $sql, array $p = []) use ($q) { $r = $q($sql, $p); return isset($r['erro']) ? $r['erro'] : ($r ? reset($r[0]) : null); };
$id = static fn(string $s): string => '`' . str_replace('`', '', $s) . '`';
$cols = static function (string $b, string $t) use ($q): array { $r = $q('SELECT COLUMN_NAME c, COLUMN_TYPE t, EXTRA x, COLUMN_KEY k FROM COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$b, $t]); return isset($r['erro']) ? [] : $r; };
$rel = ['diagnostico' => 'cadastros e listas de NC (fase 1)', 'banco' => $banco, 'origem' => $origem, 'gerado_em' => date('c')];

$tabelas = ['nc_tipoocorrencia', 'nc_classificacao_fornecedor', 'nc_tipoacao', 'nc_origemacao', 'nc_siglagestao', 'nc_setores', 'nc_tipos_causas',
    'nc_listadistribuicao', 'nc_listadistribuicaoxusuarios', 'nc_categorias', 'nc_listadistribuicaoxcategoria', 'nc_listadistribuicaoxcategorias', 'nc_tipo_de_naoconformidadexcampos'];
foreach ($tabelas as $t) {
    $item = [];
    foreach (['novo' => $banco, 'legado' => $origem] as $lado => $b) {
        $c = $cols($b, $t);
        if (!$c) { $item[$lado] = 'não existe'; continue; }
        $pk = array_values(array_filter($c, static fn($x) => $x['k'] === 'PRI'));
        $info = ['colunas' => array_map(static fn($x) => $x['c'] . ' ' . $x['t'] . ($x['x'] ? ' ' . $x['x'] : '') . ($x['k'] === 'PRI' ? ' PK' : ''), $c),
            'linhas' => (int)$v('SELECT COUNT(*) FROM ' . $id($b) . '.' . $id($t))];
        if ($pk) $info['maior_codigo'] = $v('SELECT MAX(' . $id($pk[0]['c']) . ') FROM ' . $id($b) . '.' . $id($t));
        $info['auto_increment'] = $v('SELECT AUTO_INCREMENT FROM TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$b, $t]);
        $info['gatilhos'] = array_column($q('SELECT TRIGGER_NAME n FROM TRIGGERS WHERE EVENT_OBJECT_SCHEMA = ? AND EVENT_OBJECT_TABLE = ?', [$b, $t]) ?: [], 'n');
        $item[$lado] = $info;
    }
    $rel['tabelas'][$t] = $item;
}
// categorias (amostra) e listas por categoria
$rel['categorias_novo'] = $q('SELECT * FROM ' . $id($banco) . '.nc_categorias LIMIT 30');

// usuários: colunas e situação (participantes externos)
foreach (['novo' => [$banco, 'usuarios'], 'legado' => [$origem . '_seguranca', 'usuarios']] as $lado => [$b, $t]) {
    $c = $cols($b, $t);
    $nomes = array_map(static fn($x) => strtolower($x['c']), $c);
    $u = ['colunas' => array_map(static fn($x) => $x['c'], $c)];
    foreach (['ativado', 'ativo'] as $col) if (in_array($col, $nomes, true))
        $u['por_' . $col] = $q('SELECT ' . $id($col) . ' valor, COUNT(*) n FROM ' . $id($b) . '.' . $id($t) . ' GROUP BY 1 ORDER BY n DESC LIMIT 10');
    $rel['usuarios_' . $lado] = $u;
}
// participantes externos do legado (ativado = 'X') e como aparecem no greenportal
$ucol = array_map(static fn($x) => strtolower($x['c']), $cols($banco, 'usuarios'));
$rel['participantes'] = [
    'legado_ativado_X' => (int)$v('SELECT COUNT(*) FROM ' . $id($origem . '_seguranca') . ".usuarios WHERE ativado = 'X'"),
    'legado_X_em_listas' => (int)$v('SELECT COUNT(DISTINCT x.CODUSUARIO) FROM ' . $id($origem) . '.nc_listadistribuicaoxusuarios x JOIN ' . $id($origem . '_seguranca') . ".usuarios u ON u.codusuario = x.CODUSUARIO WHERE u.ativado = 'X'"),
    'legado_X_no_greenportal' => (int)$v('SELECT COUNT(*) FROM ' . $id($origem . '_seguranca') . '.usuarios l JOIN ' . $id($banco) . ".usuarios n ON n.codusuario = l.codusuario WHERE l.ativado = 'X'"),
    'como_estao_no_greenportal' => in_array('ativo', $ucol, true) ? $q('SELECT n.ativo, COUNT(*) n FROM ' . $id($origem . '_seguranca') . '.usuarios l JOIN ' . $id($banco) . ".usuarios n ON n.codusuario = l.codusuario WHERE l.ativado = 'X' GROUP BY 1") : 'sem coluna ativo',
    'tem_email' => (int)$v('SELECT COUNT(*) FROM ' . $id($origem . '_seguranca') . ".usuarios WHERE ativado = 'X' AND TRIM(COALESCE(emails,'')) <> ''"),
    'amostra' => $q('SELECT codusuario, nome, LEFT(emails, 60) emails, DTINCLUSAO FROM ' . $id($origem . '_seguranca') . ".usuarios WHERE ativado = 'X' ORDER BY codusuario DESC LIMIT 8"),
];
// membros das listas: portal × externos × inativos (greenportal)
if (in_array('ativo', $ucol, true)) $rel['membros_listas_novo'] = $q('SELECT COALESCE(u.ativo, -1) ativo, COUNT(*) n FROM ' . $id($banco) . '.nc_listadistribuicaoxusuarios x LEFT JOIN ' . $id($banco) . '.usuarios u ON u.codusuario = x.CODUSUARIO GROUP BY 1');
// e-mail: há serviço/tabela de envio no greenportal?
$rel['tabelas_email_novo'] = array_column($q("SELECT TABLE_NAME n FROM TABLES WHERE TABLE_SCHEMA = ? AND (TABLE_NAME LIKE '%email%' OR TABLE_NAME LIKE '%mail%')", [$banco]) ?: [], 'n');
$rel['tabelas_email_universal'] = array_column($q("SELECT TABLE_NAME n FROM TABLES WHERE TABLE_SCHEMA = 'guniversal_dbp' AND (TABLE_NAME LIKE '%email%' OR TABLE_NAME LIKE '%mail%')") ?: [], 'n');

$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'diagnostico_nc_cadastros_listas_' . $banco . '.json', $json);
echo $json . PHP_EOL;
