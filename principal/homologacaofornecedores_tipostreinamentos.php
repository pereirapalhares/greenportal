<?php
declare(strict_types=1);

/*
 * Homologação de Fornecedores › Tipos de treinamento
 * Transação: homologacaofornecedores_tipostreinamentos — menu/permissão HOMOLOGACAO_TIPOS_TREINAMENTOS.
 * Legado: hpr_configuracoes, transação foTiposTreinamentos (codtransacao 145), tabela fo_tipotreinamentos.
 *
 * Leitura: vw_homologacao_tipos_treinamentos_grid (sp_crud_select / sp_crud_count).
 * Gravação: sp_crud_insert / sp_crud_update / sp_crud_delete na tabela fo_tipotreinamentos.
 * Exclusão de tipo em uso: bloqueada no banco (trg_fo_tipotreinamentos_antes_excluir ->
 * sp_fo_tipotreinamentos_validar_exclusao), com a mensagem exibida na tela.
 * O que cada perfil pode fazer vem de Segurança › Permissões. Objetos criados pela migração 248.
 */

require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
// Textos acentuados escritos como \u.... (não sofrem a correção automática de acentuação do portal).
$u = static fn(string $s): string => (string)json_decode('"' . $s . '"');

ManutencaoLegalCadastroGrid::render([
    'menu' => 'HOMOLOGACAO_TIPOS_TREINAMENTOS',
    'title' => ['pt' => 'Tipos de treinamento', 'en' => 'Training types', 'es' => $u('Tipos de capacitación')],
    'transaction' => 'homologacaofornecedores_tipostreinamentos', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT',
    'fragment_class' => 'homologacao-tipos-treinamentos-grid-fragment',
    'table' => 'fo_tipotreinamentos', 'view' => 'vw_homologacao_tipos_treinamentos_grid',
    'primary' => 'CODTIPOTREINAMENTO', 'primary_auto' => true, 'primary_editable' => false,
    'default_sort' => ['column' => 'DSCTIPO', 'direction' => 'asc'],
    'fields' => [
        'CODTIPOTREINAMENTO' => ['label' => ['pt' => $u('Código'), 'en' => 'Code', 'es' => $u('Código')], 'hidden' => true, 'readonly' => true, 'filter' => 'none', 'width' => 0],
        'DSCTIPO' => ['label' => ['pt' => 'Tipo de treinamento', 'en' => 'Training type', 'es' => $u('Tipo de capacitación')], 'type' => 'textarea', 'required' => true, 'filter' => 'text', 'width' => 560],
        'QTD_EM_USO' => ['label' => ['pt' => 'Em uso', 'en' => 'In use', 'es' => 'En uso'], 'readonly' => true, 'filter' => 'none', 'width' => 90],
    ],
]);

require_once dirname(__DIR__) . '/components/toolbar_grupos.php';
greenPortalToolbarGrupos('.homologacao-tipos-treinamentos-grid-fragment', []);
