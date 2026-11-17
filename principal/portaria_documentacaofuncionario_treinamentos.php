<?php
declare(strict_types=1);

/*
 * Portaria › Documentação de Funcionário › Treinamentos (aberta pelo botão "Treinamentos", grupo Associações).
 * Legado: aba Treinamento, transação foFuncionariosxTreinamentos (hpr_configuracoes, codtransacao 196).
 * Somente consulta: treinamentos do funcionário da linha escolhida (filtro CODFUNCIONARIO vindo da tela principal),
 * com realizado?, responsável, validade e evidências no GED
 * (E:\projetoGreen\GED\green_<empresa>\fo_funcionarioxtiposxtreinamentos\<codigo>).
 * Permissão: a da Documentação de Funcionário (PORTARIA_DOCUMENTACAO_FUNCIONARIO). Restrição por unidade igual à tela
 * principal (ADMINISTRADOR vê tudo). Coluna Situação (ícone + texto) ao lado do treinamento e filtro à esquerda da busca. Banco: migração 263 (vw_portaria_documentacao_treinamento_grid / _acesso).
 */

require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$pdfAdmin = strtoupper((string)($_SESSION['usuario']['codigo_perfil'] ?? '')) === 'ADMINISTRADOR';
$pdfT = static fn(string $chave, array $padrao): string => IdiomaService::t('portaria_documentacaofuncionario_treinamentos.' . $chave, $padrao);
$pdfSimNao = ['Sim' => ['pt' => 'Sim', 'en' => 'Yes', 'es' => 'Sí'], 'Não' => ['pt' => 'Não', 'en' => 'No', 'es' => 'No']];

$pdfConfig = [
    'menu' => 'PORTARIA_DOCUMENTACAO_FUNCIONARIO',
    'title' => ['pt' => 'Treinamentos do Funcionário', 'en' => 'Employee Training', 'es' => 'Capacitaciones del Empleado'],
    'transaction' => 'portaria_documentacaofuncionario_treinamentos', 'prefix' => 'GPT',
    'fragment_class' => 'portaria-documentacao-treinamento-grid-fragment',
    'table' => 'fo_funcionarioxtiposxtreinamentos',
    'view' => $pdfAdmin ? 'vw_portaria_documentacao_treinamento_grid' : 'vw_portaria_documentacao_treinamento_acesso',
    'primary' => 'CODIGO', 'primary_auto' => true,
    // Funcionário da linha escolhida na Documentação de Funcionário (sem ele, a grade fica vazia).
    'context_fields' => ['CODFUNCIONARIO'],
    'read_only_grid' => true,
    'button_options' => ['save' => false, 'new' => false, 'duplicate' => false, 'delete' => false, 'reload' => true, 'export' => true, 'columns' => true],
    'default_sort' => ['column' => 'DSCTIPOTREINAMENTO', 'direction' => 'asc'],
    'legacy_order' => ['DSCTIPO', 'DSCTIPOTREINAMENTO', 'SITUACAO_INDICADOR', 'TREINAMENTOREALIZADO', 'RESPONSAVELTREINAMENTO', 'DATADEVALIDADETREINAMENTO', 'QTDDIAS', 'ARQUIVOS'],
    'fields' => [
        'CODIGO' => ['label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'], 'hidden' => true, 'readonly' => true, 'filter' => 'none', 'width' => 0],
        'CODFUNCIONARIO' => ['label' => ['pt' => 'Funcionário', 'en' => 'Employee', 'es' => 'Empleado'], 'hidden' => true, 'readonly' => true, 'filter' => 'none', 'width' => 0],
        'FUNCIONARIO' => ['label' => ['pt' => 'Funcionário', 'en' => 'Employee', 'es' => 'Empleado'], 'readonly' => true, 'hidden' => true, 'filter' => 'none', 'width' => 220],
        'DSCTIPO' => ['label' => ['pt' => 'Tipo de Funcionário', 'en' => 'Employee Type', 'es' => 'Tipo de Empleado'], 'readonly' => true, 'filter' => 'text', 'width' => 220],
        'DSCTIPOTREINAMENTO' => ['label' => ['pt' => 'Treinamento', 'en' => 'Training', 'es' => 'Capacitación'], 'readonly' => true, 'filter' => 'text', 'width' => 260],
        // Situação (ícone da cor + texto): mostra SITUACAO_COR e sai no Excel com o texto (SITUACAO_DESCRICAO).
        // Nome próprio da coluna para não herdar o rótulo antigo cadastrado em Textos e idiomas.
        'SITUACAO_INDICADOR' => ['label' => ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación'], 'readonly' => true, 'display_from' => 'SITUACAO_COR', 'export_from' => 'SITUACAO_DESCRICAO', 'filter' => 'none', 'width' => 190],
        'TREINAMENTOREALIZADO' => ['label' => ['pt' => 'Treinamento Realizado?', 'en' => 'Training Completed?', 'es' => '¿Capacitación Realizada?'], 'readonly' => true, 'filter' => 'select', 'options' => $pdfSimNao, 'width' => 130],
        'RESPONSAVELTREINAMENTO' => ['label' => ['pt' => 'E-mail do Responsável pelo Treinamento', 'en' => 'Training Contact Email', 'es' => 'Correo del Responsable de la Capacitación'], 'readonly' => true, 'filter' => 'text', 'width' => 220],
        'DATADEVALIDADETREINAMENTO' => ['label' => ['pt' => 'Validade do Treinamento', 'en' => 'Training Expiration', 'es' => 'Validez de la Capacitación'], 'readonly' => true, 'type' => 'date', 'export_date' => true, 'filter' => 'text', 'width' => 130],
        'QTDDIAS' => ['label' => ['pt' => 'Dias para Vencer', 'en' => 'Days to Expire', 'es' => 'Días para Vencer'], 'readonly' => true, 'filter' => 'none', 'width' => 110],
        'ARQUIVOS' => ['label' => ['pt' => 'Evidências', 'en' => 'Evidence', 'es' => 'Evidencias'], 'readonly' => true, 'export_ged' => 'fo_funcionarioxtiposxtreinamentos', 'filter' => 'none', 'width' => 100],
        'RESPONSAVEL' => ['label' => ['pt' => 'Usuário Responsável', 'en' => 'Responsible User', 'es' => 'Usuario Responsable'], 'readonly' => true, 'hidden' => true, 'filter' => 'text', 'width' => 160],
        'CODUNIDADE' => ['label' => ['pt' => 'Cód. Unidade', 'en' => 'Unit Code', 'es' => 'Cód. Unidad'], 'readonly' => true, 'hidden' => true, 'export' => false, 'filter' => 'none', 'width' => 0],
    ],
];
if (!$pdfAdmin) {
    $pdfConfig['user_scope_field'] = 'CODUSUARIO_ACESSO';
    $pdfConfig['force_user_scope'] = true;
}
ManutencaoLegalCadastroGrid::render($pdfConfig);

// Situações (cor da linha): texto da coluna Situação e do filtro à esquerda da busca (ícone da cor + texto).
$pdfSituacoes = [
    'reprovado' => $pdfT('situacao.reprovado', ['pt' => 'Não realizado', 'en' => 'Not completed', 'es' => 'No realizada']),
    'vencido' => $pdfT('situacao.vencido', ['pt' => 'Vencido', 'en' => 'Expired', 'es' => 'Vencida']),
    'a-vencer' => $pdfT('situacao.a_vencer', ['pt' => 'Vence em até 30 dias', 'en' => 'Expires within 30 days', 'es' => 'Vence en hasta 30 días']),
    'aprovado' => $pdfT('situacao.aprovado', ['pt' => 'Realizado', 'en' => 'Completed', 'es' => 'Realizada']),
    'sem-situacao' => $pdfT('situacao.sem_situacao', ['pt' => 'Sem informação', 'en' => 'No information', 'es' => 'Sin información']),
];
?>
<script type="application/json" data-pdf-situacoes><?= json_encode(['titulo' => $pdfT('filtro.situacao', ['pt' => 'Filtrar por situação', 'en' => 'Filter by status', 'es' => 'Filtrar por situación']), 'todas' => $pdfT('filtro.todas', ['pt' => 'Todas as situações', 'en' => 'All statuses', 'es' => 'Todas las situaciones']), 'itens' => $pdfSituacoes], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<link rel="stylesheet" href="../assets/css/portaria-documentacao-fornecedor.css?v=5">
<script src="../assets/js/modulo-legal-arquivos.js?v=13"></script>
<script src="../assets/js/portaria-documentacao-fornecedor.js?v=7"></script>
