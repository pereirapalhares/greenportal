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
    'title' => ['pt' => 'Treinamentos', 'en' => 'Training', 'es' => 'Capacitaciones'],
    'transaction' => 'manutencaolegalrequisitoslegais_treinamentos',
    'questionnaire_context' => 'SGA',
    'questionnaire_origin_fields' => ['CODLEGISLACAO', 'CODUNIDADE'],
    'database' => 'gmodelo_dbp',
    'prefix' => 'GPT',
    'table' => 'legislacaoxtreinamentos',
    'view' => 'vw_modulolegal_requisitos_treinamentos_grid',
    'primary' => 'CODRELACAOTREINAMENTO',
    'primary_editable' => false,
    'unique_context_field' => 'CODTREINAMENTO',
    'button_options' => [
        'save' => true,
        'new' => true,
        'delete' => true,
        'reload' => true,
        'export' => true,
        'columns' => true,
    ],
    'fields' => [
        'CODTREINAMENTO' => [
            'label' => ['pt' => 'Treinamento', 'en' => 'Training', 'es' => 'Capacitaciones'],
            'foreign' => [
                'view' => 'ml_treinamentos',
                'value' => 'codtreinamento',
                'label' => 'dsctreinamento',
            ],
        ],
        // A descrição é retornada apenas como apoio técnico pela view. A
        // associação exige somente a seleção do treinamento.
        'DSCTREINAMENTO' => [
            'label' => ['pt' => 'Descrição', 'en' => 'Description', 'es' => 'Descripciones'],
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
