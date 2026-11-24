(function ($) {
    'use strict';
    var $table = $('#userBatchGrid');
    if (!$table.length) return;

    function convert($row) {
        ['codperfil', 'codgrupo'].forEach(function (column) {
            var $cell = $row.find('[data-column="' + column + '"]'), $input = $cell.find('input').first(), $template = $('#userRelationTemplates select[data-user-relation="' + column + '"]');
            if (!$input.length || !$template.length) return;
            var value = $input.val() === '0' ? '' : $input.val(), $select = $template.clone().removeAttr('data-user-relation').addClass('grid-input').attr('name', $input.attr('name'));
            $select.val(value);
            $input.replaceWith($select);
        });
    }

    function convertRows() { $table.find('tbody tr').each(function () { convert($(this)); }); }

    function convertFilter(column) {
        var $filter = $table.find('[data-column-filter="' + column + '"]'), $template = $('#userRelationTemplates select[data-user-relation="' + column + '"]');
        if (!$filter.length || !$template.length || $filter.is('select')) return;
        var $select = $template.clone().removeAttr('data-user-relation').removeClass('grid-input').addClass('column-filter form-select form-select-sm').attr('data-column-filter', column).attr('aria-label', 'Filtrar ' + column);
        $select.find('option').first().text('Todos');
        $filter.replaceWith($select);
    }

    convertRows();
    convertFilter('codperfil');
    convertFilter('codgrupo');
    $table.closest('.menu-grid-fragment').on('gridPageLoaded', function () { convertRows(); });
    // 19/11/2026: observa a tabela inteira (a paginação troca o tbody e o observador antigo ficava preso ao tbody removido;
    // a linha de "Novo" ficava com o Perfil como número em vez da lista).
    if (window.MutationObserver) new MutationObserver(function (mutations) { mutations.forEach(function (mutation) { $(mutation.addedNodes).each(function () { var $n = $(this); if ($n.is('tr')) convert($n); else if ($n.is('tbody')) convertRows(); }); }); }).observe($table[0], { childList: true, subtree: true });
}(jQuery));
