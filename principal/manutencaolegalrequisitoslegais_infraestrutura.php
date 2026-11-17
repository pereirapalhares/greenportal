<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';

$_GET['filtros'] = json_encode([
    'CODLEGISLACAO' => (int)($_GET['CODLEGISLACAO'] ?? 0),
    'CODUNIDADE' => (int)($_GET['CODUNIDADE'] ?? 0),
]);

ManutencaoLegalCadastroGrid::render([
    'context_crud' => true,
    'context_crud_keys' => ['CODLEGISLACAO', 'CODUNIDADE'],
    'menu' => 'REQUISITOS_LEGAIS',
    'title' => ['pt' => 'Infraestrutura', 'en' => 'Infrastructure', 'es' => 'Infraestructura'],
    'transaction' => 'manutencaolegalrequisitoslegais_infraestrutura',
    'questionnaire_context' => 'SGA',
    'questionnaire_origin_fields' => ['CODLEGISLACAO', 'CODUNIDADE'],
    'database' => 'gmodelo_dbp',
    'prefix' => 'GPT',
    'table' => 'legislacaoxinfraestruturas',
    'view' => 'vw_modulolegal_requisitos_infraestrutura_grid',
    'primary' => 'CODRELACAOINFRAESTRUTURA',
    'primary_editable' => false,
    'unique_context_field' => 'CODINFRAESTRUTURA',
    'button_options' => [
        'save' => true,
        'new' => true,
        'delete' => true,
        'reload' => true,
        'export' => true,
        'columns' => true,
    ],
    'fields' => [
        'CODINFRAESTRUTURA' => [
            'label' => ['pt' => 'Infraestrutura', 'en' => 'Infrastructure', 'es' => 'Infraestructura'],
            'foreign' => [
                'view' => 'ml_infraestrutura',
                'value' => 'codinfraestrutura',
                'label' => 'dscinfraestrutura',
            ],
        ],
        'DSCINFRAESTRUTURA' => [
            'label' => ['pt' => 'Descrição', 'en' => 'Description', 'es' => 'Descripcion'],
            'readonly' => true,
            'hidden' => true,
            'filter' => 'none',
        ],
        'CODLEGISLACAO' => [
            'label' => ['pt' => 'Código da legislação', 'en' => 'Legislation code', 'es' => 'Codigo de legislacion'],
        ],
        'CODUNIDADE' => [
            'label' => ['pt' => 'Código da unidade', 'en' => 'Unit code', 'es' => 'Codigo de unidad'],
        ],
    ],
]);
