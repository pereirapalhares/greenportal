<?php
declare(strict_types=1);

/** Backup lógico UTF-8 de um banco novo homologado. Uso: --banco=gcrown_dbp. */
$banco = '';
foreach ($argv ?? [] as $arg) if (str_starts_with((string)$arg, '--banco=')) $banco = strtolower(trim(substr((string)$arg, 8)));
$manifestOnly = in_array('--manifest-only', $argv ?? [], true);
if (!preg_match('/^g[a-z0-9_]+_dbp$/', $banco)) {
    fwrite(STDERR, "Informe --banco=g<empresa>_dbp.\n");
    exit(2);
}
$root = dirname(__DIR__);
$env = [];
foreach (file($root . DIRECTORY_SEPARATOR . '.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}
$dump = 'E:\\wamp\\bin\\mysql\\mysql8.4.7\\bin\\mysqldump.exe';
if (!is_file($dump)) { fwrite(STDERR, "mysqldump não encontrado.\n"); exit(2); }
$directory = $root . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . $banco . '_homologacao_' . date('Ymd_His');
if (!mkdir($directory, 0775, true) && !is_dir($directory)) { fwrite(STDERR, "Não foi possível criar a pasta de backup.\n"); exit(2); }
$existing = array_values(array_filter(glob($root . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . $banco . '_homologacao_*' . DIRECTORY_SEPARATOR . $banco . '.sql') ?: [], 'is_file'));
if ($manifestOnly && $existing) {
    usort($existing, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    $target = $existing[0]; $directory = dirname($target);
    $manifest = ['banco' => $banco, 'criado_em' => date(DATE_ATOM), 'arquivo' => basename($target), 'bytes' => filesize($target), 'sha256' => hash_file('sha256', $target), 'charset' => 'utf8mb4', 'observacao' => 'Manifesto finalizado após conclusão do dump.'];
    file_put_contents($directory . DIRECTORY_SEPARATOR . 'MANIFEST.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
    echo json_encode(['ok' => true, 'pasta' => $directory, 'manifest' => $manifest], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}
$cnf = tempnam(sys_get_temp_dir(), 'greenportal-mysql-');
file_put_contents($cnf, "[client]\n" .
    'host=' . ($env['GPT_DB_HOST'] ?? '127.0.0.1') . "\n" .
    'port=' . ($env['GPT_DB_PORT'] ?? '3306') . "\n" .
    'user=' . ($env['GPT_DB_USERNAME'] ?? '') . "\n" .
    'password=' . ($env['GPT_DB_PASSWORD'] ?? '') . "\n" .
    "default-character-set=utf8mb4\n");
$target = $directory . DIRECTORY_SEPARATOR . $banco . '.sql';
$command = '"' . $dump . '" --defaults-extra-file="' . $cnf . '" --single-transaction --routines --events --triggers --hex-blob --set-gtid-purged=OFF --default-character-set=utf8mb4 --databases ' . $banco . ' --result-file="' . $target . '"';
$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) { @unlink($cnf); fwrite(STDERR, "Não foi possível iniciar o backup.\n"); exit(2); }
$stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]); $code = proc_close($process); @unlink($cnf);
if ($code !== 0) { fwrite(STDERR, "Backup de $banco falhou: $stderr\n"); exit($code ?: 1); }
$manifest = ['banco' => $banco, 'criado_em' => date(DATE_ATOM), 'arquivo' => basename($target), 'bytes' => filesize($target), 'sha256' => hash_file('sha256', $target), 'charset' => 'utf8mb4'];
file_put_contents($directory . DIRECTORY_SEPARATOR . 'MANIFEST.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
echo json_encode(['ok' => true, 'pasta' => $directory, 'manifest' => $manifest], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
