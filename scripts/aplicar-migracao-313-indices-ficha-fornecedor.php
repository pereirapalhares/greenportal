<?php
declare(strict_types=1);

/*
 * Migração 313 — índices por fornecedor para a Ficha do fornecedor e o Painel de Homologação (19/10/2026).
 * A ficha (principal/homologacaofornecedores_ficha.php) lê, de um fornecedor, os documentos, funcionários, treinamentos,
 * equipamentos, químicos, plano de ação e o histórico de documentos (na Crown ~1 milhão de linhas). Sem índice pela coluna
 * do fornecedor, cada leitura percorre a tabela inteira. Cria o índice só onde não existe nenhum que comece por essa coluna.
 * Só DDL de índice (não mexe em dados). Pode ser executada mais de uma vez. Tabelas grandes podem levar alguns segundos.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-313-indices-ficha-fornecedor.php [--simular] [--banco=gcrown_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$simular = in_array('--simular', $argv ?? [], true);
$soBanco = null;
foreach ($argv ?? [] as $a) if (str_starts_with($a, '--banco=')) $soBanco = array_filter(array_map('trim', explode(',', substr($a, 8))));
$env = GreenPortalDatabase::env();
$is = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$is->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$bancos = $is->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME = 'fo_fornecedores' AND TABLE_TYPE = 'BASE TABLE' AND TABLE_SCHEMA LIKE 'g%\\_dbp' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
if ($soBanco) $bancos = array_values(array_intersect($bancos, $soBanco));

// tabela => [colunas do índice], a primeira é a do fornecedor (ou do funcionário).
$desejados = [
    'fo_fornecedorxcriterios_historico' => ['codfornecedor', 'DATAALTERACAO', 'CODHISTORICO'],
    'fo_fornecedorxcriterios' => ['codfornecedor'],
    'fo_fornecedoresxtipofornecedores' => ['codfornecedor'],
    'fo_funcionarios' => ['CODFORNECEDOR'],
    'fo_funcionarioxtiposxcriterios' => ['codfuncionario'],
    'fo_funcionarioxtiposxtreinamentos' => ['codfuncionario'],
    'fo_equipamentos' => ['codfornecedor'],
    'fo_produtoquimico' => ['codfornecedor'],
    'fo_planodeacao' => ['codfornecedor'],
    'fo_fornecedorxusuario' => ['CODFORNECEDOR'],
];
$colunas = $is->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
$temIndice = $is->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND SEQ_IN_INDEX = 1 AND LOWER(COLUMN_NAME) = LOWER(?)');

$rel = ['ok' => true, 'migracao' => '313', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
foreach ($bancos as $banco) {
    $info = [];
    $pdo = null;
    foreach ($desejados as $tabela => $cols) {
        $colunas->execute([$banco, $tabela]);
        $existentes = [];
        foreach ($colunas->fetchAll(PDO::FETCH_COLUMN) as $c) $existentes[strtolower((string)$c)] = (string)$c;
        if (!$existentes) { $info[$tabela] = 'tabela não existe'; continue; }
        $usar = [];
        foreach ($cols as $c) if (isset($existentes[strtolower($c)])) $usar[] = $existentes[strtolower($c)];
        if (!$usar || strtolower($usar[0]) !== strtolower($cols[0])) { $info[$tabela] = 'coluna ' . $cols[0] . ' não existe'; continue; }
        $temIndice->execute([$banco, $tabela, $usar[0]]);
        if ((int)$temIndice->fetchColumn() > 0) { $info[$tabela] = 'já tem índice por ' . $usar[0]; continue; }
        $nome = 'ix_gp313_' . substr(strtolower(preg_replace('/[^a-z0-9]/i', '', $tabela)), 0, 40);
        $sql = 'ALTER TABLE `' . $tabela . '` ADD INDEX `' . $nome . '` (' . implode(', ', array_map(static fn($c) => '`' . $c . '`', $usar)) . ')';
        if ($simular) { $info[$tabela] = 'será criado: ' . $nome . ' (' . implode(', ', $usar) . ')'; continue; }
        try {
            $pdo ??= GreenPortalDatabase::connect($env, (string)$banco, 'GPT');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $t0 = microtime(true);
            $pdo->exec($sql);
            $info[$tabela] = 'criado ' . $nome . ' (' . implode(', ', $usar) . ') em ' . round(microtime(true) - $t0, 1) . ' s';
        } catch (Throwable $e) { $rel['ok'] = false; $info[$tabela] = 'ERRO: ' . $e->getMessage(); }
    }
    $rel['bancos'][$banco] = $info;
}
if (!$bancos) $rel['bancos'] = 'nenhum banco com as tabelas da homologação';
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
