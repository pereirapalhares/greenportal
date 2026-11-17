<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$targets = ['gatlas_dbp', 'gmodelo_dbp', 'gmodelo2_dbp'];
$checks = [];
$failures = [];
$check = static function (string $scope, string $name, bool $ok, mixed $detail = null) use (&$checks, &$failures): void {
    $item = ['escopo' => $scope, 'teste' => $name, 'ok' => $ok];
    if ($detail !== null) $item['detalhe'] = $detail;
    $checks[] = $item;
    if (!$ok) $failures[] = $item;
};

$universal = GreenPortalDatabase::connect($env, $env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp', 'GUNIVERSAL');
$menuRows = $universal->query("SELECT codigo,codmenu,codmenu_pai,url,label_pt,label_en,label_es,ativo FROM menus WHERE codigo IN ('DOCUMENTOS_COMPARTILHADOS','REQUISITOS_SEM_EVIDENCIA') ORDER BY codigo")->fetchAll(PDO::FETCH_ASSOC);
$check('universal', 'menus da Fase 5 cadastrados', count($menuRows) === 2, $menuRows);
$check('universal', 'menus com pai no Módulo Legal', count($menuRows) === 2 && count(array_unique(array_map(static fn(array $r): int => (int)$r['codmenu_pai'], $menuRows))) === 1 && (int)$menuRows[0]['codmenu_pai'] === (int)$universal->query("SELECT codmenu FROM menus WHERE codigo='MODULO_LEGAL' LIMIT 1")->fetchColumn(), $menuRows);
$check('universal', 'menus com labels PT/EN/ES', count($menuRows) === 2 && !array_filter($menuRows, static fn(array $r): bool => trim((string)$r['label_pt']) === '' || trim((string)$r['label_en']) === '' || trim((string)$r['label_es']) === ''), $menuRows);
$check('universal', 'menus com URLs distintas', count($menuRows) === 2 && count(array_unique(array_column($menuRows, 'url'))) === 2, array_column($menuRows, 'url'));

foreach ($targets as $database) {
    $pdo = GreenPortalDatabase::connect($env, $database, 'GPT');
    $scope = $database;
    foreach (['content_documents','content_versions','content_document_links','li_tipos_documentos','logs_auditoria'] as $table) {
        $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $q->execute([$table]);
        $check($scope, "tabela $table existente", (int)$q->fetchColumn() === 1);
    }
    foreach (['vw_controle_documentos_links','vw_licencas_grid','vw_documentos_compartilhados_grid','vw_requisitos_sem_evidencia_grid'] as $view) {
        $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.views WHERE table_schema=DATABASE() AND table_name=?");
        $q->execute([$view]);
        $check($scope, "view $view existente", (int)$q->fetchColumn() === 1);
    }
    foreach (['pode_visualizar_total','pode_importar_arquivos','pode_visualizar_arquivos'] as $column) {
        $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='permissoes' AND column_name=?");
        $q->execute([$column]);
        $check($scope, "permissão $column existente", (int)$q->fetchColumn() === 1);
    }
    $migrationCodes = ['GREEN_CONTENT_DOCUMENT_LINKS_224','GREEN_CONTROL_DOCUMENT_LINKS_225','GREEN_LICENSE_DOCUMENT_TYPES_226','GREEN_LICENSE_TYPES_CATALOG_227','GREEN_PHASE5_REPORTS_231'];
    foreach ($migrationCodes as $code) {
        $q = $pdo->prepare("SELECT COUNT(*) FROM registros_migracao WHERE codigo_migracao=? AND status='CONCLUIDA'");
        $q->execute([$code]);
        $check($scope, "migração $code concluída", (int)$q->fetchColumn() >= 1);
    }
    $check($scope, 'índice de unicidade dos vínculos documentais', (int)$pdo->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='content_document_links' AND index_name='uk_content_document_link'")->fetchColumn() > 0);
    $check($scope, 'vínculos documentais sem documento órfão', (int)$pdo->query("SELECT COUNT(*) FROM content_document_links l LEFT JOIN content_documents d ON d.coddocumento=l.coddocumento WHERE d.coddocumento IS NULL")->fetchColumn() === 0);
    $check($scope, 'cada base possui no máximo uma versão ativa por licença', (int)$pdo->query("SELECT COUNT(*) FROM (SELECT CODLICENCA_BASE FROM li_licencas WHERE STATUS_VERSAO='ATIVA' GROUP BY CODLICENCA_BASE HAVING COUNT(*)>1) x")->fetchColumn() === 0);
    $check($scope, 'relatório de documentos compartilhados respeita mínimo de dois vínculos', (int)$pdo->query('SELECT COUNT(*) FROM vw_documentos_compartilhados_grid WHERE QTD_VINCULOS < 2')->fetchColumn() === 0);
    $check($scope, 'relatório de requisitos sem evidência não exibe requisito com evidência', (int)$pdo->query("SELECT COUNT(*) FROM vw_requisitos_sem_evidencia_grid v WHERE EXISTS (SELECT 1 FROM content_document_links l WHERE l.codrequisitolegal=v.CODLEGISLACAO AND l.codunidade=v.CODUNIDADE AND l.status='ATIVO' AND l.tipo_entidade IN ('LICENCA_REQUISITO','CONTROLE_REQUISITO','REQUISITO_LEGAL'))")->fetchColumn() === 0);
    $check($scope, 'auditoria possui registros', (int)$pdo->query('SELECT COUNT(*) FROM logs_auditoria')->fetchColumn() > 0);
}

$result = ['ok' => $failures === [], 'fase' => 6, 'testes' => count($checks), 'falhas' => count($failures), 'checks' => $checks];
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
exit($failures === [] ? 0 : 1);
