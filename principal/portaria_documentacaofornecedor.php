<?php
declare(strict_types=1);

/*
 * Portaria › Documentação de Fornecedor (legado foFornecedorxCriterios, hpr_configuracoes codtransacao 194).
 * Somente consulta: documentos exigidos de cada fornecedor, com situação, validade e observações.
 * Evidências: botão com a quantidade de arquivos no GED; abre a janela de arquivos para ver e enviar
 * (E:\projetoGreen\GED\green_<empresa>\fo_fornecedorxcriterios\<código>; permissão em classes/GedPermissaoTipo.php).
 * Restrição por unidade: ADMINISTRADOR vê tudo (view completa); os demais perfis veem só as unidades do usuário
 * (vw_portaria_documentacao_fornecedor_acesso filtrada por CODUSUARIO_ACESSO = usuário logado).
 * Coluna Situação (ícone da cor + texto, SITUACAO_COR da view) ao lado do fornecedor, filtro de situação à esquerda da busca
 * e cor da linha. A situação gravada (SITUACAO) fica oculta (aparece em Colunas e no Excel).
 * Banco: migração 260 (scripts/aplicar-migracao-260-portaria-documentacao-fornecedor.php).
 */

require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$pdfAdmin = strtoupper((string)($_SESSION['usuario']['codigo_perfil'] ?? '')) === 'ADMINISTRADOR';
$pdfT = static fn(string $chave, array $padrao): string => IdiomaService::t('portaria_documentacaofornecedor.' . $chave, $padrao);
$pdfSituacoes = ['Aprovado', 'Aprovado sem validade', 'Pendente com o fornecedor', 'Pendente com a empresa', 'Reprovado', 'NA', 'Não enviar ao fornecedor', 'Ciente da regra'];
$pdfSimNao = ['Sim' => ['pt' => 'Sim', 'en' => 'Yes', 'es' => 'Sí'], 'Não' => ['pt' => 'Não', 'en' => 'No', 'es' => 'No']];

$pdfConfig = [
    'menu' => 'PORTARIA_DOCUMENTACAO_FORNECEDOR',
    'title' => ['pt' => 'Documentação de Fornecedor', 'en' => 'Supplier Documentation', 'es' => 'Documentación de Proveedor'],
    'transaction' => 'portaria_documentacaofornecedor', 'prefix' => 'GPT',
    'fragment_class' => 'portaria-documentacao-fornecedor-grid-fragment',
    'table' => 'fo_fornecedorxcriterios',
    'view' => $pdfAdmin ? 'vw_portaria_documentacao_fornecedor_grid' : 'vw_portaria_documentacao_fornecedor_acesso',
    'primary' => 'CODFORNECEDORXCRITERIOS', 'primary_auto' => true,
    // Somente consulta: sem salvar, incluir, duplicar ou excluir (o envio de arquivos é pela coluna Evidências).
    'read_only_grid' => true,
    'button_options' => ['save' => false, 'new' => false, 'duplicate' => false, 'delete' => false, 'reload' => true, 'export' => true, 'columns' => true],
    'default_sort' => ['column' => 'RAZAOSOCIAL', 'direction' => 'asc'],
    'legacy_order' => ['UNIDADEFORNECEDOR', 'RAZAOSOCIAL', 'SITUACAO_INDICADOR', 'DSCRITERIO', 'NIVEL_DE_EXIGENCIA', 'ENVIADOODOCUMENTO', 'RESPONSAVEL', 'ARQUIVOS', 'DATADEVALIDADE', 'EMPROTOCOLO', 'SITUACAO', 'OBSERVACAO', 'OBSERVACAO_DO_FORNECEDOR'],
    'fields' => [
        'CODFORNECEDORXCRITERIOS' => ['label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'], 'hidden' => true, 'readonly' => true, 'filter' => 'none', 'width' => 0],
        'UNIDADEFORNECEDOR' => ['label' => ['pt' => 'Unidade do Fornecedor', 'en' => 'Supplier Unit', 'es' => 'Unidad del Proveedor'], 'readonly' => true, 'filter' => 'text', 'width' => 170],
        'RAZAOSOCIAL' => ['label' => ['pt' => 'Fornecedor', 'en' => 'Supplier', 'es' => 'Proveedor'], 'readonly' => true, 'filter' => 'text', 'width' => 230],
        // Situação (ícone da cor + texto): mostra SITUACAO_COR e sai no Excel com o texto (SITUACAO_DESCRICAO).
        // Nome próprio da coluna para não herdar o rótulo antigo cadastrado em Textos e idiomas.
        'SITUACAO_INDICADOR' => ['label' => ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación'], 'readonly' => true, 'display_from' => 'SITUACAO_COR', 'export_from' => 'SITUACAO_DESCRICAO', 'filter' => 'none', 'width' => 190],
        'DSCRITERIO' => ['label' => ['pt' => 'Tipo / Documento', 'en' => 'Type / Document', 'es' => 'Tipo / Documento'], 'readonly' => true, 'filter' => 'text', 'width' => 280],
        'NIVEL_DE_EXIGENCIA' => ['label' => ['pt' => 'Nível de Exigência', 'en' => 'Requirement Level', 'es' => 'Nivel de Exigencia'], 'readonly' => true, 'filter' => 'text', 'width' => 130],
        'ENVIADOODOCUMENTO' => ['label' => ['pt' => 'Documento Enviado', 'en' => 'Document Sent', 'es' => 'Documento Enviado'], 'readonly' => true, 'filter' => 'select', 'options' => $pdfSimNao, 'width' => 120],
        'RESPONSAVEL' => ['label' => ['pt' => 'Responsável', 'en' => 'Responsible', 'es' => 'Responsable'], 'readonly' => true, 'filter' => 'text', 'width' => 160],
        'ARQUIVOS' => ['label' => ['pt' => 'Evidências', 'en' => 'Evidence', 'es' => 'Evidencias'], 'readonly' => true, 'export_ged' => 'fo_fornecedorxcriterios', 'filter' => 'none', 'width' => 100],
        'DATADEVALIDADE' => ['label' => ['pt' => 'Data de Validade', 'en' => 'Expiration Date', 'es' => 'Fecha de Validez'], 'readonly' => true, 'type' => 'date', 'export_date' => true, 'filter' => 'text', 'width' => 130],
        'EMPROTOCOLO' => ['label' => ['pt' => 'Em Protocolo', 'en' => 'In Process', 'es' => 'En Trámite'], 'readonly' => true, 'filter' => 'select', 'options' => $pdfSimNao, 'width' => 110],
        'SITUACAO' => ['label' => ['pt' => 'Situação do Documento', 'en' => 'Document Status', 'es' => 'Situación del Documento'], 'readonly' => true, 'hidden' => true, 'filter' => 'select', 'options' => array_combine($pdfSituacoes, $pdfSituacoes), 'width' => 190],
        'OBSERVACAO' => ['label' => ['pt' => 'Obs. da Empresa', 'en' => 'Company Notes', 'es' => 'Obs. de la Empresa'], 'readonly' => true, 'filter' => 'text', 'width' => 220],
        'OBSERVACAO_DO_FORNECEDOR' => ['label' => ['pt' => 'Obs. do Fornecedor', 'en' => "Supplier's Notes", 'es' => 'Obs. del Proveedor'], 'readonly' => true, 'filter' => 'text', 'width' => 220],
        'CODUNIDADE' => ['label' => ['pt' => 'Cód. Unidade', 'en' => 'Unit Code', 'es' => 'Cód. Unidad'], 'readonly' => true, 'hidden' => true, 'export' => false, 'filter' => 'none', 'width' => 0],
        'QTDDIAS' => ['label' => ['pt' => 'Dias para Vencer', 'en' => 'Days to Expire', 'es' => 'Días para Vencer'], 'readonly' => true, 'hidden' => true, 'filter' => 'none', 'width' => 100],
    ],
];
if (!$pdfAdmin) {
    // Demais perfis: só as unidades do usuário, mesmo com "Visualizar total".
    $pdfConfig['user_scope_field'] = 'CODUSUARIO_ACESSO';
    $pdfConfig['force_user_scope'] = true;
}
ManutencaoLegalCadastroGrid::render($pdfConfig);

// Situações (cor da linha): texto da coluna Situação e do filtro à esquerda da busca (ícone da cor + texto).
$pdfSituacoes = [
    'reprovado' => $pdfT('situacao.reprovado', ['pt' => 'Reprovado', 'en' => 'Rejected', 'es' => 'Reprobado']),
    'vencido' => $pdfT('situacao.vencido', ['pt' => 'Vencido', 'en' => 'Expired', 'es' => 'Vencido']),
    // Migração 350: regra única de cores do Painel (dias de reação do documento) e fornecedor bloqueado no ranking
    'fornecedor-bloqueado' => $pdfT('situacao.fornecedor_bloqueado', ['pt' => 'Fornecedor bloqueado (não liberado)', 'en' => 'Supplier blocked (not released)', 'es' => 'Proveedor bloqueado (no liberado)']),
    'a-vencer' => $pdfT('situacao.a_vencer_reacao', ['pt' => 'Vence nos dias de reação', 'en' => 'Expires within the reaction days', 'es' => 'Vence en los días de reacción']),
    'aprovado' => $pdfT('situacao.aprovado', ['pt' => 'Aprovado', 'en' => 'Approved', 'es' => 'Aprobado']),
    'pendente-fornecedor' => $pdfT('situacao.pendente_fornecedor', ['pt' => 'Pendente com o fornecedor', 'en' => 'Pending with supplier', 'es' => 'Pendiente con el proveedor']),
    'pendente-empresa' => $pdfT('situacao.pendente_empresa', ['pt' => 'Pendente com a empresa', 'en' => 'Pending with company', 'es' => 'Pendiente con la empresa']),
    'nao-aplica' => $pdfT('situacao.nao_aplica', ['pt' => 'Não se aplica', 'en' => 'Not applicable', 'es' => 'No aplica']),
    'sem-situacao' => $pdfT('situacao.sem_situacao', ['pt' => 'Sem situação', 'en' => 'No status', 'es' => 'Sin situación']),
];
?>
<script type="application/json" data-pdf-situacoes><?= json_encode(['titulo' => $pdfT('filtro.situacao', ['pt' => 'Filtrar por situação', 'en' => 'Filter by status', 'es' => 'Filtrar por situación']), 'todas' => $pdfT('filtro.todas', ['pt' => 'Todas as situações', 'en' => 'All statuses', 'es' => 'Todas las situaciones']), 'itens' => $pdfSituacoes], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<link rel="stylesheet" href="../assets/css/portaria-documentacao-fornecedor.css?v=6">
<script src="../assets/js/modulo-legal-arquivos.js?v=12"></script>
<script src="../assets/js/portaria-documentacao-fornecedor.js?v=7"></script>
