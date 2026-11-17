(function ($) {
    'use strict';
    function T(chave, padrao) { return typeof window.greenT === 'function' ? window.greenT(chave, padrao) : padrao; }

    $('.cadastro-legal-fragment').each(function () {
        var $fragment = $(this), $form = $fragment.find('.menu-grid-form'), $table = $fragment.find('.menu-batch-grid');
        if (!$form.length) return;
        var $save = $fragment.find('[data-save-cadastro][form="' + $form.attr('id') + '"]');

        function confirmSave(callback) {
            var id = 'greenPortalCadastroConfirm';
            $('#' + id).remove();
            var $modal = $('<div class="modal fade" id="' + id + '" tabindex="-1" role="dialog" aria-modal="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">' + T('comum.confirmacao.titulo', 'Confirmação') + '</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div><div class="modal-body">' + T('comum.confirmacao.gravar', 'Confirma a gravação das alterações?') + '</div><div class="modal-footer"><button type="button" class="btn btn-sm btn-outline-secondary" data-cancelar>' + T('comum.confirmacao.cancelar', 'Cancelar') + '</button><button type="button" class="btn btn-sm btn-success" data-confirmar>' + T('comum.confirmacao.confirmar', 'Confirmar') + '</button></div></div></div></div>');
            $('body').append($modal);
            var modal = bootstrap.Modal.getOrCreateInstance($modal[0]);
            $modal.on('click', '[data-confirmar]', function () { modal.hide(); callback(); });
            $modal.on('click', '[data-cancelar]', function () { modal.hide(); });
            modal.show();
        }

        // Replace the older delegated handler with a native submit call. This
        // avoids re-entering jQuery.submit() and guarantees a POST for new rows.
        $form.off('submit').on('submit', function (event) {
            if ($form.data('confirmed-submit')) {
                $form.removeData('confirmed-submit');
                return;
            }
            event.preventDefault();
            // Campos obrigatórios e datas são tratados pelo grid (manutencao-legal-cadastro.js) antes da confirmação.
            var antesDeGravar = $form.data('antes-de-gravar');
            if (typeof antesDeGravar === 'function' && antesDeGravar() === false) return;
            confirmSave(function () {
                $form.data('confirmed-submit', true);
                HTMLFormElement.prototype.submit.call($form[0]);
            });
        });

        $fragment.on('click.cadastroLegalFixes', '[data-new-cadastro]', function () {
            $form.data('grid-dirty', true);
            window.setTimeout(function () { $save.prop('disabled', false).removeAttr('aria-disabled'); }, 0);
        });

        // Row selection must not swallow the native select interaction.
        $table.on('mousedown.cadastroLegalFixes click.cadastroLegalFixes', 'select.grid-input', function (event) {
            event.stopPropagation();
        });
    });
}(jQuery));
