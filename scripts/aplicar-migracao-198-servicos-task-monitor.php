<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$env = GreenPortalDatabase::env();
$sql = file_get_contents(dirname(__DIR__) . '/database/198_servicos_task_monitor.sql');
if ($sql === false) throw new RuntimeException('Migração 198 não localizada.');
$pdo = GreenPortalDatabase::connect($env, 'guniversal_dbp', 'GUNIVERSAL');
$pdo->exec($sql);
$tables = [];
foreach (['servicos_task_status','servicos_fila_status','servicos_exportacoes_cache','servicos_monitoramento_execucoes'] as $table) {
    $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $q->execute([$table]); $tables[$table] = (int)$q->fetchColumn();
}
echo json_encode(['ok' => true, 'migration' => '198', 'tables' => $tables], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
