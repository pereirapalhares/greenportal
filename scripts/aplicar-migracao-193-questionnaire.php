<?php
declare(strict_types=1);

require_once __DIR__ . '/../classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$universalSql = file_get_contents(__DIR__ . '/../database/193_questionnaire_universal.sql');
$tenantSql = file_get_contents(__DIR__ . '/../database/193_questionnaire_tenant.sql');
if ($universalSql === false || $tenantSql === false) {
    throw new RuntimeException('Scripts SQL da migração 193 não encontrados.');
}

$universal = GreenPortalDatabase::connect($env, 'guniversal_dbp', 'GUNIVERSAL');
$universal->exec('USE `guniversal_dbp`; ' . $universalSql);

$tenants = ['gatlas_dbp', 'gmodelo_dbp', 'gmodelo2_dbp'];
$result = ['universal' => 'CONCLUIDA', 'tenants' => []];
foreach ($tenants as $database) {
    $pdo = GreenPortalDatabase::connect($env, $database, 'GPT');
    $pdo->exec('USE `' . $database . '`; ' . $tenantSql);
    $check = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_name IN
         ('amb_questionario_execucoes','amb_questionario_respostas','amb_questionario_resultados','amb_questionario_evidencias')"
    );
    $result['tenants'][$database] = [
        'status' => 'CONCLUIDA',
        'tabelas' => (int)$check->fetchColumn(),
        'migracao' => (int)$pdo->query("SELECT COUNT(*) FROM registros_migracao WHERE codigo_migracao='GREEN_QUESTIONNAIRE_TENANT_193'")->fetchColumn(),
    ];
}

$result['universal_tabelas'] = (int)$universal->query(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema=DATABASE() AND table_name IN
     ('amb_questionario','amb_questionario_versao','amb_questionario_bloco','amb_questionario_pergunta','amb_questionario_opcao','amb_questionario_regra','amb_questionario_publicacao')"
)->fetchColumn();
$result['universal_migracao'] = (int)$universal->query("SELECT COUNT(*) FROM green_universal_migrations WHERE codigo_migracao='GREEN_QUESTIONNAIRE_UNIVERSAL_193'")->fetchColumn();
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
