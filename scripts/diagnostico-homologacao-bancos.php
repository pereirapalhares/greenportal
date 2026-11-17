<?php
declare(strict_types=1);

/*
 * Diagnóstico (somente leitura) — Homologação de Fornecedores em todas as empresas migradas.
 * Não grava nada. Lê apenas information_schema e a view vw_empresa_migracao_grid do banco universal.
 *
 * Para cada empresa migrada (banco novo g*_dbp existente) mostra:
 *  - banco legado de origem e caminho do GED (existe? quantas pastas);
 *  - tamanho aproximado dos dados fo_* (TABLE_ROWS do information_schema) no banco novo e no legado;
 *  - o que falta em relação ao gmodelo_dbp (referência, com as migrações 216 a 303 aplicadas):
 *    tabelas fo_*, colunas fo_*, views, procedures/functions e gatilhos da homologação.
 * Serve para decidir em quais bancos aplicar a cadeia de migrações da homologação (216 → 303).
 *
 * Uso:
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-homologacao-bancos.php
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-homologacao-bancos.php --detalhe   (lista os nomes que faltam)
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-homologacao-bancos.php --homologacao   (só os bancos de classes/HomologacaoBancos.php,
 *     com o resumo "precisa atualizar" e a situação das regras automáticas)
 * Além dos objetos que faltam, compara a definição de cada view, procedure e gatilho com a da referência ("diferentes"):
 * mostra versões antigas que precisam ser reaplicadas. Algumas views são montadas conforme as colunas do banco (ex.: 260, 289)
 * e podem diferir sem problema — por isso os nomes aparecem para conferência.
 * Relatório também em storage\logs\diagnostico_homologacao_bancos.json.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

const DHB_REFERENCIA = 'gmodelo_dbp';
const DHB_FILTRO_OBJ = "(LOWER(%1\$s) LIKE 'fo\\_%%' OR LOWER(%1\$s) LIKE 'sp\\_fo\\_%%' OR LOWER(%1\$s) LIKE 'vw\\_fo\\_%%' OR LOWER(%1\$s) LIKE 'trg\\_fo\\_%%'
    OR LOWER(%1\$s) LIKE '%%homologacao%%' OR LOWER(%1\$s) LIKE '%%fornecedor%%' OR LOWER(%1\$s) LIKE '%%funcionario%%')";

$detalhe = in_array('--detalhe', $argv ?? [], true);
$soHomologacao = in_array('--homologacao', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$universal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$is = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$is->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$is->exec('SET SESSION information_schema_stats_expiry = 0'); // contagens atuais, não as guardadas em cache por até 24 h
$gedRaiz = rtrim((string)($env['GGED_LEGACY_ROOT'] ?? 'E:\\projetoGreen\\GED'), '\\/');
$q = static function (PDO $pdo, string $sql, array $p = []): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); while ($s->nextRowset()) { } $s->closeCursor(); return $r; };
$col = static fn(array $r, string $c): array => array_map('strval', array_column($r, $c));

$objetos = static function (string $b) use ($q, $is, $col): array {
    return [
        'tabelas' => $col($q($is, "SELECT TABLE_NAME n FROM TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' AND LOWER(TABLE_NAME) LIKE 'fo\\_%'", [$b]), 'n'),
        'colunas' => $col($q($is, "SELECT CONCAT(LOWER(c.TABLE_NAME), '.', LOWER(c.COLUMN_NAME)) n FROM COLUMNS c JOIN TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
            WHERE c.TABLE_SCHEMA = ? AND t.TABLE_TYPE = 'BASE TABLE' AND LOWER(c.TABLE_NAME) LIKE 'fo\\_%'", [$b]), 'n'),
        'views' => $col($q($is, 'SELECT TABLE_NAME n FROM VIEWS WHERE TABLE_SCHEMA = ? AND ' . sprintf(DHB_FILTRO_OBJ, 'TABLE_NAME'), [$b]), 'n'),
        'rotinas' => $col($q($is, 'SELECT ROUTINE_NAME n FROM ROUTINES WHERE ROUTINE_SCHEMA = ? AND ' . sprintf(DHB_FILTRO_OBJ, 'ROUTINE_NAME'), [$b]), 'n'),
        'gatilhos' => $col($q($is, 'SELECT TRIGGER_NAME n FROM TRIGGERS WHERE TRIGGER_SCHEMA = ? AND (' . sprintf(DHB_FILTRO_OBJ, 'TRIGGER_NAME') . " OR LOWER(EVENT_OBJECT_TABLE) LIKE 'fo\\_%')", [$b]), 'n'),
    ];
};
// Definições normalizadas (sem o nome do banco, espaços e maiúsculas) de views, rotinas e gatilhos
$definicoes = static function (string $b) use ($q, $is): array {
    $norm = static fn(?string $d): ?string => $d === null ? null : strtolower(trim((string)preg_replace('/\s+/', ' ', str_ireplace(['`' . $b . '`.', $b . '.'], '', $d))));
    $out = [];
    foreach ($q($is, 'SELECT TABLE_NAME n, VIEW_DEFINITION d FROM VIEWS WHERE TABLE_SCHEMA = ? AND ' . sprintf(DHB_FILTRO_OBJ, 'TABLE_NAME'), [$b]) as $l) $out['view ' . strtolower($l['n'])] = $norm($l['d']);
    foreach ($q($is, 'SELECT ROUTINE_NAME n, ROUTINE_DEFINITION d FROM ROUTINES WHERE ROUTINE_SCHEMA = ? AND ' . sprintf(DHB_FILTRO_OBJ, 'ROUTINE_NAME'), [$b]) as $l) $out['rotina ' . strtolower($l['n'])] = $norm($l['d']);
    foreach ($q($is, 'SELECT TRIGGER_NAME n, ACTION_STATEMENT d FROM TRIGGERS WHERE TRIGGER_SCHEMA = ? AND (' . sprintf(DHB_FILTRO_OBJ, 'TRIGGER_NAME') . " OR LOWER(EVENT_OBJECT_TABLE) LIKE 'fo\\_%')", [$b]) as $l) $out['gatilho ' . strtolower($l['n'])] = $norm($l['d']);
    return $out;
};
$linhasFo = static function (string $b) use ($q, $is): array {
    $r = [];
    foreach ($q($is, "SELECT LOWER(TABLE_NAME) n, COALESCE(TABLE_ROWS, 0) r FROM TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'
        AND LOWER(TABLE_NAME) IN ('fo_fornecedores', 'fo_fornecedor', 'fo_fornecedorxcriterios', 'fo_funcionarios', 'fo_funcionarioxtiposxcriterios', 'fo_funcionarioxtiposxtreinamentos')", [$b]) as $l)
        $r[$l['n']] = (int)$l['r'];
    return $r;
};
$existe = static fn(string $b): bool => (bool)$q($is, 'SELECT 1 FROM SCHEMATA WHERE SCHEMA_NAME = ?', [$b]);

$rel = ['diagnostico' => 'homologacao-bancos', 'gerado_em' => date('c'), 'referencia' => DHB_REFERENCIA,
        'obs' => 'Linhas = estimativa atual do information_schema (TABLE_ROWS), só para ordem de grandeza.', 'empresas' => []];
try {
    $u = GreenPortalDatabase::connect($env, $universal, 'GUNIVERSAL');
    $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $empresas = $q($u, "SELECT codempresa, codigo, nome, status, bloqueada, banco_dados, ged, banco_legado, banco_novo, status_migracao, sincronismo_ativo
        FROM vw_empresa_migracao_grid ORDER BY codigo");
} catch (Throwable $e) {
    fwrite(STDERR, 'Não foi possível ler vw_empresa_migracao_grid: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$ref = $objetos(DHB_REFERENCIA);
$refDef = $definicoes(DHB_REFERENCIA);
$vistos = [];
$resumo = [];
foreach ($empresas as $e) {
    $banco = (string)($e['banco_novo'] ?: $e['banco_dados']);
    if ($banco === '' || $banco === $universal || !preg_match('/^g[a-z0-9_]+_dbp$/', $banco) || str_ends_with($banco, '_seguranca_dbp')) continue;
    if ($soHomologacao && HomologacaoBancos::legado($banco) === null) continue;
    if (!$e['banco_legado'] && HomologacaoBancos::legado($banco)) $e['banco_legado'] = HomologacaoBancos::legado($banco);
    $info = ['empresa' => $e['codigo'] . ' — ' . $e['nome'], 'status' => $e['status'] . (strtoupper((string)$e['bloqueada']) === 'S' ? ' (bloqueada)' : ''),
             'migracao' => $e['status_migracao'], 'sincronismo' => $e['sincronismo_ativo'], 'banco_legado' => $e['banco_legado']];
    if (!$existe($banco)) { $info['banco_novo'] = 'NÃO EXISTE'; $rel['empresas'][$banco] = $info; continue; }
    $vistos[$banco] = true;
    // Pasta do GED = raiz do GED legado + banco legado (o campo ged da empresa guarda só um rótulo)
    $pastaGed = $gedRaiz . DIRECTORY_SEPARATOR . (string)($e['banco_legado'] ?: preg_replace('/^g/', 'green_', preg_replace('/_dbp$/', '', $banco)));
    $info['ged'] = $pastaGed . (is_dir($pastaGed) ? ' — ' . max(0, count(@scandir($pastaGed) ?: []) - 2) . ' itens' : ' — NÃO ENCONTRADA');
    $info['linhas_novo'] = $linhasFo($banco);
    if ($e['banco_legado'] && $existe((string)$e['banco_legado'])) $info['linhas_legado'] = $linhasFo((string)$e['banco_legado']);
    elseif ($e['banco_legado']) $info['linhas_legado'] = 'banco legado não está neste servidor';
    $tem = $objetos($banco);
    $falta = [];
    foreach ($ref as $tipo => $lista) {
        $f = array_values(array_diff(array_map('strtolower', $lista), array_map('strtolower', $tem[$tipo])));
        if ($f) $falta[$tipo] = $detalhe ? $f : count($f);
    }
    $info['homologacao'] = $banco === DHB_REFERENCIA ? 'REFERÊNCIA' : (!$tem['tabelas'] ? 'SEM TABELAS fo_* (módulo não migrado)' : (!$falta ? 'COMPLETA (igual à referência)' : 'INCOMPLETA'));
    if ($falta && $banco !== DHB_REFERENCIA) $info['faltam_em_relacao_a_referencia'] = $falta;
    $diferentes = [];
    if ($banco !== DHB_REFERENCIA) {
        $def = $definicoes($banco);
        foreach ($refDef as $nome => $h) if (isset($def[$nome]) && $h !== null && $def[$nome] !== null && $def[$nome] !== $h) $diferentes[] = $nome;
        // --detalhe: o que só existe numa das definições (termos separados por espaço, vírgula e parênteses)
        if ($detalhe && $diferentes) {
            $termos = static fn(string $t): array => array_values(array_unique(array_filter(preg_split('/[\s,()]+/', str_replace('`', '', $t)) ?: [])));
            foreach ($diferentes as $nome) $info['o_que_difere'][$nome] = [
                'so_na_referencia' => array_slice(array_values(array_diff($termos($refDef[$nome]), $termos($def[$nome]))), 0, 40),
                'so_neste_banco' => array_slice(array_values(array_diff($termos($def[$nome]), $termos($refDef[$nome]))), 0, 40),
            ];
        }
        if ($diferentes) { $info['diferentes_da_referencia'] = $diferentes; if ($info['homologacao'] === 'COMPLETA (igual à referência)') $info['homologacao'] = 'COMPLETA, com definições diferentes da referência'; }
    }
    try {
        $pdoB = GreenPortalDatabase::connect($env, $banco, 'GPT');
        if ($q($is, "SELECT 1 FROM VIEWS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'vw_fo_regras_documentos'", [$banco])) {
            $r = $q($pdoB, 'SELECT COUNT(*) total, COALESCE(SUM(ATIVO = 1), 0) ligadas FROM vw_fo_regras_documentos')[0] ?? [];
            $info['regras_automaticas_301'] = (int)($r['ligadas'] ?? 0) . ' de ' . (int)($r['total'] ?? 0) . ' ligadas';
        }
    } catch (Throwable $x) { $info['regras_automaticas_301'] = 'não foi possível ler: ' . $x->getMessage(); }
    $resumo[$banco] = $banco === DHB_REFERENCIA ? 'referência' : (($falta ? 'PRECISA ATUALIZAR — faltam ' . json_encode(array_map(static fn($v) => is_array($v) ? count($v) : $v, $falta), JSON_UNESCAPED_UNICODE) : '')
        . ($diferentes ? ($falta ? '; ' : 'CONFERIR — ') . count($diferentes) . ' definições diferentes' : '') ?: 'em dia');
    $info['marcos'] = [
        '293 documentos' => in_array('vw_homologacao_fornecedor_documentos', array_map('strtolower', $tem['views']), true),
        '298 painel' => (bool)array_filter($tem['rotinas'], static fn($n) => stripos($n, 'painel') !== false),
        '300 protocolo' => in_array('sp_fo_protocolo_vencidos_processar', array_map('strtolower', $tem['rotinas']), true),
        '301 regras' => in_array('sp_fo_regras_documentos_aplicar', array_map('strtolower', $tem['rotinas']), true),
        '302 sem cobrança' => in_array('sp_fo_protocolo_sem_cobranca_converter', array_map('strtolower', $tem['rotinas']), true),
        '303 itens funcionário' => in_array('sp_fo_funcionario_itens_gerar', array_map('strtolower', $tem['rotinas']), true),
    ];
    $rel['empresas'][$banco] = $info;
}
$rel = ['resumo' => $resumo] + $rel;
// Bancos g*_dbp com tabelas fo_* que não aparecem como empresa migrada
$soltos = [];
foreach ($q($is, "SELECT DISTINCT TABLE_SCHEMA b FROM TABLES WHERE LOWER(TABLE_NAME) = 'fo_fornecedorxcriterios' AND TABLE_SCHEMA LIKE 'g%\\_dbp' ORDER BY 1") as $l)
    if (!isset($vistos[$l['b']]) && $l['b'] !== $universal) $soltos[] = $l['b'];
if ($soltos) $rel['bancos_com_fo_fora_do_cadastro_de_empresas'] = $soltos;

$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'diagnostico_homologacao_bancos.json', $json);
echo $json . PHP_EOL;
