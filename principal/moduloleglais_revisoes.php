<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
ManutencaoLegalCadastroGrid::render(['fragment_class' => 'lais-revisoes-grid-fragment', 'menu'=>'LAIS_REVISOES','title'=>['pt'=>'Revisões LAIS','en'=>'LAIS Reviews','es'=>'Revisiones LAIS'],'transaction'=>'moduloleglais_revisoes','database'=>'gmodelo_dbp','prefix'=>'GPT','table'=>'lais_revisao','view'=>'lais_revisao','primary'=>'CODREVISAO','primary_auto'=>true,'primary_editable'=>false,'button_options'=>['save'=>true,'new'=>true,'duplicate'=>true,'delete'=>true,'reload'=>true,'export'=>true,'columns'=>true],'fields'=>['CODREVISAO'=>['label'=>['pt'=>'Código','en'=>'Code','es'=>'Código'],'hidden'=>true,'readonly'=>true,'filter'=>'none'],'DSCREVISAO'=>['label'=>['pt'=>'Revisão','en'=>'Review','es'=>'Revisión'],'filter'=>'text','width'=>320],'DATA'=>['label'=>['pt'=>'Data','en'=>'Date','es'=>'Fecha'],'type'=>'date','display_format'=>'date','filter'=>'text','width'=>150]]]);

// Barra de botões em grupos, no mesmo padrão da LAIA (Operações; Correlacionamentos e Avaliação
// ficam ocultos enquanto a tela não tiver botões desses grupos).
require_once dirname(__DIR__) . '/components/toolbar_grupos.php';
greenPortalToolbarGrupos('.lais-revisoes-grid-fragment', []);
