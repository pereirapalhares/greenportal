<?php
declare(strict_types=1);

/*
 * Homologação de Fornecedores › Critérios dos funcionários
 * Transação: homologacaofornecedores_criteriosfuncionarios — menu/permissão HOMOLOGACAO_CRITERIOS_FUNCIONARIOS.
 * Legado: configuracoes/fo_criteriofuncionarios.php (tabela fo_criteriofuncionarios).
 *
 * Leitura: vw_homologacao_criterios_funcionarios_grid (sp_crud_select / sp_crud_count).
 * Lista Tipo de documento: vw_homologacao_criterios_funcionarios_tipos_documento.
 * Gravação: sp_crud_insert / sp_crud_update / sp_crud_delete na tabela fo_criteriofuncionarios.
 * Exclusão de critério em uso: bloqueada no banco (trg_fo_criteriofuncionarios_antes_excluir ->
 * sp_fo_criteriofuncionario_validar_exclusao), com a mensagem exibida na tela.
 * O que cada perfil pode fazer vem de Segurança › Permissões (visualizar, incluir, editar, excluir, exportar).
 * Objetos criados pela migração 245.
 */

require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
// Textos acentuados escritos como \u.... (não sofrem a correção automática de acentuação do portal).
$u = static fn(string $s): string => (string)json_decode('"' . $s . '"');
// Migração 347: modelo do documento (o que ele precisa ter; validação automática pela IA) do critério selecionado
$modeloBotao = is_file(__DIR__ . '/homologacaofornecedores_modelo_documento.php')
    ? '<button type="button" class="btn btn-sm btn-outline-success grid-crud-button" data-criterio-modelo disabled title="' . htmlspecialchars(IdiomaService::t('homologacao_criterios.botao.modelo', ['pt' => 'Modelo do documento (o que ele precisa ter; validação automática pela IA)', 'en' => 'Document template (what it must contain; automatic AI validation)', 'es' => 'Modelo del documento (lo que debe tener; validación automática por la IA)']), ENT_QUOTES, 'UTF-8') . '"><i class="bi bi-file-earmark-check"></i></button>'
    : '';

ManutencaoLegalCadastroGrid::render([
    'menu' => 'HOMOLOGACAO_CRITERIOS_FUNCIONARIOS',
    'title' => ['pt' => $u('Critérios dos funcionários'), 'en' => 'Employee criteria', 'es' => 'Criterios de los empleados'],
    'transaction' => 'homologacaofornecedores_criteriosfuncionarios', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT',
    'fragment_class' => 'homologacao-criterios-funcionarios-grid-fragment',
    'table' => 'fo_criteriofuncionarios', 'view' => 'vw_homologacao_criterios_funcionarios_grid',
    'primary' => 'CODCRITERIOFUNCIONARIO', 'primary_auto' => true, 'primary_editable' => false,
    'default_sort' => ['column' => 'DSCRITERIO', 'direction' => 'asc'],
    'extras' => $modeloBotao,
    'fields' => [
        'CODCRITERIOFUNCIONARIO' => ['label' => ['pt' => $u('Código'), 'en' => 'Code', 'es' => $u('Código')], 'hidden' => true, 'readonly' => true, 'filter' => 'none', 'width' => 0],
        'DSCRITERIO' => ['label' => ['pt' => $u('Critério'), 'en' => 'Criterion', 'es' => 'Criterio'], 'type' => 'textarea', 'required' => true, 'filter' => 'text', 'width' => 520],
        'TIPO_DE_DOCUMENTO' => ['label' => ['pt' => 'Tipo de documento', 'en' => 'Document type', 'es' => 'Tipo de documento'], 'filter' => 'select', 'width' => 260, 'lazy_foreign' => false,
            'foreign' => ['view' => 'vw_homologacao_criterios_funcionarios_tipos_documento', 'value' => 'TIPO_DE_DOCUMENTO', 'label' => 'TIPO_DE_DOCUMENTO', 'description' => 'TIPO_DE_DOCUMENTO']],
        'QTD_EM_USO' => ['label' => ['pt' => 'Em uso', 'en' => 'In use', 'es' => 'En uso'], 'readonly' => true, 'filter' => 'none', 'width' => 90],
    ],
]);

// Barra de botões em grupos, no mesmo padrão das demais telas (sem Correlacionamentos/Avaliação).
require_once dirname(__DIR__) . '/components/toolbar_grupos.php';
greenPortalToolbarGrupos('.homologacao-criterios-funcionarios-grid-fragment', ['associacoes' => ['[data-criterio-modelo]']]);
?>
<script>
(function () {
    // Migração 347: Modelo do documento do critério selecionado (popup)
    if (!window.jQuery) return;
    var $ = window.jQuery, fragment = $('.homologacao-criterios-funcionarios-grid-fragment').first(), botao = fragment.find('[data-criterio-modelo]');
    if (!botao.length) return;
    function linha() { return fragment.find('table tbody tr.selected').first(); }
    function selecionado() { var r = linha(); return r.length ? Number(r.attr('data-key') || 0) : 0; }
    function atualizar() { botao.prop('disabled', !selecionado()); }
    fragment.on('click', 'table tbody tr', atualizar).on('gridPageLoaded', atualizar);
    botao.on('click', function () {
        var id = selecionado(); if (!id) return;
        var host = window.top || window, r = linha(), nome = $.trim(r.find('[data-column="DSCRITERIO"]').find('textarea,input').first().val() || r.find('[data-column="DSCRITERIO"]').text() || ''),
            titulo = (window.greenT ? window.greenT('homologacao_criterios.popup.modelo', 'Modelo do documento') : 'Modelo do documento') + (nome ? ' - ' + nome.substring(0, 70) : ''),
            url = (host.greenPortalBaseUrl || '') + '/principal/homologacaofornecedores_modelo_documento.php?escopo=FUNCIONARIO&criterio=' + encodeURIComponent(id);
        if (host.GreenPortalPopups && host.jQuery) host.GreenPortalPopups.transacaoIndependente({ titulo: titulo, conteudo: host.jQuery('<iframe>', { src: url, style: 'width:100%;height:100%;border:0;display:block;' }), amplo: true, redimensionavel: true });
        else if (host.greenPortalOpenTab) host.greenPortalOpenTab('criterio-modelo-funcionario-' + id, url, titulo);
    });
    atualizar();
}());
</script>
