<?php
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';

ManutencaoLegalCadastroGrid::render(['fragment_class' => 'laia-aspectos-grid-fragment', 
    'menu' => 'ASPECTOS',
    'title' => ['pt' => 'Aspectos', 'en' => 'Aspects', 'es' => 'Aspectos'],
    'transaction' => 'manutencaolegal_aspectos',
    'database' => 'gmodelo_dbp',
    'prefix' => 'GPT',
    'table' => 'aspectos',
    'view' => 'vw_aspectos_grid',
    'primary' => 'CODASPECTO',
    'default_sort' => ['column' => 'DSCASPECTO', 'direction' => 'asc'],
    'fields' => [
        'CODASPECTO' => ['label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'], 'readonly' => true],
        'DSCASPECTO' => ['label' => ['pt' => 'Aspecto', 'en' => 'Aspect', 'es' => 'Aspecto'], 'type' => 'textarea'],
    ],
]);

// Barra de botões em grupos, no mesmo padrão da LAIA (Operações; Correlacionamentos e Avaliação
// ficam ocultos enquanto a tela não tiver botões desses grupos).
require_once dirname(__DIR__) . '/components/toolbar_grupos.php';
greenPortalToolbarGrupos('.laia-aspectos-grid-fragment', []);
