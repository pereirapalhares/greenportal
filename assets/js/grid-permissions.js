(function ($) {
    'use strict';
    var permission = window.greenGridPermissions || {};
    function allowed(name) { return permission[name] === true || Number(permission[name]) === 1; }
    var $fragment = $('.menu-grid-fragment');
    if (!$fragment.length) return;
    if (!allowed('pode_visualizar')) {
        $fragment.find('.crud-toolbar, .menu-grid-scroll, .grid-pagination').remove();
        $('<div class="alert alert-warning grid-permission-message">Você não possui permissão para visualizar esta transação.</div>').appendTo($fragment);
        return;
    }
    if (!allowed('pode_incluir')) $fragment.find('[data-new-menu],[data-new-group],[data-new-user],[data-new-permission],[data-new-profile],[data-new-formulario],[data-new-lista],[data-new-legislacao-empresa],[data-crud-duplicate],[data-duplicate-lista],[data-duplicate-formulario],[data-duplicate-legislacao-empresa]').prop('disabled', true).attr('title', 'Seu perfil não permite incluir novos registros.');
    if (!allowed('pode_excluir')) $fragment.find('[data-delete-menu],[data-delete-group],[data-delete-user],[data-delete-permission],[data-delete-profile],[data-delete-formulario],[data-delete-lista],[data-delete-legislacao-empresa]').prop('disabled', true).attr('title', 'Seu perfil não permite excluir registros.');
    if (!allowed('pode_exportar_excel')) $fragment.find('[name="action"][value="export_request"],[data-export-lista],[data-export-formulario]').prop('disabled', true).attr('title', 'Seu perfil não permite exportar esta transação.');
    if (!allowed('pode_editar')) $fragment.find('tbody .grid-input:not([readonly]), tbody input[type="checkbox"]').prop('disabled', true).attr('title', 'Seu perfil permite apenas visualizar esta transação.');
    if (!allowed('pode_editar') && !allowed('pode_incluir')) $fragment.find('button[type="submit"][form$="GridForm"]').prop('disabled', true);
}(jQuery));
(function ($) {
    $('.legislacao-empresa-grid-fragment,.legislacao-poder-grid-fragment').each(function () {
        var $grid=$(this), options=window.legalContextOptions||{}, selected=options.selected||{};
        if($grid.find('.legal-context-filters').length)return;
        function select(name,label,values){var html='<div class="legal-context-field"><label>'+label+'</label><select class="form-select form-select-sm" data-grid-filter="'+name+'"><option value="">Todos</option>';Object.keys(values||{}).forEach(function(key){html+='<option value="'+$('<div>').text(key).html()+'">'+$('<div>').text(values[key]).html()+'</option>';});return html+'</select></div>';}
        var html='<div class="legal-context-filters">'+select('CODEMPRESA','Empresa',options.companies)+select('MES_ANO_ATUALIZACAO','Mês/Ano de atualização',options.periods)+'<div class="legal-context-field"><label>Correlacionados?</label><select class="form-select form-select-sm" data-grid-filter="CORRELACIONADO"><option value="">Todos</option><option value="Sim">Sim</option><option value="Não">Não</option></select></div></div>';
        $grid.find('.crud-toolbar').after(html);$grid.find('[data-grid-filter]').each(function(){var key=String($(this).data('grid-filter'));$(this).val(selected[key]||'');});
    });
}(jQuery));
