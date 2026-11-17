<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';

$in = json_decode((string)($_GET['filtros'] ?? '{}'), true);
$in = is_array($in) ? $in : [];
$f = [];
foreach (['CODATIVIDADE', 'CODUNIDADE'] as $key) {
    if (isset($in[$key]) && trim((string)$in[$key]) !== '') $f[$key] = trim((string)$in[$key]);
}
$_GET['filtros'] = json_encode($f, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$resp = htmlspecialchars(IdiomaService::t('modulolegalpro_infraestrutura.botao.responsabilidades', ['pt' => 'Responsabilidades', 'en' => 'Responsibilities', 'es' => 'Responsabilidades']), ENT_QUOTES, 'UTF-8');
$responsibilityButton = '<button class="btn btn-sm btn-outline-secondary grid-crud-button" type="button" data-context-child data-context-id="responsabilidades" data-context-url="/principal/infraestrutura_responsabilidades.php" data-context-fields="CODINFRAESTRUTURA" data-context-title="' . $resp . '" disabled title="' . $resp . '" aria-label="' . $resp . '"><i class="bi bi-people"></i></button>';

ManutencaoLegalCadastroGrid::render([
    'menu' => 'LPRO_INFRAESTRUTURA',
    'title' => ['pt' => 'LPRO - Infraestrutura', 'en' => 'LPRO - Infrastructure', 'es' => 'LPRO - Infraestructura'],
    'transaction' => 'modulolegalpro_infraestrutura',
    'database' => 'gmodelo_dbp',
    'prefix' => 'GPT',
    'table' => 'infraestrutura',
    'view' => 'vw_lpro_infraestrutura_grid',
    'primary' => 'CODINFRAESTRUTURA',
    'context_or_fields' => ['CODATIVIDADE'],
    'button_options' => ['save' => false, 'new' => false, 'duplicate' => false, 'delete' => false, 'reload' => true, 'export' => true, 'columns' => true],
    'extras' => $responsibilityButton,
    'fields' => [
        'DSCINFRAESTRUTURA' => ['label' => ['pt' => 'Infraestrutura', 'en' => 'Infrastructure', 'es' => 'Infraestructura'], 'readonly' => true, 'filter' => 'text', 'width' => 300],
        'CODINFRAESTRUTURA' => ['label' => ['pt' => 'Código técnico', 'en' => 'Technical code', 'es' => 'Código técnico'], 'hidden' => true, 'readonly' => true, 'filter' => 'none'],
        'CODATIVIDADE' => ['label' => ['pt' => 'Atividade', 'en' => 'Activity', 'es' => 'Actividad'], 'hidden' => true, 'readonly' => true, 'filter' => 'none'],
        'CODUNIDADE' => ['label' => ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad'], 'hidden' => true, 'readonly' => true, 'filter' => 'none'],
    ],
    'legacy_order' => ['DSCINFRAESTRUTURA', 'CODINFRAESTRUTURA', 'CODATIVIDADE', 'CODUNIDADE'],
    'legacy_fixed' => true,
]);
