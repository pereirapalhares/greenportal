(function ($) {
    'use strict';
    var configs = { menuBatchGrid: { form: '#menuGridForm', key: 'codmenu', prefix: 'menu' }, groupBatchGrid: { form: '#groupGridForm', key: 'codgrupo', prefix: 'group' }, permissionBatchGrid: { form: '#permissionGridForm', key: 'codpermissao', prefix: 'permission' }, profileBatchGrid: { form: '#profileGridForm', key: 'codperfil', prefix: 'profile' }, formularioBatchGrid: { form: '#formularioGridForm', key: 'codpermissao_campo', prefix: 'formulario' }, listaBatchGrid: { form: '#listaGridForm', key: 'codformulariolista', prefix: 'lista' }, legislacaoBatchGrid: { form: '#legislacaoGridForm', key: 'CODLEGISLACAO', prefix: 'legislacao' } };
    $('table.menu-batch-grid').each(function () {
        var $table = $(this), config = configs[$table.attr('id')];
        if (!config) return;
        var $form = $(config.form), $grid = $table.closest('.menu-grid-fragment');
        if (!$form.length) return;
        function message(text, type) { $grid.find('.grid-ajax-message').remove(); $('<div class="alert alert-' + (type || 'success') + ' py-2 mb-2 grid-ajax-message" role="alert"></div>').text(text).insertAfter($grid.find('.crud-toolbar')); }
        $form.on('submit', function (event) {
            if (event.isDefaultPrevented && event.isDefaultPrevented()) return;
            event.preventDefault();
            var $current = $(this), action = $current.find('input[name="action"]').val();
            $.ajax({ url: window.location.href, method: 'POST', data: $current.serialize() + '&ajax=1', dataType: 'json' }).done(function (response) {
                if (!response || !response.ok) return message(response && response.message ? response.message : 'Não foi possível concluir a operação. Revise os dados informados e tente novamente.', 'danger');
                if (action === 'delete_row') { var key = $current.find('input[name="' + config.key + '"]').val(); $table.find('tbody tr[data-key="' + key + '"]').remove(); $current.find('input[name="' + config.key + '"]').remove(); $current.find('input[name="action"]').val('save_rows'); }
                Object.keys(response.created || {}).forEach(function (temporaryKey) { var newKey = response.created[temporaryKey], $row = $table.find('tbody tr[data-key="' + temporaryKey + '"]'); if (!$row.length) return; $row.attr('data-key', newKey).data('key', newKey); /* jQuery guarda o data-key lido antes: sem isso Excluir/Associações ainda viam "novo_..." */ $row.find('[data-column="' + config.key + '"]').html('<input class="grid-input" value="' + newKey + '" readonly>'); $row.find('[name]').each(function () { $(this).attr('name', $(this).attr('name').replace('rows[' + temporaryKey + ']', 'rows[' + newKey + ']')); }); });
                $table.find('tbody tr').removeClass('row-pending-edit row-operation-error').removeAttr('data-row-state');
                if ($current.data('set-grid-save-state')) $current.data('set-grid-save-state')(false);
                message(response.message || 'Operação concluída.');
            }).fail(function (xhr) { var response = xhr.responseJSON || {}; message(response.message || 'Não foi possível concluir a operação. Revise os dados informados e tente novamente. Se o problema continuar, solicite apoio ao administrador do sistema.', 'danger'); });
        });
    });
    $(document).ajaxError(function (event, xhr, settings) {
        if (settings && String(settings.type || '').toUpperCase() === 'POST') $('.menu-grid-fragment tbody tr.selected').addClass('row-operation-error').attr('data-row-state', 'Erro na operação');
    });
}(jQuery));
