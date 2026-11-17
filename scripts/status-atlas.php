<?php
declare(strict_types=1);

$env = [];
foreach (file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}

function db(array $e, string $prefix, string $name = ''): PDO
{
    $dsn = 'mysql:host=' . ($e[$prefix . '_DB_HOST'] ?? '127.0.0.1') . ';port=' . ($e[$prefix . '_DB_PORT'] ?? '3306') . ';charset=utf8mb4';
    if ($name !== '') $dsn .= ';dbname=' . $name;
    return new PDO($dsn, $e[$prefix . '_DB_USERNAME'] ?? '', $e[$prefix . '_DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}

$server = db($env, 'GPT');
foreach ($server->query('SHOW FULL PROCESSLIST')->fetchAll() as $process) {
    if (($process['User'] ?? '') === ($env['GPT_DB_USERNAME'] ?? '')) echo 'process: ' . json_encode(['id'=>$process['Id']??null,'db'=>$process['db']??null,'command'=>$process['Command']??null,'time'=>$process['Time']??null,'state'=>$process['State']??null,'info'=>substr((string)($process['Info']??''),0,160)], JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
foreach (['green_atlas', 'green_atlas_seguranca', 'gatlas_dbp'] as $name) {
    $s = $server->prepare('SELECT SCHEMA_NAME, DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.schemata WHERE SCHEMA_NAME = ?');
    $s->execute([$name]);
    echo $name . ': ' . json_encode($s->fetch() ?: null, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

$centralName = $env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp';
$central = db($env, 'GUNIVERSAL', $centralName);
$s = $central->prepare('SELECT codigo, nome, banco_dados, status FROM empresa WHERE UPPER(codigo) IN (\'ATLAS\', \'GATLAS\') OR banco_dados = \'gatlas_dbp\'');
$s->execute();
foreach ($s as $row) echo 'central: ' . json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;

foreach ([['LEGADO_MODELO', 'green_atlas'], ['LEGADO_SEGURANCA', 'green_atlas_seguranca']] as [$prefix, $name]) {
    $source = db($env, $prefix, $name);
    foreach (['fo_fornecedorxusuario', 'vusuariosxempresas'] as $object) {
        try {
            $kind = str_starts_with($object, 'v') ? 'VIEW' : 'TABLE';
            $sql = $kind === 'VIEW' ? 'SELECT COUNT(*) FROM `' . $object . '`' : 'SELECT COUNT(*) FROM `' . $object . '`';
            echo $name . '.' . $object . ': ' . $source->query($sql)->fetchColumn() . PHP_EOL;
        } catch (Throwable) {
            echo $name . '.' . $object . ': unavailable' . PHP_EOL;
        }
    }
}

$modelo = db($env, 'GPT', 'gmodelo_dbp');
foreach (['usuarios', 'grupos', 'perfis', 'menus', 'perfil_menus', 'vw_usuarios_grid', 'vw_grupos_grid', 'vw_permissoes_grid'] as $object) {
    try {
        echo 'gmodelo_dbp.' . $object . ': ' . json_encode($modelo->query('DESCRIBE `' . $object . '`')->fetchAll(), JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } catch (Throwable) {
        echo 'gmodelo_dbp.' . $object . ': unavailable' . PHP_EOL;
    }
}
$auditCols=$modelo->query("SELECT column_name FROM information_schema.columns WHERE table_schema='gmodelo_dbp' AND table_name='logs_auditoria' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_COLUMN);echo 'gmodelo_dbp.logs_auditoria: '.implode(',', $auditCols).PHP_EOL;
foreach(['menusxconfiguracoes','nearmiss_nc_indicacao_ocorrencia','pendencias_planodeacao'] as $wide){$r=$modelo->prepare("SELECT column_name,column_type,column_key FROM information_schema.columns WHERE table_schema='gatlas_dbp' AND table_name=? ORDER BY ordinal_position");$r->execute([$wide]);echo 'gatlas_dbp.'.$wide.': '.json_encode($r->fetchAll(),JSON_UNESCAPED_UNICODE).PHP_EOL;}
foreach(['pendencias_planodeacao'] as $wide){$gt=db($env,'GPT','gatlas_dbp');$r=$gt->query("SHOW INDEX FROM `".$wide."`");echo 'gatlas_dbp.'.$wide.'.indexes: '.json_encode($r->fetchAll(),JSON_UNESCAPED_UNICODE).PHP_EOL;}
$counts = $modelo->query("SELECT table_type, COUNT(*) total FROM information_schema.tables WHERE table_schema='gmodelo_dbp' GROUP BY table_type")->fetchAll();
echo 'gmodelo_dbp.counts: ' . json_encode($counts, JSON_UNESCAPED_UNICODE) . PHP_EOL;
$modeloTables = $modelo->query("SELECT table_name FROM information_schema.tables WHERE table_schema='gmodelo_dbp' AND table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
$sourceTables = [];
foreach ([['LEGADO_MODELO', 'green_atlas'], ['LEGADO_SEGURANCA', 'green_atlas_seguranca']] as [$prefix, $name]) {
    $source = db($env, $prefix, $name);
    foreach ($source->query("SELECT table_name FROM information_schema.tables WHERE table_schema=" . $source->quote($name) . " AND table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table) $sourceTables[$table] = true;
}
$overlap = array_values(array_filter($modeloTables, static fn(string $table): bool => isset($sourceTables[$table])));
echo 'gmodelo/source table overlap: ' . count($overlap) . '/' . count($modeloTables) . PHP_EOL;
echo 'gmodelo-only sample: ' . implode(',', array_slice(array_values(array_diff($modeloTables, array_keys($sourceTables))), 0, 30)) . PHP_EOL;
$routineCounts = $modelo->query("SELECT routine_type, COUNT(*) total FROM information_schema.routines WHERE routine_schema='gmodelo_dbp' GROUP BY routine_type")->fetchAll();
echo 'gmodelo_dbp.routines: ' . json_encode($routineCounts, JSON_UNESCAPED_UNICODE) . PHP_EOL;
$modelRoutineNames = $modelo->query("SELECT routine_name FROM information_schema.routines WHERE routine_schema='gmodelo_dbp'")->fetchAll(PDO::FETCH_COLUMN);
$legacyRoutineNames = [];
foreach ([['LEGADO_MODELO', 'green_atlas'], ['LEGADO_SEGURANCA', 'green_atlas_seguranca']] as [$prefix, $name]) { $source = db($env, $prefix, $name); foreach ($source->query("SELECT routine_name FROM information_schema.routines WHERE routine_schema=" . $source->quote($name))->fetchAll(PDO::FETCH_COLUMN) as $routine) $legacyRoutineNames[$routine] = true; }
echo 'routine overlap template/legacy: ' . count(array_intersect($modelRoutineNames, array_keys($legacyRoutineNames))) . PHP_EOL;
