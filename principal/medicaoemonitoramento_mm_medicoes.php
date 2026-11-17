<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';

ManutencaoLegalCadastroGrid::render([
    'menu' => 'MM_MEDICOES',
    'title' => [
        'pt' => 'Medição',
        'en' => 'Measurement',
        'es' => 'Medición',
    ],
    'transaction' => 'medicaoemonitoramento_mm_medicoes',
    'database' => 'gmodelo_dbp',
    'prefix' => 'GPT',
    'table' => 'mm_medicao',
    'view' => 'vw_medicao_monitoramento_medicoes_grid',
    'primary' => 'CODMEDICAO',
    'primary_auto' => true,
    'primary_editable' => false,
    'legacy_order' => ['DSCMEDICAO', 'CODMEDICAO'],
    'button_options' => [
        'save' => true,
        'new' => true,
        'duplicate' => true,
        'delete' => true,
        'reload' => true,
        'export' => true,
        'columns' => true,
    ],
    'fields' => [
        'DSCMEDICAO' => [
            'label' => ['pt' => 'Medição', 'en' => 'Measurement', 'es' => 'Medición'],
            'type' => 'textarea',
            'filter' => 'text',
            'width' => 520,
        ],
        'CODMEDICAO' => [
            'label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'],
            'hidden' => true,
            'readonly' => true,
            'filter' => 'none',
        ],
    ],
]);
