<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';

ManutencaoLegalCadastroGrid::render(['fragment_class' => 'lpro-revisaolpro-grid-fragment', 
    'menu' => 'LPRO_REVISAO',
    'title' => ['pt' => 'Revisão do LPRO', 'en' => 'LPRO Review', 'es' => 'Revisión del LPRO'],
    'transaction' => 'manutencaolegal_revisaolpro',
    'database' => 'gmodelo_dbp',
    'prefix' => 'GPT',
    'table' => 'levantamentoocupacionalxrevisoes',
    'view' => 'vw_lpro_revisao_grid',
    'primary' => 'CHAVE',
    'primary_auto' => true,
    'primary_editable' => false,
    'fields' => [
        'CHAVE' => ['label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'], 'readonly' => true, 'hidden' => true, 'filter' => 'none'],
        'DSCREVISAO' => ['label' => ['pt' => 'Revisão', 'en' => 'Review', 'es' => 'Revisión'], 'type' => 'textarea'],
        'DATA' => ['label' => ['pt' => 'Data', 'en' => 'Date', 'es' => 'Fecha'], 'type' => 'date'],
    ],
]);

// Barra de botões em grupos, no mesmo padrão da LAIA (Operações; Correlacionamentos e Avaliação
// ficam ocultos enquanto a tela não tiver botões desses grupos).
require_once dirname(__DIR__) . '/components/toolbar_grupos.php';
greenPortalToolbarGrupos('.lpro-revisaolpro-grid-fragment', []);
