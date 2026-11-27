<?php
declare(strict_types=1);

/*
 * Auditoria › Gestão de Auditorias / Minhas Auditorias — a lista, no grid padrão do portal (migração 424, 27/11/2026):
 * filtro em cada coluna, "Buscar no grid", paginação, ordenação, Colunas, Exportar Excel e a barra em grupos rotulados
 * (Operações · Associações · Outros). Duplo clique (ou Abrir) abre a auditoria em popup (auditoria_gestao.php?id=).
 * Incluído por auditoria_gestao.php quando não há ?id= nem ?novo=. Leitura pela view vw_aud_lista (Minhas: vw_aud_minhas,
 * filtrada por CODUSUARIO_ENVOLVIDO). ?CODFORNECEDOR=n (Homologação › Fornecedor › Auditorias) vira filtro exato.
 */
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
require_once dirname(__DIR__) . '/components/toolbar_grupos.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$audMinhas = !empty($GLOBALS['AUD_MINHAS']) || isset($_GET['minhas']);
$audMenu = $audMinhas ? 'MINHAS_AUDITORIAS' : 'AUDITORIA_GESTAO';
$audTransacao = $audMinhas ? 'minhas_auditorias' : 'auditoria_gestao';
$audT = static fn(string $k, string $pt, string $en, string $es): string => IdiomaService::t('aud.lista.' . $k, ['pt' => $pt, 'en' => $en, 'es' => $es]);
$audE = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

// filtros vindos da URL: ?CODFORNECEDOR= (botão do fornecedor) e os do contexto ({"CODFORNECEDOR":"692"}) — sempre exatos
$audFiltros = json_decode((string)($_GET['filtros'] ?? '{}'), true);
$audFiltros = is_array($audFiltros) ? $audFiltros : [];
foreach ($_GET as $k => $v) if (strcasecmp((string)$k, 'CODFORNECEDOR') === 0 && is_scalar($v) && (int)$v > 0) $audFiltros['CODFORNECEDOR'] = (string)(int)$v;
$audForn = 0;
foreach ($audFiltros as $k => $v) {
    if (strcasecmp((string)$k, 'CODFORNECEDOR') !== 0) continue;
    $audForn = (int)(is_array($v) ? ($v['value'] ?? 0) : $v);
    unset($audFiltros[$k]);
    if ($audForn > 0) $audFiltros['CODFORNECEDOR'] = ['value' => (string)$audForn, 'exact' => true];
}
$_GET['filtros'] = json_encode($audFiltros ?: new stdClass(), JSON_UNESCAPED_UNICODE);

$audPerm = PermissaoTransacao::obter($audMenu);
$audIncluir = !$audMinhas && !empty($audPerm['pode_incluir']);
$audBtn = static function (string $attr, string $icone, string $titulo, string $classe = 'btn-outline-secondary', bool $selecao = true) use ($audE): string {
    return '<button type="button" class="btn btn-sm ' . $classe . ' grid-crud-button" ' . $attr . ($selecao ? ' data-aud-sel disabled' : '') . ' title="' . $audE($titulo) . '" aria-label="' . $audE($titulo) . '"><i class="bi ' . $icone . '"></i></button>';
};
$audExtras = ($audIncluir ? $audBtn('data-aud-nova', 'bi-plus-lg', $audT('nova', 'Nova auditoria', 'New audit', 'Nueva auditoría'), 'btn-outline-success', false) : '')
    . $audBtn('data-aud-abrir', 'bi-box-arrow-up-right', $audT('abrir', 'Abrir a auditoria selecionada', 'Open the selected audit', 'Abrir la auditoría seleccionada'), 'btn-outline-primary')
    . $audBtn('data-aud-aba="checklist"', 'bi-list-check', $audT('checklist', 'Checklist da auditoria', 'Audit checklist', 'Checklist de la auditoría'))
    . $audBtn('data-aud-aba="constatacoes"', 'bi-flag', $audT('constatacoes', 'Constatações', 'Findings', 'Hallazgos'))
    . $audBtn('data-aud-aba="rnc"', 'bi-exclamation-octagon', $audT('rnc', 'RNCs abertas pela auditoria', 'NCRs opened by the audit', 'RNC abiertas por la auditoría'))
    . $audBtn('data-aud-arquivos', 'bi-folder2-open', $audT('arquivos', 'Arquivos da auditoria', 'Audit files', 'Archivos de la auditoría'))
    . $audBtn('data-aud-aba="historico"', 'bi-clock-history', $audT('historico', 'Histórico', 'History', 'Historial'))
    . $audBtn('data-aud-relatorio', 'bi-file-earmark-pdf', $audT('relatorio', 'Relatório da auditoria (PDF)', 'Audit report (PDF)', 'Informe de la auditoría (PDF)'), 'btn-outline-danger');

$ro = ['readonly' => true];
$sit = ['Em Planejamento' => ['pt' => 'Em Planejamento', 'en' => 'Planning', 'es' => 'En Planificación'], 'Em Avaliação' => ['pt' => 'Em Avaliação', 'en' => 'Under review', 'es' => 'En Evaluación'],
    'Recusada' => ['pt' => 'Recusada', 'en' => 'Rejected', 'es' => 'Rechazada'], 'Em Execução' => ['pt' => 'Em Execução', 'en' => 'In progress', 'es' => 'En Ejecución'],
    'Encerrada' => ['pt' => 'Encerrada', 'en' => 'Closed', 'es' => 'Cerrada'], 'Cancelada' => ['pt' => 'Cancelada', 'en' => 'Cancelled', 'es' => 'Cancelada']];
$tipos = ['Interna' => ['pt' => 'Interna', 'en' => 'Internal', 'es' => 'Interna'], 'Externa (certificação)' => ['pt' => 'Externa (certificação)', 'en' => 'External (certification)', 'es' => 'Externa (certificación)'],
    'De fornecedor' => ['pt' => 'De fornecedor', 'en' => 'Supplier', 'es' => 'De proveedor'], 'De cliente' => ['pt' => 'De cliente', 'en' => 'Customer', 'es' => 'De cliente']];
$simNao = ['Sim' => ['pt' => 'Sim', 'en' => 'Yes', 'es' => 'Sí'], 'Não' => ['pt' => 'Não', 'en' => 'No', 'es' => 'No']];

$audCfg = [
    'menu' => $audMenu,
    'title' => $audMinhas ? ['pt' => 'Minhas Auditorias', 'en' => 'My audits', 'es' => 'Mis Auditorías'] : ['pt' => 'Gestão de Auditorias', 'en' => 'Audit management', 'es' => 'Gestión de Auditorías'],
    'transaction' => $audTransacao,
    'database' => 'gmodelo_dbp',
    'prefix' => 'GPT',
    'table' => 'auditoria_planejamento',
    'view' => $audMinhas ? 'vw_aud_minhas' : 'vw_aud_lista',
    'user_scope_field' => $audMinhas ? 'CODUSUARIO_ENVOLVIDO' : '',
    'force_user_scope' => $audMinhas,
    'context_or_fields' => $audMinhas ? ['CODUSUARIO_ENVOLVIDO'] : [],
    'primary' => 'CODAUDITORIA',
    'read_only_grid' => true,
    'lightweight_assets' => true,
    'default_sort' => ['column' => 'CODAUDITORIA', 'direction' => 'desc'],
    'button_options' => ['save' => false, 'new' => false, 'duplicate' => false, 'delete' => false, 'reload' => true, 'export' => true, 'columns' => true],
    'fragment_class' => 'auditoria-grid-fragment',
    'extras' => $audExtras,
    'fields' => [
        'NUMERO' => ['label' => ['pt' => 'Nº', 'en' => 'No.', 'es' => 'Nº'], 'width' => 70] + $ro,
        'SITUACAO' => ['label' => ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación'], 'width' => 140, 'options' => $sit] + $ro,
        'DSCAUDITORIA' => ['label' => ['pt' => 'Auditoria', 'en' => 'Audit', 'es' => 'Auditoría'], 'width' => 280] + $ro,
        'NORMA' => ['label' => ['pt' => 'Norma / referência', 'en' => 'Standard / reference', 'es' => 'Norma / referencia'], 'width' => 150] + $ro,
        'DSCUNIDADE' => ['label' => ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad'], 'width' => 160, 'filter' => 'select', 'options_sources' => [['table' => 'unidades', 'field' => 'DSCUNIDADE']]] + $ro,
        'TIPO_DSC' => ['label' => ['pt' => 'Tipo', 'en' => 'Type', 'es' => 'Tipo'], 'width' => 140, 'options' => $tipos] + $ro,
        'DATADEINICIO' => ['label' => ['pt' => 'Início', 'en' => 'Start', 'es' => 'Inicio'], 'width' => 95] + $ro,
        'DATADEFIM' => ['label' => ['pt' => 'Fim', 'en' => 'End', 'es' => 'Fin'], 'width' => 95] + $ro,
        'ATRASADA' => ['label' => ['pt' => 'Período vencido?', 'en' => 'Overdue?', 'es' => '¿Período vencido?'], 'width' => 90, 'options' => $simNao] + $ro,
        'AUDITOR_NOME' => ['label' => ['pt' => 'Auditor', 'en' => 'Auditor', 'es' => 'Auditor'], 'width' => 170, 'options_from_view_distinct' => true, 'filter' => 'select'] + $ro,
        'AVALIADOR_NOME' => ['label' => ['pt' => 'Avaliador', 'en' => 'Reviewer', 'es' => 'Evaluador'], 'width' => 170, 'options_from_view_distinct' => true, 'filter' => 'select'] + $ro,
        'FORNECEDOR' => ['label' => ['pt' => 'Fornecedor', 'en' => 'Supplier', 'es' => 'Proveedor'], 'width' => 180] + $ro,
        'CHECKLIST' => ['label' => ['pt' => 'Checklist (respondidas/perguntas)', 'en' => 'Checklist (answered/questions)', 'es' => 'Checklist (respondidas/preguntas)'], 'width' => 110] + $ro,
        'QTD_NC' => ['label' => ['pt' => 'NC', 'en' => 'NC', 'es' => 'NC'], 'width' => 60] + $ro,
        'RNC_ABERTAS' => ['label' => ['pt' => 'RNC (abertas/total)', 'en' => 'NCR (open/total)', 'es' => 'RNC (abiertas/total)'], 'width' => 100] + $ro,
        'CRIADOR' => ['label' => ['pt' => 'Criada por', 'en' => 'Created by', 'es' => 'Creada por'], 'width' => 150] + $ro,
        'DATADECRIACAO' => ['label' => ['pt' => 'Criada em', 'en' => 'Created on', 'es' => 'Creada el'], 'width' => 130] + $ro,
        'CODFORNECEDOR' => ['label' => ['pt' => 'Código do fornecedor', 'en' => 'Supplier code', 'es' => 'Código del proveedor'], 'width' => 0, 'hidden' => true, 'filter' => 'none'] + $ro,
        'CODAUDITORIA' => ['label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'], 'width' => 0, 'hidden' => true, 'filter' => 'none'] + $ro,
    ],
];
ManutencaoLegalCadastroGrid::render($audCfg);

greenPortalToolbarGrupos('.auditoria-grid-fragment', [
    'operacoes' => ['[data-aud-nova]', '[data-aud-abrir]'],
    'associacoes' => ['[data-aud-aba="checklist"]', '[data-aud-aba="constatacoes"]', '[data-aud-aba="rnc"]', '[data-aud-arquivos]', '[data-aud-aba="historico"]'],
]);
echo '<script type="application/json" id="audListaCfg">' . json_encode([
    'tela' => 'auditoria_gestao.php', 'minhas' => $audMinhas, 'fornecedor' => $audForn,
    'titulo' => $audT('titulo', 'Auditoria', 'Audit', 'Auditoría'), 'nova' => $audT('nova', 'Nova auditoria', 'New audit', 'Nueva auditoría'),
    'arquivos' => $audT('arquivos', 'Arquivos da auditoria', 'Audit files', 'Archivos de la auditoría'), 'relatorio' => $audT('relatorio_t', 'Relatório da auditoria', 'Audit report', 'Informe de la auditoría'),
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
echo '<script src="../assets/js/auditoria-lista.js?v=1"></script>';
