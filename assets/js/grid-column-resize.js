(function ($) {
    'use strict';
    $('.menu-grid-fragment table.menu-batch-grid').each(function () {
        var $table = $(this), $fragment = $table.closest('.menu-grid-fragment');
        var preferences = $fragment.data('preferences') || {}, transaction = $fragment.data('grid-transaction') || ({ formularioBatchGrid: 'seguranca_formulario', listaBatchGrid: 'seguranca_lista' }[$table.attr('id')] || '');
        $table.find('thead th').each(function () {
            var $header = $(this), column = String($header.data('column'));
            var preference = preferences[column] || {};
            $table.find('[data-column="' + column + '"]').css('width', Math.max(60, Number(preference.largura || 140)) + 'px');
            if ($header.find('.column-resizer').length) return;
            $header.append('<span class="column-resizer" title="Redimensionar coluna"></span>');
            $header.on('pointerdown', '.column-resizer', function (event) {
                event.preventDefault(); event.stopPropagation();
                var startX = event.clientX, startWidth = $header.outerWidth();
                function move(moveEvent) { $table.find('[data-column="' + column + '"]').css('width', Math.max(60, startWidth + moveEvent.clientX - startX) + 'px'); }
                function stop() {
                    $(document).off('pointermove', move).off('pointerup', stop);
                    if (!transaction) return;
                    var payload = [];
                    $table.find('thead th').each(function (index) { var c = String($(this).data('column')); payload.push({ coluna: c, ordem: index, visivel: $table.find('[data-column="' + c + '"]:first').is(':visible') ? 1 : 0, largura: Math.max(60, Math.round($(this).outerWidth() || 140)) }); });
                    $.post(window.location.href, { action: 'save_preferences', preferences: JSON.stringify(payload) });
                }
                $(document).on('pointermove', move).on('pointerup', stop);
            });
        });
    });
}(jQuery));
