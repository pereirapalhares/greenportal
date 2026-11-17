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
    'title' => ['pt' => 'Homologação de Fornecedores', 'en' => 'Supplier approval', 'es' => 'Homologación de proveedores'],
    'transaction' => 'manutencaolegalrequisitoslegais_homologacao',
    'database' => 'gmodelo_dbp',
    'prefix' => 'GPT',
    'table' => 'legislacaoxfornecedores',
    'view' => 'vw_modulolegal_requisitos_homologacao_grid',
    'primary' => 'CODLEGISLACAOXFORNECEDOR',
    'primary_auto' => true,
    'primary_editable' => false,
    'unique_context_field' => 'CODFORNECEDOR',
    'fragment_class' => 'homologacao-fornecedores-grid-fragment',
    'button_options' => [
        'save' => true,
        'new' => true,
        'delete' => true,
        'reload' => true,
        'duplicate' => false,
        'export' => false,
        'columns' => false,
    ],
    'fields' => [
        'CODLEGISLACAOXFORNECEDOR' => [
            'label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'],
            'hidden' => true, 'readonly' => true, 'filter' => 'none',
        ],
        'CODFORNECEDOR' => [
            'label' => ['pt' => 'Razão Social', 'en' => 'Corporate name', 'es' => 'Razón social'],
            'filter' => 'none', 'width' => 320, 'force_visible' => true,
            'readonly' => true, 'display_from' => 'RAZAOSOCIAL', 'new_editable' => true,
            'foreign' => [
                'view' => 'vw_homologacao_fornecedores_grid',
                'value' => 'CODFORNECEDOR',
                'label' => 'RAZAOSOCIAL',
                'description' => 'RAZAOSOCIAL',
            ],
        ],
        'RAZAOSOCIAL' => [
            'label' => ['pt' => 'Razão Social', 'en' => 'Corporate name', 'es' => 'Razón social'],
            'hidden' => true, 'readonly' => true, 'filter' => 'none',
        ],
        'NOMEFANTASIA' => [
            'label' => ['pt' => 'Nome fantasia', 'en' => 'Trade name', 'es' => 'Nombre comercial'],
            'hidden' => true, 'readonly' => true, 'filter' => 'none',
        ],
        'ATIVADOFOR' => [
            'label' => ['pt' => 'Ativo', 'en' => 'Active', 'es' => 'Activo'],
            'hidden' => true, 'readonly' => true, 'filter' => 'none',
        ],
        'CODLEGISLACAO' => [
            'label' => ['pt' => 'Código da legislação', 'en' => 'Legislation code', 'es' => 'Código de legislación'],
            'hidden' => true, 'readonly' => true, 'filter' => 'none',
        ],
        'CODUNIDADE' => [
            'label' => ['pt' => 'Código da unidade', 'en' => 'Unit code', 'es' => 'Código de unidad'],
            'hidden' => true, 'readonly' => true, 'filter' => 'none',
        ],
    ],
]);
