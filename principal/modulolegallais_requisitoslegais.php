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
    'menu' => 'LAIS_REQUISITOS_LEGAIS', 'title' => ['pt' => 'LAIS - Requisitos legais', 'en' => 'LAIS - Legal requirements', 'es' => 'LAIS - Requisitos legales'],
    'transaction' => 'moduloleglais_requisitoslegais', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT', 'table' => 'lais_legislacaoxaspectossociais', 'view' => 'vw_lais_requisitos_legais_grid', 'primary' => 'CODLEGISLACAO',
    'context_or_fields' => ['CODASPECTOSOCIAL', 'CODIMPACTOSOCIAL'], 'button_options' => ['save' => false, 'new' => false, 'duplicate' => false, 'delete' => false, 'reload' => true, 'export' => true, 'columns' => true],
    'legacy_order' => ['linha', 'LEGISLACAO', 'ARQUIVO', 'CODLEGISLACAO', 'CODIGO', 'CODASPECTOSOCIAL', 'CODIMPACTOSOCIAL', 'codunidade'], 'legacy_fixed' => true,
    'fields' => [
        'linha' => ['label' => ['pt' => '', 'en' => '', 'es' => ''], 'hidden' => true, 'readonly' => true, 'filter' => 'none'],
        'LEGISLACAO' => ['label' => ['pt' => 'Legislação', 'en' => 'Legislation', 'es' => 'Legislación'], 'readonly' => true, 'filter' => 'text', 'width' => 180],
        'ARQUIVO' => ['label' => ['pt' => 'Textos da lei', 'en' => 'Law texts', 'es' => 'Textos de la ley'], 'readonly' => true, 'filter' => 'text', 'width' => 220],
        'CODLEGISLACAO' => ['label' => ['pt' => 'Código técnico', 'en' => 'Technical code', 'es' => 'Código técnico'], 'hidden' => true, 'readonly' => true, 'filter' => 'none'],
        'CODIGO' => ['label' => ['pt' => 'Código da legislação', 'en' => 'Legislation code', 'es' => 'Código de legislación'], 'hidden' => true, 'readonly' => true, 'filter' => 'none'],
        'CODASPECTOSOCIAL' => ['label' => ['pt' => 'Aspecto social', 'en' => 'Social aspect', 'es' => 'Aspecto social'], 'hidden' => true, 'readonly' => true, 'filter' => 'none'],
        'CODIMPACTOSOCIAL' => ['label' => ['pt' => 'Impacto social', 'en' => 'Social impact', 'es' => 'Impacto social'], 'hidden' => true, 'readonly' => true, 'filter' => 'none'],
        'codunidade' => ['label' => ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad'], 'hidden' => true, 'readonly' => true, 'filter' => 'none'],
    ],
]);
