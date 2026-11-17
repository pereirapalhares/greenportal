<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/TaskService.php';

$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, 'gatlas_dbp', 'GPT');
$user = $pdo->query('SELECT codusuario FROM usuarios WHERE ativo=1 ORDER BY codusuario LIMIT 1')->fetchColumn();
if (!$user) {
    throw new RuntimeException('Atlas não possui usuário ativo para o teste.');
}

$key = 'TEST_TASK_SERVICE_183_' . date('YmdHis');
$oldIds = $pdo->query("SELECT codtarefa FROM green_tasks WHERE chave_idempotencia LIKE 'TEST_TASK_SERVICE_183_%'")->fetchAll(PDO::FETCH_COLUMN);
if ($oldIds) {
    $marks = implode(',', array_fill(0, count($oldIds), '?'));
    $historyCleanup = $pdo->prepare("DELETE FROM green_task_history WHERE codtarefa IN ({$marks})");
    $historyCleanup->execute(array_map('intval', $oldIds));
    $taskCleanup = $pdo->prepare("DELETE FROM green_tasks WHERE codtarefa IN ({$marks})");
    $taskCleanup->execute(array_map('intval', $oldIds));
}
$service = new TaskService($pdo);
$task = $service->enqueue([
    'chave_idempotencia' => $key,
    'tipo' => 'NOTIFICACAO',
    'origem_transacao' => 'teste-task-worker-183.php',
    'origem_entidade' => 'homologacao',
    'origem_id' => $key,
    'titulo' => 'Teste TaskService 183',
    'descricao' => 'Notificação temporária de homologação do worker.',
    'codusuario_destino' => (int)$user,
    'criado_por' => (int)$user,
]);

$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/task-worker.php') . ' --database=gatlas_dbp --limit=10';
$output = [];
$exit = 0;
exec($command, $output, $exit);
$check = $pdo->prepare('SELECT status,codtarefa FROM green_tasks WHERE chave_idempotencia=:key');
$check->execute(['key' => $key]);
$state = $check->fetch() ?: [];
$alert = $pdo->prepare('SELECT COUNT(*) FROM alertas WHERE link=:link');
$alert->execute(['link' => 'TASK:' . (int)($state['codtarefa'] ?? 0)]);
$alertCount = (int)$alert->fetchColumn();

$deleteAlert = $pdo->prepare('DELETE FROM alertas WHERE link=:link');
$deleteAlert->execute(['link' => 'TASK:' . (int)($state['codtarefa'] ?? 0)]);
$deleteHistory = $pdo->prepare('DELETE FROM green_task_history WHERE codtarefa=:id');
$deleteHistory->execute(['id' => (int)($state['codtarefa'] ?? 0)]);
$deleteTask = $pdo->prepare('DELETE FROM green_tasks WHERE codtarefa=:id');
$deleteTask->execute(['id' => (int)($state['codtarefa'] ?? 0)]);

echo json_encode([
    'ok' => $exit === 0 && ($state['status'] ?? '') === 'CONCLUIDA' && $alertCount === 1,
    'worker_exit' => $exit,
    'task_status' => $state['status'] ?? null,
    'alertas_criados' => $alertCount,
    'limpeza' => true,
    'worker_output' => implode("\n", $output),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
