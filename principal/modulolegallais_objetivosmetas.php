<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
$incoming = json_decode((string)($_GET['filtros'] ?? '{}'), true);
$incoming = is_array($incoming) ? $incoming : [];
$filters = [];
if (isset($incoming['CODASPECTOSOCIAL']) && trim((string)$incoming['CODASPECTOSOCIAL']) !== '') $filters['CODASPECTOSOCIAL'] = trim((string)$incoming['CODASPECTOSOCIAL']);
if (isset($incoming['CODUNIDADE']) && trim((string)$incoming['CODUNIDADE']) !== '') $filters['codunidade'] = trim((string)$incoming['CODUNIDADE']);
$_GET['filtros'] = json_encode($filters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

ManutencaoLegalCadastroGrid::render([
    'menu' => 'LAIS_OBJETIVOS_METAS', 'title' => ['pt' => 'LAIS - Objetivos e metas', 'en' => 'LAIS - Objectives and targets', 'es' => 'LAIS - Objetivos y metas'],
    'transaction' => 'moduloleglais_objetivosmetas', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT', 'table' => 'objetivosmetas', 'view' => 'vw_lais_objetivos_metas_grid', 'primary' => 'CODOBJETIVO',
    'context_or_fields' => ['CODASPECTOSOCIAL'], 'button_options' => ['save' => false, 'new' => false, 'duplicate' => false, 'delete' => false, 'reload' => true, 'export' => true, 'columns' => true],
    'legacy_order' => ['linha', 'codigo', 'item_atendido_da_politica', 'objetivo', 'meta', 'indicador', 'lider', 'tipo_estrategico_tatico', 'CODOBJETIVO', 'CODASPECTOSOCIAL', 'codunidade'], 'legacy_fixed' => true,
    'fields' => [
        'linha' => ['label' => ['pt' => '', 'en' => '', 'es' => ''], 'hidden' => true, 'readonly' => true, 'filter' => 'none'],
        'codigo' => ['label' => ['pt' => 'Código do Objetivo', 'en' => 'Objective code', 'es' => 'Código del objetivo'], 'readonly' => true, 'filter' => 'text', 'width' => 150],
        'item_atendido_da_politica' => ['label' => ['pt' => 'Item Atendido da Política', 'en' => 'Policy item', 'es' => 'Ítem de política'], 'readonly' => true, 'filter' => 'text', 'width' => 300],
        'objetivo' => ['label' => ['pt' => 'Objetivo', 'en' => 'Objective', 'es' => 'Objetivo'], 'readonly' => true, 'filter' => 'text', 'width' => 300],
        'meta' => ['label' => ['pt' => 'Meta', 'en' => 'Target', 'es' => 'Meta'], 'readonly' => true, 'filter' => 'text', 'width' => 300],
        'indicador' => ['label' => ['pt' => 'Indicador', 'en' => 'Indicator', 'es' => 'Indicador'], 'readonly' => true, 'filter' => 'text', 'width' => 200],
        'lider' => ['label' => ['pt' => 'Líder', 'en' => 'Leader', 'es' => 'Líder'], 'readonly' => true, 'filter' => 'text', 'width' => 200],
        'tipo_estrategico_tatico' => ['label' => ['pt' => 'Estratégico / Tático', 'en' => 'Strategic / Tactical', 'es' => 'Estratégico / Táctico'], 'readonly' => true, 'filter' => 'text', 'width' => 90],
        'CODOBJETIVO' => ['label' => ['pt' => 'Código técnico', 'en' => 'Technical code', 'es' => 'Código técnico'], 'hidden' => true, 'readonly' => true, 'filter' => 'none'],
        'CODASPECTOSOCIAL' => ['label' => ['pt' => 'Aspecto social', 'en' => 'Social aspect', 'es' => 'Aspecto social'], 'hidden' => true, 'readonly' => true, 'filter' => 'none'],
        'codunidade' => ['label' => ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad'], 'hidden' => true, 'readonly' => true, 'filter' => 'none'],
    ],
]);
