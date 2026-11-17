<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
$incoming = json_decode((string)($_GET['filtros'] ?? '{}'), true);
$incoming = is_array($incoming) ? $incoming : [];
$filters = [];
foreach (['CODASPECTOSOCIAL', 'CODIMPACTOSOCIAL'] as $field) if (isset($incoming[$field]) && trim((string)$incoming[$field]) !== '') $filters[$field] = trim((string)$incoming[$field]);
if (isset($incoming['CODUNIDADE']) && trim((string)$incoming['CODUNIDADE']) !== '') $filters['codunidade'] = trim((string)$incoming['CODUNIDADE']);
$_GET['filtros'] = json_encode($filters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

ManutencaoLegalCadastroGrid::render([
    'menu' => 'LAIS_CONTROLES_DOCUMENTOS', 'title' => ['pt' => 'LAIS - Controles e documentos', 'en' => 'LAIS - Controls and documents', 'es' => 'LAIS - Controles y documentos'],
    'transaction' => 'moduloleglais_controlesdocumentos', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT', 'table' => 'lais_documentosxaspectos', 'view' => 'vw_lais_controles_documentos_grid', 'primary' => 'CODDOCUMENTO',
    'context_or_fields' => ['CODASPECTOSOCIAL', 'CODIMPACTOSOCIAL'], 'button_options' => ['save' => false, 'new' => false, 'duplicate' => false, 'delete' => false, 'reload' => true, 'export' => true, 'columns' => true],
    'legacy_order' => ['CODIGO', 'TITULO', 'ARQUIVO', 'CODDOCUMENTO', 'CODASPECTOSOCIAL', 'CODIMPACTOSOCIAL', 'codunidade'], 'legacy_fixed' => true,
    'fields' => [
        'CODIGO' => ['label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'], 'readonly' => true, 'filter' => 'text', 'width' => 180],
        'TITULO' => ['label' => ['pt' => 'Título', 'en' => 'Title', 'es' => 'Título'], 'readonly' => true, 'filter' => 'text', 'width' => 260],
        'ARQUIVO' => ['label' => ['pt' => 'Arquivo', 'en' => 'File', 'es' => 'Archivo'], 'readonly' => true, 'filter' => 'text', 'width' => 220],
        'CODDOCUMENTO' => ['label' => ['pt' => 'Código técnico', 'en' => 'Technical code', 'es' => 'Código técnico'], 'hidden' => true, 'readonly' => true, 'filter' => 'none'],
        'CODASPECTOSOCIAL' => ['label' => ['pt' => 'Aspecto social', 'en' => 'Social aspect', 'es' => 'Aspecto social'], 'hidden' => true, 'readonly' => true, 'filter' => 'none'],
        'CODIMPACTOSOCIAL' => ['label' => ['pt' => 'Impacto social', 'en' => 'Social impact', 'es' => 'Impacto social'], 'hidden' => true, 'readonly' => true, 'filter' => 'none'],
        'codunidade' => ['label' => ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad'], 'hidden' => true, 'readonly' => true, 'filter' => 'none'],
    ],
]);
