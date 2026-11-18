// Onda 3 de idiomas: textos fixos traduzidos por window.greenTxt (AdministracaoTextos::script()).
var _tx = window._tx || function (s) { return typeof window.greenTxt === 'function' ? window.greenTxt(s) : s; };
(function ($) {
    'use strict';
    var $fragment = $('.lista-grid-fragment'), $table = $('#listaBatchGrid');
    if (!$table.length) return;
    var preferences = $fragment.data('preferences') || {}, columns = $table.find('thead th').map(function () { return String($(this).data('column')); }).get();
    function apply() {
        columns.sort(function (a, b) { return Number((preferences[a] || {}).ordem || 0) - Number((preferences[b] || {}).ordem || 0); });
        columns.forEach(function (column) { $table.find('thead tr').append($table.find('th[data-column="' + column + '"]')); $table.find('tbody tr').each(function () { $(this).append($(this).find('td[data-column="' + column + '"]').detach()); }); });
        columns.forEach(function (column) { var pref = preferences[column] || {}, visible = column === 'codformulariolista' ? false : (pref.visivel === undefined ? true : !!Number(pref.visivel)); $table.find('[data-column="' + column + '"]').toggle(visible); });
    }
    function save() {
        $.post(window.location.href, { action: 'save_preferences', preferences: JSON.stringify(columns.map(function (column, index) { return { coluna: column, ordem: index, visivel: $table.find('[data-column="' + column + '"]:first').is(':visible') ? 1 : 0, largura: Math.round($table.find('th[data-column="' + column + '"]').outerWidth() || 140) }; })) });
    }
    function showModal() {
        var $modal = $('#listaColumnsModal');
        if (!$modal.length) { $modal = $('<div class="modal fade" id="listaColumnsModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">' + _tx("Colunas do grid") + '</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" id="listaColumnsList"></div><div class="modal-footer"><button type="button" class="btn btn-sm btn-success" data-save-lista-columns>' + _tx("Salvar colunas") + '</button></div></div></div></div>').appendTo('body'); }
        $('#listaColumnsList').html(columns.map(function (column) { var label = $table.find('th[data-column="' + column + '"] span').text(), checked = $table.find('[data-column="' + column + '"]:first').is(':visible'); return '<label class="column-option"><input type="checkbox" data-lista-column-toggle="' + column + '"' + (checked ? ' checked' : '') + '> ' + $('<div>').text(label).html() + '</label>'; }).join(''));
        bootstrap.Modal.getOrCreateInstance($modal[0]).show();
    }
    $('<button data-crud-action="columns" class="btn grid-crud-button btn-sm btn-outline-secondary" type="button" data-lista-columns><i class="bi bi-layout-three-columns me-1"></i>Colunas</button>').insertAfter($fragment.find('[data-export-lista]'));
    $fragment.on('click', '[data-lista-columns]', showModal);
    $(document).on('click', '[data-save-lista-columns]', function () { $('[data-lista-column-toggle]').each(function () { var column = $(this).data('lista-column-toggle'); preferences[column] = preferences[column] || {}; preferences[column].visivel = this.checked ? 1 : 0; }); apply(); save(); bootstrap.Modal.getInstance($('#listaColumnsModal')[0]).hide(); });
    apply();
}(jQuery));
