<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/TaskService.php';
require_once dirname(__DIR__) . '/classes/AgendaService.php';

$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, 'gatlas_dbp', 'GPT');
$user = (int)$pdo->query('SELECT codusuario FROM usuarios WHERE ativo=1 ORDER BY codusuario LIMIT 1')->fetchColumn();
$key = 'TEST_EVENT_SOURCE_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
$service = new AgendaService($pdo, new TaskService($pdo));
$data = [
    'codusuario_responsavel' => $user,
    'titulo' => 'Teste de sincronização de evento',
    'descricao' => 'Teste temporário.',
    'inicio' => date('Y-m-d H:i:s', strtotime('+1 hour')),
    'fim' => date('Y-m-d H:i:s', strtotime('+2 hours')),
    'origem_transacao' => 'eventos.php',
    'origem_entidade' => 'ev_eventos',
    'origem_id' => 'TESTE',
];
$first = $service->syncSource($key, $data);
$second = $service->syncSource($key, $data);
$lookup = $pdo->prepare('SELECT COUNT(*) FROM agenda_eventos WHERE chave_idempotencia=:chave');
$lookup->execute(['chave' => $key]);
$count = (int)$lookup->fetchColumn();
$cancelled = $service->cancelSource($key);
$status = $pdo->prepare('SELECT status FROM agenda_eventos WHERE chave_idempotencia=:chave');
$status->execute(['chave' => $key]);
$statusValue = (string)$status->fetchColumn();
$pdo->prepare('DELETE FROM agenda_eventos WHERE chave_idempotencia=:chave')->execute(['chave' => $key]);
echo json_encode(['ok' => $first === $second && $count === 1 && $cancelled === 1 && $statusValue === 'CANCELADO', 'idempotente' => $first === $second, 'registros' => $count, 'cancelados' => $cancelled, 'status' => $statusValue], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
