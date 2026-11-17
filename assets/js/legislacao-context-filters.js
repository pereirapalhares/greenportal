(function ($) {
    'use strict';
    $('.legal-context-grid,.legislacao-empresa-grid-fragment,.legislacao-poder-grid-fragment').each(function () {
        var $grid=$(this), options=window.legalContextOptions||{}, current=options.selected||{};
        if($grid.find('.legal-context-filters').length)return;
        function select(name,label,values){var html='<div class="legal-context-field"><label>'+label+'</label><select class="form-select form-select-sm" data-grid-filter="'+name+'"><option value="">Todos</option>';Object.keys(values||{}).forEach(function(key){html+='<option value="'+$('<div>').text(key).html()+'">'+$('<div>').text(values[key]).html()+'</option>';});return html+'</select></div>';}
        var html='<div class="legal-context-filters">'+select('CODEMPRESA','Empresa',options.companies)+select('MES_ANO_ATUALIZACAO','Mês/Ano de atualização',options.periods)+'<div class="legal-context-field"><label>Correlacionados?</label><select class="form-select form-select-sm" data-grid-filter="CORRELACIONADO"><option value="">Todos</option><option value="Sim">Sim</option><option value="Não">Não</option></select></div></div>';
        $grid.find('.crud-toolbar').after(html);$grid.find('[data-grid-filter]').each(function(){var key=String($(this).data('grid-filter'));$(this).val(current[key]||'');});
    });
}(jQuery));
