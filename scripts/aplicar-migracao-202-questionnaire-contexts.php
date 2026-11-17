<?php
declare(strict_types=1);

require_once __DIR__ . '/../classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$universalSql = file_get_contents(__DIR__ . '/../database/202_questionnaire_context_catalog.sql');
$tenantSql = file_get_contents(__DIR__ . '/../database/202_questionnaire_context_workflows.sql');
if ($universalSql === false || $tenantSql === false) {
    throw new RuntimeException('Scripts SQL da migração 202 não encontrados.');
}

$universal = GreenPortalDatabase::connect($env, 'guniversal_dbp', 'GUNIVERSAL');
$universal->exec('USE `guniversal_dbp`; ' . $universalSql);

$tenants = ['gatlas_dbp', 'gmodelo_dbp', 'gmodelo2_dbp'];
$result = [
    'migration' => '202',
    'universal' => [
        'questionarios' => (int)$universal->query("SELECT COUNT(*) FROM amb_questionario WHERE codigo IN ('LEGAL_REQUIREMENTS','SGA_COMPLIANCE','QUALITY_AUDIT','WORKFLOW_CHECKLIST','SUPPLIER_HOMOLOGATION','ESG_ASSESSMENT','PROJECT_DELIVERABLES','EMPLOYEE_INTEGRATION')")->fetchColumn(),
        'versoes_rascunho' => (int)$universal->query("SELECT COUNT(*) FROM amb_questionario_versao v JOIN amb_questionario q ON q.codquestionario=v.codquestionario WHERE q.codigo IN ('LEGAL_REQUIREMENTS','SGA_COMPLIANCE','QUALITY_AUDIT','WORKFLOW_CHECKLIST','SUPPLIER_HOMOLOGATION','ESG_ASSESSMENT','PROJECT_DELIVERABLES','EMPLOYEE_INTEGRATION') AND v.versao=1 AND v.status='RASCUNHO'")->fetchColumn(),
        'migracao' => (int)$universal->query("SELECT COUNT(*) FROM green_universal_migrations WHERE codigo_migracao='GREEN_QUESTIONNAIRE_CONTEXT_CATALOG_202'")->fetchColumn(),
    ],
    'tenants' => [],
];

foreach ($tenants as $database) {
    $pdo = GreenPortalDatabase::connect($env, $database, 'GPT');
    $pdo->beginTransaction();
    try {
        $pdo->exec('USE `' . $database . '`; ' . $tenantSql);
        $migration = $pdo->prepare(
            "INSERT INTO registros_migracao
                (codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,iniciada_em,concluida_em,detalhes)
             VALUES
                ('GREEN_QUESTIONNAIRE_CONTEXTS_202','QuestionnaireService','workflow_definicoes/etapas/transicoes','CONFIGURACAO','4.0.1','CONCLUIDA',3,NOW(),NOW(),:detalhes)
             ON DUPLICATE KEY UPDATE status=VALUES(status),quantidade_registros=VALUES(quantidade_registros),concluida_em=NOW(),detalhes=VALUES(detalhes)"
        );
        $migration->execute(['detalhes' => json_encode([
            'contextos' => ['SUPPLIER', 'ESG', 'PROJECTS', 'EMPLOYEE_INTEGRATION'],
            'workflows_criados' => 3,
            'idempotente' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    $result['tenants'][$database] = [
        'workflows' => (int)$pdo->query("SELECT COUNT(*) FROM workflow_definicoes WHERE codigo IN ('LEGAL_QUESTIONNAIRE','SGA_QUESTIONNAIRE','QUALITY_QUESTIONNAIRE','WORKFLOW_QUESTIONNAIRE','SUPPLIER_QUESTIONNAIRE','ESG_QUESTIONNAIRE','PROJECTS_QUESTIONNAIRE','EMPLOYEE_INTEGRATION_QUESTIONNAIRE') AND publicado=1 AND ativo=1")->fetchColumn(),
        'migracao' => (int)$pdo->query("SELECT COUNT(*) FROM registros_migracao WHERE codigo_migracao='GREEN_QUESTIONNAIRE_CONTEXTS_202' AND status='CONCLUIDA'")->fetchColumn(),
    ];
}

echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
