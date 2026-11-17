<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$env = GreenPortalDatabase::env();
$result = [];
foreach (['gatlas_dbp', 'gmodelo_dbp', 'gmodelo2_dbp'] as $database) {
    $pdo = GreenPortalDatabase::connect($env, $database, 'GPT');
    $user = (int)$pdo->query('SELECT codusuario FROM usuarios WHERE ativo=1 ORDER BY codusuario LIMIT 1')->fetchColumn();
    $result[$database] = ['usuario' => $user, 'views' => []];
    foreach (['vw_minhas_licencas_grid', 'vw_minhas_monitoramentos_grid', 'vw_minhas_treinamentos_grid', 'vw_minhas_infraestrutura_grid', 'vw_meus_controles_grid'] as $view) {
        try {
            $rows = GreenPortalDatabase::call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,10,0)', [
                'tabela' => $view,
                'filtros' => json_encode(['CODUSUARIO' => $user], JSON_UNESCAPED_UNICODE),
            ]);
            $result[$database]['views'][$view] = ['ok' => true, 'rows' => count($rows)];
        } catch (Throwable $error) {
            $result[$database]['views'][$view] = ['ok' => false, 'error' => $error->getMessage()];
        }
    }
}
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
