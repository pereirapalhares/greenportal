(function ($) {
    'use strict';
    $('.modulo-legal-requisitos-fragment, .menu-grid-fragment').has('[data-open-monitoramento-responsabilidades]').each(function () {
        var $fragment = $(this), $table = $fragment.find('.menu-batch-grid');
        function atualizar() { $fragment.find('[data-open-monitoramento-responsabilidades]').prop('disabled', !$table.find('tbody tr.selected').length); }
        $table.on('click', 'tbody tr', function () { $table.find('tbody tr').removeClass('selected'); $(this).addClass('selected'); atualizar(); });
        $fragment.on('gridPageLoaded', atualizar);
        $fragment.on('click', '[data-open-monitoramento-responsabilidades]', function () {
            var $row = $table.find('tbody tr.selected'), $cell = $row.find('[data-column="CODMONITORAMENTO"]'), $control = $cell.find('input,select,textarea').first(), id = $.trim(String($control.val() || $cell.text() || ''));
            if (!$row.length || !id) return;
            var host = window.parent && window.parent.GreenPortalPopups ? window.parent : window, popup = host.GreenPortalPopups, url = 'monitoramento_responsabilidades.php?CODMONITORAMENTO=' + encodeURIComponent(id);
            if (popup && host.jQuery) popup.transacao({ titulo: 'Responsabilidades do monitoramento', conteudo: host.jQuery('<iframe>', {style: 'width:100%;height:100%;border:0;display:block;' }).attr('src', new URL(url, window.location.href).toString()) }); else window.location.href = url;
        });
        atualizar();
    });
}(jQuery));


