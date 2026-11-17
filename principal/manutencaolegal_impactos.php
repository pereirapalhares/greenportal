<?php
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
ManutencaoLegalCadastroGrid::render(['fragment_class' => 'laia-impactos-grid-fragment', 
    'menu' => 'IMPACTOS', 'title' => ['pt' => 'Impactos', 'en' => 'Impacts', 'es' => 'Impactos'],
    'transaction' => 'manutencaolegal_impactos', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT',
    'table' => 'impactos', 'view' => 'vw_impactos_grid', 'primary' => 'CODIMPACTO',
    'default_sort' => ['column' => 'DSCIMPACTO', 'direction' => 'asc'],
    'fields' => [
        'CODIMPACTO' => ['label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'], 'readonly' => true],
        'DSCIMPACTO' => ['label' => ['pt' => 'Impacto', 'en' => 'Impact', 'es' => 'Impacto'], 'type' => 'textarea'],
    ],
]);

// Barra de botões em grupos, no mesmo padrão da LAIA (Operações; Correlacionamentos e Avaliação
// ficam ocultos enquanto a tela não tiver botões desses grupos).
require_once dirname(__DIR__) . '/components/toolbar_grupos.php';
greenPortalToolbarGrupos('.laia-impactos-grid-fragment', []);
