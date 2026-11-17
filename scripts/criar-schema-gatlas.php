<?php
declare(strict_types=1);

/** Cria o schema-base do tenant Atlas a partir do template Green Portal. */
$env = [];
foreach (file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}
$dump = 'E:\\wamp\\bin\\mysql\\mysql8.4.7\\bin\\mysqldump.exe';
$mysql = 'E:\\wamp\\bin\\mysql\\mysql8.4.7\\bin\\mysql.exe';
$db = 'gatlas_dbp';
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$pdo = new PDO('mysql:host=' . $env['GPT_DB_HOST'] . ';port=' . $env['GPT_DB_PORT'] . ';charset=utf8mb4', $env['GPT_DB_USERNAME'], $env['GPT_DB_PASSWORD'], $opts);
$exists = $pdo->prepare('SELECT 1 FROM information_schema.schemata WHERE schema_name = ?');
$exists->execute([$db]);
if ($exists->fetchColumn()) {
    fwrite(STDERR, "O banco gatlas_dbp já existe; o script não fará rebuild destrutivo.\n");
    exit(3);
}

$work = __DIR__ . '/../runtime/atlas-schema-' . date('Ymd_His');
mkdir($work, 0775, true);
$cnf = tempnam(sys_get_temp_dir(), 'atlas-template-');
file_put_contents($cnf, "[client]\n" . 'host=' . $env['GPT_DB_HOST'] . "\nport=" . $env['GPT_DB_PORT'] . "\nuser=" . $env['GPT_DB_USERNAME'] . "\npassword=" . $env['GPT_DB_PASSWORD'] . "\ndefault-character-set=utf8mb4\n");
$sourceDump = $work . '/gmodelo_schema.sql';
$targetDump = $work . '/gatlas_schema.sql';
$command = '"' . $dump . '" --defaults-extra-file="' . $cnf . '" --no-data --routines --events --triggers --set-gtid-purged=OFF --databases gmodelo_dbp --result-file="' . $sourceDump . '"';
$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) { @unlink($cnf); exit(2); }
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
$code = proc_close($process);
if ($code !== 0) { @unlink($cnf); fwrite(STDERR, $stderr . PHP_EOL); exit($code ?: 1); }

$in = fopen($sourceDump, 'rb');
$out = fopen($targetDump, 'wb');
while (($line = fgets($in)) !== false) fwrite($out, str_replace('gmodelo_dbp', $db, $line));
fclose($in); fclose($out);
$command = '"' . $mysql . '" --defaults-extra-file="' . $cnf . '" --default-character-set=utf8mb4';
$process = proc_open($command, [0 => ['file', $targetDump, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) { @unlink($cnf); exit(2); }
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
$code = proc_close($process);
@unlink($cnf);
if ($code !== 0) { fwrite(STDERR, $stderr . PHP_EOL); exit($code ?: 1); }

$pdo->exec('ALTER DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
$check = new PDO('mysql:host=' . $env['GPT_DB_HOST'] . ';port=' . $env['GPT_DB_PORT'] . ';dbname=' . $db . ';charset=utf8mb4', $env['GPT_DB_USERNAME'], $env['GPT_DB_PASSWORD'], $opts);
$counts = $check->query("SELECT table_type, COUNT(*) total FROM information_schema.tables WHERE table_schema='gatlas_dbp' GROUP BY table_type")->fetchAll();
echo json_encode(['database' => $db, 'counts' => $counts, 'work' => $work], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
