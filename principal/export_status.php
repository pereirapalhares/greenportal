<?php
declare(strict_types=1);
date_default_timezone_set('America/Sao_Paulo');
session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados']) || empty($_SESSION['usuario']['codusuario'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'active' => false], JSON_UNESCAPED_UNICODE);
    exit;
}

$transaction = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', (string)($_GET['transacao'] ?? '')));
$jobId = (int)($_GET['codexportacaogrid'] ?? 0);
$allowed = ['setup_menu', 'seguranca_grupos', 'seguranca_usuarios', 'seguranca_permissoes', 'seguranca_perfil', 'seguranca_formulario', 'seguranca_lista'];
$allowed = in_array($transaction, $allowed, true) || str_starts_with($transaction, 'modulolegal_') || str_starts_with($transaction, 'manutencaolegal_') || str_starts_with($transaction, 'administracao_');
if (!$allowed) {
    echo json_encode(['ok' => true, 'active' => false], JSON_UNESCAPED_UNICODE);
    exit;
}

$config = [];
foreach (file(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
    [$key, $value] = explode('=', $line, 2);
    if (!array_key_exists(trim($key), $config)) $config[trim($key)] = trim(trim($value), "\"'");
}
$pdo = new PDO('mysql:host=' . ($config['GPT_DB_HOST'] ?? '127.0.0.1') . ';port=' . ($config['GPT_DB_PORT'] ?? '3306') . ';dbname=' . $_SESSION['tenant']['banco_dados'] . ';charset=utf8mb4', $config['GPT_DB_USERNAME'] ?? '', $config['GPT_DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$call = static function (PDO $pdo, array $filters): array {
    $statement = $pdo->prepare('CALL sp_crud_select(:tabela, :filtros, NULL, 100, 0)');
    $statement->execute(['tabela' => 'exportacoes_grid', 'filtros' => json_encode($filters, JSON_UNESCAPED_UNICODE)]);
    $rows = $statement->fetchAll();
    $statement->closeCursor();
    return $rows;
};

$active = false;
$jobDetails = null;
$jobFilters = ['codusuario' => (int)$_SESSION['usuario']['codusuario']];
if ($jobId > 0) $jobFilters['codexportacaogrid'] = $jobId;
if ($transaction !== '') $jobFilters['transacao'] = $transaction;
$jobs = $call($pdo, $jobFilters);
if ($jobs) {
    usort($jobs, static fn(array $a, array $b): int => ((int)($b['codexportacaogrid'] ?? 0)) <=> ((int)($a['codexportacaogrid'] ?? 0)));
    $job = $jobs[0];
    $status = (string)($job['status'] ?? '');
    $jobDetails = ['codexportacaogrid' => (int)$job['codexportacaogrid'], 'transacao' => (string)$job['transacao'], 'status' => $status, 'solicitado_em' => $job['solicitado_em'] ?? null, 'iniciada_em' => $job['iniciada_em'] ?? null, 'concluido_em' => $job['concluido_em'] ?? null, 'arquivo' => $job['arquivo'] ?? null, 'erro' => $job['erro'] ?? null, 'percentual' => $status === 'CONCLUIDA' || $status === 'ERRO' ? 100 : ($status === 'PROCESSANDO' ? 60 : 10)];
}
if ($jobId > 0) { echo json_encode(['ok' => (bool)$jobDetails, 'active' => $jobDetails && in_array($jobDetails['status'], ['SOLICITADA', 'PROCESSANDO'], true), 'job' => $jobDetails], JSON_UNESCAPED_UNICODE); exit; }
$now = new DateTimeImmutable('now');
$timeoutMinutes = 30;
foreach (['SOLICITADA', 'PROCESSANDO'] as $status) {
    foreach ($call($pdo, ['codusuario' => (int)$_SESSION['usuario']['codusuario'], 'transacao' => $transaction, 'status' => $status]) as $job) {
        $requestedAt = null;
        try { $requestedAt = new DateTimeImmutable((string)($job['solicitado_em'] ?? '')); } catch (Throwable) { }
        if ($requestedAt && ($now->getTimestamp() - $requestedAt->getTimestamp()) > ($timeoutMinutes * 60)) {
            $update = $pdo->prepare('CALL sp_crud_update(:tabela, :chave, :dados)');
            $update->execute([
                'tabela' => 'exportacoes_grid',
                'chave' => json_encode(['codexportacaogrid' => (int)$job['codexportacaogrid']], JSON_UNESCAPED_UNICODE),
                'dados' => json_encode(['status' => 'ERRO', 'concluido_em' => date('Y-m-d H:i:s'), 'erro' => 'A solicitação expirou por falta de conclusão.'], JSON_UNESCAPED_UNICODE)
            ]);
            $update->closeCursor();
            continue;
        }
        $active = true;
        break 2;
    }
}
echo json_encode(['ok' => true, 'active' => $active], JSON_UNESCAPED_UNICODE);
