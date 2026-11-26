<?php
declare(strict_types=1);

/*
 * Homologação de Fornecedores › Critérios dos Fornecedores
 * Transação: homologacaofornecedores_criteriosfornecedores — menu/permissão HOMOLOGACAO_CRITERIOS_FORNECEDORES.
 * Legado: configuracoes/fo_criteriofornecedores.php (tabela fo_criteriofornecedores: DSCRITERIO, DSCRITERIO_EN).
 *
 * Leitura: vw_homologacao_criterios_fornecedores_grid (sp_crud_select / sp_crud_count).
 * Gravação: sp_crud_insert / sp_crud_update / sp_crud_delete na tabela fo_criteriofornecedores.
 * Exclusão de critério em uso (tipos de fornecedor ou documentos): bloqueada no banco
 * (trg_fo_criteriofornecedores_antes_excluir -> sp_fo_criteriofornecedores_validar_exclusao). Objetos: migração 292 (via 248).
 * O vínculo com os tipos e o nível de exigência ficam em Tipos de Fornecedores › Critérios.
 * O que cada perfil pode fazer vem de Segurança › Permissões.
 */

require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
// Textos acentuados escritos como \u.... (não sofrem a correção automática de acentuação do portal).
$u = static fn(string $s): string => (string)json_decode('"' . $s . '"');
// Migração 347: modelo do documento (o que ele precisa ter; validação automática pela IA) do critério selecionado
$modeloBotao = is_file(__DIR__ . '/homologacaofornecedores_modelo_documento.php')
    ? '<button type="button" class="btn btn-sm btn-outline-success grid-crud-button" data-criterio-modelo disabled title="' . htmlspecialchars(IdiomaService::t('homologacao_criterios.botao.modelo', ['pt' => 'Modelo do documento (o que ele precisa ter; validação automática pela IA)', 'en' => 'Document template (what it must contain; automatic AI validation)', 'es' => 'Modelo del documento (lo que debe tener; validación automática por la IA)']), ENT_QUOTES, 'UTF-8') . '"><i class="bi bi-file-earmark-check"></i></button>'
    : '';
// Revisão G item 152 (migração 420): quem aprova cada documento exigido (pessoa ou grupo) no painel de Aprovações
if (is_file(__DIR__ . '/homologacaofornecedores_aprovadores_documentos.php')) $modeloBotao .= '<button type="button" class="btn btn-sm btn-outline-primary grid-crud-button" data-criterio-aprovadores title="' . htmlspecialchars(IdiomaService::t('homologacao_criterios.botao.aprovadores', ['pt' => 'Aprovadores dos documentos (pessoa ou grupo que aprova cada documento)', 'en' => 'Document approvers (person or group approving each document)', 'es' => 'Aprobadores de los documentos (persona o grupo que aprueba cada documento)']), ENT_QUOTES, 'UTF-8') . '"><i class="bi bi-person-check"></i></button>';

ManutencaoLegalCadastroGrid::render([
    'menu' => 'HOMOLOGACAO_CRITERIOS_FORNECEDORES',
    'title' => ['pt' => $u('Critérios dos Fornecedores'), 'en' => 'Supplier criteria', 'es' => 'Criterios de los proveedores'],
    'transaction' => 'homologacaofornecedores_criteriosfornecedores', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT',
    'fragment_class' => 'homologacao-criterios-fornecedores-grid-fragment',
    'table' => 'fo_criteriofornecedores', 'view' => 'vw_homologacao_criterios_fornecedores_grid',
    'primary' => 'CODCRITERIOFORNECEDOR', 'primary_auto' => true, 'primary_editable' => false,
    'default_sort' => ['column' => 'DSCRITERIO', 'direction' => 'asc'],
    'extras' => $modeloBotao,
    'fields' => [
        'CODCRITERIOFORNECEDOR' => ['label' => ['pt' => $u('Código'), 'en' => 'Code', 'es' => $u('Código')], 'hidden' => true, 'readonly' => true, 'filter' => 'none', 'width' => 0],
        'DSCRITERIO' => ['label' => ['pt' => $u('Critério (documento exigido)'), 'en' => 'Criterion (required document)', 'es' => 'Criterio (documento exigido)'], 'type' => 'textarea', 'required' => true, 'filter' => 'text', 'width' => 480],
        'DSCRITERIO_EN' => ['label' => ['pt' => $u('Critério em inglês'), 'en' => 'Criterion in English', 'es' => $u('Criterio en inglés')], 'type' => 'textarea', 'filter' => 'text', 'width' => 360],
        'QTD_TIPOS' => ['label' => ['pt' => 'Tipos', 'en' => 'Types', 'es' => 'Tipos'], 'readonly' => true, 'filter' => 'none', 'width' => 80],
        'QTD_EM_USO' => ['label' => ['pt' => 'Em uso', 'en' => 'In use', 'es' => 'En uso'], 'readonly' => true, 'filter' => 'none', 'width' => 90],
    ],
]);

require_once dirname(__DIR__) . '/components/toolbar_grupos.php';
greenPortalToolbarGrupos('.homologacao-criterios-fornecedores-grid-fragment', ['associacoes' => ['[data-criterio-modelo]', '[data-criterio-aprovadores]']]);
?>
<script>
(function () {
    // Migração 347: Modelo do documento do critério selecionado (popup)
    if (!window.jQuery) return;
    var $ = window.jQuery, fragment = $('.homologacao-criterios-fornecedores-grid-fragment').first(), botao = fragment.find('[data-criterio-modelo]');
    if (!botao.length) return;
    function linha() { return fragment.find('table tbody tr.selected').first(); }
    function selecionado() { var r = linha(); return r.length ? Number(r.attr('data-key') || 0) : 0; }
    function atualizar() { botao.prop('disabled', !selecionado()); }
    fragment.on('click', 'table tbody tr', atualizar).on('gridPageLoaded', atualizar);
    botao.on('click', function () {
        var id = selecionado(); if (!id) return;
        var host = window.top || window, r = linha(), nome = $.trim(r.find('[data-column="DSCRITERIO"]').find('textarea,input').first().val() || r.find('[data-column="DSCRITERIO"]').text() || ''),
            titulo = (window.greenT ? window.greenT('homologacao_criterios.popup.modelo', 'Modelo do documento') : 'Modelo do documento') + (nome ? ' - ' + nome.substring(0, 70) : ''),
            url = (host.greenPortalBaseUrl || '') + '/principal/homologacaofornecedores_modelo_documento.php?escopo=FORNECEDOR&criterio=' + encodeURIComponent(id);
        if (host.GreenPortalPopups && host.jQuery) host.GreenPortalPopups.transacaoIndependente({ titulo: titulo, conteudo: host.jQuery('<iframe>', { src: url, style: 'width:100%;height:100%;border:0;display:block;' }), amplo: true, redimensionavel: true });
        else if (host.greenPortalOpenTab) host.greenPortalOpenTab('criterio-modelo-fornecedor-' + id, url, titulo);
    });
    atualizar();
    fragment.find('[data-criterio-aprovadores]').on('click', function () {
        var host = window.top || window, titulo = window.greenT ? window.greenT('homologacao_criterios.popup.aprovadores', 'Aprovadores dos documentos') : 'Aprovadores dos documentos',
            url = (host.greenPortalBaseUrl || '') + '/principal/homologacaofornecedores_aprovadores_documentos.php';
        if (host.GreenPortalPopups && host.jQuery) host.GreenPortalPopups.transacaoIndependente({ titulo: titulo, conteudo: host.jQuery('<iframe>', { src: url, style: 'width:100%;height:100%;border:0;display:block;' }), amplo: true, redimensionavel: true });
        else if (host.greenPortalOpenTab) host.greenPortalOpenTab('criterio-aprovadores', url, titulo);
    });
}());
</script>
