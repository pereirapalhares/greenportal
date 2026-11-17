<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';

$responsibilityButton = '<button type="button" class="btn btn-sm btn-outline-primary" data-open-monitoramento-responsabilidades disabled title="Responsabilidades" aria-label="Responsabilidades"><i class="bi bi-people"></i></button>';

ManutencaoLegalCadastroGrid::render([
    'context_crud' => true,
    'context_crud_keys' => ['CODLEGISLACAO','CODUNIDADE'],
    'context_key_visible' => ['CODUNIDADE'],
    'menu' => 'REQUISITOS_LEGAIS',
    'title' => ['pt' => 'Medição e Monitoramento', 'en' => 'Measurement and monitoring', 'es' => 'Medición y monitoreo'],
    'transaction' => 'manutencaolegalrequisitoslegais_medicaomonitoramento',
    'questionnaire_context' => 'SGA',
    'questionnaire_origin_fields' => ['CODLEGISLACAO', 'CODUNIDADE'],
    'database' => 'gmodelo_dbp',
    'prefix' => 'GPT',
    'table' => 'mm_monitoramento',
    'view' => 'vw_modulolegal_requisitos_medicao_grid',
    'primary' => 'CODMONITORAMENTO',
    'unique_context_field' => 'CODMONITORAMENTO',
    'primary_auto' => true,
    'primary_editable' => false,
    'read_only_grid' => true,
    'legacy_order' => ['CODUNIDADE','CODMEDICAO','CODEQUIPAMENTO','CODAREA','CODASPECTO','CODPERIGO','PARAMETROS','DATA_MEDICAO','DATA_REACAO','CODUSUARIO','cronograma','OBS','CODMONITORAMENTO'],
    'legacy_search' => ['#select_filter','#select_filter','#select_filter','#select_filter','#select_filter','#select_filter','#text_filter','#text_filter','#text_filter','#select_filter','','#text_filter',''],
    'legacy_labels' => ['Unidade','Tipo de Medição','Equipamento de Medição','Área','Aspecto','Perigo','Parâmetros','Data da Medição','Data de Reação','Responsável','Cronograma','Observações','Código'],
    'legacy_widths' => [190,220,220,190,210,210,240,160,160,200,120,300,0],
    'legacy_sort' => ['strc','strc','strc','strc','strc','strc','str','str','str','strc','str','str','str'],
    'legacy_types' => ['coro','coro','coro','coro','coro','coro','txt','ed','ed','coro','ro','txt','cntr'],
    'legacy_fixed' => true,
    'extras' => $responsibilityButton,
    'button_options' => ['save' => false, 'new' => false, 'duplicate' => false, 'delete' => false, 'reload' => true, 'export' => true, 'columns' => true],
    'fields' => [
        'CODUNIDADE' => ['label'=>['pt'=>'Unidade','en'=>'Unit','es'=>'Unidad'],'filter'=>'select','width'=>190,'readonly'=>true,'foreign'=>['view'=>'vw_unidades_grid','value'=>'CODUNIDADE','label'=>'DSCUNIDADE','filters'=>['usado_em_manutencao_legal'=>'Sim'],'description'=>'DSCUNIDADE']],
        'CODMEDICAO' => ['label'=>['pt'=>'Tipo de medição','en'=>'Measurement type','es'=>'Tipo de medición'],'filter'=>'select','width'=>220,'readonly'=>true,'foreign'=>['view'=>'mm_medicao','value'=>'codmedicao','label'=>'dscmedicao','description'=>'DSCMEDICAO']],
        'CODEQUIPAMENTO' => ['label'=>['pt'=>'Equipamento de medição','en'=>'Measuring equipment','es'=>'Equipo de medición'],'filter'=>'select','width'=>220,'readonly'=>true,'foreign'=>['view'=>'mm_equipamento','value'=>'CODEQUIPAMENTO','label'=>'DSCEQUIPAMENTO','description'=>'DSCEQUIPAMENTO']],
        'CODAREA' => ['label'=>['pt'=>'Área','en'=>'Area','es'=>'Área'],'filter'=>'select','width'=>190,'readonly'=>true,'foreign'=>['view'=>'vw_areas_grid','value'=>'CODAREA','label'=>'DSCAREA','description'=>'DSCAREA']],
        'CODASPECTO' => ['label'=>['pt'=>'Aspecto','en'=>'Aspect','es'=>'Aspecto'],'filter'=>'select','width'=>210,'readonly'=>true,'foreign'=>['view'=>'vw_aspectos_grid','value'=>'CODASPECTO','label'=>'DSCASPECTO','description'=>'DSCASPECTO']],
        'CODPERIGO' => ['label'=>['pt'=>'Perigo','en'=>'Hazard','es'=>'Peligro'],'filter'=>'select','width'=>210,'readonly'=>true,'foreign'=>['view'=>'vw_perigos_grid','value'=>'CODPERIGO','label'=>'DSCPERIGO','description'=>'DSCPERIGO']],
        'PARAMETROS' => ['label'=>['pt'=>'Parâmetros','en'=>'Parameters','es'=>'Parámetros'],'type'=>'textarea','filter'=>'text','width'=>240,'readonly'=>true],
        'DATA_MEDICAO' => ['label'=>['pt'=>'Data da medição','en'=>'Measurement date','es'=>'Fecha de medición'],'type'=>'date','display_format'=>'date','filter'=>'text','width'=>160,'readonly'=>true],
        'DATA_REACAO' => ['label'=>['pt'=>'Data de reação','en'=>'Reaction date','es'=>'Fecha de reacción'],'type'=>'date','display_format'=>'date','filter'=>'text','width'=>160,'readonly'=>true],
        'CODUSUARIO' => ['label'=>['pt'=>'Responsável','en'=>'Responsible','es'=>'Responsable'],'filter'=>'select','width'=>200,'readonly'=>true,'foreign'=>['view'=>'vw_usuarios_grid','value'=>'codusuario','label'=>'nome','filters'=>['ativo'=>1],'description'=>'NOME_RESPONSAVEL']],
        'cronograma' => ['label'=>['pt'=>'Cronograma','en'=>'Schedule','es'=>'Cronograma'],'virtual'=>true,'action_key'=>'cronograma','action_label'=>['pt'=>'Abrir cronograma','en'=>'Open schedule','es'=>'Abrir cronograma'],'action_icon'=>'bi-calendar3','filter'=>'none','width'=>120],
        'OBS' => ['label'=>['pt'=>'Observações','en'=>'Notes','es'=>'Observaciones'],'type'=>'textarea','filter'=>'text','width'=>300,'readonly'=>true],
        'CODMONITORAMENTO' => ['label'=>['pt'=>'Código','en'=>'Code','es'=>'Código'],'hidden'=>true,'readonly'=>true,'filter'=>'none'],
        'CODLEGISLACAO' => ['label'=>['pt'=>'Código da legislação','en'=>'Legislation code','es'=>'Código de legislación'],'hidden'=>true,'readonly'=>true,'filter'=>'none'],
    ],
]);
