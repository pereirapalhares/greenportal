<?php
declare(strict_types=1);

/*
 * Levantamento (SOMENTE LEITURA) do módulo legado Homologação de Fornecedores, para a engenharia reversa.
 * Não grava nada em banco nenhum. Lê:
 *   1. information_schema: tabelas fo_* (e as do legado sem prefixo: fornecedor, tipofornecedor, criterios, avaliacao_*, ...)
 *      em green_atlas e em gatlas_dbp, com colunas, chaves e quantidade de linhas — para comparar o que foi migrado;
 *   2. views, funções/procedures e triggers que citam fo_ ou fornecedor (com a definição), incluindo as funções que as
 *      páginas usam (getTipoFornecedores, getDSCUNIDADE, getCODUNIDADE, getUsuario, MASK, ...);
 *   3. green_seguranca.menu (tabela inteira, para ver a árvore e os links do módulo) e as tabelas de usuário/fornecedor
 *      do green_atlas_seguranca (só estrutura e contagem);
 *   4. contagens por situação em fo_fornecedorxcriterios e fo_fornecedores (ativos), para saber o que está em uso.
 * O resultado completo vai para scripts/saida/levantamento-fornecedores-legado.json; na tela sai só um resumo.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\levantamento-fornecedores-legado.php [--legado=green_atlas] [--novo=gatlas_dbp] [--menu=green_seguranca]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$arg = static function (string $nome, string $padrao) use ($argv): string {
    foreach ($argv ?? [] as $a) if (str_starts_with($a, "--$nome=")) return substr($a, strlen($nome) + 3);
    return $padrao;
};
$legado = $arg('legado', 'green_atlas');
$novo = $arg('novo', 'gatlas_dbp');
$menuDb = $arg('menu', 'green_seguranca');
$segLegado = $legado . '_seguranca';

$env = GreenPortalDatabase::env();
$rel = ['ok' => true, 'gerado_em' => date('c'), 'bancos' => compact('legado', 'novo', 'menuDb', 'segLegado'), 'erros' => []];
$is = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$is->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$q = static function (string $sql, array $p = []) use ($is): array { $s = $is->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC); };

// Tabelas do módulo: prefixo fo_ + legado sem prefixo
$filtroTabela = "(LOWER(TABLE_NAME) LIKE 'fo\\_%' OR LOWER(TABLE_NAME) LIKE 'vwfo\\_%' OR LOWER(TABLE_NAME) IN ('fornecedor','tipofornecedor','tipofornecedorxfornecedor','fornecedorxtipofornecedor',
  'criterios','tipofornecedorxcriterios','criteriosxlegislacao','avaliacao_documental','avaliacao_sistemica','avaliacao_amostra','planodeacao_fornecedores',
  'enviar_criterios_fornecedor','grupos_avaliacao_sistemica','gruposxtipofornecedor_avaliacao_sistemica','gruposxperguntas_avaliacao_sistemica',
  'certificados','fornecedorxcertificados','fornecedorxcontatos','categoria_fornecedor','fornecedorxcategoria_fornecedor','tipofornecedorxaspectos',
  'tipofornecedorxperigos','integracao','nc_classificacao_fornecedor') OR LOWER(TABLE_NAME) LIKE '%fornecedor%')";

$estrutura = static function (string $db) use ($q, $filtroTabela): array {
    $out = [];
    foreach ($q("SELECT TABLE_NAME, TABLE_TYPE, TABLE_ROWS, ENGINE, TABLE_COLLATION, CREATE_TIME, UPDATE_TIME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = ? AND $filtroTabela ORDER BY TABLE_NAME", [$db]) as $t) {
        $out[$t['TABLE_NAME']] = ['tipo' => $t['TABLE_TYPE'], 'linhas_aprox' => $t['TABLE_ROWS'] === null ? null : (int)$t['TABLE_ROWS'],
            'engine' => $t['ENGINE'], 'collation' => $t['TABLE_COLLATION'], 'atualizada' => $t['UPDATE_TIME'], 'colunas' => [], 'indices' => []];
    }
    foreach ($q("SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY, EXTRA, COLUMN_COMMENT FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND $filtroTabela ORDER BY TABLE_NAME, ORDINAL_POSITION", [$db]) as $c) {
        if (!isset($out[$c['TABLE_NAME']])) continue;
        $out[$c['TABLE_NAME']]['colunas'][$c['COLUMN_NAME']] = trim($c['COLUMN_TYPE'] . ($c['IS_NULLABLE'] === 'NO' ? ' NOT NULL' : '')
            . ($c['COLUMN_DEFAULT'] !== null ? ' DEFAULT ' . $c['COLUMN_DEFAULT'] : '') . ($c['COLUMN_KEY'] ? ' [' . $c['COLUMN_KEY'] . ']' : '')
            . ($c['EXTRA'] ? ' ' . $c['EXTRA'] : '') . ($c['COLUMN_COMMENT'] ? ' -- ' . $c['COLUMN_COMMENT'] : ''));
    }
    foreach ($q("SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) c FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = ? AND $filtroTabela GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE", [$db]) as $i) {
        if (isset($out[$i['TABLE_NAME']])) $out[$i['TABLE_NAME']]['indices'][$i['INDEX_NAME']] = ($i['NON_UNIQUE'] ? '' : 'UNIQUE ') . '(' . $i['c'] . ')';
    }
    foreach ($q("SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL AND $filtroTabela", [$db]) as $f) {
        if (isset($out[$f['TABLE_NAME']])) $out[$f['TABLE_NAME']]['fks'][] = $f['COLUMN_NAME'] . ' -> ' . $f['REFERENCED_TABLE_NAME'] . '.' . $f['REFERENCED_COLUMN_NAME'];
    }
    return $out;
};

$objetos = static function (string $db) use ($q): array {
    $funcoesUsadas = ['gettipofornecedores', 'getdscunidade', 'getcodunidade', 'getusuario', 'mask', 'getncprazo', 'getncdtaplano', 'getstatusplanoacaonc'];
    $r = ['views' => [], 'rotinas' => [], 'triggers' => []];
    foreach ($q("SELECT TABLE_NAME, VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ?", [$db]) as $v) {
        $d = (string)$v['VIEW_DEFINITION'];
        if (preg_match('/fo_|fornecedor/i', $v['TABLE_NAME'] . ' ' . $d)) $r['views'][$v['TABLE_NAME']] = $d;
    }
    foreach ($q("SELECT ROUTINE_NAME, ROUTINE_TYPE, DTD_IDENTIFIER, ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ?", [$db]) as $p) {
        $d = (string)$p['ROUTINE_DEFINITION'];
        if (in_array(strtolower($p['ROUTINE_NAME']), $funcoesUsadas, true) || preg_match('/fo_|fornecedor/i', $p['ROUTINE_NAME'] . ' ' . $d))
            $r['rotinas'][$p['ROUTINE_NAME']] = ['tipo' => $p['ROUTINE_TYPE'], 'retorno' => $p['DTD_IDENTIFIER'], 'definicao' => $d];
    }
    foreach ($q("SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_TIMING, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?", [$db]) as $t) {
        if (preg_match('/fo_|fornecedor/i', $t['EVENT_OBJECT_TABLE'] . ' ' . $t['ACTION_STATEMENT']))
            $r['triggers'][$t['TRIGGER_NAME']] = $t['ACTION_TIMING'] . ' ' . $t['EVENT_MANIPULATION'] . ' ON ' . $t['EVENT_OBJECT_TABLE'] . ': ' . $t['ACTION_STATEMENT'];
    }
    foreach ($q("SELECT EVENT_NAME, STATUS, INTERVAL_VALUE, INTERVAL_FIELD, EVENT_DEFINITION FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ?", [$db]) as $e)
        if (preg_match('/fo_|fornecedor/i', (string)$e['EVENT_DEFINITION'])) $r['eventos'][$e['EVENT_NAME']] = $e;
    return $r;
};

$existe = static fn(string $db): bool => (bool)$q('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$db]);

foreach ([$legado, $novo, $segLegado] as $db) {
    try {
        if (!$existe($db)) { $rel['erros'][] = "banco $db não existe"; continue; }
        $rel['estrutura'][$db] = $estrutura($db);
        $rel['objetos'][$db] = $objetos($db);
    } catch (Throwable $e) { $rel['erros'][] = "$db: " . $e->getMessage(); }
}
// Funções citadas com nome de banco fixo (green_universal.MASK etc.)
foreach (['green_universal', $segLegado] as $db) {
    try { if ($existe($db)) $rel['objetos_funcoes'][$db] = $objetos($db)['rotinas']; } catch (Throwable $e) { $rel['erros'][] = "$db rotinas: " . $e->getMessage(); }
}

// Comparação legado x novo
$cmp = [];
$a = $rel['estrutura'][$legado] ?? []; $b = $rel['estrutura'][$novo] ?? [];
$bLower = array_change_key_case($b, CASE_LOWER);
foreach ($a as $t => $info) {
    $n = $bLower[strtolower($t)] ?? null;
    $colsA = array_map('strtolower', array_keys($info['colunas'])); $colsB = $n ? array_map('strtolower', array_keys($n['colunas'])) : [];
    $cmp[$t] = ['no_novo' => (bool)$n, 'linhas_legado' => $info['linhas_aprox'], 'linhas_novo' => $n['linhas_aprox'] ?? null,
        'colunas_faltando_no_novo' => array_values(array_diff($colsA, $colsB)), 'colunas_so_no_novo' => $n ? array_values(array_diff($colsB, $colsA)) : []];
}
foreach ($b as $t => $info) if (!isset(array_change_key_case($a, CASE_LOWER)[strtolower($t)])) $cmp[$t] = ['so_no_novo' => true, 'linhas_novo' => $info['linhas_aprox']];
$rel['comparacao'] = $cmp;

// Leituras diretas no legado (só SELECT): contagem exata, situações e menu
try {
    if ($existe($legado)) {
        $l = GreenPortalDatabase::connect($env, $legado, 'GPT');
        $l->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ($a as $t => $info) {
            if ($info['tipo'] !== 'BASE TABLE') continue;
            try { $rel['contagem_exata'][$t] = (int)$l->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); } catch (Throwable $e) { $rel['contagem_exata'][$t] = 'erro: ' . $e->getMessage(); }
        }
        $grupos = [
            'fo_fornecedores.ativadofor' => 'SELECT ativadofor v, COUNT(*) n FROM fo_fornecedores GROUP BY ativadofor',
            'fo_fornecedorxcriterios.situacao' => 'SELECT situacao v, COUNT(*) n FROM fo_fornecedorxcriterios GROUP BY situacao',
            'fo_fornecedorxcriterios.emprotocolo' => 'SELECT emprotocolo v, COUNT(*) n FROM fo_fornecedorxcriterios GROUP BY emprotocolo',
            'fo_tipofornecedoresxcriteriofornecedores.nivel_de_exigencia' => 'SELECT nivel_de_exigencia v, COUNT(*) n FROM fo_tipofornecedoresxcriteriofornecedores GROUP BY nivel_de_exigencia',
            'fo_fornecedoresxtipofornecedores.fornecedoraprovadoauditoria' => 'SELECT fornecedoraprovadoauditoria v, COUNT(*) n FROM fo_fornecedoresxtipofornecedores GROUP BY fornecedoraprovadoauditoria',
            'fo_funcionarioxtiposxcriterios.atendeaocriterio' => 'SELECT atendeaocriterio v, COUNT(*) n FROM fo_funcionarioxtiposxcriterios GROUP BY atendeaocriterio',
            'fo_funcionarios.status' => 'SELECT status v, COUNT(*) n FROM fo_funcionarios GROUP BY status',
            'fornecedores_por_qtd_tipos' => 'SELECT qtd v, COUNT(*) n FROM (SELECT codfornecedor, COUNT(*) qtd FROM fo_fornecedoresxtipofornecedores GROUP BY codfornecedor) x GROUP BY qtd',
            'fo_fornecedorxcriterios.ultima_alteracao_historico' => 'SELECT MAX(DATAALTERACAO) v, COUNT(*) n FROM fo_fornecedorxcriterios_historico',
            'fo_fornecedorxcriterios.datacobranca_emprotocolo_preenchida' => 'SELECT datacobranca_emprotocolo IS NOT NULL v, COUNT(*) n FROM fo_fornecedorxcriterios GROUP BY 1',
        ];
        foreach ($grupos as $k => $sql) {
            try { $rel['valores_em_uso'][$k] = $l->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR); } catch (Throwable $e) { $rel['valores_em_uso'][$k] = 'erro: ' . $e->getMessage(); }
        }
    }
} catch (Throwable $e) { $rel['erros'][] = "leitura $legado: " . $e->getMessage(); }

// Usuários fornecedores no seguranca (contagem, sem dados pessoais)
try {
    if ($existe($segLegado)) {
        $s = GreenPortalDatabase::connect($env, $segLegado, 'GPT');
        $s->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $colsU = array_column($q("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'usuarios'", [$segLegado]), 'COLUMN_NAME');
        $rel['seguranca_usuarios_colunas'] = $colsU;
        $lc = array_map('strtolower', $colsU);
        foreach (['codfornecedor', 'fornecedor', 'fornecedor_gestor'] as $c) if (in_array($c, $lc, true)) {
            $col = $colsU[array_search($c, $lc, true)];
            $rel['seguranca_usuarios_' . $c] = $s->query("SELECT CASE WHEN `$col` IS NULL OR `$col` IN ('', '0') THEN '(vazio/0)' ELSE 'preenchido' END v, COUNT(*) n FROM usuarios GROUP BY 1")->fetchAll(PDO::FETCH_KEY_PAIR);
        }
        $rel['seguranca_tabelas'] = array_column($q("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY 1", [$segLegado]), 'TABLE_NAME');
    }
} catch (Throwable $e) { $rel['erros'][] = "leitura $segLegado: " . $e->getMessage(); }

// Menu do legado (tabela inteira) + itens do módulo
try {
    if (!$existe($menuDb)) throw new RuntimeException("banco $menuDb não existe");
    $tabMenu = $q("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND LOWER(TABLE_NAME) IN ('menu','menus') LIMIT 1", [$menuDb])[0]['TABLE_NAME'] ?? null;
    if (!$tabMenu) throw new RuntimeException("tabela menu não encontrada em $menuDb");
    $rel['menu_colunas'] = array_column($q("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION", [$menuDb, $tabMenu]), 'COLUMN_NAME');
    $m = GreenPortalDatabase::connect($env, $menuDb, 'GPT');
    $m->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $todos = $m->query("SELECT * FROM `$tabMenu`")->fetchAll(PDO::FETCH_ASSOC);
    $rel['menu_total'] = count($todos);
    $rel['menu_completo'] = $todos;
    $rel['menu_fornecedores'] = array_values(array_filter($todos, static fn($r) => (bool)preg_match('/fo_|fornecedor|homolog|supplier/i', implode(' ', array_map('strval', $r)))));
} catch (Throwable $e) { $rel['erros'][] = 'menu: ' . $e->getMessage(); }

$dir = __DIR__ . DIRECTORY_SEPARATOR . 'saida';
if (!is_dir($dir)) mkdir($dir, 0777, true);
$arq = $dir . DIRECTORY_SEPARATOR . 'levantamento-fornecedores-legado.json';
$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
file_put_contents($arq, $json);

$resumo = ['arquivo' => $arq, 'bytes' => strlen((string)$json), 'erros' => $rel['erros']];
foreach ($rel['estrutura'] ?? [] as $db => $t) $resumo['tabelas'][$db] = count($t);
foreach ($rel['objetos'] ?? [] as $db => $o) $resumo['objetos'][$db] = ['views' => count($o['views']), 'rotinas' => count($o['rotinas']), 'triggers' => count($o['triggers'])];
$resumo['nao_migradas'] = array_keys(array_filter($cmp, static fn($c) => isset($c['no_novo']) && !$c['no_novo']));
$resumo['menu_total'] = $rel['menu_total'] ?? null;
$resumo['menu_fornecedores'] = count($rel['menu_fornecedores'] ?? []);
echo json_encode($resumo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
