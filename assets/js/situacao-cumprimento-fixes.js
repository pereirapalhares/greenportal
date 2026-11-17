(function ($) {
    'use strict';
    $('.situacao-grid-fragment').each(function () {
        var $fragment = $(this), $form = $fragment.find('#situacaoGridForm');
        $fragment.find('[data-new-cadastro], [data-duplicate-cadastro]').remove();
        $fragment.find('button[type="submit"]').attr('data-save-cadastro', '');
        $fragment.find('[data-delete-cadastro]').attr('data-delete-cadastro', '');
        if (!$form.length) return;
        $form.on('change input', 'input, select, textarea', function () {
            $fragment.find('[data-save-cadastro]').prop('disabled', false).removeAttr('aria-disabled');
        });
    });
}(jQuery));
