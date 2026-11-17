<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, 'gmodelo_dbp', 'GPT');
$json = static fn(array $value): string => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$call = static function (PDO $db, string $sql, array $params = []): array {
    $statement = $db->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    $statement->closeCursor();
    return $rows;
};

$cases = [
    'laia' => [
        'table' => 'lais_revisao',
        'view' => 'vw_lais_revisao_grid',
        'primary' => 'CODREVISAO',
        'marker' => '[TESTE CRUD REVISAO LAIA]',
    ],
    'lpro' => [
        'table' => 'levantamentoocupacionalxrevisoes',
        'view' => 'vw_lpro_revisao_grid',
        'primary' => 'CHAVE',
        'marker' => '[TESTE CRUD REVISAO LPRO]',
    ],
];

$result = ['startedAt' => date(DATE_ATOM), 'tipo' => 'CRUD real sem navegador', 'casos' => [], 'status' => 'REPROVADO'];
$created = [];

try {
    foreach ($cases as $name => $case) {
        $item = ['table' => $case['table'], 'checks' => [], 'status' => 'REPROVADO'];
        $check = static function (string $label, bool $ok, string $details = '') use (&$item): void {
            $item['checks'][] = ['name' => $label, 'ok' => $ok, 'details' => $details];
            if (!$ok) throw new RuntimeException($label . ($details !== '' ? ': ' . $details : ''));
        };

        $objects = $pdo->query("SELECT TABLE_NAME,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA='gmodelo_dbp' AND TABLE_NAME IN ('{$case['table']}','{$case['view']}')")->fetchAll(PDO::FETCH_ASSOC);
        $objectNames = array_column($objects, 'TABLE_NAME');
        $check('Tabela e view existem', in_array($case['table'], $objectNames, true) && in_array($case['view'], $objectNames, true));

        $insertText = $case['marker'] . ' inclusão ' . date('YmdHis');
        $insertRows = $call($pdo, 'CALL sp_crud_insert(:tabela,:dados)', [
            'tabela' => $case['table'],
            'dados' => $json(['DSCREVISAO' => $insertText, 'DATA' => date('Y-m-d')]),
        ]);
        $key = (int)($insertRows[0]['chave_gerada'] ?? 0);
        if ($key <= 0) {
            $keyRows = $call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,10,0)', ['tabela' => $case['view'], 'filtros' => $json([])]);
            foreach ($keyRows as $row) if (($row['DSCREVISAO'] ?? '') === $insertText) { $key = (int)$row[$case['primary']]; break; }
        }
        $created[$name] = [$case, $key];
        $check('Inclusão persistida', $key > 0, 'chave=' . $key);

        $rows = $call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,10,0)', ['tabela' => $case['view'], 'filtros' => $json([$case['primary'] => $key])]);
        $check('Registro incluído aparece na view', count($rows) === 1 && ($rows[0]['DSCREVISAO'] ?? '') === $insertText);

        $updatedText = $case['marker'] . ' alteração ' . date('YmdHis');
        $call($pdo, 'CALL sp_crud_update(:tabela,:chave,:dados)', [
            'tabela' => $case['table'],
            'chave' => $json([$case['primary'] => $key]),
            'dados' => $json(['DSCREVISAO' => $updatedText, 'DATA' => date('Y-m-d')]),
        ]);
        $rows = $call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,10,0)', ['tabela' => $case['view'], 'filtros' => $json([$case['primary'] => $key])]);
        $check('Alteração persistida', count($rows) === 1 && ($rows[0]['DSCREVISAO'] ?? '') === $updatedText);

        $duplicateRows = $call($pdo, 'CALL sp_crud_insert(:tabela,:dados)', [
            'tabela' => $case['table'],
            'dados' => $json(['DSCREVISAO' => $case['marker'] . ' duplicação ' . date('YmdHis'), 'DATA' => date('Y-m-d')]),
        ]);
        $duplicateKey = (int)($duplicateRows[0]['chave_gerada'] ?? 0);
        $created[$name][2] = $duplicateKey;
        $check('Duplicação cria nova chave', $duplicateKey > 0 && $duplicateKey !== $key, 'chave=' . $duplicateKey);

        $call($pdo, 'CALL sp_crud_delete(:tabela,:chave)', ['tabela' => $case['table'], 'chave' => $json([$case['primary'] => $key])]);
        $call($pdo, 'CALL sp_crud_delete(:tabela,:chave)', ['tabela' => $case['table'], 'chave' => $json([$case['primary'] => $duplicateKey])]);
        $remaining = $call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,10,0)', ['tabela' => $case['view'], 'filtros' => $json([$case['primary'] => $key])]);
        $duplicateRemaining = $call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,10,0)', ['tabela' => $case['view'], 'filtros' => $json([$case['primary'] => $duplicateKey])]);
        $check('Exclusão remove os registros', count($remaining) === 0 && count($duplicateRemaining) === 0);
        $item['status'] = 'APROVADO';
        $result['casos'][$name] = $item;
    }
    $result['status'] = 'APROVADO';
} catch (Throwable $error) {
    $result['error'] = $error->getMessage();
    if (!isset($result['casos'][$name]) && isset($item)) $result['casos'][$name] = $item;
} finally {
    foreach ($created as [$case, $key, $duplicateKey]) {
        foreach ([$key, $duplicateKey] as $cleanupKey) {
            if ((int)$cleanupKey > 0) {
                try { $call($pdo, 'CALL sp_crud_delete(:tabela,:chave)', ['tabela' => $case['table'], 'chave' => $json([$case['primary'] => (int)$cleanupKey])]); } catch (Throwable) {}
            }
        }
    }
    $result['finishedAt'] = date(DATE_ATOM);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($result['status'] === 'APROVADO' ? 0 : 1);
}
