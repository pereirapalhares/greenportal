<?php
declare(strict_types=1);

/** Gera backups de homologação das duas fontes legadas do Atlas. */
$env = [];
foreach (file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}

$dump = 'E:\\wamp\\bin\\mysql\\mysql8.4.7\\bin\\mysqldump.exe';
if (!is_file($dump)) {
    fwrite(STDERR, "mysqldump não encontrado.\n");
    exit(2);
}

$stamp = date('Ymd_His');
$directory = __DIR__ . '/../backups/atlas_homologacao_' . $stamp;
if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
    fwrite(STDERR, "Não foi possível criar a pasta de backup.\n");
    exit(2);
}

$sources = [
    ['prefix' => 'LEGADO_MODELO', 'database' => 'green_atlas'],
    ['prefix' => 'LEGADO_SEGURANCA', 'database' => 'green_atlas_seguranca'],
];
$manifest = ['created_at' => date(DATE_ATOM), 'files' => []];

foreach ($sources as $source) {
    $prefix = $source['prefix'];
    $cnf = tempnam(sys_get_temp_dir(), 'atlas-mysql-');
    $content = "[client]\n" .
        'host=' . ($env[$prefix . '_DB_HOST'] ?? '127.0.0.1') . "\n" .
        'port=' . ($env[$prefix . '_DB_PORT'] ?? '3306') . "\n" .
        'user=' . ($env[$prefix . '_DB_USERNAME'] ?? '') . "\n" .
        'password=' . ($env[$prefix . '_DB_PASSWORD'] ?? '') . "\n" .
        "default-character-set=latin1\n";
    file_put_contents($cnf, $content);
    $target = $directory . DIRECTORY_SEPARATOR . $source['database'] . '.sql';
    $command = '"' . $dump . '" --defaults-extra-file="' . $cnf . '" --single-transaction --routines --events --triggers --hex-blob --set-gtid-purged=OFF --databases ' . $source['database'] . ' --result-file="' . $target . '"';
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        @unlink($cnf);
        fwrite(STDERR, "Não foi possível iniciar o backup de {$source['database']}.\n");
        exit(2);
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    @unlink($cnf);
    if ($code !== 0) {
        fwrite(STDERR, "Backup de {$source['database']} falhou: {$stderr}\n");
        exit($code ?: 1);
    }
    $manifest['files'][$source['database']] = [
        'path' => basename($target),
        'bytes' => filesize($target),
        'sha256' => hash_file('sha256', $target),
        'mysqldump_output' => trim($stdout),
    ];
    echo $source['database'] . ': ' . number_format((int)filesize($target), 0, ',', '.') . " bytes\n";
}

file_put_contents($directory . DIRECTORY_SEPARATOR . 'MANIFEST.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
echo 'Pasta: ' . $directory . PHP_EOL;
