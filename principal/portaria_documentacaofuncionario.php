<?php
declare(strict_types=1);

/*
 * Portaria › Documentação de Funcionário (legado foFuncionariosXCriterios, hpr_configuracoes codtransacao 195).
 * Somente consulta: critérios exigidos de cada funcionário dos fornecedores (ASO, treinamentos, documentos), com
 * "Atende ao critério?", situação, validade e observações.
 * Evidências: botão com a quantidade de arquivos no GED; abre a janela de arquivos para ver e enviar
 * (E:\projetoGreen\GED\green_<empresa>\fo_funcionarioxtiposxcriterios\<codigo>; permissão em classes/GedPermissaoTipo.php).
 * Com "Editar" nesta transação, a janela de arquivos analisa o arquivo com IA e atualiza o critério depois da revisão
 * (principal/api/funcionariocriterios_ia.php).
 * Restrição por unidade: ADMINISTRADOR vê tudo; os demais perfis só as unidades do usuário
 * (vw_portaria_documentacao_funcionario_acesso filtrada por CODUSUARIO_ACESSO = usuário logado).
 * Coluna Situação (ícone da cor + texto) ao lado do nome e filtro de situação à esquerda da busca; cor da linha: SITUACAO_COR (view), legenda acima do grid. Banco: migração 262.
 * Associações › Treinamentos: portaria_documentacaofuncionario_treinamentos.php (migração 263).
 */

require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$pdfAdmin = strtoupper((string)($_SESSION['usuario']['codigo_perfil'] ?? '')) === 'ADMINISTRADOR';
$pdfT = static fn(string $chave, array $padrao): string => IdiomaService::t('portaria_documentacaofuncionario.' . $chave, $padrao);
$pdfAtende = ['Sim', 'Não', 'Desligado', 'Afastado', 'NAP'];
$pdfSituacoes = ['Pendente com o fornecedor', 'Pendente com a empresa'];
// Associações › Treinamentos (aba Treinamento do legado, foFuncionariosxTreinamentos): treinamentos do funcionário da linha.
// O título da janela recebe o nome do funcionário da linha: "Treinamentos do funcionário - <nome>" (portaria-documentacao-fornecedor.js).
$pdfTitTrein = $pdfT('botao.treinamentos', ['pt' => 'Treinamentos do funcionário', 'en' => 'Employee training', 'es' => 'Capacitaciones del empleado']);
$pdfExtras = '<button class="btn btn-sm btn-outline-secondary grid-crud-button" type="button" data-context-child data-context-id="docfuncionario-treinamentos"'
    . ' data-context-url="/principal/portaria_documentacaofuncionario_treinamentos.php" data-context-fields="CODFUNCIONARIO"'
    . ' data-pdf-titulo-campo="FUNCIONARIO" data-pdf-titulo-base="' . htmlspecialchars($pdfTitTrein, ENT_QUOTES, 'UTF-8') . '"'
    . ' data-context-title="' . htmlspecialchars($pdfTitTrein, ENT_QUOTES, 'UTF-8') . '" disabled title="' . htmlspecialchars($pdfTitTrein, ENT_QUOTES, 'UTF-8') . '"'
    . ' aria-label="' . htmlspecialchars($pdfTitTrein, ENT_QUOTES, 'UTF-8') . '"><i class="bi bi-mortarboard"></i></button>';

$pdfConfig = [
    'menu' => 'PORTARIA_DOCUMENTACAO_FUNCIONARIO',
    'title' => ['pt' => 'Documentação de Funcionário', 'en' => 'Employee Documentation', 'es' => 'Documentación de Empleado'],
    'transaction' => 'portaria_documentacaofuncionario', 'prefix' => 'GPT',
    'fragment_class' => 'portaria-documentacao-funcionario-grid-fragment',
    'table' => 'fo_funcionarioxtiposxcriterios',
    'view' => $pdfAdmin ? 'vw_portaria_documentacao_funcionario_grid' : 'vw_portaria_documentacao_funcionario_acesso',
    'primary' => 'CODIGO', 'primary_auto' => true,
    // Somente consulta (o envio de arquivos e a análise por IA são pela coluna Evidências).
    'read_only_grid' => true,
    'button_options' => ['save' => false, 'new' => false, 'duplicate' => false, 'delete' => false, 'reload' => true, 'export' => true, 'columns' => true],
    'extras' => $pdfExtras,
    'default_sort' => ['column' => 'DSCUNIDADE', 'direction' => 'asc'],
    'legacy_order' => ['DSCUNIDADE', 'RAZAOSOCIAL', 'FUNCIONARIO', 'SITUACAO_INDICADOR', 'SITUACAO', 'TEM_TREINAMENTO', 'NOME', 'NRIDENTIFICACAO', 'DSCTIPO', 'DSCRITERIO', 'ATENDEAOCRITERIO', 'RESPONSAVELDOCUMENTACAOFORNECEDOR', 'DATADEVALIDADE', 'ARQUIVOS', 'OBSERVACAO'],
    'fields' => [
        'CODIGO' => ['label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'], 'hidden' => true, 'readonly' => true, 'filter' => 'none', 'width' => 0],
        'DSCUNIDADE' => ['label' => ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad'], 'readonly' => true, 'filter' => 'text', 'width' => 150],
        'RAZAOSOCIAL' => ['label' => ['pt' => 'Razão Social', 'en' => 'Corporate Name', 'es' => 'Razón Social'], 'readonly' => true, 'filter' => 'text', 'width' => 220],
        'FUNCIONARIO' => ['label' => ['pt' => 'Funcionário', 'en' => 'Employee', 'es' => 'Empleado'], 'readonly' => true, 'filter' => 'text', 'width' => 230],
        // Situação (ícone da cor + texto): mostra SITUACAO_COR e sai no Excel com o texto (SITUACAO_DESCRICAO).
        // Nome próprio da coluna para não herdar o rótulo antigo cadastrado em Textos e idiomas.
        'SITUACAO_INDICADOR' => ['label' => ['pt' => 'Atende ao critério?', 'en' => 'Meets the criterion?', 'es' => '¿Cumple el criterio?'], 'readonly' => true, 'display_from' => 'SITUACAO_COR', 'export_from' => 'SITUACAO_DESCRICAO', 'filter' => 'none', 'width' => 190],
        'SITUACAO' => ['label' => ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación'], 'readonly' => true, 'filter' => 'select', 'options' => array_combine($pdfSituacoes, $pdfSituacoes), 'width' => 170],
        'TEM_TREINAMENTO' => ['label' => ['pt' => 'Tem treinamento?', 'en' => 'Has training?', 'es' => '¿Tiene capacitación?'], 'readonly' => true, 'filter' => 'select', 'options' => ['Sim' => ['pt' => 'Sim', 'en' => 'Yes', 'es' => 'Sí'], 'Não' => ['pt' => 'Não', 'en' => 'No', 'es' => 'No']], 'width' => 120],
        'NOME' => ['label' => ['pt' => 'Responsável', 'en' => 'Responsible', 'es' => 'Responsable'], 'readonly' => true, 'filter' => 'text', 'width' => 170],
        'NRIDENTIFICACAO' => ['label' => ['pt' => 'Nº do Registro', 'en' => 'Registration Number', 'es' => 'N.º de Registro'], 'readonly' => true, 'filter' => 'text', 'width' => 120],
        'DSCTIPO' => ['label' => ['pt' => 'Função', 'en' => 'Role', 'es' => 'Función'], 'readonly' => true, 'hidden' => true, 'filter' => 'text', 'width' => 160],
        'DSCRITERIO' => ['label' => ['pt' => 'Critério', 'en' => 'Criterion', 'es' => 'Criterio'], 'readonly' => true, 'filter' => 'text', 'width' => 230],
        'ATENDEAOCRITERIO' => ['label' => ['pt' => 'Atende ao critério? (cadastro)', 'en' => 'Meets the criterion? (record)', 'es' => '¿Cumple el criterio? (registro)'], 'readonly' => true, 'hidden' => true, 'filter' => 'select', 'options' => array_combine($pdfAtende, $pdfAtende), 'width' => 120],
        'RESPONSAVELDOCUMENTACAOFORNECEDOR' => ['label' => ['pt' => 'Responsável pela documentação do fornecedor', 'en' => 'Responsible for supplier documentation', 'es' => 'Responsable de la documentación del proveedor'], 'readonly' => true, 'hidden' => true, 'filter' => 'text', 'width' => 200],
        'DATADEVALIDADE' => ['label' => ['pt' => 'Data de Validade', 'en' => 'Expiration Date', 'es' => 'Fecha de Validez'], 'readonly' => true, 'type' => 'date', 'export_date' => true, 'filter' => 'text', 'width' => 120],
        'ARQUIVOS' => ['label' => ['pt' => 'Evidências', 'en' => 'Evidence', 'es' => 'Evidencias'], 'readonly' => true, 'export_ged' => 'fo_funcionarioxtiposxcriterios', 'filter' => 'none', 'width' => 100],
        'OBSERVACAO' => ['label' => ['pt' => 'Observações', 'en' => 'Notes', 'es' => 'Observaciones'], 'readonly' => true, 'filter' => 'text', 'width' => 260],
        'CODUNIDADE' => ['label' => ['pt' => 'Cód. Unidade', 'en' => 'Unit Code', 'es' => 'Cód. Unidad'], 'readonly' => true, 'hidden' => true, 'export' => false, 'filter' => 'none', 'width' => 0],
        'CODFUNCIONARIO' => ['label' => ['pt' => 'Cód. Funcionário', 'en' => 'Employee Code', 'es' => 'Cód. Empleado'], 'readonly' => true, 'hidden' => true, 'export' => false, 'filter' => 'none', 'width' => 0],
        'DATADEREACAO' => ['label' => ['pt' => 'Dias de Reação', 'en' => 'Reaction Days', 'es' => 'Días de Reacción'], 'readonly' => true, 'hidden' => true, 'filter' => 'none', 'width' => 90],
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
    'reprovado' => $pdfT('situacao.reprovado', ['pt' => 'Não atende', 'en' => 'Does not meet', 'es' => 'No cumple']),
    'vencido' => $pdfT('situacao.vencido', ['pt' => 'Vencido', 'en' => 'Expired', 'es' => 'Vencido']),
    // Migração 350: regra única de cores do Painel (dias de reação do documento) e fornecedor bloqueado no ranking
    'fornecedor-bloqueado' => $pdfT('situacao.fornecedor_bloqueado', ['pt' => 'Fornecedor bloqueado (não liberado)', 'en' => 'Supplier blocked (not released)', 'es' => 'Proveedor bloqueado (no liberado)']),
    'a-vencer' => $pdfT('situacao.a_vencer_reacao', ['pt' => 'Vence nos dias de reação', 'en' => 'Expires within the reaction days', 'es' => 'Vence en los días de reacción']),
    'aprovado' => $pdfT('situacao.aprovado', ['pt' => 'Atende', 'en' => 'Meets', 'es' => 'Cumple']),
    'pendente-fornecedor' => $pdfT('situacao.pendente_fornecedor', ['pt' => 'Pendente com o fornecedor', 'en' => 'Pending with supplier', 'es' => 'Pendiente con el proveedor']),
    'pendente-empresa' => $pdfT('situacao.pendente_empresa', ['pt' => 'Pendente com a empresa', 'en' => 'Pending with company', 'es' => 'Pendiente con la empresa']),
    'nao-aplica' => $pdfT('situacao.nao_aplica', ['pt' => 'Desligado / afastado / NAP', 'en' => 'Dismissed / on leave / N/A', 'es' => 'Desvinculado / con licencia / NAP']),
    'sem-situacao' => $pdfT('situacao.sem_situacao', ['pt' => 'Sem informação', 'en' => 'No information', 'es' => 'Sin información']),
];
?>
<script type="application/json" data-pdf-situacoes><?= json_encode(['titulo' => $pdfT('filtro.situacao', ['pt' => 'Filtrar por situação', 'en' => 'Filter by status', 'es' => 'Filtrar por situación']), 'todas' => $pdfT('filtro.todas', ['pt' => 'Todas as situações', 'en' => 'All statuses', 'es' => 'Todas las situaciones']), 'itens' => $pdfSituacoes], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<link rel="stylesheet" href="../assets/css/portaria-documentacao-fornecedor.css?v=6">
<script src="../assets/js/modulo-legal-arquivos.js?v=13"></script>
<script src="../assets/js/portaria-documentacao-fornecedor.js?v=8"></script>
<script src="../assets/js/homologacao-chat.js?v=2"></script>
