<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/TaskService.php';
require_once dirname(__DIR__) . '/classes/ResponsibilityService.php';

$env = GreenPortalDatabase::env();
$result = [];
foreach (['gatlas_dbp', 'gmodelo_dbp', 'gmodelo2_dbp'] as $database) {
    $pdo = GreenPortalDatabase::connect($env, $database, 'GPT');
    $service = new ResponsibilityService($pdo, new TaskService($pdo));
    $user = (int)$pdo->query('SELECT codusuario FROM usuarios WHERE ativo=1 ORDER BY codusuario LIMIT 1')->fetchColumn();
    $group = (int)$pdo->query('SELECT codgrupo FROM grupos WHERE ativo=1 ORDER BY codgrupo LIMIT 1')->fetchColumn();
    $result[$database] = [
        'usuario_teste' => $user,
        'responsabilidades_usuario' => count($service->effectiveForUser($user)),
        'grupo_teste' => $group,
        'responsabilidades_grupo' => count($service->effectiveForGroup($group)),
        'delegacoes_ativas' => count($service->activeDelegations()),
        'conflitos_principais' => count($service->conflicts()),
    ];
}
echo json_encode(['ok' => true, 'targets' => $result], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
