<?php
declare(strict_types=1);

/*
 * Homologação de Fornecedores › Histórico de alterações de documentos
 * Transação: homologacaofornecedores_historicodocumentos — menu/permissão HOMOLOGACAO_HISTORICO_DOCUMENTOS.
 * Legado: hpr_configuracoes, transação foFornecedorxCriteriosHistorico (codtransacao 32), tabela fo_fornecedorxcriterios_historico.
 *
 * Somente consulta (cada gravação dos documentos deixa uma cópia completa: quem, quando e todos os campos).
 * Leitura: vw_homologacao_fornecedor_documentos_historico (sp_crud_select / sp_crud_count). Objetos: migrações 293 e 295.
 * O que cada perfil pode fazer vem de Segurança › Permissões (visualizar e exportar).
 */

require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
// Textos acentuados escritos como \u.... (não sofrem a correção automática de acentuação do portal).
$u = static fn(string $s): string => (string)json_decode('"' . $s . '"');
$simNao = ['Sim' => ['pt' => 'Sim', 'en' => 'Yes', 'es' => $u('Sí')], $u('Não') => ['pt' => $u('Não'), 'en' => 'No', 'es' => 'No']];

ManutencaoLegalCadastroGrid::render([
    'menu' => 'HOMOLOGACAO_HISTORICO_DOCUMENTOS',
    'title' => ['pt' => $u('Histórico de alterações de documentos'), 'en' => 'Document change history', 'es' => 'Historial de cambios de documentos'],
    'transaction' => 'homologacaofornecedores_historicodocumentos', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT',
    'fragment_class' => 'homologacao-historico-documentos-grid-fragment',
    'table' => 'fo_fornecedorxcriterios_historico', 'view' => 'vw_homologacao_fornecedor_documentos_historico',
    'primary' => 'CODHISTORICO', 'primary_auto' => true, 'primary_editable' => false,
    'read_only_grid' => true,
    'button_options' => ['save' => false, 'new' => false, 'duplicate' => false, 'delete' => false, 'reload' => true, 'export' => true, 'columns' => true],
    'default_sort' => ['column' => 'DATAALTERACAO', 'direction' => 'desc'],
    'fields' => [
        'CODHISTORICO' => ['label' => ['pt' => $u('Código'), 'en' => 'Code', 'es' => $u('Código')], 'hidden' => true, 'filter' => 'none', 'width' => 0],
        'DATAALTERACAO' => ['label' => ['pt' => $u('Data da alteração'), 'en' => 'Change date', 'es' => 'Fecha del cambio'], 'filter' => 'text', 'width' => 150],
        'ALTERADO_POR' => ['label' => ['pt' => 'Alterado por', 'en' => 'Changed by', 'es' => 'Cambiado por'], 'filter' => 'text', 'width' => 180],
        'DSCUNIDADE' => ['label' => ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad'], 'filter' => 'text', 'width' => 150],
        'RAZAOSOCIAL' => ['label' => ['pt' => 'Fornecedor', 'en' => 'Supplier', 'es' => 'Proveedor'], 'filter' => 'text', 'width' => 220],
        'DSCTIPO' => ['label' => ['pt' => 'Tipo', 'en' => 'Type', 'es' => 'Tipo'], 'filter' => 'text', 'width' => 180],
        'DSCRITERIO' => ['label' => ['pt' => $u('Critério / documento'), 'en' => 'Criterion / document', 'es' => 'Criterio / documento'], 'filter' => 'text', 'width' => 300],
        'SITUACAO' => ['label' => ['pt' => $u('Situação'), 'en' => 'Status', 'es' => $u('Situación')], 'filter' => 'text', 'width' => 170],
        'DATADEVALIDADE' => ['label' => ['pt' => 'Validade', 'en' => 'Expiry', 'es' => 'Vencimiento'], 'type' => 'date', 'display_format' => 'date', 'filter' => 'text', 'width' => 110],
        'DATADEREACAO' => ['label' => ['pt' => $u('Dias de reação'), 'en' => 'Reaction days', 'es' => $u('Días de reacción')], 'filter' => 'text', 'width' => 100],
        'ENVIADOODOCUMENTO' => ['label' => ['pt' => 'Enviado?', 'en' => 'Sent?', 'es' => $u('¿Enviado?')], 'options' => $simNao, 'filter' => 'select', 'width' => 90],
        'EMPROTOCOLO' => ['label' => ['pt' => 'Em protocolo', 'en' => 'In protocol', 'es' => 'En protocolo'], 'options' => ['S' => ['pt' => 'Sim', 'en' => 'Yes', 'es' => $u('Sí')], 'N' => ['pt' => $u('Não'), 'en' => 'No', 'es' => 'No']], 'filter' => 'select', 'width' => 100],
        'DATACOBRANCA_EMPROTOCOLO' => ['label' => ['pt' => $u('Cobrança do protocolo'), 'en' => 'Protocol follow-up', 'es' => 'Cobro del protocolo'], 'type' => 'date', 'display_format' => 'date', 'filter' => 'text', 'width' => 120],
        'INCLUIDOCONTRATO' => ['label' => ['pt' => 'No contrato?', 'en' => 'In contract?', 'es' => $u('¿En contrato?')], 'options' => $simNao, 'filter' => 'select', 'width' => 100],
        'RESPONSAVEL' => ['label' => ['pt' => $u('Responsável (empresa)'), 'en' => 'Owner (company)', 'es' => 'Responsable (empresa)'], 'filter' => 'text', 'width' => 170],
        'RESPONSAVEL_FORNECEDOR' => ['label' => ['pt' => $u('Responsável (fornecedor)'), 'en' => 'Owner (supplier)', 'es' => 'Responsable (proveedor)'], 'filter' => 'text', 'width' => 170],
        'OBSERVACAO' => ['label' => ['pt' => $u('Observação da empresa'), 'en' => 'Company note', 'es' => $u('Observación de la empresa')], 'type' => 'textarea', 'filter' => 'text', 'width' => 260],
        'OBSERVACAO_DO_FORNECEDOR' => ['label' => ['pt' => $u('Observação do fornecedor'), 'en' => 'Supplier note', 'es' => $u('Observación del proveedor')], 'type' => 'textarea', 'filter' => 'text', 'width' => 260],
    ],
]);

require_once dirname(__DIR__) . '/components/toolbar_grupos.php';
greenPortalToolbarGrupos('.homologacao-historico-documentos-grid-fragment', []);
