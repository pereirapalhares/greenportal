<?php
declare(strict_types=1);

/** Reaplica os textos UTF-8 das cargas de ajuda nos registros já existentes. */
$env = [];
foreach (file(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}
$dsn = 'mysql:host=' . ($env['GUNIVERSAL_DB_HOST'] ?? '127.0.0.1') . ';port=' . ($env['GUNIVERSAL_DB_PORT'] ?? '3306') . ';dbname=guniversal_dbp;charset=utf8mb4';
$pdo = new PDO($dsn, $env['GUNIVERSAL_DB_USERNAME'] ?? '', $env['GUNIVERSAL_DB_PASSWORD'] ?? '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$files = [__DIR__ . '/066_ajuda_transacoes.sql', __DIR__ . '/067_ajuda_transacoes_complementar.sql'];
foreach ($files as $file) {
    $sql = file_get_contents($file);
    if ($sql === false) throw new RuntimeException('Não foi possível ler ' . $file);
    $sql = preg_split('/\RINSERT INTO `(?:menus|gmodelo_dbp)`/i', $sql, 2)[0];
    $sql = preg_replace('/^USE `guniversal_dbp`;\s*/i', '', $sql) ?? $sql;
    $sql = preg_replace('/INSERT IGNORE INTO/i', 'INSERT INTO', $sql) ?? $sql;
    $sql = preg_replace('/\s+ON DUPLICATE KEY UPDATE.*$/is', '', $sql) ?? $sql;
    $sql = rtrim($sql, " ;\r\n") . ' ON DUPLICATE KEY UPDATE '
        . 'titulo_pt=VALUES(titulo_pt),titulo_en=VALUES(titulo_en),titulo_es=VALUES(titulo_es),'
        . 'texto_pt=VALUES(texto_pt),texto_en=VALUES(texto_en),texto_es=VALUES(texto_es),ativo=VALUES(ativo)';
    $pdo->exec($sql);
}

echo 'ajuda_transacoes corrigida: ' . $pdo->query('SELECT COUNT(*) FROM ajuda_transacoes')->fetchColumn() . " registros\n";
