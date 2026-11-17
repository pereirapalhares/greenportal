<?php
declare(strict_types=1);

/*
 * Diagnóstico (somente leitura) — tempo de abertura da Ficha do fornecedor (principal/homologacaofornecedores_ficha.php).
 * Mede cada leitura da ficha no banco da empresa (duas vezes: a primeira com o cache frio), compara com o jeito antigo
 * (1.000 linhas por chamada) e lista os índices por fornecedor das tabelas que a ficha lê (a migração 313 cria os que faltam).
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-ficha-fornecedor.php --banco=gcrown_dbp [--fornecedor=N]
 *      sem --fornecedor usa o fornecedor ativo com mais documentos.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$banco = 'gcrown_dbp'; $cod = 0;
foreach ($argv ?? [] as $a) {
    if (str_starts_with($a, '--banco=')) $banco = preg_replace('/[^A-Za-z0-9_]/', '', substr($a, 8));
    if (str_starts_with($a, '--fornecedor=')) $cod = (int)substr($a, 13);
}
$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$rel = ['banco' => $banco, 'gerado_em' => date('c')];
if ($cod <= 0) {
    $s = $pdo->query("SELECT x.codfornecedor, COUNT(*) q FROM fo_fornecedorxcriterios x JOIN fo_fornecedores f ON f.codfornecedor = x.codfornecedor AND f.ATIVADOFOR = 'Sim' GROUP BY x.codfornecedor ORDER BY q DESC LIMIT 1");
    $cod = (int)($s->fetchColumn() ?: 0);
}
$rel['fornecedor'] = $cod;
$tempo = static function (callable $fn) use ($pdo): array {
    $r = [];
    foreach (['frio', 'quente'] as $vez) {
        $t0 = microtime(true);
        try { $n = $fn(); $r[$vez] = round((microtime(true) - $t0) * 1000, 1) . ' ms (' . $n . ' linha(s))'; }
        catch (Throwable $e) { $r[$vez] = 'ERRO: ' . mb_substr($e->getMessage(), 0, 200); }
    }
    return $r;
};
$crud = static function (string $view, array $filtros, int $limite, bool $exato = true) use ($pdo): int {
    $f = [];
    foreach ($filtros as $k => $v) $f[$k] = $exato ? ['value' => (string)$v, 'exact' => true] : $v;
    $s = $pdo->prepare('CALL sp_crud_select(?, ?, NULL, ?, 0)');
    $s->execute([$view, json_encode($f, JSON_UNESCAPED_UNICODE), $limite]);
    $n = count($s->fetchAll(PDO::FETCH_ASSOC)); $s->closeCursor();
    return $n;
};
$itens = static function (?int $fornecedor) use ($pdo): int {
    $s = $pdo->prepare("CALL sp_fo_painel_itens(0, 0, NULL, NULL, '', '', '', ?)");
    $s->execute([$fornecedor]);
    $n = count($s->fetchAll(PDO::FETCH_ASSOC));
    while ($s->nextRowset()) { }
    $s->closeCursor();
    return $n;
};
$rel['ficha_nova'] = [
    'fornecedor (1 linha)' => $tempo(fn() => $crud('vw_homologacao_fornecedores_grid', ['CODFORNECEDOR' => $cod], 1)),
    'itens do fornecedor (sp_fo_painel_itens)' => $tempo(fn() => $itens($cod)),
    'historico (6 linhas)' => $tempo(fn() => $crud('vw_homologacao_fornecedor_documentos_historico', ['CODFORNECEDOR' => $cod], 6)),
    'usuarios do fornecedor' => $tempo(fn() => $crud('vw_homologacao_fornecedor_usuarios', ['CODFORNECEDOR' => $cod], 200)),
];
$rel['ficha_antiga_para_comparar'] = [
    'fornecedor (1.000 linhas por chamada)' => $tempo(fn() => $crud('vw_homologacao_fornecedores_grid', ['CODFORNECEDOR' => $cod], 1000, false)),
    'historico (1.000 linhas por chamada)' => $tempo(fn() => $crud('vw_homologacao_fornecedor_documentos_historico', ['CODFORNECEDOR' => $cod], 1000, false)),
    'checagem de procedures do painel' => $tempo(fn() => (int)$pdo->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'sp_fo_painel_itens'")->fetchColumn()),
];
$rel['painel_inteiro_sem_filtro'] = $tempo(fn() => $itens(null));
// Índices por fornecedor (primeira coluna do índice).
$indices = [
    'fo_fornecedorxcriterios_historico' => 'codfornecedor', 'fo_fornecedorxcriterios' => 'codfornecedor', 'fo_fornecedoresxtipofornecedores' => 'codfornecedor',
    'fo_funcionarios' => 'CODFORNECEDOR', 'fo_funcionarioxtiposxcriterios' => 'codfuncionario', 'fo_funcionarioxtiposxtreinamentos' => 'codfuncionario',
    'fo_equipamentos' => 'codfornecedor', 'fo_produtoquimico' => 'codfornecedor', 'fo_planodeacao' => 'codfornecedor', 'fo_fornecedorxusuario' => 'CODFORNECEDOR',
];
$s = $pdo->prepare('SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
$i = $pdo->prepare('SELECT GROUP_CONCAT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND SEQ_IN_INDEX = 1 AND LOWER(COLUMN_NAME) = LOWER(?)');
foreach ($indices as $tabela => $coluna) {
    $s->execute([$tabela]); $linhas = $s->fetchColumn();
    if ($linhas === false) { $rel['indices'][$tabela] = 'tabela não existe'; continue; }
    $i->execute([$tabela, $coluna]); $nome = $i->fetchColumn();
    $rel['indices'][$tabela] = '~' . (int)$linhas . ' linhas; ' . $coluna . ': ' . ($nome ? 'índice ' . $nome : 'SEM ÍNDICE');
}
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
