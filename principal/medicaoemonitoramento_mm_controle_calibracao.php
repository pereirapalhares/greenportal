<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';

ManutencaoLegalCadastroGrid::render([
    'menu' => 'MM_CONTROLE_CALIBRACAO',
    'title' => [
        'pt' => 'Controle de Calibração',
        'en' => 'Calibration Control',
        'es' => 'Control de Calibración',
    ],
    'transaction' => 'medicaoemonitoramento_mm_controle_calibracao',
    'database' => 'gmodelo_dbp',
    'prefix' => 'GPT',
    'table' => 'mm_controle_calibracao',
    'view' => 'vw_medicao_monitoramento_controle_calibracao_grid',
    'primary' => 'CODCONTROLE',
    'primary_auto' => true,
    'primary_editable' => false,
    'legacy_order' => ['CODEQUIPAMENTO', 'FAIXA_DE_INDICACAO', 'FAIXA_DE_USO', 'CRITERIO_DE_ACELERACAO', 'TOLERANCIA', 'OBSERVACAO', 'CODUSUARIO', 'DATA_CALIBRACAO', 'DATA_REACAO', 'VALIDADE', 'STATUS', 'CODCONTROLE'],
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
        'CODEQUIPAMENTO' => [
            'label' => ['pt' => 'Equipamento', 'en' => 'Equipment', 'es' => 'Equipo'],
            'filter' => 'select',
            'width' => 220,
            'foreign' => [
                'view' => 'mm_equipamento',
                'value' => 'CODEQUIPAMENTO',
                'label' => 'DSCEQUIPAMENTO',
                'description' => 'DSCEQUIPAMENTO',
            ],
        ],
        'FAIXA_DE_INDICACAO' => [
            'label' => ['pt' => 'Faixa de indicação', 'en' => 'Indication range', 'es' => 'Rango de indicación'],
            'type' => 'textarea', 'filter' => 'text', 'width' => 220,
        ],
        'FAIXA_DE_USO' => [
            'label' => ['pt' => 'Faixa de uso', 'en' => 'Operating range', 'es' => 'Rango de uso'],
            'type' => 'textarea', 'filter' => 'text', 'width' => 220,
        ],
        'CRITERIO_DE_ACELERACAO' => [
            'label' => ['pt' => 'Critério de aceitação', 'en' => 'Acceptance criterion', 'es' => 'Criterio de aceptación'],
            'type' => 'textarea', 'filter' => 'text', 'width' => 240,
        ],
        'TOLERANCIA' => [
            'label' => ['pt' => 'Tolerância', 'en' => 'Tolerance', 'es' => 'Tolerancia'],
            'type' => 'textarea', 'filter' => 'text', 'width' => 180,
        ],
        'OBSERVACAO' => [
            'label' => ['pt' => 'Observação', 'en' => 'Notes', 'es' => 'Observación'],
            'type' => 'textarea', 'filter' => 'text', 'width' => 260,
        ],
        'CODUSUARIO' => [
            'label' => ['pt' => 'Responsável', 'en' => 'Responsible', 'es' => 'Responsable'],
            'filter' => 'select',
            'width' => 200,
            'foreign' => [
                'view' => 'vw_usuarios_grid',
                'value' => 'codusuario',
                'label' => 'nome',
                'filters' => ['ativo' => 1],
                'description' => 'NOME_RESPONSAVEL',
            ],
        ],
        'DATA_CALIBRACAO' => [
            'label' => ['pt' => 'Data de calibração', 'en' => 'Calibration date', 'es' => 'Fecha de calibración'],
            'type' => 'date', 'filter' => 'text', 'display_format' => 'date', 'width' => 160,
        ],
        'DATA_REACAO' => [
            'label' => ['pt' => 'Data de reação', 'en' => 'Reaction date', 'es' => 'Fecha de reacción'],
            'type' => 'date', 'filter' => 'text', 'display_format' => 'date', 'width' => 160,
        ],
        'VALIDADE' => [
            'label' => ['pt' => 'Validade', 'en' => 'Validity', 'es' => 'Validez'],
            'type' => 'date', 'filter' => 'text', 'display_format' => 'date', 'width' => 160,
        ],
        'STATUS' => [
            'label' => ['pt' => 'Status', 'en' => 'Status', 'es' => 'Estado'],
            'filter' => 'text', 'width' => 150,
        ],
        'CODCONTROLE' => [
            'label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'],
            'hidden' => true, 'readonly' => true, 'filter' => 'none',
        ],
    ],
]);
