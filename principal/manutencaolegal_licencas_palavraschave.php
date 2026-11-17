<?php
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
ManutencaoLegalCadastroGrid::render([
    'menu' => 'LICENCAS', 'title' => ['pt' => 'Palavras-chave da licença', 'en' => 'License keywords', 'es' => 'Palabras clave de la licencia'],
    'transaction' => 'manutencaolegal_licencas_palavraschave', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT',
    'table' => 'li_licencasxpalavraschave', 'view' => 'vw_licencas_palavras_chaves_grid', 'primary' => 'codlicencaxpalavrachave',
    'context_fields' => ['codlicenca'], 'read_only_grid' => true,
    'button_options' => ['save'=>false,'new'=>false,'duplicate'=>false,'delete'=>false,'reload'=>true,'export'=>true,'columns'=>true],
    'fields' => [
        'codlicencaxpalavrachave'=>['label'=>['pt'=>'Código','en'=>'Code','es'=>'Código'],'readonly'=>true,'hidden'=>true],
        'codlicenca'=>['label'=>['pt'=>'Licença','en'=>'License','es'=>'Licencia'],'readonly'=>true,'hidden'=>true],
        'codpalavrachave'=>['label'=>['pt'=>'Código da palavra-chave','en'=>'Keyword code','es'=>'Código de palabra clave'],'readonly'=>true,'filter'=>'text'],
        'DSCPALAVRACHAVE'=>['label'=>['pt'=>'Palavra-chave','en'=>'Keyword','es'=>'Palabra clave'],'readonly'=>true,'filter'=>'text','width'=>360],
    ],
]);
