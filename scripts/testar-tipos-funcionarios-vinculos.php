<?php
declare(strict_types=1);

/*
 * Teste (sem navegador) dos vínculos dos Tipos de Funcionários — migração 249.
 * Em cada banco: objetos (3 views, 2 procedures, 3 índices únicos), sem repetidos e sem tipo inexistente;
 * cria um tipo de teste (sp_crud_insert), lista cada relação, vincula 2 itens, repete (não duplica), desvincula 1,
 * recusa item inexistente, exclui o tipo com vínculo (gatilho remove os vínculos junto) e confere.
 * Também confere o sincronismo (SINC_MESTRE_NOVO) e o menu/ajuda. Os registros de teste são removidos.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\testar-tipos-funcionarios-vinculos.php
 * Rode também: scripts\testar-cadastros-homologacao.php --cadastro=fo_tipofuncionarios
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$bancos = ['gmodelo_dbp', 'gatlas_dbp', 'gmodelo2_dbp'];
$relacoes = [
    'CRITERIOS' => ['tabela' => 'fo_tipofuncionariosxcriteriofuncionarios', 'item' => 'codcriteriofuncionario', 'indice' => 'uk_tipofunc_criterio'],
    'TREINAMENTOS' => ['tabela' => 'fo_tipofuncionariosxtipotreinamentos', 'item' => 'codtipotreinamento', 'indice' => 'uk_tipofunc_treinamento'],
    'PALAVRASCHAVE' => ['tabela' => 'fo_tipofuncionariosxpalavraschave', 'item' => 'codpalavrachave', 'indice' => 'uk_tipofunc_palavrachave'],
];
$env = GreenPortalDatabase::env();
$resultado = ['teste' => 'vínculos dos tipos de funcionário (249)', 'ok' => true, 'geral' => [], 'bancos' => []];
$checar = static function (array &$lista, string $nome, bool $passou, string $detalhe = '') use (&$resultado): void {
    $lista[$nome] = ($passou ? 'OK' : 'FALHOU') . ($detalhe !== '' ? " — $detalhe" : '');
    if (!$passou) $resultado['ok'] = false;
};
$conjuntos = static function (PDO $pdo, string $sql, array $p = []): array {
    $s = $pdo->prepare($sql); $s->execute($p); $r = [];
    do { try { $r[] = $s->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable) {} } while ($s->nextRowset());
    $s->closeCursor(); return $r;
};

// Sincronismo e menu
$sinc = (string)file_get_contents(dirname(__DIR__) . '/scripts/sincronizar-fo-legado.php');
foreach (['fo_tipofuncionarios', 'fo_tipofuncionariosxcriteriofuncionarios', 'fo_tipofuncionariosxtipotreinamentos', 'fo_tipofuncionariosxpalavraschave'] as $t) {
    $checar($resultado['geral'], "fora do sincronismo: $t", (bool)preg_match("/SINC_MESTRE_NOVO\s*=\s*\[[^\]]*'$t'/s", $sinc));
}
$universal = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
$q = $universal->prepare("SELECT codmenu, url FROM menus WHERE codigo = 'HOMOLOGACAO_TIPOS_FUNCIONARIOS' AND ativo = 1"); $q->execute(); $m = $q->fetch(PDO::FETCH_ASSOC) ?: [];
$checar($resultado['geral'], 'menu universal', !empty($m), json_encode($m));
foreach (['principal/homologacaofornecedores_tiposfuncionarios.php', 'principal/homologacaofornecedores_tiposfuncionarios_vinculos.php', 'assets/js/homologacao-tipos-funcionarios-vinculos.js'] as $arq) {
    $checar($resultado['geral'], "arquivo $arq", is_file(dirname(__DIR__) . '/' . $arq));
}

foreach ($bancos as $banco) {
    $r = [];
    try { $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT'); }
    catch (Throwable $e) { if ($banco !== 'gmodelo2_dbp') { $checar($r, 'conexão', false, $e->getMessage()); $resultado['bancos'][$banco] = $r; } continue; }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
    $tipo = 0;
    try {
        $o = $pdo->query("SELECT CONCAT(ROUTINE_TYPE, ' ', ROUTINE_NAME) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME LIKE 'sp\\_fo\\_tipofuncionario\\_vinculos\\_%'
            UNION ALL SELECT CONCAT('VIEW ', TABLE_NAME) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'vw\\_homologacao\\_tipofuncionario\\_%'")->fetchAll(PDO::FETCH_COLUMN);
        $checar($r, 'objetos (2 procedures, 3 views)', count($o) === 5, implode(', ', $o));
        foreach ($relacoes as $rel => $c) {
            $t = $c['tabela'];
            $ind = (int)$pdo->query("SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t' AND INDEX_NAME = '{$c['indice']}' AND NON_UNIQUE = 0")->fetchColumn();
            $checar($r, "$rel: índice único", $ind === 1);
            $orf = (int)$pdo->query("SELECT COUNT(*) FROM `$t` v WHERE NOT EXISTS (SELECT 1 FROM fo_tipofuncionarios x WHERE x.codtipofuncionario = v.codtipofuncionario)")->fetchColumn();
            $checar($r, "$rel: sem vínculo de tipo inexistente", $orf === 0, "$orf");
        }

        // Tipo de teste
        $marca = 'TESTE 249 ' . date('YmdHis');
        $conjuntos($pdo, 'CALL sp_crud_insert(?, ?)', ['fo_tipofuncionarios', json_encode(['DSCTIPO' => $marca], JSON_UNESCAPED_UNICODE)]);
        $tipo = (int)$pdo->query('SELECT codtipofuncionario FROM fo_tipofuncionarios WHERE DSCTIPO = ' . $pdo->quote($marca))->fetchColumn();
        $checar($r, 'tipo de teste incluído', $tipo > 0, "código $tipo" . ($tipo >= 100000 ? ' (faixa do sistema novo)' : ' (ATENÇÃO: abaixo de 100000)'));

        foreach ($relacoes as $rel => $c) {
            $l = $conjuntos($pdo, 'CALL sp_fo_tipofuncionario_vinculos_listar(?, ?)', [$rel, $tipo]);
            $opcoes = $l[1] ?? [];
            $checar($r, "$rel: listar", ($l[0][0]['DSCTIPO'] ?? '') === $marca, count($opcoes) . ' opções');
            if (count($opcoes) < 2) { $r["$rel: gravar"] = 'NÃO TESTADO — menos de 2 opções neste banco'; continue; }
            $a = (int)$opcoes[0]['codigo']; $b = (int)$opcoes[1]['codigo'];
            $g = $conjuntos($pdo, 'CALL sp_fo_tipofuncionario_vinculos_gravar(?, ?, ?, ?)', [$rel, $tipo, json_encode([$a, $b, $a]), '[]']);
            $checar($r, "$rel: vincular 2", (int)($g[0][0]['incluidos'] ?? -1) === 2, json_encode($g[0][0] ?? []));
            $g = $conjuntos($pdo, 'CALL sp_fo_tipofuncionario_vinculos_gravar(?, ?, ?, ?)', [$rel, $tipo, json_encode([$a]), '[]']);
            $checar($r, "$rel: repetir não duplica", (int)($g[0][0]['incluidos'] ?? -1) === 0);
            $g = $conjuntos($pdo, 'CALL sp_fo_tipofuncionario_vinculos_gravar(?, ?, ?, ?)', [$rel, $tipo, '[]', json_encode([$b])]);
            $l = $conjuntos($pdo, 'CALL sp_fo_tipofuncionario_vinculos_listar(?, ?)', [$rel, $tipo]);
            $vinc = array_map('intval', array_column(array_filter($l[1] ?? [], static fn($i) => (int)$i['vinculado'] === 1), 'codigo'));
            $checar($r, "$rel: desvincular 1", (int)($g[0][0]['removidos'] ?? -1) === 1 && $vinc === [$a], 'vinculados: ' . json_encode($vinc));
            try {
                $conjuntos($pdo, 'CALL sp_fo_tipofuncionario_vinculos_gravar(?, ?, ?, ?)', [$rel, $tipo, json_encode([987654321]), '[]']);
                $checar($r, "$rel: item inexistente recusado", false, 'foi aceito');
            } catch (PDOException $e) { $checar($r, "$rel: item inexistente recusado", str_contains($e->getMessage(), '1644')); }
        }
        // Exclusão do tipo com vínculos: os vínculos saem junto
        $conjuntos($pdo, 'CALL sp_crud_delete(?, ?)', ['fo_tipofuncionarios', json_encode(['CODTIPOFUNCIONARIO' => $tipo])]);
        $resto = 0;
        foreach ($relacoes as $c) $resto += (int)$pdo->query("SELECT COUNT(*) FROM `{$c['tabela']}` WHERE codtipofuncionario = $tipo")->fetchColumn();
        $existe = (int)$pdo->query("SELECT COUNT(*) FROM fo_tipofuncionarios WHERE codtipofuncionario = $tipo")->fetchColumn();
        $checar($r, 'excluir tipo remove os vínculos junto', $existe === 0 && $resto === 0, "tipo $existe, vínculos $resto");
        if ($existe === 0) $tipo = 0;
    } catch (Throwable $e) {
        $checar($r, 'execução', false, $e->getMessage());
    }
    if ($tipo > 0) { // limpeza se algo falhou no meio
        foreach ($relacoes as $c) $pdo->exec("DELETE FROM `{$c['tabela']}` WHERE codtipofuncionario = $tipo");
        try { $pdo->exec("DELETE FROM fo_tipofuncionarios WHERE codtipofuncionario = $tipo"); } catch (Throwable) {}
    }
    $resultado['bancos'][$banco] = $r;
}

echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($resultado['ok'] ? 0 : 1);
