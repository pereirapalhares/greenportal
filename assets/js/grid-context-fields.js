(function ($) {
    'use strict';
    var enumOptions = {
        nivel: { '1': 'Nível 1', '2': 'Nível 2', '3': 'Nível 3', '4': 'Nível 4' },
        tipo: { text: 'Texto', number: 'Número', checkbox: 'Caixa de seleção', select: 'Lista de seleção', date: 'Data', email: 'E-mail' }
    };

    function optionsFromTable($table, column) {
        var values = {};
        $table.find('[data-column="' + column + '"]').each(function () {
            var value = String($(this).find('input,select').first().val() || $(this).text() || '').trim();
            if (value !== '') values[value] = value;
        });
        return values;
    }

    function convert($table, $row, column, options) {
        var $cell = $row.find('[data-column="' + column + '"]'), $input = $cell.find('input').first();
        if (!$input.length || $input.is(':checkbox') || $cell.find('select').length) return;
        var $select = $('<select class="grid-input"></select>').attr('name', $input.attr('name'));
        $select.append($('<option>', { value: '', text: 'Selecione' }));
        Object.keys(options).sort(function (a, b) { return String(options[a]).localeCompare(String(options[b]), 'pt-BR', { numeric: true, sensitivity: 'base' }); }).forEach(function (value) {
            $select.append($('<option>', { value: value, text: options[value] }));
        });
        $select.val(String($input.val() || ''));
        $input.replaceWith($select);
    }

    $('.menu-grid-fragment .menu-batch-grid').each(function () {
        var $table = $(this), id = $table.attr('id'), columns = id === 'menuBatchGrid' ? ['codmenu_pai', 'modulo', 'nivel'] : id === 'formularioBatchGrid' ? ['tipo'] : [];
        function process($rows) {
            columns.forEach(function (column) {
                var pais = column === 'codmenu_pai' ? $table.data('pais') : null;
                var options = enumOptions[column] || (pais && typeof pais === 'object' ? pais : optionsFromTable($table, column));
                $rows.each(function () { convert($table, $(this), column, options); });
            });
        }
        process($table.find('tbody tr'));
        var observer = window.MutationObserver ? new MutationObserver(function (mutations) { mutations.forEach(function (mutation) { $(mutation.addedNodes).filter('tr').each(function () { process($(this)); }); }); }) : null;
        function observar() { var body = $table.find('tbody')[0]; if (body && observer) { observer.disconnect(); observer.observe(body, { childList: true }); } }
        observar();
        // grid-pagination troca o tbody inteiro ao filtrar/paginar: converte as linhas novas e passa a observar o tbody novo
        $table.closest('.menu-grid-fragment').on('gridPageLoaded', function () { process($table.find('tbody tr')); observar(); });
    });
}(jQuery));
