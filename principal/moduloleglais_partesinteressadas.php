<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
ManutencaoLegalCadastroGrid::render(['fragment_class' => 'lais-partesinteressadas-grid-fragment', 'menu'=>'LAIS_PARTES_INTERESSADAS','title'=>['pt'=>'Parte Interessada','en'=>'Stakeholder','es'=>'Parte interesada'],'transaction'=>'moduloleglais_partesinteressadas','database'=>'gmodelo_dbp','prefix'=>'GPT','table'=>'lais_partes_interessadas','view'=>'lais_partes_interessadas','primary'=>'CODPARTE','primary_auto'=>true,'primary_editable'=>false,'button_options'=>['save'=>true,'new'=>true,'duplicate'=>true,'delete'=>true,'reload'=>true,'export'=>true,'columns'=>true],'fields'=>['CODPARTE'=>['label'=>['pt'=>'Código','en'=>'Code','es'=>'Código'],'hidden'=>true,'readonly'=>true,'filter'=>'none'],'DSCPARTE'=>['label'=>['pt'=>'Parte Interessada','en'=>'Stakeholder','es'=>'Parte interesada'],'filter'=>'text','width'=>360]]]);

// Barra de botões em grupos, no mesmo padrão da LAIA (Operações; Correlacionamentos e Avaliação
// ficam ocultos enquanto a tela não tiver botões desses grupos).
require_once dirname(__DIR__) . '/components/toolbar_grupos.php';
greenPortalToolbarGrupos('.lais-partesinteressadas-grid-fragment', []);
