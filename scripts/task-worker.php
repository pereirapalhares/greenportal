<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/TaskService.php';
require_once dirname(__DIR__) . '/classes/ResponsibilityService.php';
require_once dirname(__DIR__) . '/classes/AgendaService.php';
require_once dirname(__DIR__) . '/classes/ResponsibilityAgendaService.php';

// Uma execução por vez (c584): a tarefa GreenPortalTarefas iniciava este script sem esperar e empilhou cópias.
// Desde 28/10/2026 a tarefa está desativada: Agenda, Responsibility e Notification fazem o mesmo trabalho.
$travaDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'runtime';
if (!is_dir($travaDir)) @mkdir($travaDir, 0770, true);
$trava = fopen($travaDir . DIRECTORY_SEPARATOR . 'green-task-worker.lock', 'c');
if ($trava === false || !flock($trava, LOCK_EX | LOCK_NB)) exit(0);

$env = GreenPortalDatabase::env();
$args = $argv ?? [];
$database = null;
$limit = 20;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--database=')) $database = substr($arg, 11);
    if (str_starts_with($arg, '--limit=')) $limit = max(1, min(100, (int)substr($arg, 8)));
}

$configured = array_values(array_filter(array_map('trim', explode(',', (string)($env['TASK_WORKER_DATABASES'] ?? '')))));
$databases = $database !== null ? [$database] : ($configured ?: ['gatlas_dbp', 'gmodelo_dbp', 'gmodelo2_dbp']);
$result = ['application' => 'GreenSolutions', 'version' => '4.0.1', 'processed' => 0, 'databases' => []];

foreach ($databases as $db) {
    if (!preg_match('/^g[a-z0-9]+_dbp$/i', $db)) {
        throw new InvalidArgumentException('Banco não permitido para o worker: ' . $db);
    }
    $pdo = GreenPortalDatabase::connect($env, $db, 'GPT');
    $service = new TaskService($pdo);
    $responsibilities = new ResponsibilityService($pdo, $service);
    $agenda = new AgendaService($pdo, $service);
    $processed = 0;
    $errors = [];
    $generated = 0;
    $agendaGenerated = 0;
    $responsibilityAgenda = ['criados_ou_atualizados' => 0, 'cancelados' => 0, 'erros' => []];
    try {
        $agendaGenerated = $agenda->materializeDueOccurrences(1);
    } catch (Throwable $exception) {
        $errors[] = ['etapa' => 'agenda', 'erro' => $exception->getMessage()];
    }
    try {
        $responsibilityAgenda = (new ResponsibilityAgendaService($pdo, $agenda))->synchronizeFivePillars();
        $generated = $responsibilities->enqueueExpiringTasks(30);
    } catch (Throwable $exception) {
        $errors[] = ['etapa' => 'responsabilidades', 'erro' => $exception->getMessage()];
    }
    while ($processed < $limit && ($task = $service->claim('NOTIFICACAO')) !== null) {
        try {
            $codusuario = (int)($task['codusuario_destino'] ?? 0);
            if ($codusuario <= 0) {
                throw new RuntimeException('Tarefa de notificação sem usuário de destino.');
            }
            $key = 'TASK:' . (int)$task['codtarefa'];
            // Idempotente pelo link TASK:<codtarefa> (não repete o alerta)
            $insert = $pdo->prepare("CALL sp_alerta_criar(:usuario,:titulo,:mensagem,:link,'LINK')");
            $insert->execute([
                'usuario' => $codusuario,
                'titulo' => (string)$task['titulo'],
                'mensagem' => (string)$task['descricao'],
                'link' => $key,
            ]);
            $insert->closeCursor();
            $service->complete((int)$task['codtarefa'], 'Notificação entregue; inserção idempotente por link ' . $key . '.');
            $processed++;
        } catch (Throwable $exception) {
            $errors[] = ['codtarefa' => (int)$task['codtarefa'], 'erro' => $exception->getMessage()];
            $service->fail((int)$task['codtarefa'], $exception->getMessage());
        }
    }
    $result['processed'] += $processed;
    $result['databases'][$db] = ['agenda_generated' => $agendaGenerated, 'responsibility_agenda' => $responsibilityAgenda, 'responsibilities_generated' => $generated, 'processed' => $processed, 'pending' => $service->pendingCount(), 'errors' => $errors];
    $run = $pdo->prepare('INSERT INTO green_service_runs (servico,banco_dados,status,inicio_em,fim_em,quantidade,detalhes) VALUES (:servico,:banco,:status,DATE_SUB(CURRENT_TIMESTAMP,INTERVAL 1 SECOND),CURRENT_TIMESTAMP,:quantidade,:detalhes)');
    $run->execute(['servico' => 'task', 'banco' => $db, 'status' => $errors === [] && empty($responsibilityAgenda['erros']) ? 'CONCLUIDA' : 'ERRO', 'quantidade' => $processed, 'detalhes' => json_encode(['agenda_generated' => $agendaGenerated, 'responsibility_agenda' => $responsibilityAgenda, 'responsibilities_generated' => $generated, 'pending' => $service->pendingCount(), 'errors' => $errors], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
