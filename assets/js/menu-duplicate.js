(function ($) {
    'use strict';

    var $table = $('#menuBatchGrid');
    if (!$table.length || !window.MutationObserver) return;

    function makeUniqueCode($row) {
        var $key = $row.find('[data-column="codmenu"]');
        if (!$key.length || String($row.attr('data-key') || $row.data('key') || '').indexOf('novo_') !== 0) return;

        var $input = $row.find('[data-column="codigo"] input');
        if (!$input.length || String($input.val() || '').trim() === '') return;

        var base = String($input.val()).trim().toUpperCase().replace(/[^A-Z0-9_]/g, '_');
        $input.val((base + '_COPIA_' + Date.now().toString().slice(-6)).slice(0, 100));
    }

    var observer = new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
            $(mutation.addedNodes).filter('tr').each(function () {
                makeUniqueCode($(this));
            });
        });
    });

    observer.observe($table.find('tbody')[0], { childList: true });
}(jQuery));
