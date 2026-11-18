<?php
declare(strict_types=1);

/*
 * Minhas atividades › Minhas condicionantes (migração 358, GER-5.1 / LIC-59).
 * Condicionantes das licenças ativas em que o usuário logado é o responsável (direto ou por atribuição de usuário/grupo):
 * vw_minhas_condicionantes_grid filtrada por CODUSUARIO_ESCOPO. Mesmas colunas da aba Condicionantes da licença, mais a
 * licença e a unidade; salvar sim, incluir/duplicar/excluir não. Permissão: a de Minhas licenças.
 */
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
// valor gravado (português) => rótulo nos três idiomas
$frequencias = ['' => '', 'Diario' => ['pt' => 'Diário', 'en' => 'Daily', 'es' => 'Diario'], 'Semanal' => ['pt' => 'Semanal', 'en' => 'Weekly', 'es' => 'Semanal'],
    'Quinzenal' => ['pt' => 'Quinzenal', 'en' => 'Fortnightly', 'es' => 'Quincenal'], 'Mensal' => ['pt' => 'Mensal', 'en' => 'Monthly', 'es' => 'Mensual'],
    'Bimestral' => ['pt' => 'Bimestral', 'en' => 'Bimonthly', 'es' => 'Bimestral'], 'Trimestral' => ['pt' => 'Trimestral', 'en' => 'Quarterly', 'es' => 'Trimestral'],
    'Semestral' => ['pt' => 'Semestral', 'en' => 'Semiannual', 'es' => 'Semestral'], 'Anual' => ['pt' => 'Anual', 'en' => 'Annual', 'es' => 'Anual'],
    'Bienal' => ['pt' => 'Bienal', 'en' => 'Biennial', 'es' => 'Bienal'], 'Trienal' => ['pt' => 'Trienal', 'en' => 'Triennial', 'es' => 'Trienal'], 'Quadrienal' => ['pt' => 'Quadrienal', 'en' => 'Quadrennial', 'es' => 'Cuatrienal']];
ManutencaoLegalCadastroGrid::render([
    'menu' => 'MINHAS_LICENCAS', 'title' => ['pt' => 'Condicionantes', 'en' => 'License conditions', 'es' => 'Condicionantes'],
    'transaction' => 'minhas_condicionantes', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT',
    'table' => 'li_condicionantes', 'view' => 'vw_minhas_condicionantes_grid', 'primary' => 'CODCONDICIONANTE', 'primary_auto' => true,
    'user_scope_field' => 'CODUSUARIO_ESCOPO', 'force_user_scope' => true,
    'button_options' => ['save' => true, 'new' => false, 'duplicate' => false, 'delete' => false, 'reload' => true, 'export' => true, 'columns' => true],
    'fields' => [
        'CODCONDICIONANTE' => ['label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'], 'readonly' => true, 'hidden' => true, 'filter' => 'none'],
        'CODLICENCA' => ['label' => ['pt' => 'Licença', 'en' => 'License', 'es' => 'Licencia'], 'readonly' => true, 'hidden' => true, 'filter' => 'none'],
        'DSCUNIDADE' => ['label' => ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad'], 'readonly' => true, 'filter' => 'select', 'width' => 160],
        'DSCLICENCA' => ['label' => ['pt' => 'Licença', 'en' => 'License', 'es' => 'Licencia'], 'readonly' => true, 'filter' => 'text', 'width' => 240],
        'DSCCONDICIONANTE' => ['label' => ['pt' => 'Descrição', 'en' => 'Description', 'es' => 'Descripción'], 'type' => 'textarea', 'filter' => 'text', 'width' => 360],
        'FREQUENCIA' => ['label' => ['pt' => 'Frequência de medição', 'en' => 'Measurement frequency', 'es' => 'Frecuencia de medición'], 'filter' => 'select', 'width' => 150, 'options' => $frequencias, 'options_sources' => [['table' => 'li_condicionantes', 'field' => 'FREQUENCIA']]],
        'ATENDIDO' => ['label' => ['pt' => 'Atende?', 'en' => 'Attended?', 'es' => '¿Atiende?'], 'filter' => 'select', 'width' => 100, 'options' => ['' => '', 'Sim' => ['pt' => 'Sim', 'en' => 'Yes', 'es' => 'Sí'], 'Não' => ['pt' => 'Não', 'en' => 'No', 'es' => 'No']], 'options_sources' => [['table' => 'li_condicionantes', 'field' => 'ATENDIDO']]],
        'CODUSUARIO' => ['label' => ['pt' => 'Responsável', 'en' => 'Responsible', 'es' => 'Responsable'], 'filter' => 'select', 'width' => 200, 'allow_empty' => true, 'display_from' => 'RESPONSAVEL', 'foreign' => ['view' => 'vw_usuarios_grid', 'value' => 'codusuario', 'label' => 'nome', 'filters' => [], 'description' => 'nome']],
        'OBS' => ['label' => ['pt' => 'Observação', 'en' => 'Notes', 'es' => 'Observación'], 'type' => 'textarea', 'filter' => 'text', 'width' => 260],
        'QTD_PLANOS_ACAO' => ['label' => ['pt' => 'Ações no plano', 'en' => 'Plan actions', 'es' => 'Acciones del plan'], 'readonly' => true, 'filter' => 'none', 'width' => 110],
        'CODUSUARIO_ESCOPO' => ['label' => ['pt' => 'Escopo', 'en' => 'Scope', 'es' => 'Alcance'], 'readonly' => true, 'hidden' => true, 'filter' => 'none'],
    ],
]);
