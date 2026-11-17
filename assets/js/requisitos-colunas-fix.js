(function ($) {
    'use strict';
    $('.modulo-legal-requisitos-fragment').each(function () {
        var $fragment = $(this), $table = $fragment.find('.menu-batch-grid'), modalSelector = '#requisitosColumnsModal';
        if (!$table.length) return;
        // O modal é criado no body, fora do fragmento. O listener precisa
        // ficar no documento do iframe para alcançar o botão Salvar.
        $(document).on('click.requisitosColumnsFix', modalSelector + ' [data-save-requisitos-columns]', function (event) {
            event.preventDefault();
            event.stopImmediatePropagation();
            var preferences = [], columns = $table.find('thead th').map(function () { return String($(this).data('column')); }).get();
            columns.forEach(function (column, index) {
                var $choice = $(modalSelector + ' [data-requisitos-column="' + column + '"]');
                preferences.push({ coluna: column, ordem: index, visivel: $choice.length ? ($choice.prop('checked') ? 1 : 0) : ($table.find('[data-column="' + column + '"]:first').is(':visible') ? 1 : 0), largura: Math.round($table.find('th[data-column="' + column + '"]').outerWidth() || 140) });
            });
            var $button = $(this).prop('disabled', true);
            $.ajax({ url: window.location.href, method: 'POST', dataType: 'json', data: { action: 'save_preferences', preferences: JSON.stringify(preferences), ajax: 1 } })
                .done(function (response) {
                    if (!response || response.ok !== true) { $button.prop('disabled', false); return; }
                    // Atualiza o grid imediatamente; o reload apenas reconfirma
                    // o estado vindo do servidor.
                    preferences.forEach(function (preference) {
                        var visible = Number(preference.visivel) === 1;
                        $table.find('[data-column="' + preference.coluna + '"]').toggle(visible);
                    });
                    var modal = bootstrap.Modal.getInstance($(modalSelector)[0]);
                    if (modal) modal.hide();
                    window.location.reload();
                })
                .fail(function () { $button.prop('disabled', false); });
        });
    });
}(jQuery));
