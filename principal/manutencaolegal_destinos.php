<?php
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
ManutencaoLegalCadastroGrid::render(['fragment_class' => 'laia-destinos-grid-fragment', 'menu' => 'LAIA_DESTINOS', 'title' => ['pt' => 'Destinos', 'en' => 'Destinations', 'es' => 'Destinos'], 'transaction' => 'manutencaolegal_destinos', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT', 'table' => 'destinos', 'view' => 'vw_destinos_grid', 'primary' => 'CODDESTINO', 'default_sort' => ['column' => 'DSCDESTINO', 'direction' => 'asc'], 'fields' => ['CODDESTINO' => ['label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'], 'readonly' => true], 'DSCDESTINO' => ['label' => ['pt' => 'Destino', 'en' => 'Destination', 'es' => 'Destino'], 'type' => 'textarea']]]);

// Barra de botões em grupos, no mesmo padrão da LAIA (Operações; Correlacionamentos e Avaliação
// ficam ocultos enquanto a tela não tiver botões desses grupos).
require_once dirname(__DIR__) . '/components/toolbar_grupos.php';
greenPortalToolbarGrupos('.laia-destinos-grid-fragment', []);
