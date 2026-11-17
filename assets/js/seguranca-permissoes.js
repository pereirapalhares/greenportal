(function ($) {
    'use strict';

    var $fragment = $('.permission-grid-fragment');
    var $table = $('#permissionBatchGrid');
    if (!$table.length) return;

    var preferences = $fragment.data('preferences') || {};
    var extraPermissionFields = ['pode_importar_arquivos', 'pode_visualizar_arquivos', 'pode_baixar_arquivos'];
    var extraPermissionLabels = { pode_importar_arquivos: 'Importar Arquivos', pode_visualizar_arquivos: 'Visualizar Arquivos', pode_baixar_arquivos: 'Baixar/Imprimir Arquivos' };
    function ensureExtraPermissionFields() {
      extraPermissionFields.forEach(function (field) {
        if (!$table.find('thead th[data-column="' + field + '"]').length) {
            $table.find('thead tr').append($('<th>', { 'data-column': field }).append($('<div class="column-heading">').append($('<span>').text(extraPermissionLabels[field] || field)).append($('<input>', { class: 'column-filter', type: 'search', 'data-column-filter': field, placeholder: 'Buscar', 'aria-label': 'Buscar ' + field }))));
        }
        $table.find('tbody tr').each(function () {
            var $row = $(this), $cell = $row.find('[data-column="' + field + '"]');
            if ($cell.length) return;
            var key = $row.attr('data-key') || '';
            $row.append($('<td>', { 'data-column': field, class: 'text-center' }).append($('<input>', { type: 'checkbox', name: 'rows[' + key + '][' + field + ']', value: '1' })));
        });
      });
    }
    ensureExtraPermissionFields();
    var columns = $table.find('thead th').map(function () { return $(this).data('column'); }).get();

    function rowValue($row, column) {
        var $cell = $row.find('[data-column="' + column + '"]');
        if (column === 'codmenu' && $cell.find('.permission-menu-label').length) return String($cell.find('.permission-menu-label').text() || '').trim();
        var $field = $cell.find('input,select,textarea');
        return String($field.length ? ($field.val() || '') : ($cell.text() || '')).trim();
    }

    function reorder() {
        var desiredOrder = ['codpermissao', 'codperfil', 'perfil_codigo', 'codmenu', 'pode_visualizar', 'pode_visualizar_total', 'pode_incluir', 'pode_editar', 'pode_excluir', 'pode_exportar_excel', 'pode_gerar_pdf', 'pode_importar_arquivos', 'pode_visualizar_arquivos', 'pode_baixar_arquivos'];
        columns.sort(function (a, b) { return desiredOrder.indexOf(a) - desiredOrder.indexOf(b); });
        columns.forEach(function (column) {
            $table.find('thead tr').append($table.find('th[data-column="' + column + '"]'));
            $table.find('tbody tr').each(function () { $(this).append($(this).find('td[data-column="' + column + '"]').detach()); });
        });
    }

    function applyPreferences() {
        reorder();
        columns.forEach(function (column) {
            var pref = preferences[column] || {};
            var technicalColumn = column === 'codperfil' || column === 'perfil_codigo';
            var visible = column === 'pode_visualizar_total' ? true : (technicalColumn ? false : (pref.visivel === undefined ? column !== 'codpermissao' : !!Number(pref.visivel)));
            $table.find('[data-column="' + column + '"]').css('width', Math.max(60, Number(pref.largura || 140)) + 'px').toggle(visible);
        });
    }

    function savePreferences() {
        var payload = columns.map(function (column, index) {
            preferences[column] = preferences[column] || {};
            return { coluna: column, ordem: index, visivel: $table.find('[data-column="' + column + '"]:first').is(':visible') ? 1 : 0, largura: Math.round($table.find('th[data-column="' + column + '"]').outerWidth() || 140) };
        });
        $.post(window.location.href, { action: 'save_preferences', preferences: JSON.stringify(payload) });
    }

    function installCatalogFilters() {
        var $filter = $table.find('[data-column-filter="codperfil"]');
        var $template = $('#permissionFilterTemplates select[data-permission-filter="codperfil"]');
        if (!$filter.length || !$template.length || $filter.is('select')) return;
        var $select = $template.clone().removeAttr('data-permission-filter');
        $select.addClass('form-select form-select-sm')
            .attr('data-grid-filter', 'codperfil')
            .attr('aria-label', 'Filtrar Perfil');
        var $holder = $('<label class="permission-profile-filter d-inline-flex align-items-center gap-1 mb-0" title="Filtrar por perfil"><span class="small text-muted">Perfil:</span></label>');
        $holder.append($select);
        var $pagination = $fragment.find('.grid-pagination').first();
        if ($pagination.length) $pagination.prepend($holder);
        else $filter.replaceWith($holder);

        var queryFilters = new URLSearchParams(window.location.search).get('filtros');
        if (queryFilters) {
            try {
                var parsedFilters = JSON.parse(queryFilters), profileFilter = parsedFilters && parsedFilters.codperfil;
                if (profileFilter && typeof profileFilter === 'object') profileFilter = profileFilter.value;
                if (profileFilter !== undefined && profileFilter !== null) $select.val(String(profileFilter));
            } catch (error) {}
        }
    }

    function hideTechnicalColumns() {
        ['codperfil', 'perfil_codigo'].forEach(function (column) {
            $table.find('[data-column="' + column + '"]').hide();
        });
    }

    function syncTechnicalColumnsVisibility() {
        var show = $table.find('tbody tr[data-key^="novo_"]').length > 0;
        ['codperfil', 'perfil_codigo'].forEach(function (column) {
            $table.find('[data-column="' + column + '"]').toggle(show);
        });
    }

    var menuTree = {};
    try { menuTree = JSON.parse($('#permissionMenuTreeData').text() || '{}') || {}; } catch (error) { menuTree = {}; }

    function decorateMenuTree() {
        $table.find('tbody tr').each(function () {
            var $row = $(this), $cell = $row.find('[data-column="codmenu"]'), $menu = $cell.find('select,input[name*="[codmenu]"]'), menuId = Number($menu.val() || 0), meta = menuTree[menuId] || {};
            $row.attr('data-menu-id', menuId).attr('data-menu-parent', Number(meta.parent || 0)).attr('data-menu-level', Number(meta.nivel || 0));
            $cell.attr('title', String($cell.find('.permission-menu-label').text() || meta.label || meta.codigo || ''));
        });
    }

    function makeMenuReadonly() {
        $table.find('tbody tr [data-column="codmenu"]').each(function () {
            var $cell = $(this), $select = $cell.find('select[name*="[codmenu]"]');
            if (!$select.length) return;
            var menuId = String($select.val() || ''), label = String($select.find('option:selected').text() || '').trim();
            $cell.empty().append($('<span class="permission-menu-label">').text(label)).append($('<input>', { type: 'hidden', name: $select.attr('name'), value: menuId }));
        });
    }

    // Perfil escolhido no seletor da barra (à esquerda da busca). Sem perfil o grid fica vazio e as ações desabilitadas;
    // ao escolher, a tela recarrega só com as permissões daquele perfil (?perfil=). Antes: popup ao abrir a tela.
    function setupProfileSelector() {
        var selected = Number($fragment.data('selected-profile') || 0), $picker = $('#permissionProfilePicker');
        var $actions = $fragment.find('.crud-actions').first();
        var $managedButtons = $actions.find('[data-new-permission],[data-crud-duplicate],[data-delete-permission],[data-reload-grid],[data-columns],button[type="submit"][form="permissionGridForm"],button[name="action"][value="export_request"]');
        $managedButtons.prop('disabled', !selected).attr('aria-disabled', selected ? 'false' : 'true');
        if (!selected) $managedButtons.attr('title', 'Selecione o perfil ao lado da busca');
        $('#permissionSearch').prop('disabled', !selected);
        $picker.toggleClass('is-empty', !selected);
        $picker.on('change', function () {
            var profileId = Number($(this).val() || 0);
            var url = new URL(window.location.href);
            if (profileId) url.searchParams.set('perfil', String(profileId)); else url.searchParams.delete('perfil');
            url.searchParams.set('pagina', '1');
            url.searchParams.delete('filtros');
            url.searchParams.delete('busca');
            window.location.href = url.toString();
        });
        if (!selected) setTimeout(function () { $picker.trigger('focus'); }, 0);
    }

    function filter() {
        var general = String($('#permissionSearch').val() || '').toLowerCase();
        var filters = {};
        $('[data-column-filter],[data-grid-filter]').each(function () {
            var column = $(this).data('column-filter') || $(this).data('grid-filter');
            if ($(this).val()) filters[column] = String($(this).val()).toLowerCase();
        });
        $table.find('tbody tr').each(function () {
            var $row = $(this);
            var all = columns.map(function (column) { return rowValue($row, column); }).join(' ').toLowerCase();
            var visible = !general || all.indexOf(general) !== -1;
            Object.keys(filters).forEach(function (column) { visible = visible && rowValue($row, column).toLowerCase().indexOf(filters[column]) !== -1; });
            $row.toggle(visible);
        });
    }

    function confirmation(text, callback) {
        var $modal = $('<div class="modal fade" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Confirmação</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"></div><div class="modal-footer"><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-sm btn-success" data-confirm>Confirmar</button></div></div></div></div>').appendTo('body');
        var instance = bootstrap.Modal.getOrCreateInstance($modal[0]);
        $modal.find('.modal-body').text(text);
        $modal.on('click', '[data-confirm]', function () { instance.hide(); if (callback) callback(); });
        $modal.on('hidden.bs.modal', function () { $modal.remove(); });
        instance.show();
    }

    decorateMenuTree();
    makeMenuReadonly();
    applyPreferences();
    hideTechnicalColumns();
    setupProfileSelector();
    $fragment.on('gridPageLoaded', function () { ensureExtraPermissionFields(); decorateMenuTree(); makeMenuReadonly(); applyPreferences(); hideTechnicalColumns(); });
    $('#permissionSearch,[data-column-filter],[data-grid-filter]').on('input change', filter);
    $('[data-reload-grid]').on('click', function () { window.location.reload(); });
    $table.on('click', 'tbody tr', function () { $table.find('tbody tr').removeClass('selected'); $(this).addClass('selected'); });

    $('[data-new-permission]').on('click', function () {
        var key = 'novo_' + Date.now();
        var $source = $table.find('tbody tr').first();
        if (!$source.length) return;
        var $row = $source.clone().removeClass('selected').attr('data-key', key);
        $row.find('[data-column="codpermissao"]').html('<span class="text-muted">Novo</span>');
        $row.find('[data-column="perfil_codigo"]').empty();
        $row.find('input,select').each(function () {
            var $field = $(this), name = $field.attr('name') || '';
            $field.attr('name', name.replace(/rows\[[^\]]+\]/, 'rows[' + key + ']'));
            if ($field.is(':checkbox')) $field.prop('checked', false);
            if ($field.is('select') && $field.closest('[data-column="codperfil"]').length === 0) $field.val('');
        });
        $table.find('tbody').prepend($row);
        $row.find('[data-column="codmenu"] input').val('');
        $row.find('[data-column="codmenu"] .permission-menu-label').text('');
        syncTechnicalColumnsVisibility();
    });

    $('[data-crud-duplicate]').on('click', function () {
        var $selected = $table.find('tbody tr.selected');
        if (!$selected.length) return confirmation('Selecione uma linha para duplicar.', null);
        confirmation('Confirma a duplicação da permissão selecionada?', function () {
            var key = 'novo_' + Date.now();
            var $row = $selected.clone().removeClass('selected').attr('data-key', key);
            $row.find('[data-column="codpermissao"]').html('<span class="text-muted">Novo</span>');
            $row.find('input,select').each(function () {
                var $field = $(this), name = $field.attr('name') || '';
                $field.attr('name', name.replace(/rows\[[^\]]+\]/, 'rows[' + key + ']'));
            });
            $table.find('tbody').prepend($row);
            syncTechnicalColumnsVisibility();
        });
    });

    $('[data-delete-permission]').on('click', function () {
        var $selected = $table.find('tbody tr.selected');
        if (!$selected.length) return;
        var key = $selected.attr('data-key');
        confirmation('Confirma a exclusão da permissão selecionada?', function () {
            if (String(key).indexOf('novo_') === 0) {
                $selected.remove();
                syncTechnicalColumnsVisibility();
                return;
            }
            var $form = $('#permissionGridForm');
            $form.find('input[name="action"]').val('delete_row');
            $('<input>', { type: 'hidden', name: 'codpermissao', value: key }).appendTo($form);
            $form.data('confirmed', true).trigger('submit');
        });
    });

    $('#permissionGridForm').on('submit', function (event) {
        if ($(this).data('confirmed')) { $(this).removeData('confirmed'); return; }
        event.preventDefault();
        var form = this;
        confirmation('Confirma a gravação das permissões?', function () { $(form).data('confirmed', true).trigger('submit'); });
    });

    $('[data-columns]').on('click', function () {
        var html = columns.map(function (column) {
            var checked = $table.find('[data-column="' + column + '"]:first').is(':visible') ? ' checked' : '';
            return '<label class="column-option"><input type="checkbox" data-permission-column-toggle="' + column + '"' + checked + '> ' + $table.find('th[data-column="' + column + '"] span').text() + '</label>';
        }).join('');
        $('#permissionColumnsList').html(html);
        bootstrap.Modal.getOrCreateInstance(document.getElementById('permissionColumnsModal')).show();
    });

    $('[data-save-permission-columns]').on('click', function () {
        $('[data-permission-column-toggle]').each(function () { preferences[$(this).data('permission-column-toggle')] = { visivel: this.checked ? 1 : 0 }; });
        applyPreferences();
        savePreferences();
        bootstrap.Modal.getInstance(document.getElementById('permissionColumnsModal')).hide();
    });

    $table.find('th').each(function () {
        var $header = $(this), column = $header.data('column');
        $header.attr('draggable', true).append('<span class="column-resizer"></span>');
        $header.on('dragstart', function (event) { event.originalEvent.dataTransfer.setData('text/plain', String(column)); });
        $header.on('dragover', function (event) { event.preventDefault(); });
        $header.on('drop', function (event) {
            event.preventDefault();
            var from = event.originalEvent.dataTransfer.getData('text/plain'), fromIndex = columns.indexOf(from), toIndex = columns.indexOf(String(column));
            if (fromIndex < 0 || toIndex < 0) return;
            columns.splice(fromIndex, 1); columns.splice(toIndex, 0, from); applyPreferences(); savePreferences();
        });
    });

    $table.on('pointerdown', '.column-resizer', function (event) {
        event.preventDefault();
        var $header = $(this).closest('th'), column = $header.data('column'), startX = event.clientX, startWidth = $header.outerWidth();
        function move(moveEvent) { $table.find('[data-column="' + column + '"]').css('width', Math.max(60, startWidth + moveEvent.clientX - startX) + 'px'); }
        function stop() { $(document).off('pointermove', move).off('pointerup', stop); savePreferences(); }
        $(document).on('pointermove', move).on('pointerup', stop);
    });
    // Marcar/desmarcar a coluna inteira: checkbox no cabeçalho de cada permissão. Vale para as linhas visíveis
    // (página atual, depois dos filtros e da busca); cada linha alterada fica "Alteração pendente" até Salvar.
    var permissionFields = ['pode_visualizar', 'pode_visualizar_total', 'pode_incluir', 'pode_editar', 'pode_excluir', 'pode_exportar_excel', 'pode_gerar_pdf', 'pode_importar_arquivos', 'pode_visualizar_arquivos', 'pode_baixar_arquivos'];
    function visibleColumnBoxes(field) {
        return $table.find('tbody tr[data-row]:visible td[data-column="' + field + '"] input[type="checkbox"]');
    }
    function refreshColumnCheckAll() {
        permissionFields.forEach(function (field) {
            var $all = $table.find('thead input[data-check-column="' + field + '"]');
            if (!$all.length) return;
            var $boxes = visibleColumnBoxes(field), total = $boxes.length, marked = $boxes.filter(':checked').length;
            $all.prop('checked', total > 0 && marked === total).prop('indeterminate', marked > 0 && marked < total).prop('disabled', total === 0);
        });
    }
    function installColumnCheckAll() {
        permissionFields.forEach(function (field) {
            var $th = $table.find('thead th[data-column="' + field + '"]');
            if (!$th.length || $th.find('input[data-check-column]').length) return;
            var label = String($th.find('.column-heading > span').first().text() || field).trim();
            $('<input>', { type: 'checkbox', class: 'form-check-input permission-check-all', 'data-check-column': field, title: 'Marcar ou desmarcar "' + label + '" em todas as linhas visíveis', 'aria-label': 'Marcar ou desmarcar ' + label + ' em todas as linhas visíveis' })
                .prependTo($th.find('.column-heading > span').first());
        });
        refreshColumnCheckAll();
    }
    $table.on('click', 'thead input[data-check-column]', function (event) { event.stopPropagation(); });
    $table.on('change', 'thead input[data-check-column]', function () {
        var field = $(this).data('check-column'), marcar = this.checked;
        visibleColumnBoxes(field).each(function () {
            if (this.checked === marcar) return;
            $(this).prop('checked', marcar).trigger('change'); // marca a linha como alteração pendente (grid-pagination.js)
        });
        refreshColumnCheckAll();
    });
    $table.on('change', 'tbody input[type="checkbox"]', function () { refreshColumnCheckAll(); });
    $('#permissionSearch,[data-column-filter],[data-grid-filter]').on('input change', function () { setTimeout(refreshColumnCheckAll, 0); });
    $fragment.on('gridPageLoaded', function () { setTimeout(installColumnCheckAll, 0); });
    installColumnCheckAll();
}(jQuery));
