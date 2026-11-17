(function ($) {
    'use strict';

    var $table = $('#userBatchGrid');
    var $fragment = $('.user-grid-fragment');
    var preferences = $fragment.data('preferences') || {};

    if (!$table.length) {
        return;
    }

    function applyVisibility() {
        $table.find('[data-column="codusuario"]').hide();
    }

    // Mantém o corpo do grid na mesma ordem declarada no cabeçalho.
    // Isso também corrige linhas carregadas pela paginação sem alterar os nomes
    // dos campos enviados no formulário.
    function normalizeColumnOrder() {
        var order = $table.find('thead th').map(function () {
            return String($(this).data('column') || '');
        }).get();
        $table.find('tbody tr').each(function () {
            var $row = $(this);
            $.each(order, function (_, column) {
                var $cell = $row.children('[data-column="' + column + '"]');
                if ($cell.length) {
                    $row.append($cell);
                }
            });
        });
    }

    function ensurePasswordCells() {
        var $templates = $('#userPasswordTemplates');
        var $lastLoginTemplates = $('#userLastLoginTemplates');
        var $superiorTemplates = $('#userSuperiorTemplates');
        $table.find('tbody tr').each(function () {
            var $row = $(this);
            var id = String($row.data('key') || '');
            if ($templates.length && !$row.children('[data-column="senha"]').length) {
                var $template = $templates.find('[data-user-password="' + id + '"]');
                var value = String($template.data('password-value') || '');
                var $cell = $('<td data-column="senha" class="user-password-cell">');
                var $mask = $('<span data-password-mask>****</span>');
                var $value = $('<span data-password-value hidden>').text(value);
                var $button = $('<button type="button" class="btn btn-sm btn-link p-0 ms-1" data-toggle-user-password aria-label="Mostrar senha" title="Mostrar senha"><i class="bi bi-eye"></i></button>');
                $cell.append($mask, $value, $button);
                $row.append($cell);
            }
            if ($lastLoginTemplates.length && !$row.children('[data-column="ultimo_acesso"]').length) {
                var $loginTemplate = $lastLoginTemplates.find('[data-user-last-login="' + id + '"]');
                $row.append($('<td data-column="ultimo_acesso">').text(String($loginTemplate.data('last-login-value') || '')));
            }
            if ($superiorTemplates.length && !$row.children('[data-column="codusuario_superior"]').length) {
                var $superiorTemplate = $superiorTemplates.find('[data-user-superior="' + id + '"]');
                if (!$superiorTemplate.length) $superiorTemplate = $('#userSuperiorOptions');
                if ($superiorTemplate.length) {
                    var $select = $superiorTemplate.clone().removeAttr('data-user-superior').removeAttr('id').removeAttr('hidden').attr('name', 'rows[' + id + '][codusuario_superior]').addClass('grid-input');
                    $row.append($('<td data-column="codusuario_superior">').append($select));
                }
            }
        });
    }

    $(document).on('click', '[data-toggle-user-password]', function () {
        var $button = $(this);
        var $cell = $button.closest('[data-column="senha"]');
        var $mask = $cell.find('[data-password-mask]');
        var $value = $cell.find('[data-password-value]');
        var showing = !$value.prop('hidden');
        $value.prop('hidden', showing);
        $mask.prop('hidden', !showing);
        $button.attr('aria-label', showing ? 'Mostrar senha' : 'Ocultar senha').attr('title', showing ? 'Mostrar senha' : 'Ocultar senha');
        $button.find('i').toggleClass('bi-eye', showing).toggleClass('bi-eye-slash', !showing);
    });

    $(document).on('click', '[data-new-user]', function () {
        window.setTimeout(function () { ensurePasswordCells(); normalizeColumnOrder(); applyVisibility(); }, 0);
    });

    $(document).on('click', '[data-columns]', function () {
        var html = $table.find('thead th').map(function () {
            var $header = $(this);
            var column = String($header.data('column') || '');
            var label = $.trim($header.text()) || column;
            var locked = column === 'codusuario';
            var visible = !locked && $table.find('[data-column="' + column + '"]:first').is(':visible');

            return '<label class="column-option">' +
                '<input type="checkbox" data-user-column-toggle="' + column + '" ' +
                (visible ? 'checked ' : '') + (locked ? 'disabled ' : '') + '> ' +
                $('<div>').text(label).html() +
                '</label>';
        }).get().join('');

        var $modal = $('#userColumnsModal');
        if (!$modal.length) {
            $modal = $('<div id="userColumnsModal" class="columns-modal"><div class="columns-modal-content">' +
                '<h3>Colunas visíveis</h3><div class="columns-list"></div>' +
                '<div class="columns-actions"><button type="button" data-close-columns>Cancelar</button>' +
                '<button type="button" id="saveUserColumns">Salvar</button></div></div></div>');
            $('body').append($modal);
        }

        $modal.find('.columns-list').html(html);
        $modal.show();
    });

    $(document).on('click', '[data-close-columns]', function () {
        $('#userColumnsModal').hide();
    });

    $(document).on('click', '#saveUserColumns', function () {
        var payload = {};

        $('[data-user-column-toggle]').each(function () {
            var column = String($(this).data('user-column-toggle'));
            payload[column] = { visivel: $(this).is(':checked') ? 1 : 0 };
        });

        // CODUSUARIO é técnico e nunca deve ser exibido na grade.
        payload.codusuario = { visivel: 0 };
        Object.keys(payload).forEach(function (column) {
            preferences[column] = preferences[column] || {};
            preferences[column].visivel = payload[column].visivel;
        });
        $fragment.data('preferences', preferences);

        applyVisibility();
        var items = $table.find('thead th').map(function (index) {
            var column = String($(this).data('column'));
            var preference = preferences[column] || {};
            return {
                coluna: column,
                ordem: index,
                visivel: column === 'codusuario' ? 0 : (preference.visivel ? 1 : 0),
                largura: Math.max(60, Math.round($(this).outerWidth() || preference.largura || 140))
            };
        }).get();

        $.post(location.href, {
            action: 'save_preferences',
            preferences: JSON.stringify(items),
            ajax: 1
        });

        $('#userColumnsModal').hide();
    });

    $fragment.on('gridPageLoaded', function () { ensurePasswordCells(); normalizeColumnOrder(); applyVisibility(); });
    ensurePasswordCells();
    normalizeColumnOrder();
    applyVisibility();
}(jQuery));
