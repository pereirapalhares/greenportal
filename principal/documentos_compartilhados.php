<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
ManutencaoLegalCadastroGrid::render([
    'menu'=>'DOCUMENTOS_COMPARTILHADOS',
    'title'=>['pt'=>'Documentos compartilhados','en'=>'Shared documents','es'=>'Documentos compartidos'],
    'transaction'=>'documentos_compartilhados',
    'database'=>'gmodelo_dbp','prefix'=>'GPT','table'=>'content_documents','view'=>'vw_documentos_compartilhados_grid','primary'=>'coddocumento',
    'default_sort'=>['column'=>'QTD_VINCULOS','direction'=>'desc'],
    'button_options'=>['save'=>false,'new'=>false,'duplicate'=>false,'delete'=>false,'reload'=>true,'export'=>true,'columns'=>true],
    'fields'=>[
        'coddocumento'=>['label'=>['pt'=>'Código','en'=>'Code','es'=>'Código'],'readonly'=>true],
        'codigo'=>['label'=>['pt'=>'Código do documento','en'=>'Document code','es'=>'Código del documento'],'readonly'=>true,'filter'=>'text','width'=>240],
        'titulo'=>['label'=>['pt'=>'Título','en'=>'Title','es'=>'Título'],'readonly'=>true,'filter'=>'text','width'=>360],
        'tipo_documento'=>['label'=>['pt'=>'Tipo','en'=>'Type','es'=>'Tipo'],'readonly'=>true,'filter'=>'text','width'=>180],
        'status'=>['label'=>['pt'=>'Status','en'=>'Status','es'=>'Estado'],'readonly'=>true,'filter'=>'select','width'=>120],
        'versao_atual'=>['label'=>['pt'=>'Versão','en'=>'Version','es'=>'Versión'],'readonly'=>true,'width'=>90],
        'QTD_VINCULOS'=>['label'=>['pt'=>'Qtd. vínculos','en'=>'Links','es'=>'Vínculos'],'readonly'=>true,'width'=>110],
        'QTD_CONTEXTOS'=>['label'=>['pt'=>'Qtd. contextos','en'=>'Contexts','es'=>'Contextos'],'readonly'=>true,'width'=>110],
        'CONTEXTOS'=>['label'=>['pt'=>'Contextos','en'=>'Contexts','es'=>'Contextos'],'readonly'=>true,'filter'=>'text','width'=>260],
        'UNIDADES'=>['label'=>['pt'=>'Unidades','en'=>'Units','es'=>'Unidades'],'readonly'=>true,'filter'=>'text','width'=>260],
    ],
]);
