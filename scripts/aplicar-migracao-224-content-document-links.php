<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$sql = file_get_contents(dirname(__DIR__) . '/database/224_content_document_links.sql');
if ($sql === false) throw new RuntimeException('Migração 224 não localizada.');

$databases = ['gatlas_dbp', 'gmodelo_dbp', 'gmodelo2_dbp'];
$result = [];

foreach ($databases as $database) {
    $pdo = GreenPortalDatabase::connect($env, $database, 'GPT');
    $pdo->exec('USE `' . $database . '`; ' . $sql);
    $result[$database] = [
        'tabela' => (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='content_document_links'")->fetchColumn(),
        'vinculos' => (int)$pdo->query('SELECT COUNT(*) FROM content_document_links')->fetchColumn(),
        'indices' => (int)$pdo->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='content_document_links'")->fetchColumn(),
        'migracao' => (int)$pdo->query("SELECT COUNT(*) FROM registros_migracao WHERE codigo_migracao='GREEN_CONTENT_DOCUMENT_LINKS_224' AND status='CONCLUIDA'")->fetchColumn(),
    ];
}

echo json_encode(['ok' => true, 'migration' => 224, 'targets' => $result], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
