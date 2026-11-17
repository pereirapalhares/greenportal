<?php
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';

ManutencaoLegalCadastroGrid::render([
    'menu'=>'SITUACAO_CUMPRIMENTO',
    'title'=>['pt'=>'Situação quanto ao cumprimento','en'=>'Compliance status','es'=>'Situación de cumplimiento'],
    'transaction'=>'modulolegal_situacaocumprimento',
    'database'=>'gmodelo_dbp',
    'prefix'=>'GPT',
    'table'=>'legislacaoxsituacao',
    'view'=>'vw_modulolegal_situacao_cumprimento_grid',
    'primary'=>'CODSITUACAO',
    'button_options'=>['save'=>false,'new'=>false,'duplicate'=>false,'delete'=>false,'reload'=>true,'export'=>true,'columns'=>true],
    'fields'=>[
        'CODSITUACAO'=>['label'=>['pt'=>'Código','en'=>'Code','es'=>'Código'],'readonly'=>true],
        'DSCASSUNTO'=>['label'=>['pt'=>'Assunto','en'=>'Subject','es'=>'Asunto'],'readonly'=>true],
        'DSCUNIDADE'=>['label'=>['pt'=>'Unidade','en'=>'Unit','es'=>'Unidad'],'readonly'=>true],
        'NIVEL_DE_CONTROLE'=>['label'=>['pt'=>'Nível de controle','en'=>'Control level','es'=>'Nivel de control'],'readonly'=>true],
        'NOME'=>['label'=>['pt'=>'Responsável','en'=>'Responsible','es'=>'Responsable'],'readonly'=>true],
        'TEXTODALEI'=>['label'=>['pt'=>'Lei','en'=>'Law','es'=>'Ley'],'readonly'=>true],
        'DSCSITUACAO'=>['label'=>['pt'=>'Situação','en'=>'Situation','es'=>'Situación'],'readonly'=>true],
        'ATENDIDO'=>['label'=>['pt'=>'Atendido?','en'=>'Assisted?','es'=>'¿Atendido?'],'readonly'=>true],
        'OBS'=>['label'=>['pt'=>'Observação','en'=>'Note','es'=>'Observación'],'readonly'=>true],
        'DATA_DE_AVALIACAO'=>['label'=>['pt'=>'Data de avaliação','en'=>'Evaluation date','es'=>'Fecha de evaluación'],'readonly'=>true],
        'DATA_DE_VENCIMENTO_AVALIACAO'=>['label'=>['pt'=>'Data de vencimento','en'=>'Due date','es'=>'Fecha de vencimiento'],'readonly'=>true],
        'CODLEGISLACAO'=>['label'=>['pt'=>'Código da legislação','en'=>'Legislation code','es'=>'Código de legislación'],'readonly'=>true],
        'codunidade'=>['label'=>['pt'=>'Código da unidade','en'=>'Unit code','es'=>'Código de unidad'],'readonly'=>true]
    ]
]);
