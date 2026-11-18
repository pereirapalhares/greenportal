// Onda 3 de idiomas: textos fixos traduzidos por window.greenTxt (AdministracaoTextos::script()).
var _tx = window._tx || function (s) { return typeof window.greenTxt === 'function' ? window.greenTxt(s) : s; };
(function ($) {
    'use strict';
    var $form = $('#userGridForm'), $grid = $('.user-grid-fragment');
    if (!$form.length) return;

    function message(text, type) {
        $grid.find('.user-ajax-message').remove();
        $('<div class="alert alert-' + (type || 'success') + ' py-2 mb-2 user-ajax-message" role="alert"></div>').text(text).insertAfter($grid.find('.crud-toolbar'));
    }

    $form.on('submit', function (event) {
        if (event.isDefaultPrevented && event.isDefaultPrevented()) return;
        event.preventDefault();
        var $currentForm = $(this), action = $currentForm.find('input[name="action"]').val();
        $currentForm.removeData('confirmed');
        $.ajax({ url: window.location.href, method: 'POST', data: $currentForm.serialize(), dataType: 'json' }).done(function (response) {
            if (!response || !response.ok) return message(response && response.message ? response.message : _tx('Não foi possível concluir a operação. Revise os dados informados e tente novamente.'), 'danger');
            if (action === 'delete_row') {
                var key = $currentForm.find('input[name="codusuario"]').val();
                $grid.find('tbody tr[data-key="' + key + '"]').remove();
                $currentForm.find('input[name="codusuario"]').remove();
                $currentForm.find('input[name="action"]').val('save_rows');
            } else {
                Object.keys(response.created || {}).forEach(function (temporaryKey) {
                    var newKey = response.created[temporaryKey], $row = $grid.find('tbody tr[data-key="' + temporaryKey + '"]');
                    if (!$row.length) return;
                    $row.attr('data-key', newKey);
                    $row.find('[data-column="codusuario"]').html('<input class="grid-input" value="' + newKey + '" readonly>');
                    $row.find('[name]').each(function () { $(this).attr('name', $(this).attr('name').replace('rows[' + temporaryKey + ']', 'rows[' + newKey + ']')); });
                });
            }
            if ($currentForm.data('set-grid-save-state')) $currentForm.data('set-grid-save-state')(false);
            message(response.message || _tx('Operação concluída.'));
        }).fail(function (xhr) { message(xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : _tx('Não foi possível concluir a operação. Revise os dados informados e tente novamente. Se o problema continuar, solicite apoio ao administrador do sistema.'), 'danger'); });
    });
}(jQuery));
