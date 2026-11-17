(function ($) {
    'use strict';
    $(function () {
        var $fragment = $('.situacao-grid-fragment'), $actions = $fragment.find('.crud-actions[data-crud-buttons]').first(), $table = $fragment.find('.menu-batch-grid').first();
        if (!$fragment.length || !$actions.length || !$table.length) return;
        var language = String(window.greenPortalIdioma || 'pt');
        var historyLabel = language === 'en' ? 'Situation history' : (language === 'es' ? 'Historial de situación' : 'Histórico da situação');
        var $historyButton = $('<button>', { type: 'button', class: 'btn btn-sm btn-outline-primary grid-crud-button', title: historyLabel, 'aria-label': historyLabel }).attr('data-requisito-situacao-historico', '').append($('<i>', { class: 'bi bi-clock-history' }));
        var $button = $('<button>', { type: 'button', class: 'btn btn-sm btn-outline-primary grid-crud-button', title: 'Consultar os responsáveis atribuídos a esta situação', 'aria-label': 'Consultar os responsáveis atribuídos a esta situação', disabled: true }).attr('data-requisito-responsabilidades', '').append($('<i>', { class: 'bi bi-people' }));
        var $editButton = $('<button>', { type: 'button', class: 'btn btn-sm btn-outline-primary grid-crud-button', title: 'Administrar os responsáveis desta situação', 'aria-label': 'Administrar os responsáveis desta situação', disabled: true }).attr('data-requisito-responsabilidades-edicao', '').append($('<i>', { class: 'bi bi-pencil-square' }));
        $actions.append($historyButton).append($button).append($editButton);
        $table.on('click', 'tbody tr', function () { $table.find('tbody tr').removeClass('selected'); $(this).addClass('selected'); $historyButton.prop('disabled', false); $button.prop('disabled', false); $editButton.prop('disabled', false); });
        $historyButton.on('click', function () {
            var $row = $table.find('tbody tr.selected'), id = String($row.data('key') || ''), lawId = String($('.situacao-law-context').attr('data-situacao-law-id') || '');
            var codeCell = $row.find('[data-column="CODLEGISLACAO"]'), descriptionCell = $row.find('[data-column="dscsituacao"]');
            var legislationCode = String($('.situacao-law-context').attr('data-situacao-law-code') || codeCell.find('input,select,textarea').first().val() || codeCell.text() || '').trim();
            var situationDescription = String(descriptionCell.find('input,select,textarea').first().val() || descriptionCell.text() || '').trim();
            var allHistoryLabel = language === 'en' ? 'All history records' : (language === 'es' ? 'Todos los registros del historial' : 'Todos os registros de histórico');
            var title = legislationCode + ' - ' + (id ? situationDescription : allHistoryLabel), host = window.top || window;
            if (!id && !lawId) return;
            var url = (host.greenPortalBaseUrl || '') + '/principal/modulolegal_requisitoslegais_situacaohistorico.php?' + (id ? 'CODSITUACAO=' + encodeURIComponent(id) : 'CODLEGISLACAO=' + encodeURIComponent(lawId));
            if (host.GreenPortalPopups && host.jQuery && host.bootstrap) {
                var modalId = 'greenPortalSituacaoHistoricoModal_' + Date.now();
                var $modal = host.jQuery('<div class="modal fade green-portal-popup-transacao" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content" style="height:90vh"><div class="modal-header py-2"><h5 class="modal-title"></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar" title="Fechar"></button></div><div class="modal-body p-0" style="min-height:0;flex:1"></div></div></div></div>').attr('id', modalId).appendTo(host.document.body);
                var frame = host.jQuery('<iframe>', {style: 'width:100%;height:100%;border:0;display:block;' }).attr('src', url);
                $modal.find('.modal-title').text(title || historyLabel); $modal.find('.modal-body').append(frame);
                $modal.on('shown.bs.modal', function () { var zIndex = 1060 + (host.jQuery('.modal.show').length * 10); $modal.css('z-index', zIndex); host.jQuery('.modal-backdrop').last().css('z-index', zIndex - 5); });
                $modal.on('hidden.bs.modal', function () { $modal.remove(); });
                host.bootstrap.Modal.getOrCreateInstance($modal[0]).show();
            } else if (host.greenPortalOpenTab) host.greenPortalOpenTab('situacao-historico-' + id, url, title || historyLabel); else window.open(url, '_blank');
        });
        $button.on('click', function () { var $row = $table.find('tbody tr.selected'), id = String($row.data('key') || ''); if (!$row.length || !id) return; var host = window.parent && window.parent.GreenPortalPopups ? window.parent : window, url = 'requisito_responsabilidades.php?CODSITUACAO=' + encodeURIComponent(id); if (host.GreenPortalPopups && host.jQuery) { var frame = host.jQuery('<iframe>', {style: 'width:100%;height:100%;border:0;display:block;' }).attr('src', new URL(url, window.location.href).toString()); host.GreenPortalPopups.transacao({ titulo: 'Responsabilidades', conteudo: frame }); } });
        $editButton.on('click', function () { var $row = $table.find('tbody tr.selected'), id = String($row.data('key') || ''); if (!$row.length || !id) return; var host = window.parent && window.parent.GreenPortalPopups ? window.parent : window, url = 'requisito_responsabilidades_edicao.php?CODSITUACAO=' + encodeURIComponent(id); if (host.GreenPortalPopups && host.jQuery) { var frame = host.jQuery('<iframe>', {style: 'width:100%;height:100%;border:0;display:block;' }).attr('src', new URL(url, window.location.href).toString()); host.GreenPortalPopups.transacao({ titulo: 'Manter responsabilidades', conteudo: frame }); } });
    });
}(jQuery));


