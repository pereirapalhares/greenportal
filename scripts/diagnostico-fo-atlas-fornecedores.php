<?php
declare(strict_types=1);

/*
 * Diagnóstico SOMENTE LEITURA: compara funcionários por fornecedor entre o legado do Atlas
 * (green_atlas / green_atlas_seguranca) e o novo banco (gatlas_dbp). Não grava nada.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-fo-atlas-fornecedores.php [codfornecedor] > diagnostico-atlas.json
 */

require_once __DIR__ . '/../classes/GreenPortalDatabase.php';

$foco = (int)($argv[1] ?? 626);
$env = GreenPortalDatabase::env();
$pdo = new PDO('mysql:host=' . ($env['GPT_DB_HOST'] ?? '127.0.0.1') . ';port=' . ($env['GPT_DB_PORT'] ?? '3306') . ';charset=utf8mb4', $env['GPT_DB_USERNAME'] ?? '', $env['GPT_DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
$q = static function (string $sql, array $p = []) use ($pdo): array { try { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); } catch (Throwable $e) { return ['ERRO' => $e->getMessage()]; } };
$out = ['fornecedor_foco' => $foco];

// 1) Fornecedor em foco: cadastro e funcionários nos dois bancos (todas as colunas).
foreach (['legado' => 'green_atlas', 'novo' => 'gatlas_dbp'] as $nome => $db) {
    $out['fornecedor'][$nome] = $q("SELECT * FROM `$db`.fo_fornecedores WHERE codfornecedor = ?", [$foco]);
    $out['funcionarios'][$nome] = $q("SELECT * FROM `$db`.fo_funcionarios WHERE codfornecedor = ? ORDER BY CODFUNCIONARIO", [$foco]);
    $out['colunas_fo_funcionarios'][$nome] = array_column($q("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'fo_funcionarios' ORDER BY ORDINAL_POSITION", [$db]), 'COLUMN_NAME');
}

// 2) Outras tabelas que ligam funcionário a fornecedor (no legado e no novo).
foreach (['green_atlas', 'green_atlas_seguranca', 'gatlas_dbp'] as $db) {
    $out['tabelas_com_codfuncionario'][$db] = $q("SELECT c.TABLE_NAME, GROUP_CONCAT(c.COLUMN_NAME ORDER BY c.ORDINAL_POSITION) colunas, t.TABLE_ROWS
        FROM information_schema.COLUMNS c JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
        WHERE c.TABLE_SCHEMA = ? AND c.TABLE_NAME IN (SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND LOWER(COLUMN_NAME) = 'codfuncionario')
        GROUP BY c.TABLE_NAME, t.TABLE_ROWS ORDER BY c.TABLE_NAME", [$db, $db]);
}
$out['tabelas_seguranca_fornecedor'] = $q("SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'green_atlas_seguranca' AND (TABLE_NAME LIKE '%forn%' OR TABLE_NAME LIKE '%func%' OR TABLE_NAME LIKE '%unid%')");

// 3) Contagem de funcionários por fornecedor: legado x novo (todas as linhas e diferenças).
$leg = []; foreach ($q('SELECT codfornecedor c, COUNT(*) n FROM green_atlas.fo_funcionarios GROUP BY codfornecedor') as $r) $leg[(int)$r['c']] = (int)$r['n'];
$nov = []; foreach ($q('SELECT codfornecedor c, COUNT(*) n FROM gatlas_dbp.fo_funcionarios GROUP BY codfornecedor') as $r) $nov[(int)$r['c']] = (int)$r['n'];
$dif = [];
foreach (array_unique(array_merge(array_keys($leg), array_keys($nov))) as $c) if (($leg[$c] ?? 0) !== ($nov[$c] ?? 0)) $dif[$c] = ['legado' => $leg[$c] ?? 0, 'novo' => $nov[$c] ?? 0];
ksort($dif);
$out['contagem_total'] = ['legado' => array_sum($leg), 'novo' => array_sum($nov)];
$out['fornecedores_com_contagem_diferente'] = $dif;

// 4) Funcionários que existem só de um lado, ou com fornecedor diferente.
$out['funcionarios_so_no_legado'] = $q('SELECT s.CODFUNCIONARIO, s.NOME, s.codfornecedor FROM green_atlas.fo_funcionarios s WHERE NOT EXISTS (SELECT 1 FROM gatlas_dbp.fo_funcionarios d WHERE d.CODFUNCIONARIO = s.CODFUNCIONARIO) LIMIT 100');
$out['funcionarios_so_no_novo'] = $q('SELECT d.CODFUNCIONARIO, d.NOME, d.codfornecedor FROM gatlas_dbp.fo_funcionarios d WHERE NOT EXISTS (SELECT 1 FROM green_atlas.fo_funcionarios s WHERE s.CODFUNCIONARIO = d.CODFUNCIONARIO) LIMIT 100');
$out['funcionarios_fornecedor_diferente'] = $q('SELECT s.CODFUNCIONARIO, s.NOME, s.codfornecedor legado, d.codfornecedor novo FROM green_atlas.fo_funcionarios s JOIN gatlas_dbp.fo_funcionarios d ON d.CODFUNCIONARIO = s.CODFUNCIONARIO WHERE NOT (s.codfornecedor <=> d.codfornecedor) LIMIT 100');

// 5) Fornecedores: só de um lado, ou com unidade / razão social diferentes.
$out['fornecedores_so_no_legado'] = $q('SELECT s.codfornecedor, s.razaosocial, s.codunidade FROM green_atlas.fo_fornecedores s WHERE NOT EXISTS (SELECT 1 FROM gatlas_dbp.fo_fornecedores d WHERE d.codfornecedor = s.codfornecedor)');
$out['fornecedores_so_no_novo'] = $q('SELECT d.codfornecedor, d.razaosocial, d.codunidade FROM gatlas_dbp.fo_fornecedores d WHERE NOT EXISTS (SELECT 1 FROM green_atlas.fo_fornecedores s WHERE s.codfornecedor = d.codfornecedor)');
$out['fornecedores_dados_diferentes'] = $q('SELECT s.codfornecedor, s.razaosocial legado_razao, d.razaosocial novo_razao, s.codunidade legado_unidade, d.codunidade novo_unidade, s.ativadofor legado_ativo, d.ativadofor novo_ativo FROM green_atlas.fo_fornecedores s JOIN gatlas_dbp.fo_fornecedores d ON d.codfornecedor = s.codfornecedor WHERE NOT (s.razaosocial <=> d.razaosocial) OR NOT (s.codunidade <=> d.codunidade) OR NOT (s.ativadofor <=> d.ativadofor)');

// 6) Mesmo nome de funcionário repetido no mesmo fornecedor (possível duplicidade).
$out['nomes_repetidos_no_fornecedor_foco'] = $q('SELECT NOME, COUNT(*) n, GROUP_CONCAT(CODFUNCIONARIO) codigos FROM gatlas_dbp.fo_funcionarios WHERE codfornecedor = ? GROUP BY NOME HAVING COUNT(*) > 1', [$foco]);

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
