<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
ManutencaoLegalCadastroGrid::render(['fragment_class' => 'lais-impactossociais-grid-fragment', 'menu'=>'LAIS_IMPACTOS_SOCIAIS','title'=>['pt'=>'Impactos Sociais','en'=>'Social Impacts','es'=>'Impactos Sociales'],'transaction'=>'moduloleglais_impactossociais','database'=>'gmodelo_dbp','prefix'=>'GPT','table'=>'lais_impactossociais','view'=>'lais_impactossociais','primary'=>'CODIMPACTOSOCIAL','primary_auto'=>true,'primary_editable'=>false,'button_options'=>['save'=>true,'new'=>true,'duplicate'=>true,'delete'=>true,'reload'=>true,'export'=>true,'columns'=>true],'fields'=>['CODIMPACTOSOCIAL'=>['label'=>['pt'=>'Código','en'=>'Code','es'=>'Código'],'hidden'=>true,'readonly'=>true,'filter'=>'none'],'DSCIMPACTOSOCIAL'=>['label'=>['pt'=>'Impacto Social','en'=>'Social Impact','es'=>'Impacto Social'],'filter'=>'text','width'=>360]]]);

// Barra de botões em grupos, no mesmo padrão da LAIA (Operações; Correlacionamentos e Avaliação
// ficam ocultos enquanto a tela não tiver botões desses grupos).
require_once dirname(__DIR__) . '/components/toolbar_grupos.php';
greenPortalToolbarGrupos('.lais-impactossociais-grid-fragment', []);
