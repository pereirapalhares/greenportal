<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
$env = GreenPortalDatabase::env();
$db = $argv[1] ?? 'gatlas_dbp';
$pdo = GreenPortalDatabase::connect($env, $db, 'GPT');
$result = ['database' => $db, 'filas' => []];
foreach (['documentos' => 'CODDOCUMENTO', 'li_licencas' => 'CODLICENCA', 'ml_treinamentos' => 'CODTREINAMENTO', 'ml_infraestrutura' => 'CODINFRAESTRUTURA'] as $table => $key) {
    try { $result['registros'][$table] = $pdo->query("SELECT `{$key}` FROM `{$table}` ORDER BY `{$key}` LIMIT 5")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable) { $result['registros'][$table] = []; }
}
$gedRoot = 'E:\\projetoGreen\\GED\\green_atlas';
foreach (['documentos' => 'CODDOCUMENTO', 'licencas' => 'CODLICENCA', 'ml_treinamentos' => 'CODTREINAMENTO', 'ml_infraestrutura' => 'CODINFRAESTRUTURA'] as $folder => $key) {
    $table = $folder === 'licencas' ? 'li_licencas' : $folder;
    try { $ids = $pdo->query("SELECT `{$key}` FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN); $result['ged_intersecoes'][$folder] = array_values(array_filter($ids, static fn($id) => is_dir($gedRoot . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR . (int)$id))); } catch (Throwable) { $result['ged_intersecoes'][$folder] = []; }
}
foreach (['green_ia_document_jobs', 'ged_analises_ia', 'green_ocr_jobs', 'ml_potenciais_solicitacoes', 'exportacoes_grid'] as $table) {
    try {
        $q = $pdo->query("SELECT status, erro, COUNT(*) qtd FROM `{$table}` WHERE status IN ('ERRO','FALHA') GROUP BY status, erro ORDER BY qtd DESC LIMIT 10");
        $result['filas'][$table] = $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $result['filas'][$table] = ['erro_consulta' => $e->getMessage()]; }
}
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
