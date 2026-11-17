<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$sql = file_get_contents(dirname(__DIR__) . '/database/225_controle_documentos_links.sql');
if ($sql === false) throw new RuntimeException('Migração 225 não localizada.');

$databases = ['gatlas_dbp', 'gmodelo_dbp', 'gmodelo2_dbp'];
$result = [];

foreach ($databases as $database) {
    $pdo = GreenPortalDatabase::connect($env, $database, 'GPT');
    $pdo->exec('USE `' . $database . '`; ' . $sql);
    $result[$database] = [
        'documentos_logicos' => (int)$pdo->query("SELECT COUNT(*) FROM content_documents WHERE origem_entidade='documentos'")->fetchColumn(),
        'vinculos_controle' => (int)$pdo->query("SELECT COUNT(*) FROM content_document_links WHERE tipo_entidade LIKE 'CONTROLE%'")->fetchColumn(),
        'view' => (int)$pdo->query("SELECT COUNT(*) FROM information_schema.views WHERE table_schema=DATABASE() AND table_name='vw_controle_documentos_links'")->fetchColumn(),
        'migracao' => (int)$pdo->query("SELECT COUNT(*) FROM registros_migracao WHERE codigo_migracao='GREEN_CONTROL_DOCUMENT_LINKS_225' AND status='CONCLUIDA'")->fetchColumn(),
    ];
}

echo json_encode(['ok' => true, 'migration' => 225, 'targets' => $result], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
