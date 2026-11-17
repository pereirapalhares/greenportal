<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';

ManutencaoLegalCadastroGrid::render([
    'menu' => 'MM_EQUIPAMENTOS',
    'title' => [
        'pt' => 'Equipamentos',
        'en' => 'Equipment',
        'es' => 'Equipos',
    ],
    'transaction' => 'medicaoemonitoramento_equipamentos',
    'database' => 'gmodelo_dbp',
    'prefix' => 'GPT',
    'table' => 'mm_equipamento',
    'view' => 'vw_medicao_monitoramento_equipamentos_grid',
    'primary' => 'CODEQUIPAMENTO',
    'primary_auto' => true,
    'primary_editable' => false,
    'legacy_order' => ['CODUNIDADE', 'DSCEQUIPAMENTO', 'MARCA', 'SERIE', 'MODELO', 'IDENTIFICACAO', 'LOCALIZACAO', 'CODEQUIPAMENTO'],
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
        'CODUNIDADE' => [
            'label' => ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad'],
            'filter' => 'select',
            'width' => 190,
            'foreign' => [
                'view' => 'vw_unidades_grid',
                'value' => 'CODUNIDADE',
                'label' => 'DSCUNIDADE',
                'filters' => ['usado_em_manutencao_legal' => 'Sim'],
                'description' => 'DSCUNIDADE',
            ],
        ],
        'DSCEQUIPAMENTO' => [
            'label' => ['pt' => 'Equipamento', 'en' => 'Equipment', 'es' => 'Equipo'],
            'type' => 'textarea',
            'filter' => 'text',
            'width' => 260,
        ],
        'MARCA' => [
            'label' => ['pt' => 'Marca', 'en' => 'Brand', 'es' => 'Marca'],
            'filter' => 'text',
            'width' => 180,
        ],
        'SERIE' => [
            'label' => ['pt' => 'Série', 'en' => 'Serial number', 'es' => 'Serie'],
            'filter' => 'text',
            'width' => 180,
        ],
        'MODELO' => [
            'label' => ['pt' => 'Modelo', 'en' => 'Model', 'es' => 'Modelo'],
            'filter' => 'text',
            'width' => 180,
        ],
        'IDENTIFICACAO' => [
            'label' => ['pt' => 'Identificação', 'en' => 'Identification', 'es' => 'Identificación'],
            'filter' => 'text',
            'width' => 220,
        ],
        'LOCALIZACAO' => [
            'label' => ['pt' => 'Localização', 'en' => 'Location', 'es' => 'Ubicación'],
            'filter' => 'text',
            'width' => 220,
        ],
        'CODEQUIPAMENTO' => [
            'label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'],
            'hidden' => true,
            'readonly' => true,
            'filter' => 'none',
        ],
    ],
]);
