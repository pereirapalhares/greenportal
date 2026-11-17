<?php
declare(strict_types=1);

/**
 * Retenção controlada de artefatos assíncronos.
 *
 * Padrão: simulação. Exclusão somente com --execute.
 * Uso: php scripts/manutencao-retencao-exportacoes-alertas.php [--execute]
 *      [--export-days=30] [--alert-days=90] [--database=gmodelo_dbp]
 */
date_default_timezone_set('America/Sao_Paulo');

$root = dirname(__DIR__);
$options = getopt('', ['execute', 'export-days::', 'alert-days::', 'database::']);
$exportDays = max(1, (int)($options['export-days'] ?? 30));
$alertDays = max(1, (int)($options['alert-days'] ?? 90));
$database = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($options['database'] ?? 'gmodelo_dbp'));
$execute = array_key_exists('execute', $options);

$env = [];
foreach (file($root . DIRECTORY_SEPARATOR . '.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}

$pdo = new PDO(
    'mysql:host=' . ($env['GPT_DB_HOST'] ?? '127.0.0.1') . ';port=' . ($env['GPT_DB_PORT'] ?? '3306') . ';dbname=' . $database . ';charset=utf8mb4',
    $env['GPT_DB_USERNAME'] ?? '',
    $env['GPT_DB_PASSWORD'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$exportCutoff = date('Y-m-d H:i:s', time() - ($exportDays * 86400));
$alertCutoff = date('Y-m-d H:i:s', time() - ($alertDays * 86400));
// Dados sempre por procedure (migração 281): listagem das exportações, exclusão por sp_crud_delete e retenção dos alertas
$chamar = static function (string $sql, array $params) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($params); $r = $s->fetchAll(); $s->closeCursor(); return $r; };
$exports = $chamar('CALL sp_exportacoes_retencao_listar(:cutoff)', ['cutoff' => $exportCutoff]);
$eligibleAlerts = (int)($chamar('CALL sp_alertas_retencao(:cutoff, 0)', ['cutoff' => $alertCutoff])[0]['elegiveis'] ?? 0);

$deletedFiles = 0;
$deletedExports = 0;
$deletedAlerts = 0;
$exportRoot = realpath($root . DIRECTORY_SEPARATOR . 'exports');
if ($execute) {
    $pdo->beginTransaction();
    try {
        foreach ($exports as $row) {
            $chamar('CALL sp_crud_delete(:tabela, :chave)', ['tabela' => 'exportacoes_grid', 'chave' => json_encode(['codexportacaogrid' => (int)$row['codexportacaogrid']])]);
            $deletedExports++;
            $file = trim((string)($row['arquivo'] ?? ''));
            if ($exportRoot && $file !== '') {
                $candidate = realpath($root . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, preg_replace('#^/+#', '', $file)), DIRECTORY_SEPARATOR));
                if ($candidate && str_starts_with(strtolower($candidate), strtolower($exportRoot . DIRECTORY_SEPARATOR)) && is_file($candidate) && @unlink($candidate)) $deletedFiles++;
            }
        }
        $deletedAlerts = (int)($chamar('CALL sp_alertas_retencao(:cutoff, 1)', ['cutoff' => $alertCutoff])[0]['excluidos'] ?? 0);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

$result = [
    'mode' => $execute ? 'execute' : 'dry-run',
    'database' => $database,
    'export_cutoff' => $exportCutoff,
    'alert_cutoff' => $alertCutoff,
    'eligible_exports' => count($exports),
    'eligible_alerts' => $eligibleAlerts,
    'deleted_exports' => $deletedExports,
    'deleted_files' => $deletedFiles,
    'deleted_alerts' => $deletedAlerts,
    'generated_at' => date('c'),
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
