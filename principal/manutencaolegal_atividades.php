<?php
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
ManutencaoLegalCadastroGrid::render(['fragment_class' => 'laia-atividades-grid-fragment', 'menu' => 'LAIA_ATIVIDADES', 'title' => ['pt' => 'Atividades', 'en' => 'Activities', 'es' => 'Actividades'], 'transaction' => 'manutencaolegal_atividades', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT', 'table' => 'atividades', 'view' => 'vw_atividades_grid', 'primary' => 'CODATIVIDADE', 'default_sort' => ['column' => 'DSCATIVIDADE', 'direction' => 'asc'], 'fields' => ['CODATIVIDADE' => ['label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'], 'readonly' => true], 'DSCATIVIDADE' => ['label' => ['pt' => 'Atividade', 'en' => 'Activity', 'es' => 'Actividad'], 'type' => 'textarea']]]);

// Barra de botões em grupos, no mesmo padrão da LAIA (Operações; Correlacionamentos e Avaliação
// ficam ocultos enquanto a tela não tiver botões desses grupos).
require_once dirname(__DIR__) . '/components/toolbar_grupos.php';
greenPortalToolbarGrupos('.laia-atividades-grid-fragment', []);
