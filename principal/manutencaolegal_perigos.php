<?php
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
ManutencaoLegalCadastroGrid::render(['fragment_class' => 'lpro-perigos-grid-fragment', 
    'menu' => 'LPRO_PERIGOS', 'title' => ['pt' => 'Perigos', 'en' => 'Hazards', 'es' => 'Peligros'],
    'transaction' => 'manutencaolegal_perigos', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT',
    'table' => 'perigos', 'view' => 'vw_perigos_grid', 'primary' => 'CODPERIGO',
    'default_sort' => ['column' => 'DSCPERIGO', 'direction' => 'asc'],
    'fields' => [
        'CODPERIGO' => ['label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'], 'readonly' => true],
        'DSCPERIGO' => ['label' => ['pt' => 'Perigo', 'en' => 'Hazard', 'es' => 'Peligro'], 'type' => 'textarea'],
    ],
]);

// Barra de botões em grupos, no mesmo padrão da LAIA (Operações; Correlacionamentos e Avaliação
// ficam ocultos enquanto a tela não tiver botões desses grupos).
require_once dirname(__DIR__) . '/components/toolbar_grupos.php';
greenPortalToolbarGrupos('.lpro-perigos-grid-fragment', []);
