<?php
declare(strict_types=1);

/** Valida a convencao basica: uma transacao por arquivo PHP e sem nomes duplicados. */
$root = dirname(__DIR__);
$directory = $root . DIRECTORY_SEPARATOR . 'principal';
$files = glob($directory . DIRECTORY_SEPARATOR . '*.php') ?: [];
$issues = [];
$warnings = [];
$seen = [];
foreach ($files as $file) {
    $name = pathinfo($file, PATHINFO_FILENAME);
    if ($name === '' || !preg_match('/^[a-z0-9_]+$/', $name)) {
        $warnings[] = 'Legado fora do padrao, manter ate migracao de URL: ' . basename($file);
    }
    if (isset($seen[$name])) {
        $issues[] = 'Transacao duplicada: ' . $name;
    }
    $seen[$name] = true;
}
if ($issues) {
    fwrite(STDERR, implode(PHP_EOL, $issues) . PHP_EOL);
    exit(1);
}
foreach ($warnings as $warning) fwrite(STDOUT, 'AVISO: ' . $warning . PHP_EOL);
echo 'OK: ' . count($files) . ' arquivos PHP de transacao validados.' . PHP_EOL;
