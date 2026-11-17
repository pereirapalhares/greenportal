<?php
declare(strict_types=1);

/*
 * Diagnóstico (somente leitura) para a tela Portaria › Documentação de Fornecedor (legado foFornecedorxCriterios,
 * hpr_configuracoes codtransacao 194). Em cada cliente mostra:
 *  - as tabelas usadas pela consulta do legado (existem? quantos registros?), novo x legado;
 *  - os valores de SITUACAO (situação do documento), ENVIADOODOCUMENTO, EMPROTOCOLO e NIVEL_DE_EXIGENCIA com contagem;
 *  - quantos registros a consulta do legado traz;
 *  - de onde vem a unidade do usuário: tabelas *usuario*unidade* no banco do cliente e no _seguranca do legado;
 *  - a pasta do GED dos anexos (fo_fornecedorxcriterios): existe? quantas subpastas/arquivos?
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-portaria-documentacao-fornecedor.php
 * Saída: tela + storage/logs/diagnostico_portaria_documentacao_fornecedor.json
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$pares = [['green_atlas', 'gatlas_dbp'], ['green_modelo', 'gmodelo_dbp']];
$tabelas = ['fo_fornecedorxcriterios', 'fo_fornecedores', 'fo_tipofornecedores', 'fo_criteriofornecedores', 'fo_tipofornecedoresxcriteriofornecedores', 'fo_fornecedoresxtipofornecedores', 'unidades'];
$env = GreenPortalDatabase::env();
$saida = ['diagnostico' => 'portaria documentação de fornecedor', 'data' => date('c'), 'bancos' => []];

foreach ($pares as [$legado, $banco]) {
    $r = ['legado' => $legado];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $q = static fn(string $sql, array $p = []): array => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC); })();
        $existe = static fn(string $b, string $t): bool => (bool)$q('SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$b, $t])[0]['n'];
        foreach ($tabelas as $t) {
            $r['tabelas'][$t] = [
                'novo' => $existe($banco, $t) ? (int)$q("SELECT COUNT(*) n FROM `$banco`.`$t`")[0]['n'] : 'NÃO EXISTE',
                'legado' => $existe($legado, $t) ? (int)$q("SELECT COUNT(*) n FROM `$legado`.`$t`")[0]['n'] : 'NÃO EXISTE',
            ];
        }
        if ($existe($banco, 'fo_fornecedorxcriterios')) {
            $cols = array_column($q("SELECT COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'fo_fornecedorxcriterios' ORDER BY ORDINAL_POSITION", [$banco]), 'c');
            $r['colunas_fo_fornecedorxcriterios'] = $cols;
            foreach (['SITUACAO', 'ENVIADOODOCUMENTO', 'EMPROTOCOLO', 'NIVEL_DE_EXIGENCIA'] as $c) {
                if (!in_array(strtolower($c), array_map('strtolower', $cols), true)) { $r['valores'][$c] = 'coluna não existe'; continue; }
                $r['valores'][$c] = array_map(static fn($x) => ($x['v'] ?? '(vazio)') . ' = ' . $x['n'], $q("SELECT `$c` v, COUNT(*) n FROM `$banco`.fo_fornecedorxcriterios GROUP BY `$c` ORDER BY n DESC LIMIT 30"));
            }
            // Mesma junção da consulta do legado (sem a restrição de unidade)
            $r['linhas_da_consulta_do_legado'] = (int)$q("SELECT COUNT(*) n FROM `$banco`.fo_fornecedorxcriterios fc
                JOIN `$banco`.fo_fornecedores f ON f.codfornecedor = fc.codfornecedor
                JOIN `$banco`.unidades u ON u.codunidade = f.codunidade
                JOIN `$banco`.fo_fornecedoresxtipofornecedores ft ON ft.codfornecedor = f.codfornecedor AND ft.codtipofornecedor = fc.codtipo
                JOIN `$banco`.fo_tipofornecedoresxcriteriofornecedores tc ON tc.codtipofornecedor = fc.codtipo AND tc.codcriteriofornecedor = fc.codcriterio
                JOIN `$banco`.fo_tipofornecedores t ON t.codtipofornecedor = tc.codtipofornecedor
                JOIN `$banco`.fo_criteriofornecedores c ON c.codcriteriofornecedor = tc.codcriteriofornecedor")[0]['n'];
            $r['situacao_x_dias_para_vencer'] = $q("SELECT COALESCE(SITUACAO, '(vazio)') situacao,
                SUM(DATEDIFF(DATADEVALIDADE, NOW()) < 0) vencidos, SUM(DATEDIFF(DATADEVALIDADE, NOW()) BETWEEN 0 AND 30) vence_30_dias,
                SUM(DATEDIFF(DATADEVALIDADE, NOW()) > 30) mais_de_30, SUM(DATADEVALIDADE IS NULL) sem_validade
                FROM `$banco`.fo_fornecedorxcriterios GROUP BY situacao ORDER BY 1");
        }
        // Unidade do usuário
        $r['tabelas_usuario_unidade'] = array_map(static fn($x) => $x['s'] . '.' . $x['t'] . ' (' . $x['n'] . ' linhas aprox.)', $q("SELECT TABLE_SCHEMA s, TABLE_NAME t, TABLE_ROWS n FROM information_schema.TABLES
            WHERE TABLE_SCHEMA IN (?, ?, ?) AND TABLE_NAME LIKE '%usuario%' AND TABLE_NAME LIKE '%unidade%'", [$banco, $legado . '_seguranca', $legado]));
        $r['colunas_unidade_em_usuarios'] = array_map(static fn($x) => $x['s'] . '.usuarios.' . $x['c'], $q("SELECT TABLE_SCHEMA s, COLUMN_NAME c FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA IN (?, ?) AND TABLE_NAME = 'usuarios' AND COLUMN_NAME LIKE '%unidade%'", [$banco, $legado . '_seguranca']));
    } catch (Throwable $e) { $r['erro'] = $e->getMessage(); }
    // GED
    foreach (['E:\\projetoGreen\\GED\\' . $legado . '\\fo_fornecedorxcriterios', 'E:\\projetoGreen\\GED\\' . $legado . '\\fo_fornecedoresxcriterios'] as $dir) {
        if (!is_dir($dir)) { $r['ged'][$dir] = 'não existe'; continue; }
        $sub = glob($dir . '\\*', GLOB_ONLYDIR) ?: [];
        $arquivos = 0; foreach (array_slice($sub, 0, 3000) as $s) $arquivos += count(glob($s . '\\*') ?: []);
        $r['ged'][$dir] = count($sub) . ' pastas, ' . $arquivos . ' itens; exemplo: ' . implode(', ', array_map('basename', array_slice($sub, 0, 5)));
    }
    $saida['bancos'][$banco] = $r;
}
$json = json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@file_put_contents(dirname(__DIR__) . '/storage/logs/diagnostico_portaria_documentacao_fornecedor.json', $json);
echo $json . PHP_EOL;
