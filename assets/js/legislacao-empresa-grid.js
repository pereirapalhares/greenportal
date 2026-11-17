(function ($) {
    'use strict';
    var $fragment = $('.legislacao-empresa-grid-fragment'), $table = $('#legislacaoEmpresaBatchGrid'), $form = $('#legislacaoEmpresaGridForm');
    if (!$table.length) return;
    var options = window.legislacaoEmpresaOptions || {}, preferences = $fragment.data('preferences') || {};
    var types = options.tipos || { MATRIZ: 'MATRIZ' }, units = options.unidades || {};

    function esc(value) { return $('<div>').text(value == null ? '' : value).html(); }
    function unitSelect(key, selected) {
        var html = '<select class="grid-input" name="rows[' + key + '][codunidade]"><option value="">Selecione</option>';
        Object.keys(units).forEach(function (id) { html += '<option value="' + esc(id) + '"' + (String(id) === String(selected || '') ? ' selected' : '') + '>' + esc(units[id]) + '</option>'; });
        return html + '</select>';
    }
    function typeSelect(key, selected) {
        var html = '<select class="grid-input" name="rows[' + key + '][tipo_de_lei]"><option value="MATRIZ">MATRIZ</option>';
        Object.keys(types).filter(function (item) { return item !== 'MATRIZ'; }).forEach(function (type) { html += '<option value="' + esc(type) + '"' + (String(type) === String(selected || '') ? ' selected' : '') + '>' + esc(types[type]) + '</option>'; });
        return html + '</select>';
    }
    function rowHtml(key, source) {
        var code = source ? source.find('[data-column="CODIGO"] input').val() || '' : '', unit = source ? source.attr('data-unit-id') || '' : '', type = source ? source.find('[data-column="tipo_de_lei"] select').val() || 'MATRIZ' : 'MATRIZ';
        return '<td data-column="CODLEGISLACAXEMPRESA"><span class="text-muted">Novo</span></td>' +
            '<td data-column="CODIGO"><input class="grid-input" name="rows[' + key + '][CODIGO]" value="' + esc(code) + '"></td>' +
            '<td data-column="DSCUNIDADE">' + unitSelect(key, unit) + '</td>' +
            '<td data-column="tipo_de_lei">' + typeSelect(key, type) + '</td>';
    }
    function selected() { return $table.find('tbody tr.selected').first(); }
    function confirmAction(text, callback) {
        var $modal = $('#legislacaoEmpresaConfirmModal');
        if (!$modal.length) $modal = $('<div class="modal fade" id="legislacaoEmpresaConfirmModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Confirmação</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"></div><div class="modal-footer"><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-sm btn-success" data-confirm>Confirmar</button></div></div></div></div>').appendTo('body');
        $modal.find('.modal-body').text(text); $modal.off('click', '[data-confirm]').on('click', '[data-confirm]', function () { bootstrap.Modal.getInstance($modal[0]).hide(); if (callback) callback(); }); bootstrap.Modal.getOrCreateInstance($modal[0]).show();
    }
    function ajaxSave() {
        $.ajax({ url: window.location.href, method: 'POST', data: $form.serialize(), dataType: 'json' }).done(function (response) {
            if (!response || !response.ok) return;
            Object.keys(response.created || {}).forEach(function (temporary) { var id = response.created[temporary], $row = $table.find('tbody tr[data-key="' + temporary + '"]'); if (!$row.length) return; $row.attr('data-key', id).attr('data-row-state', ''); $row.find('[name]').each(function () { $(this).attr('name', $(this).attr('name').replace('rows[' + temporary + ']', 'rows[' + id + ']')); }); $row.find('[data-column="CODLEGISLACAXEMPRESA"]').html('<input class="grid-input" value="' + id + '" readonly>'); $row.find('[data-column="CODIGO"] input,[data-column="DSCUNIDADE"] select').prop('readonly', true).prop('disabled', true); });
            $form.data('grid-dirty', false); $fragment.find('.legislacao-empresa-message').remove(); $('<div class="alert alert-success py-2 mb-2 legislacao-empresa-message"></div>').text(response.message || 'Relacionamentos salvos com sucesso.').insertAfter($fragment.find('.crud-toolbar'));
        }).fail(function () { $fragment.find('.legislacao-empresa-message').remove(); $('<div class="alert alert-danger py-2 mb-2 legislacao-empresa-message"></div>').text('Nao foi possivel concluir a operacao. Revise os dados e tente novamente.').insertAfter($fragment.find('.crud-toolbar')); });
    }
    $table.on('click', 'tbody tr', function () { $table.find('tbody tr').removeClass('selected'); $(this).addClass('selected'); });
    $form.on('input change', 'tbody input,tbody select', function () { $form.data('grid-dirty', true); $(this).closest('tr').addClass('row-pending-edit'); });
    $form.on('submit', function (event) { event.preventDefault(); if (!$form.data('grid-dirty')) return; confirmAction('Confirma a gravação das alterações?', ajaxSave); });
    $('[data-new-legislacao-empresa]').on('click', function () { var key = 'novo_' + Date.now(); $table.find('tbody').prepend('<tr data-row data-key="' + key + '">' + rowHtml(key) + '</tr>'); $form.data('grid-dirty', true); });
    $('[data-duplicate-legislacao-empresa]').on('click', function () { var $row = selected(); if (!$row.length) return confirmAction('Selecione uma linha para duplicar.'); confirmAction('Confirma a duplicação do registro selecionado?', function () { var key = 'novo_' + Date.now(); $table.find('tbody').prepend('<tr data-row data-key="' + key + '" data-unit-id="' + esc($row.attr('data-unit-id') || '') + '">' + rowHtml(key, $row) + '</tr>'); $form.data('grid-dirty', true); }); });
    $('[data-delete-legislacao-empresa]').on('click', function () { var $row = selected(); if (!$row.length) return confirmAction('Selecione uma linha para excluir.'); confirmAction('Confirma a exclusão do relacionamento selecionado?', function () { var key = String($row.data('key')); if (key.indexOf('novo_') === 0) return $row.remove(); $.post(window.location.href, { action: 'delete_row', CODLEGISLACAXEMPRESA: key, ajax: 1 }, function (response) { if (response && response.ok) $row.remove(); }, 'json'); }); });
    $table.find('thead th[data-column="CODLEGISLACAXEMPRESA"],tbody td[data-column="CODLEGISLACAXEMPRESA"]').hide();
    $fragment.on('click', '[data-columns]', function () {
        var $modal = $('#legislacaoEmpresaColumnsModal');
        if (!$modal.length) $modal = $('<div class="modal fade" id="legislacaoEmpresaColumnsModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Colunas do grid</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" data-columns-list></div><div class="modal-footer"><button type="button" class="btn btn-sm btn-success" data-save-columns>Salvar colunas</button></div></div></div></div>').appendTo('body');
        var html = ''; $table.find('thead th').each(function () { var column = String($(this).data('column')), label = $(this).find('span').text(), checked = $table.find('[data-column="' + column + '"]:first').is(':visible'); html += '<label class="column-option d-block"><input type="checkbox" data-column-choice="' + esc(column) + '"' + (checked ? ' checked' : '') + (column === 'CODLEGISLACAXEMPRESA' ? ' disabled' : '') + '> ' + esc(label) + '</label>'; });
        $modal.find('[data-columns-list]').html(html); bootstrap.Modal.getOrCreateInstance($modal[0]).show();
    });
    $fragment.on('click', '[data-save-columns]', function () { var payload = []; $table.find('thead th').each(function (index) { var column = String($(this).data('column')), visible = $modalColumnChoice(column).prop('checked'); $table.find('[data-column="' + column + '"]').toggle(column === 'CODLEGISLACAXEMPRESA' ? false : visible); payload.push({ coluna: column, ordem: index, visivel: visible ? 1 : 0, largura: Math.round($(this).outerWidth() || 140) }); }); $.post(window.location.href, { action: 'save_preferences', preferences: JSON.stringify(payload) }); bootstrap.Modal.getInstance(document.getElementById('legislacaoEmpresaColumnsModal')).hide(); });
    function $modalColumnChoice(column) { return $('#legislacaoEmpresaColumnsModal [data-column-choice="' + column + '"]'); }
    $fragment.find('[data-reload-grid]').on('click', function () { window.location.reload(); });
}(jQuery));
