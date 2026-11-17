(function ($) {
    'use strict';

    var $fragment = $('.user-grid-fragment');
    var $table = $('#userBatchGrid');
    var preferences = $fragment.data('preferences') || {};
    var columns = $table.find('thead th').map(function () { return $(this).data('column'); }).get();

    function applyVisibility() {
        columns.forEach(function (column) {
            var preference = preferences[column] || {};
            var visible = column === 'codusuario'
                ? false
                : (preference.visivel === undefined || !!Number(preference.visivel));
            $table.find('[data-column="' + column + '"]').toggle(visible);
        });
    }

    applyVisibility();

    // O grid-pagination troca o tbody quando um filtro ou paginação é aplicado.
    // Reaplica a regra para que o codusuario nunca volte a ocupar espaço.
    $fragment.on('gridPageLoaded', applyVisibility);

    $('[data-reload-grid]').on('click', function () { location.reload(); });

    $('[data-new-user]').on('click', function () {
        var key = 'novo_' + Date.now();
        $table.find('tbody').prepend(
            '<tr data-key="' + key + '">' +
            '<td data-column="codusuario">Novo</td>' +
            '<td data-column="userid"><input class="grid-input" name="rows[' + key + '][userid]"></td>' +
            '<td data-column="nome"><input class="grid-input" name="rows[' + key + '][nome]"></td>' +
            '<td data-column="email"><input class="grid-input" name="rows[' + key + '][email]"></td>' +
            '<td data-column="codperfil"><input class="grid-input" name="rows[' + key + '][codperfil]"></td>' +
            '<td data-column="codgrupo"><input class="grid-input" name="rows[' + key + '][codgrupo]"></td>' +
            '<td data-column="ativo"><input type="checkbox" name="rows[' + key + '][ativo]" value="1" checked></td>' +
            '</tr>'
        );
        applyVisibility();
    });

    $table.on('click', 'tbody tr', function () {
        $table.find('tbody tr').removeClass('selected');
        $(this).addClass('selected');
    });

    $('[data-delete-user]').on('click', function () {
        var $row = $table.find('tbody tr.selected');
        if (!$row.length) return;
        var key = $row.data('key');
        if (String(key).indexOf('novo_') === 0) {
            $row.remove();
            return;
        }
        $('#userGridForm input[name=action]').val('delete_row');
        $('<input type="hidden" name="codusuario">').val(key).appendTo('#userGridForm');
        $('#userGridForm').trigger('submit');
    });
}(jQuery));
