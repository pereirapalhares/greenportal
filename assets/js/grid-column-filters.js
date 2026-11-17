(function ($) {
    'use strict';
    var comboColumns = { menuBatchGrid: ['codmenu_pai', 'modulo', 'nivel', 'ativo'], groupBatchGrid: ['ativo'], userBatchGrid: ['codperfil', 'codgrupo', 'ativo'], permissionBatchGrid: ['codperfil', 'codmenu', 'pode_incluir', 'pode_visualizar', 'pode_visualizar_total', 'pode_editar', 'pode_excluir', 'pode_exportar_excel', 'pode_gerar_pdf', 'pode_importar_arquivos', 'pode_visualizar_arquivos', 'pode_baixar_arquivos'], profileBatchGrid: ['acesso_total', 'ativo', 'aplicar_questionario'], formularioBatchGrid: ['codmenu', 'tipo', 'visivel', 'obrigatorio', 'codperfil', 'pode_visualizar', 'pode_editar', 'pode_incluir'], listaBatchGrid: ['origem', 'ativo'] };
    function normalize(value) { return String(value || '').toLocaleLowerCase('pt-BR').normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }
    function cellValue($cell) { var $field = $cell.find('select,input,textarea').first(); if (!$field.length) return String($cell.text() || '').trim(); if ($field.is(':checkbox')) return $field.is(':checked') ? '1' : '0'; return String($field.val() || '').trim(); }
    function cellLabel($cell) { var $field = $cell.find('select,input,textarea').first(); if ($field.is('select')) return String($field.find('option:selected').text() || '').trim(); if ($field.is(':checkbox')) return $field.is(':checked') ? 'Sim' : 'Não'; return cellValue($cell); }
    function applyFilter($table) {
        var $fragment = $table.closest('.menu-grid-fragment'), general = normalize($fragment.find('.crud-search input').val()), filters = {};
        $table.find('[data-column-filter]').each(function () { var $filter = $(this), value = String($filter.val() || ''); if (value !== '') filters[String($filter.data('column-filter'))] = { value: normalize(value), exact: $filter.is('select') }; });
        $table.find('tbody tr').each(function () {
            var $row = $(this), all = normalize($row.find('td').map(function () { return cellLabel($(this)); }).get().join(' ')), visible = !general || all.indexOf(general) !== -1;
            Object.keys(filters).forEach(function (column) { var rule = filters[column], $cell, raw, value = normalize(cellValue($row.find('[data-column="' + column + '"]'))); $cell = $row.find('[data-column="' + column + '"]'), raw = normalize($cell.find('[data-raw-value]').first().attr('data-raw-value')); visible = visible && (rule.exact ? (value === rule.value || (raw !== '' && raw === rule.value)) : value.indexOf(rule.value) !== -1); });
            $row.toggle(visible);
        });
    }
    $('.menu-grid-fragment .menu-batch-grid').each(function () {
        var $table = $(this), comboList = comboColumns[$table.attr('id')] || [];
        comboList.forEach(function (column) {
            var $filter = $table.find('[data-column-filter="' + column + '"]'); if (!$filter.length || $filter.is('select')) return;
            var values = {}, booleanField = /^(ativo|acesso_total|visivel|obrigatorio|pode_)/.test(column), fixedOptions = { nivel: { '0': 'Raiz', '1': 'Nível 1', '2': 'Nível 2', '3': 'Nível 3', '4': 'Nível 4' }, tipo: { text: 'Texto', number: 'Número', checkbox: 'Caixa de seleção', select: 'Lista de seleção', date: 'Data', email: 'E-mail' } };
            var $catalog = $('#permissionFilterTemplates select[data-permission-filter="' + column + '"]');
            if ($catalog.length) { $filter.replaceWith($catalog.clone().removeAttr('data-permission-filter').attr('data-column-filter', column).addClass('column-filter form-select form-select-sm')); return; }
            if (booleanField) { values['1'] = 'Sim'; values['0'] = 'Não'; }
            if (fixedOptions[column]) Object.keys(fixedOptions[column]).forEach(function (value) { values[value] = fixedOptions[column][value]; });
            $table.find('tbody tr').each(function () { var $cell = $(this).find('[data-column="' + column + '"]'), value = cellValue($cell), label = booleanField ? (value === '1' ? 'Sim' : 'Não') : cellLabel($cell); if (value !== '') values[value] = label || value; });
            var $select = $('<select class="column-filter form-select form-select-sm" data-column-filter="' + column + '" aria-label="Filtrar ' + column + '"><option value="">Todos</option></select>');
            Object.keys(values).sort(function (a, b) { return String(values[a]).localeCompare(String(values[b]), 'pt-BR', { numeric: true, sensitivity: 'base' }); }).forEach(function (value) { $select.append($('<option>', { value: value, text: values[value] })); });
            $filter.replaceWith($select);
        });
        $table.on('input change', '[data-column-filter]', function () { applyFilter($table); });
        var $fragment = $table.closest('.menu-grid-fragment');
        $fragment.find('.crud-search input').on('input search', function () { applyFilter($table); });
        applyFilter($table);
    });
}(jQuery));
