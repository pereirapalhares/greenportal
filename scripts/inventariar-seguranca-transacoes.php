<?php
declare(strict_types=1);

$root = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'principal';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$files = [];
foreach ($iterator as $item) if ($item->isFile() && strtolower($item->getExtension()) === 'php') $files[] = $item->getPathname();
$migrated = [];
$pending = [];
foreach ($files as $file) {
    $source = (string)file_get_contents($file);
    $name = pathinfo($file, PATHINFO_FILENAME);
    if (str_contains($source, 'TransactionSecurity::connect(') || str_contains($source, 'TenantContext::connect(') || str_contains($source, 'TenantContext::database(')) $migrated[] = $name;
    else $pending[] = $name;
}
sort($migrated); sort($pending);
echo json_encode(['total' => count($files), 'migradas' => count($migrated), 'pendentes' => count($pending), 'transacoes_migradas' => $migrated, 'transacoes_pendentes' => $pending], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
