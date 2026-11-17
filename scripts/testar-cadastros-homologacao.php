<?php
declare(strict_types=1);

/*
 * Teste (sem navegador) dos cadastros simples de Homologação de Fornecedores — migração 248
 * (lista em database/248_cadastros_homologacao.php; ex.: homologacaofornecedores_tipostreinamentos).
 * Em cada banco: objetos (view, 3 procedures, gatilho), menu do cliente; no universal: menu e ajuda;
 * leitura pela view via sp_crud_select; inclusão/alteração/exclusão pelas procedures sp_crud_* (o caminho da tela);
 * exclusão de registro em uso bloqueada (1644); comparação origem x destino. O registro de teste é removido.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\testar-cadastros-homologacao.php [--cadastro=fo_tipotreinamentos]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$pares = [['green_modelo', 'gmodelo_dbp'], ['green_atlas', 'gatlas_dbp'], ['green_modelo', 'gmodelo2_dbp']];
$somente = '';
foreach ($argv ?? [] as $a) if (str_starts_with($a, '--cadastro=')) $somente = strtolower(trim(substr($a, 11)));
$cadastros = require dirname(__DIR__) . '/database/248_cadastros_homologacao.php';
if ($somente !== '') $cadastros = array_intersect_key($cadastros, [$somente => true]);

$env = GreenPortalDatabase::env();
$universal = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
$resultado = ['teste' => 'cadastros de homologação (248)', 'ok' => true, 'cadastros' => []];
$checar = static function (array &$lista, string $nome, bool $passou, string $detalhe = '') use (&$resultado): void {
    $lista[$nome] = ($passou ? 'OK' : 'FALHOU') . ($detalhe !== '' ? " — $detalhe" : '');
    if (!$passou) $resultado['ok'] = false;
};
$chamar = static function (PDO $pdo, string $sql, array $p = []): array {
    $s = $pdo->prepare($sql); $s->execute($p);
    $r = []; do { try { $r = array_merge($r, $s->fetchAll(PDO::FETCH_ASSOC)); } catch (Throwable) {} } while ($s->nextRowset());
    $s->closeCursor(); return $r;
};

foreach ($cadastros as $tabela => $cfg) {
    $pk = $cfg['pk']; $dsc = $cfg['dsc']; $view = $cfg['view']; $m = $cfg['menu'];
    $rc = ['universal' => [], 'bancos' => []];
    $q = $universal->prepare('SELECT codmenu FROM menus WHERE codigo = ? AND ativo = 1'); $q->execute([$m['codigo']]);
    $codmenu = (int)$q->fetchColumn();
    $checar($rc['universal'], 'menu universal ativo', $codmenu > 0, "codmenu $codmenu");
    $q = $universal->prepare('SELECT COUNT(*) FROM ajuda_transacoes WHERE transacao = ? AND ativo = 1'); $q->execute([$m['transacao']]);
    $checar($rc['universal'], 'ajuda da tela', (int)$q->fetchColumn() === 1);

    foreach ($pares as [$origem, $banco]) {
        $r = [];
        try { $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT'); }
        catch (Throwable $e) { if ($banco === 'gmodelo2_dbp') continue; $checar($r, 'conexão', false, $e->getMessage()); $rc['bancos'][$banco] = $r; continue; }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        try {
            $o = $pdo->prepare("SELECT CONCAT(ROUTINE_TYPE, ' ', ROUTINE_NAME) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME IN (?, ?, ?)
                UNION ALL SELECT CONCAT('VIEW ', TABLE_NAME) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                UNION ALL SELECT CONCAT('TRIGGER ', TRIGGER_NAME) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?");
            $o->execute(["sp_{$tabela}_validar_exclusao", "sp_{$tabela}_comparar", "sp_{$tabela}_migrar", $view, "trg_{$tabela}_antes_excluir"]);
            $objetos = $o->fetchAll(PDO::FETCH_COLUMN);
            $checar($r, 'objetos (3 procedures, 1 view, 1 gatilho)', count($objetos) === 5, implode(', ', $objetos));
            $q = $pdo->prepare('SELECT COUNT(*) FROM menus WHERE codigo = ? AND ativo = 1'); $q->execute([$m['codigo']]);
            $checar($r, 'menu no cliente (Permissões)', (int)$q->fetchColumn() === 1);

            $grid = $chamar($pdo, 'CALL sp_crud_select(?, ?, NULL, 1000, 0)', [$view, '{}']);
            $checar($r, 'leitura do grid pela procedure', count($grid) > 0, count($grid) . ' registros');

            $marca = 'TESTE 248 ' . date('YmdHis');
            $chamar($pdo, 'CALL sp_crud_insert(?, ?)', [$tabela, json_encode([$dsc => $marca], JSON_UNESCAPED_UNICODE)]);
            $novo = $chamar($pdo, 'CALL sp_crud_select(?, ?, NULL, 5, 0)', [$view, json_encode([$dsc => $marca], JSON_UNESCAPED_UNICODE)]);
            $cod = (int)($novo[0][$pk] ?? 0);
            $checar($r, 'inclusão (sp_crud_insert)', $cod > 0, "código $cod" . ($cod >= 100000 ? ' (faixa do sistema novo)' : ' (ATENÇÃO: abaixo de 100000)'));
            if ($cod > 0) {
                $chamar($pdo, 'CALL sp_crud_update(?, ?, ?)', [$tabela, json_encode([$pk => $cod]), json_encode([$dsc => $marca . ' alterado'], JSON_UNESCAPED_UNICODE)]);
                $alt = $chamar($pdo, 'CALL sp_crud_select(?, ?, NULL, 5, 0)', [$view, json_encode([$pk => $cod])]);
                $checar($r, 'alteração (sp_crud_update)', ($alt[0][$dsc] ?? '') === $marca . ' alterado');
                $chamar($pdo, 'CALL sp_crud_delete(?, ?)', [$tabela, json_encode([$pk => $cod])]);
                $fim = $chamar($pdo, 'CALL sp_crud_select(?, ?, NULL, 5, 0)', [$view, json_encode([$pk => $cod])]);
                $checar($r, 'exclusão sem uso (sp_crud_delete)', count($fim) === 0);
            }
            $emUso = array_values(array_filter($grid, static fn($g) => (int)($g['QTD_EM_USO'] ?? 0) > 0));
            if ($emUso) {
                $codUso = (int)$emUso[0][$pk];
                try {
                    $chamar($pdo, 'CALL sp_crud_delete(?, ?)', [$tabela, json_encode([$pk => $codUso])]);
                    $checar($r, 'exclusão de registro em uso bloqueada', false, "o registro $codUso FOI EXCLUÍDO");
                } catch (PDOException $e) {
                    $checar($r, 'exclusão de registro em uso bloqueada', str_contains($e->getMessage(), '1644'), preg_replace('/^.*1644\s*/s', '', $e->getMessage()));
                }
            } else {
                $r['exclusão de registro em uso bloqueada'] = 'NÃO TESTADO — nenhum registro em uso neste banco';
            }
            $dif = $chamar($pdo, "CALL sp_{$tabela}_comparar(?)", [$origem]);
            $resumo = array_count_values(array_column($dif, 'situacao'));
            $checar($r, "comparação $origem x $banco", empty($resumo['SO_NA_ORIGEM']), json_encode($resumo ?: ['iguais' => true]));
        } catch (Throwable $e) {
            $checar($r, 'execução', false, $e->getMessage());
        }
        $rc['bancos'][$banco] = $r;
    }
    $resultado['cadastros'][$tabela] = $rc;
}

echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($resultado['ok'] ? 0 : 1);
