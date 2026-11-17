<?php
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
ManutencaoLegalCadastroGrid::render(['fragment_class' => 'lpro-riscos-grid-fragment', 
    'menu' => 'LPRO_RISCOS', 'title' => ['pt' => 'Riscos', 'en' => 'Risks', 'es' => 'Riesgos'],
    'transaction' => 'manutencaolegal_riscos', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT',
    'table' => 'riscos', 'view' => 'vw_riscos_grid', 'primary' => 'CODRISCO',
    'default_sort' => ['column' => 'DSCRISCO', 'direction' => 'asc'],
    'fields' => [
        'CODRISCO' => ['label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'], 'readonly' => true],
        'DSCRISCO' => ['label' => ['pt' => 'Risco', 'en' => 'Risk', 'es' => 'Riesgo'], 'type' => 'textarea'],
    ],
]);

// Barra de botões em grupos, no mesmo padrão da LAIA (Operações; Correlacionamentos e Avaliação
// ficam ocultos enquanto a tela não tiver botões desses grupos).
require_once dirname(__DIR__) . '/components/toolbar_grupos.php';
greenPortalToolbarGrupos('.lpro-riscos-grid-fragment', []);
