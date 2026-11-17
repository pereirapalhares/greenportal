<?php
declare(strict_types=1);

/*
 * Teste (sem navegador) da transação homologacaofornecedores_criteriosfuncionarios — migração 245.
 * Para cada banco (gmodelo_dbp, gatlas_dbp e gmodelo2_dbp se existir):
 *   - objetos: views, procedures, gatilho, menu no cliente; menu e ajuda no guniversal_dbp;
 *   - leitura pela view do grid e pela view da lista Tipo de documento;
 *   - inclusão, alteração e exclusão de um critério de teste pelas procedures sp_crud_* (o mesmo caminho da tela);
 *   - exclusão de um critério em uso bloqueada pela regra do banco (mensagem 1644);
 *   - comparação origem x destino (sp_fo_criteriofuncionarios_comparar): nenhum critério faltando no destino.
 * O critério de teste é removido no fim. Nada mais é alterado.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\testar-homologacaofornecedores-criteriosfuncionarios.php
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$pares = [['green_modelo', 'gmodelo_dbp'], ['green_atlas', 'gatlas_dbp'], ['green_modelo', 'gmodelo2_dbp']];
$env = GreenPortalDatabase::env();
$universal = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
$universal->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$resultado = ['teste' => 'homologacaofornecedores_criteriosfuncionarios', 'ok' => true, 'universal' => [], 'bancos' => []];
$checar = static function (array &$lista, string $nome, bool $passou, string $detalhe = '') use (&$resultado): void {
    $lista[$nome] = ($passou ? 'OK' : 'FALHOU') . ($detalhe !== '' ? " — $detalhe" : '');
    if (!$passou) $resultado['ok'] = false;
};
$chamar = static function (PDO $pdo, string $sql, array $p = []): array {
    $s = $pdo->prepare($sql); $s->execute($p);
    $r = []; do { try { $r = array_merge($r, $s->fetchAll(PDO::FETCH_ASSOC)); } catch (Throwable) {} } while ($s->nextRowset());
    $s->closeCursor(); return $r;
};

$codmenu = (int)$universal->query("SELECT codmenu FROM menus WHERE codigo = 'HOMOLOGACAO_CRITERIOS_FUNCIONARIOS' AND ativo = 1")->fetchColumn();
$checar($resultado['universal'], 'menu universal ativo', $codmenu > 0, "codmenu $codmenu");
$checar($resultado['universal'], 'ajuda da tela', (int)$universal->query("SELECT COUNT(*) FROM ajuda_transacoes WHERE transacao = 'homologacaofornecedores_criteriosfuncionarios' AND ativo = 1")->fetchColumn() === 1);

foreach ($pares as [$origem, $banco]) {
    $r = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
    } catch (Throwable $e) {
        if ($banco === 'gmodelo2_dbp') continue;
        $checar($r, 'conexão', false, $e->getMessage()); $resultado['bancos'][$banco] = $r; continue;
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
    try {
        $objetos = $pdo->query("SELECT CONCAT(ROUTINE_TYPE, ' ', ROUTINE_NAME) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME LIKE 'sp\\_fo\\_criteriofuncionario%'
            UNION ALL SELECT CONCAT('VIEW ', TABLE_NAME) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'vw\\_homologacao\\_criterios\\_funcionarios%'
            UNION ALL SELECT CONCAT('TRIGGER ', TRIGGER_NAME) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'trg_fo_criteriofuncionarios_antes_excluir'")->fetchAll(PDO::FETCH_COLUMN);
        $checar($r, 'objetos da migração 245 (3 procedures, 2 views, 1 gatilho)', count($objetos) === 6, implode(', ', $objetos));
        $checar($r, 'menu no cliente (Permissões)', (int)$pdo->query("SELECT COUNT(*) FROM menus WHERE codigo = 'HOMOLOGACAO_CRITERIOS_FUNCIONARIOS' AND ativo = 1")->fetchColumn() === 1);

        $grid = $chamar($pdo, 'CALL sp_crud_select(?, ?, NULL, 1000, 0)', ['vw_homologacao_criterios_funcionarios_grid', '{}']);
        $checar($r, 'leitura do grid pela procedure', count($grid) > 0, count($grid) . ' critérios');
        $tipos = array_column($chamar($pdo, 'CALL sp_crud_select(?, ?, NULL, 100, 0)', ['vw_homologacao_criterios_funcionarios_tipos_documento', '{}']), 'TIPO_DE_DOCUMENTO');
        $checar($r, 'lista Tipo de documento', in_array('Competência', $tipos, true) && in_array('Regularização de Ordem Legal', $tipos, true), implode(' | ', $tipos));

        // CRUD pelo mesmo caminho da tela
        $marca = 'TESTE 245 ' . date('YmdHis');
        $chamar($pdo, 'CALL sp_crud_insert(?, ?)', ['fo_criteriofuncionarios', json_encode(['DSCRITERIO' => $marca, 'TIPO_DE_DOCUMENTO' => 'Competência'], JSON_UNESCAPED_UNICODE)]);
        $novo = $chamar($pdo, 'CALL sp_crud_select(?, ?, NULL, 5, 0)', ['vw_homologacao_criterios_funcionarios_grid', json_encode(['DSCRITERIO' => $marca], JSON_UNESCAPED_UNICODE)]);
        $cod = (int)($novo[0]['CODCRITERIOFUNCIONARIO'] ?? 0);
        $checar($r, 'inclusão (sp_crud_insert)', $cod > 0, "código $cod" . ($cod >= 100000 ? ' (faixa do sistema novo)' : ' (ATENÇÃO: abaixo de 100000)'));
        if ($cod > 0) {
            $chamar($pdo, 'CALL sp_crud_update(?, ?, ?)', ['fo_criteriofuncionarios', json_encode(['CODCRITERIOFUNCIONARIO' => $cod]), json_encode(['TIPO_DE_DOCUMENTO' => 'Regularização de Ordem Legal'], JSON_UNESCAPED_UNICODE)]);
            $alt = $chamar($pdo, 'CALL sp_crud_select(?, ?, NULL, 5, 0)', ['vw_homologacao_criterios_funcionarios_grid', json_encode(['CODCRITERIOFUNCIONARIO' => $cod])]);
            $checar($r, 'alteração (sp_crud_update)', ($alt[0]['TIPO_DE_DOCUMENTO'] ?? '') === 'Regularização de Ordem Legal');
            $chamar($pdo, 'CALL sp_crud_delete(?, ?)', ['fo_criteriofuncionarios', json_encode(['CODCRITERIOFUNCIONARIO' => $cod])]);
            $fim = $chamar($pdo, 'CALL sp_crud_select(?, ?, NULL, 5, 0)', ['vw_homologacao_criterios_funcionarios_grid', json_encode(['CODCRITERIOFUNCIONARIO' => $cod])]);
            $checar($r, 'exclusão de critério sem uso (sp_crud_delete)', count($fim) === 0);
        }

        // Exclusão de critério em uso deve ser bloqueada (nada é excluído)
        $emUso = array_values(array_filter($grid, static fn($g) => (int)($g['QTD_EM_USO'] ?? 0) > 0));
        if ($emUso) {
            $codUso = (int)$emUso[0]['CODCRITERIOFUNCIONARIO'];
            try {
                $chamar($pdo, 'CALL sp_crud_delete(?, ?)', ['fo_criteriofuncionarios', json_encode(['CODCRITERIOFUNCIONARIO' => $codUso])]);
                $checar($r, 'exclusão de critério em uso bloqueada', false, "o critério $codUso FOI EXCLUÍDO");
            } catch (PDOException $e) {
                $checar($r, 'exclusão de critério em uso bloqueada', str_contains($e->getMessage(), '1644'), preg_replace('/^.*1644\s*/s', '', $e->getMessage()));
            }
        } else {
            $r['exclusão de critério em uso bloqueada'] = 'NÃO TESTADO — nenhum critério em uso neste banco';
        }

        $dif = $chamar($pdo, 'CALL sp_fo_criteriofuncionarios_comparar(?)', [$origem]);
        $resumo = array_count_values(array_column($dif, 'situacao'));
        $checar($r, "comparação $origem x $banco", empty($resumo['SO_NA_ORIGEM']), json_encode($resumo ?: ['iguais' => true]));
    } catch (Throwable $e) {
        $checar($r, 'execução', false, $e->getMessage());
    }
    $resultado['bancos'][$banco] = $r;
}

echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($resultado['ok'] ? 0 : 1);
