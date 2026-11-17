<?php
declare(strict_types=1);

/**
 * Inventário somente leitura das bases legadas do Atlas.
 * Uso: php scripts/inventariar-atlas.php [--json=arquivo.json]
 */

function envValues(): array
{
    $values = [];
    foreach (file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $values[trim($key)] = trim(trim($value), "\"'");
    }
    return $values;
}

function connect(array $env, string $prefix, string $database): PDO
{
    $dsn = 'mysql:host=' . ($env[$prefix . '_DB_HOST'] ?? '127.0.0.1') .
        ';port=' . ($env[$prefix . '_DB_PORT'] ?? '3306') .
        ';dbname=' . $database . ';charset=utf8mb4';
    return new PDO($dsn, $env[$prefix . '_DB_USERNAME'] ?? '', $env[$prefix . '_DB_PASSWORD'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function identifier(string $value): string
{
    return '`' . str_replace('`', '``', $value) . '`';
}

function inventory(PDO $pdo, string $database): array
{
    $quote = $pdo->quote($database);
    $result = [
        'database' => $database,
        'server' => $pdo->query('SELECT @@version AS version, @@character_set_server AS character_set_server, @@collation_server AS collation_server')->fetch(),
        'tables' => [],
        'views' => [],
        'routines' => [],
        'triggers' => [],
        'events' => [],
    ];

    $tables = $pdo->query("SELECT table_name, engine, table_collation, table_rows FROM information_schema.tables WHERE table_schema=$quote AND table_type='BASE TABLE' ORDER BY table_name")->fetchAll();
    foreach ($tables as $table) {
        $name = (string)($table['table_name'] ?? $table['TABLE_NAME']);
        $nameQuote = $pdo->quote($name);
        $columns = $pdo->query('SELECT column_name, ordinal_position, column_default, is_nullable, data_type, character_set_name, collation_name, column_type, extra, column_key, column_comment FROM information_schema.columns WHERE table_schema=' . $quote . ' AND table_name=' . $nameQuote . ' ORDER BY ordinal_position')->fetchAll();
        $indexes = $pdo->query('SELECT index_name, non_unique, seq_in_index, column_name, collation, cardinality, sub_part, index_type, comment FROM information_schema.statistics WHERE table_schema=' . $quote . ' AND table_name=' . $nameQuote . ' ORDER BY index_name, seq_in_index')->fetchAll();
        $foreignKeys = $pdo->query('SELECT constraint_name, column_name, referenced_table_name, referenced_column_name, ordinal_position FROM information_schema.key_column_usage WHERE table_schema=' . $quote . ' AND table_name=' . $nameQuote . ' AND referenced_table_name IS NOT NULL ORDER BY constraint_name, ordinal_position')->fetchAll();
        $result['tables'][] = $table + ['columns' => $columns, 'indexes' => $indexes, 'foreign_keys' => $foreignKeys];
    }

    $result['views'] = $pdo->query("SELECT table_name, check_option, is_updatable, security_type, view_definition FROM information_schema.views WHERE table_schema=$quote ORDER BY table_name")->fetchAll();
    $result['routines'] = $pdo->query("SELECT routine_name, routine_type, data_type, sql_data_access, is_deterministic, security_type, routine_definition, external_language FROM information_schema.routines WHERE routine_schema=$quote ORDER BY routine_name")->fetchAll();
    $result['triggers'] = $pdo->query("SELECT trigger_name, event_manipulation, event_object_table, action_timing, action_statement, action_orientation FROM information_schema.triggers WHERE trigger_schema=$quote ORDER BY trigger_name")->fetchAll();
    $result['events'] = $pdo->query("SELECT event_name, event_type, status, event_definition, event_comment FROM information_schema.events WHERE event_schema=$quote ORDER BY event_name")->fetchAll();
    return $result;
}

$env = envValues();
$sources = [
    ['prefix' => 'LEGADO_MODELO', 'database' => 'green_atlas'],
    ['prefix' => 'LEGADO_SEGURANCA', 'database' => 'green_atlas_seguranca'],
];
$report = [
    'generated_at' => date(DATE_ATOM),
    'sources' => [],
];

foreach ($sources as $source) {
    try {
        $pdo = connect($env, $source['prefix'], $source['database']);
        $report['sources'][] = inventory($pdo, $source['database']);
    } catch (Throwable $error) {
        fwrite(STDERR, $source['database'] . ': conexão/inventário falhou (' . $error::class . '): ' . $error->getMessage() . PHP_EOL);
        exit(2);
    }
}

$output = null;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--json=')) {
        $output = substr($argument, 7);
    }
}
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
if ($output !== null) {
    file_put_contents($output, $json . PHP_EOL);
}
foreach ($report['sources'] as $source) {
    echo $source['database'] . ': ' . count($source['tables']) . ' tabelas, ' . count($source['views']) . ' views, ' . count($source['routines']) . ' rotinas, ' . count($source['triggers']) . ' triggers, ' . count($source['events']) . ' eventos' . PHP_EOL;
}
