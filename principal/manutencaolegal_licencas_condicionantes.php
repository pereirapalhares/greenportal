<?php
/*
 * Manutenção legal › Licenças › Condicionantes da licença (li_condicionantes; aba "Condicionantes" do legado em
 * cadastros/li_licencas_c.php, gravação em cadastros/li_condicionantes_s.php).
 * Como no legado: descrição, frequência de medição (lista), atende? (Sim/Não), responsável (usuários) e observação
 * editáveis; inclusão e exclusão. Ao excluir, os planos de ação da condicionante saem junto (gatilho da migração 307).
 */
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
// Frequências do legado (cadastros/li_licencas_c.php); valores já gravados fora da lista também aparecem (options_sources)
$frequencias = ['' => '', 'Diario' => 'Diário', 'Semanal' => 'Semanal', 'Quinzenal' => 'Quinzenal', 'Mensal' => 'Mensal', 'Bimestral' => 'Bimestral',
    'Trimestral' => 'Trimestral', 'Semestral' => 'Semestral', 'Anual' => 'Anual', 'Bienal' => 'Bienal', 'Trienal' => 'Trienal', 'Quadrienal' => 'Quadrienal'];
ManutencaoLegalCadastroGrid::render([
    'menu' => 'LICENCAS', 'title' => ['pt' => 'Condicionantes da licença', 'en' => 'License conditions', 'es' => 'Condicionantes de la licencia'],
    'transaction' => 'manutencaolegal_licencas_condicionantes', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT',
    'table' => 'li_condicionantes', 'view' => 'vw_licencas_condicionantes_grid', 'primary' => 'CODCONDICIONANTE',
    'context_fields' => ['CODLICENCA'], 'primary_auto' => true,
    'button_options' => ['save'=>true,'new'=>true,'duplicate'=>true,'delete'=>true,'reload'=>true,'export'=>true,'columns'=>true],
    'row_actions' => [
        ['key' => 'plano-acao', 'label' => ['pt' => 'Plano de Acao', 'en' => 'Action plan', 'es' => 'Plan de accion'], 'icon' => 'bi-kanban'],
    ],
    'fields' => [
        'CODCONDICIONANTE'=>['label'=>['pt'=>'Código','en'=>'Code','es'=>'Código'],'readonly'=>true,'hidden'=>true],
        'CODLICENCA'=>['label'=>['pt'=>'Licença','en'=>'License','es'=>'Licencia'],'readonly'=>true,'hidden'=>true],
        'QTD_PLANOS_ACAO'=>['label'=>['pt'=>'Total de ações','en'=>'Total actions','es'=>'Total de acciones'],'readonly'=>true,'hidden'=>true,'filter'=>'none'],
        'DSCCONDICIONANTE'=>['label'=>['pt'=>'Descrição','en'=>'Description','es'=>'Descripción'],'type'=>'textarea','filter'=>'text','width'=>360],
        'FREQUENCIA'=>['label'=>['pt'=>'Frequência de medição','en'=>'Measurement frequency','es'=>'Frecuencia de medición'],'filter'=>'select','width'=>150,'options'=>$frequencias,'options_sources'=>[['table'=>'li_condicionantes','field'=>'FREQUENCIA']]],
        'ATENDIDO'=>['label'=>['pt'=>'Atende?','en'=>'Attended?','es'=>'¿Atiende?'],'filter'=>'select','width'=>100,'options'=>['' => '', 'Sim' => 'Sim', 'Não' => 'Não'],'options_sources'=>[['table'=>'li_condicionantes','field'=>'ATENDIDO']]],
        'CODUSUARIO'=>['label'=>['pt'=>'Responsável','en'=>'Responsible','es'=>'Responsable'],'filter'=>'select','width'=>200,'allow_empty'=>true,'display_from'=>'RESPONSAVEL','foreign'=>['view'=>'vw_usuarios_grid','value'=>'codusuario','label'=>'nome','filters'=>[],'description'=>'nome']],
        'OBS'=>['label'=>['pt'=>'Observação','en'=>'Notes','es'=>'Observación'],'type'=>'textarea','filter'=>'text','width'=>260],
    ],
]);
