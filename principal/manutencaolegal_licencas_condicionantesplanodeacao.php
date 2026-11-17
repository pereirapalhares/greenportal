<?php
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
require_once dirname(__DIR__) . '/classes/EficaciaPlanos.php';

ManutencaoLegalCadastroGrid::render([
    'menu' => 'LICENCAS',
    'title' => ['pt' => 'Plano de Ação', 'en' => 'Action plan', 'es' => 'Plan de acción'],
    'transaction' => 'liPlanodeAcao',
    'database' => 'gmodelo_dbp',
    'prefix' => 'GPT',
    'table' => 'li_planodeacao',
    'view' => 'vw_licencas_condicionantes_planodeacao_grid',
    'primary' => 'CODPLANOACAO',
    'primary_auto' => true,
    'fragment_class' => 'licencas-condicionantes-planodeacao-grid-fragment',
    'context_fields' => ['CODCONDICIONANTE'],
    'button_options' => ['save' => true, 'new' => true, 'duplicate' => true, 'delete' => true, 'reload' => true, 'export' => true, 'columns' => true],
    'fields' => [
        'CODPLANOACAO' => ['label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'], 'readonly' => true, 'hidden' => true],
        'CODCONDICIONANTE' => ['label' => ['pt' => 'Condicionante', 'en' => 'Condition', 'es' => 'Condicionante'], 'readonly' => true, 'hidden' => true],
        'ACAO' => ['label' => ['pt' => 'Ação', 'en' => 'Action', 'es' => 'Acción'], 'type' => 'textarea', 'filter' => 'text', 'width' => 360],
        'CODUSUARIO' => ['label' => ['pt' => 'Responsável', 'en' => 'Responsible', 'es' => 'Responsable'], 'filter' => 'select', 'width' => 180, 'foreign' => ['view' => 'vw_usuarios_grid', 'value' => 'codusuario', 'label' => 'nome', 'filters' => ['ativo' => 1], 'description' => 'nome']],
        'DATAINICIAL' => ['label' => ['pt' => 'Data inicial', 'en' => 'Start date', 'es' => 'Fecha inicial'], 'type' => 'date', 'filter' => 'text', 'width' => 130],
        'DATAFINAL' => ['label' => ['pt' => 'Data final', 'en' => 'End date', 'es' => 'Fecha final'], 'type' => 'date', 'filter' => 'text', 'width' => 130],
        // Evidências logo depois da data final (pedido do usuário, 18/10); a análise por IA da evidência sugere os campos do plano (migração 308)
        'ARQUIVO' => ['label' => ['pt' => 'Evidências', 'en' => 'Evidence', 'es' => 'Evidencias'], 'readonly' => true, 'filter' => 'none', 'width' => 120],
        'RECURSOS' => ['label' => ['pt' => 'Recursos', 'en' => 'Resources', 'es' => 'Recursos'], 'type' => 'textarea', 'filter' => 'text', 'width' => 260],
        // Status em percentual, como no legado (configuracoes/li_planodeacao.php); valores já gravados fora da lista também aparecem
        'STATUS' => ['label' => ['pt' => 'Status', 'en' => 'Status', 'es' => 'Estado'], 'filter' => 'select', 'width' => 100, 'options' => ['' => '', '0' => '0%', '25' => '25%', '50' => '50%', '75' => '75%', '100' => '100%'], 'options_sources' => [['table' => 'li_planodeacao', 'field' => 'STATUS']]],
    ] + EficaciaPlanos::camposGrade('li_planodeacao', 'vw_licencas_condicionantes_planodeacao_grid'),
]);

echo '<script src="../assets/js/modulo-legal-arquivos.js?v=5"></script>';
