<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
ManutencaoLegalCadastroGrid::render(['fragment_class' => 'lais-aspectossociais-grid-fragment', 'menu'=>'LAIS_ASPECTOS_SOCIAIS','title'=>['pt'=>'Aspectos Sociais','en'=>'Social Aspects','es'=>'Aspectos Sociales'],'transaction'=>'moduloleglais_aspectossociais','database'=>'gmodelo_dbp','prefix'=>'GPT','table'=>'lais_aspectossociais','view'=>'lais_aspectossociais','primary'=>'CODASPECTOSOCIAL','primary_auto'=>true,'primary_editable'=>false,'button_options'=>['save'=>true,'new'=>true,'duplicate'=>true,'delete'=>true,'reload'=>true,'export'=>true,'columns'=>true],'fields'=>['CODASPECTOSOCIAL'=>['label'=>['pt'=>'Código','en'=>'Code','es'=>'Código'],'hidden'=>true,'readonly'=>true,'filter'=>'none'],'DSCASPECTOSOCIAL'=>['label'=>['pt'=>'Aspecto Social','en'=>'Social Aspect','es'=>'Aspecto Social'],'filter'=>'text','width'=>360]]]);

// Barra de botões em grupos, no mesmo padrão da LAIA (Operações; Correlacionamentos e Avaliação
// ficam ocultos enquanto a tela não tiver botões desses grupos).
require_once dirname(__DIR__) . '/components/toolbar_grupos.php';
greenPortalToolbarGrupos('.lais-aspectossociais-grid-fragment', []);
