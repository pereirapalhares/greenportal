<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/TaskService.php';

$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, 'gatlas_dbp', 'GPT');
$user = (int)$pdo->query('SELECT codusuario FROM usuarios WHERE ativo=1 ORDER BY codusuario LIMIT 1')->fetchColumn();
if ($user <= 0) throw new RuntimeException('Usuário ativo do Atlas não localizado.');

$key = 'TEST_SCHEDULER_TASK_183_' . date('YmdHis');
$task = (new TaskService($pdo))->enqueue([
    'chave_idempotencia' => $key,
    'tipo' => 'NOTIFICACAO',
    'origem_transacao' => 'teste-disparo-scheduler-task.php',
    'origem_entidade' => 'homologacao_scheduler',
    'origem_id' => $key,
    'titulo' => 'Teste do Agendador Green Tasks',
    'descricao' => 'Registro temporário para confirmar o disparo automático do Windows Scheduler.',
    'codusuario_destino' => $user,
    'criado_por' => $user,
]);

$deadline = time() + 90;
$status = 'TIMEOUT';
while (time() < $deadline) {
    $check = $pdo->prepare('SELECT status FROM green_tasks WHERE codtarefa=:id');
    $check->execute(['id' => (int)$task['codtarefa']]);
    $status = (string)$check->fetchColumn();
    if (in_array($status, ['CONCLUIDA', 'ERRO'], true)) break;
    sleep(5);
}

$link = 'TASK:' . (int)$task['codtarefa'];
$pdo->prepare('DELETE FROM alertas WHERE link=:link')->execute(['link' => $link]);
$pdo->prepare('DELETE FROM green_task_history WHERE codtarefa=:id')->execute(['id' => (int)$task['codtarefa']]);
$pdo->prepare('DELETE FROM green_tasks WHERE codtarefa=:id')->execute(['id' => (int)$task['codtarefa']]);

echo json_encode([
    'ok' => $status === 'CONCLUIDA',
    'processado_pelo_scheduler' => $status === 'CONCLUIDA',
    'status_observado' => $status,
    'limpeza' => true,
    'observacao' => $status === 'TIMEOUT' ? 'Nenhuma execução observada no intervalo de 90 segundos.' : null,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
