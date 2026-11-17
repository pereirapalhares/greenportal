<?php
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';

ManutencaoLegalCadastroGrid::render(['fragment_class' => 'laia-revisaolaia-grid-fragment', 
    'menu' => 'LAIA_REVISAO',
    'title' => ['pt' => 'Revisão do LAIA', 'en' => 'LAIA Review', 'es' => 'Revisión del LAIA'],
    'transaction' => 'manutencaolegal_revisaolaia',
    'database' => 'gmodelo_dbp',
    'prefix' => 'GPT',
    'table' => 'lais_revisao',
    'view' => 'vw_lais_revisao_grid',
    'primary' => 'CODREVISAO',
    'primary_auto' => true,
    'primary_editable' => false,
    'fields' => [
        'CODREVISAO' => ['label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'], 'readonly' => true, 'hidden' => true, 'filter' => 'none'],
        'DSCREVISAO' => ['label' => ['pt' => 'Revisão', 'en' => 'Review', 'es' => 'Revisión'], 'type' => 'textarea'],
        'DATA' => ['label' => ['pt' => 'Data', 'en' => 'Date', 'es' => 'Fecha'], 'type' => 'date'],
    ],
]);

// Barra de botões em grupos, no mesmo padrão da LAIA (Operações; Correlacionamentos e Avaliação
// ficam ocultos enquanto a tela não tiver botões desses grupos).
require_once dirname(__DIR__) . '/components/toolbar_grupos.php';
greenPortalToolbarGrupos('.laia-revisaolaia-grid-fragment', []);
