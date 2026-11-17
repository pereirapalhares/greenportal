<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$isMyOccurrences = !empty($_GET['minhas']);
// Escopo (c502): Minhas ocorrências e a Lista geral de quem não tem "visualizar total" mostram só as ocorrências em que o
// usuário está envolvido (vminhasocorrenciasnc.CODUSUARIO_ENVOLVIDO), também na contagem, na exportação e nas listas de
// filtro — antes a contagem e a exportação aceitavam outro filtro enviado pela tela (ex.: o fornecedor via o total de todas).
$listaTotal = !empty(PermissaoTransacao::obter('NAO_CONFORMIDADES_LISTA_OCORRENCIAS')['pode_visualizar_total']);
$escopoProprio = $isMyOccurrences || !$listaTotal;

$gridConfig = [
    'menu' => $isMyOccurrences ? 'NAO_CONFORMIDADES_MINHAS_OCORRENCIAS' : 'NAO_CONFORMIDADES_LISTA_OCORRENCIAS',
    'title' => [
        'pt' => 'Lista geral de ocorrências',
        'en' => 'General occurrence list',
        'es' => 'Lista general de ocurrencias',
    ],
    'transaction' => $isMyOccurrences ? 'naoconformidades_minhasocorrencias' : 'naoconformidades_listageralocorrencias',
    'questionnaire_context' => 'QUALITY',
    'questionnaire_origin_fields' => ['CODOCORRENCIA'],
    'database' => 'gmodelo_dbp',
    'prefix' => 'GPT',
    'table' => 'nc_indicacao_ocorrencia',
    'view' => $escopoProprio ? 'vminhasocorrenciasnc' : 'vlistadeocorrenciasnc',
    'user_scope_field' => $escopoProprio ? 'CODUSUARIO_ENVOLVIDO' : '',
    'force_user_scope' => $escopoProprio,
    'primary' => 'CODOCORRENCIA',
    'read_only_grid' => true,
    'default_sort' => ['column' => 'CODOCORRENCIA', 'direction' => 'desc'],
    // c577: Minhas ocorrências com ?abertas=1 (card Não conformidades do painel Pendências) também tira as finalizadas, como a
    // Lista geral — pelo filtro padrão, que vale também na contagem, nas outras páginas e na exportação (o filtro "diferente de"
    // vindo só na URL se perdia depois da primeira página).
    'default_filters' => ($isMyOccurrences && empty($_GET['abertas'])) ? [] : ['ETAPA' => ['value' => 'Finalizado', 'operator' => 'not', 'exact' => true]],
    'context_or_fields' => $escopoProprio ? ['CODUSUARIO_ENVOLVIDO'] : [],
    'legacy_fixed' => true,
    'legacy_order' => [
        'DSCUNIDADE', 'NRANO', 'DESCRICAO', 'ARQUIVO', 'ETAPA',
        'NOME_GESTOR', 'NOME_RESPONSAVEL', 'NOME_EMISSOR', 'SITUACAO',
        'DSCAREA', 'DSCTIPOOCORRENCIA', 'DSCTIPONAOCONFORMIDADE',
        'SUMARIO_DA_OCORRENCIA', 'DSCLISTA', 'PROVIDENCIA',
        'DATA_OCORRENCIA', 'ANALISE_DA_PROVIDENCIA', 'DSCTIPOACAO',
        'DSCORIGEMACAO', 'DSCSIGLAGESTAO', 'POSSIBILIDADE', 'RISCO',
        'DATA_DE_CONCLUSAO_PREVISTA', 'ANALISE_DA_CAUSA',
        'VERIFICACAO_DA_EFICACIA', 'RECUSA_DO_GESTOR', 'NOME_DO_GESTOR',
        'NOME_DO_RESPONSAVEL', 'CODOCORRENCIA', 'PRAZO_ANALISE', 'ENCERRADO',
    ],
    'button_options' => [
        'save' => false,
        'new' => false,
        'duplicate' => false,
        'delete' => false,
        'reload' => true,
        'export' => true,
        'columns' => true,
    ],
    'fragment_class' => 'nao-conformidades-grid-fragment',
    'extras' => '<button class="btn btn-sm btn-outline-danger grid-crud-button" type="button" data-open-nc-pdf disabled title="Gerar PDF da ocorrência" aria-label="Gerar PDF da ocorrência"><i class="bi bi-file-earmark-pdf"></i></button>',
    'fields' => [
        'DSCUNIDADE' => ['label' => ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad'], 'width' => 170, 'readonly' => true, 'filter' => 'select'],
        'NRANO' => ['label' => ['pt' => 'Número/Ano', 'en' => 'Number/Year', 'es' => 'Número/Año'], 'width' => 100, 'readonly' => true],
        'DESCRICAO' => ['label' => ['pt' => 'Descrição', 'en' => 'Description', 'es' => 'Descripción'], 'width' => 300, 'readonly' => true, 'type' => 'textarea'],
        'ARQUIVO' => ['label' => ['pt' => 'Arquivo', 'en' => 'File', 'es' => 'Archivo'], 'width' => 120, 'readonly' => true],
        'ETAPA' => ['label' => ['pt' => 'Etapa', 'en' => 'Stage', 'es' => 'Etapa'], 'width' => 200, 'readonly' => true, 'filter' => 'select'],
        'NOME_GESTOR' => ['label' => ['pt' => 'Gestor', 'en' => 'Manager', 'es' => 'Gestor'], 'width' => 180, 'readonly' => true, 'filter' => 'select'],
        'NOME_RESPONSAVEL' => ['label' => ['pt' => 'Responsável', 'en' => 'Responsible', 'es' => 'Responsable'], 'width' => 200, 'readonly' => true, 'filter' => 'select'],
        'NOME_EMISSOR' => ['label' => ['pt' => 'Emitente da Ocorrência', 'en' => 'Occurrence Issuer', 'es' => 'Emisor de la Ocurrencia'], 'width' => 200, 'readonly' => true, 'filter' => 'select'],
        'SITUACAO' => ['label' => ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación'], 'width' => 200, 'readonly' => true, 'filter' => 'select'],
        'DSCAREA' => ['label' => ['pt' => 'Área', 'en' => 'Area', 'es' => 'Área'], 'width' => 200, 'readonly' => true, 'filter' => 'select'],
        'DSCTIPOOCORRENCIA' => ['label' => ['pt' => 'Classificação', 'en' => 'Classification', 'es' => 'Clasificación'], 'width' => 150, 'readonly' => true, 'filter' => 'select'],
        'DSCTIPONAOCONFORMIDADE' => ['label' => ['pt' => 'Tipo', 'en' => 'Type', 'es' => 'Tipo'], 'width' => 300, 'readonly' => true, 'filter' => 'select'],
        'SUMARIO_DA_OCORRENCIA' => ['label' => ['pt' => 'Sumário da Ocorrência', 'en' => 'Occurrence Summary', 'es' => 'Resumen de la Ocurrencia'], 'width' => 200, 'readonly' => true],
        'DSCLISTA' => ['label' => ['pt' => 'Lista de Distribuição', 'en' => 'Distribution List', 'es' => 'Lista de Distribución'], 'width' => 100, 'readonly' => true, 'filter' => 'select'],
        'PROVIDENCIA' => ['label' => ['pt' => 'Providência', 'en' => 'Action Taken', 'es' => 'Providencia'], 'width' => 150, 'readonly' => true, 'type' => 'textarea'],
        'DATA_OCORRENCIA' => ['label' => ['pt' => 'Data da Ocorrência', 'en' => 'Occurrence Date', 'es' => 'Fecha de la Ocurrencia'], 'width' => 100, 'readonly' => true],
        'ANALISE_DA_PROVIDENCIA' => ['label' => ['pt' => 'Análise da Providência', 'en' => 'Action Analysis', 'es' => 'Análisis de la Providencia'], 'width' => 150, 'readonly' => true, 'type' => 'textarea'],
        'DSCTIPOACAO' => ['label' => ['pt' => 'Tipo de Ação', 'en' => 'Action Type', 'es' => 'Tipo de Acción'], 'width' => 200, 'readonly' => true, 'filter' => 'select'],
        'DSCORIGEMACAO' => ['label' => ['pt' => 'Origem da Ocorrência', 'en' => 'Occurrence Origin', 'es' => 'Origen de la Ocurrencia'], 'width' => 150, 'readonly' => true, 'filter' => 'select'],
        'DSCSIGLAGESTAO' => ['label' => ['pt' => 'Sistema de Gestão', 'en' => 'Management System', 'es' => 'Sistema de Gestión'], 'width' => 100, 'readonly' => true, 'filter' => 'select'],
        'POSSIBILIDADE' => ['label' => ['pt' => 'Possibilidade', 'en' => 'Likelihood', 'es' => 'Posibilidad'], 'width' => 200, 'readonly' => true],
        'RISCO' => ['label' => ['pt' => 'Risco', 'en' => 'Risk', 'es' => 'Riesgo'], 'width' => 200, 'readonly' => true],
        'DATA_DE_CONCLUSAO_PREVISTA' => ['label' => ['pt' => 'Data Prevista para a Conclusão', 'en' => 'Expected Completion Date', 'es' => 'Fecha Prevista de Conclusión'], 'width' => 150, 'readonly' => true],
        'ANALISE_DA_CAUSA' => ['label' => ['pt' => 'Análise da Causa', 'en' => 'Cause Analysis', 'es' => 'Análisis de la Causa'], 'width' => 100, 'readonly' => true, 'type' => 'textarea'],
        'VERIFICACAO_DA_EFICACIA' => ['label' => ['pt' => 'Verificação de Eficácia', 'en' => 'Effectiveness Verification', 'es' => 'Verificación de Eficacia'], 'width' => 100, 'readonly' => true, 'type' => 'textarea'],
        'RECUSA_DO_GESTOR' => ['label' => ['pt' => 'Motivo da Recusa', 'en' => 'Reason for Refusal', 'es' => 'Motivo del Rechazo'], 'width' => 200, 'readonly' => true],
        'NOME_DO_GESTOR' => ['label' => ['pt' => 'Nome do Gestor', 'en' => 'Manager Name', 'es' => 'Nombre del Gestor'], 'width' => 150, 'readonly' => true],
        'NOME_DO_RESPONSAVEL' => ['label' => ['pt' => 'Nome do Responsável', 'en' => 'Responsible Name', 'es' => 'Nombre del Responsable'], 'width' => 100, 'readonly' => true],
        'CODOCORRENCIA' => ['label' => ['pt' => 'Código da Ocorrência', 'en' => 'Occurrence Code', 'es' => 'Código de la Ocurrencia'], 'width' => 0, 'readonly' => true, 'hidden' => true],
        'PRAZO_ANALISE' => ['label' => ['pt' => 'Prazo', 'en' => 'Deadline', 'es' => 'Plazo'], 'width' => 150, 'readonly' => true],
        'ENCERRADO' => ['label' => ['pt' => 'Plano de Ação Encerrado?', 'en' => 'Action Plan Closed?', 'es' => '¿Plan de Acción Cerrado?'], 'width' => 100, 'readonly' => true, 'filter' => 'select'],
    ],
];

$gridConfig['fields']['DSCUNIDADE']['options_sources'] = [['table' => 'unidades', 'field' => 'DSCUNIDADE']];
// Etapas da própria view (migração 331: já corrigidas por fn_nc_etapa — sem "Plano de AÃ§Ã£o" repetido no filtro)
$gridConfig['fields']['ETAPA']['options_from_view_distinct'] = true;
$gridConfig['fields']['SITUACAO']['options_sources'] = [['table' => 'nc_indicacao_ocorrencia', 'field' => 'SITUACAO']];
$gridConfig['fields']['DSCAREA']['options_sources'] = [['table' => 'areas', 'field' => 'DSCAREA']];
$gridConfig['fields']['DSCTIPOOCORRENCIA']['options_sources'] = [
    ['table' => 'nc_tipoocorrencia', 'field' => 'DSCTIPOOCORRENCIA'],
    ['table' => 'nc_classificacao_fornecedor', 'field' => 'DSCCLASSIFICACAO'],
];
$gridConfig['fields']['DSCTIPONAOCONFORMIDADE']['options_sources'] = [['table' => 'nc_tipo_de_naoconformidade', 'field' => 'DSCTIPONAOCONFORMIDADE']];
// Com escopo próprio, a lista de distribuição e os nomes não vêm da base inteira (revelariam fornecedores e pessoas de outras NCs)
if (!$escopoProprio) $gridConfig['fields']['DSCLISTA']['options_sources'] = [['table' => 'nc_listadistribuicao', 'field' => 'DSCLISTA']];
$gridConfig['fields']['DSCTIPOACAO']['options_sources'] = [['table' => 'nc_tipoacao', 'field' => 'DSCTIPOACAO']];
$gridConfig['fields']['DSCORIGEMACAO']['options_sources'] = [['table' => 'nc_origemacao', 'field' => 'DSCORIGEMACAO']];
$gridConfig['fields']['DSCSIGLAGESTAO']['options_sources'] = [['table' => 'nc_siglagestao', 'field' => 'DSCSIGLAGESTAO']];
foreach (['NOME_GESTOR', 'NOME_RESPONSAVEL', 'NOME_EMISSOR'] as $campoNome) {
    $gridConfig['fields'][$campoNome]['options_from_view_distinct'] = true;
    // Com escopo próprio, só os nomes das ocorrências do usuário (o filtro CODUSUARIO_ENVOLVIDO entra na consulta das opções)
    if ($escopoProprio) $gridConfig['fields'][$campoNome]['options_from_view_context'] = true;
}
$gridConfig['fields']['ENCERRADO']['options'] = ['Sim' => 'Sim', 'Não' => 'Não'];
ManutencaoLegalCadastroGrid::render($gridConfig);
?><script src="../assets/js/naoconformidades-workflow-popup.js?v=2"></script><?php
