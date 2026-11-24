// Onda 3 de idiomas: textos fixos traduzidos por window.greenTxt (AdministracaoTextos::script()).
var _tx = window._tx || function (s) { return typeof window.greenTxt === 'function' ? window.greenTxt(s) : s; };
(function ($) {
    'use strict';

    var $table = $('#userBatchGrid');
    var $fragment = $('.user-grid-fragment');
    var preferences = $fragment.data('preferences') || {};

    if (!$table.length) {
        return;
    }

    // 19/11/2026: respeita as colunas escolhidas em "Colunas" (antes só escondia o código e a escolha só valia depois de recarregar).
    function applyVisibility() {
        $table.find('thead th').each(function () {
            var column = String($(this).data('column') || ''), pref = preferences[column] || {};
            var visible = column !== 'codusuario' && (pref.visivel === undefined || !!Number(pref.visivel));
            $table.find('[data-column="' + column + '"]').toggle(visible);
        });
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
        var $generic = $('#userSuperiorOptions');
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
            // Grupos (19/11/2026, só consulta): linha nova fica vazia até salvar e abrir "Grupos do usuário".
            if ($table.find('thead th[data-column="grupos"]').length && !$row.children('[data-column="grupos"]').length) {
                $row.append($('<td data-column="grupos" class="small text-truncate">'));
            }
            // Data de inclusão (19/11/2026): linha nova ainda não tem data; a célula fica vazia até salvar.
            if (!$row.children('[data-column="criado_em"]').length) {
                $row.append($('<td data-column="criado_em" class="text-nowrap">'));
            }
            if ($lastLoginTemplates.length && !$row.children('[data-column="ultimo_acesso"]').length) {
                var $loginTemplate = $lastLoginTemplates.find('[data-user-last-login="' + id + '"]');
                $row.append($('<td data-column="ultimo_acesso">').text(String($loginTemplate.data('last-login-value') || '')));
            }
            if (!$row.children('[data-column="codusuario_superior"]').length) {
                // Só a opção atual; a lista completa entra ao abrir o campo (ver carregarSuperiores)
                var superior = String($row.attr('data-superior') || '0');
                var $select = $('<select class="grid-input" data-superior-select>').attr('name', 'rows[' + id + '][codusuario_superior]').attr('data-self', id);
                $select.append($('<option value="">').text($generic.find('option[value=""]').first().text() || 'Selecione'));
                if (superior !== '0' && superior !== '') {
                    var nome = $generic.find('option[value="' + superior + '"]').first().text() || ('#' + superior);
                    $select.append($('<option>').val(superior).text(nome)).val(superior);
                }
                $row.append($('<td data-column="codusuario_superior">').append($select));
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
        $button.attr('aria-label', showing ? 'Mostrar senha' : _tx('Ocultar senha')).attr('title', showing ? 'Mostrar senha' : _tx('Ocultar senha'));
        $button.find('i').toggleClass('bi-eye', showing).toggleClass('bi-eye-slash', !showing);
    });

    function carregarSuperiores() {
        var $select = $(this);
        if ($select.data('completo')) return;
        var atual = String($select.val() || ''), self = String($select.attr('data-self') || '');
        var $opcoes = $('#userSuperiorOptions').children().clone();
        $opcoes = $opcoes.filter(function () { return String(this.value) !== self || self === ''; });
        $select.empty().append($opcoes).val(atual).data('completo', 1);
    }
    $(document).on('mousedown focus keydown', '[data-superior-select]', carregarSuperiores);

    $(document).on('click', '[data-new-user]', function () {
        window.setTimeout(function () { ensurePasswordCells(); normalizeColumnOrder(); applyVisibility(); }, 0);
    });

    $(document).on('click', '[data-columns]', function () {
        var html = $table.find('thead th').map(function () {
            var $header = $(this);
            var column = String($header.data('column') || '');
            var label = $.trim($header.find('.column-heading > span').first().text()) || $.trim($header.text()) || column;
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
                '<h3>' + _tx("Colunas visíveis") + '</h3><div class="columns-list"></div>' +
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
