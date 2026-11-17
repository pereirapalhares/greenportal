(function (window, $) {
    'use strict';
    function openEditor(cell) {
        var host = window.top || window, popup = host.GreenPortalPopups;
        if (!popup || !host.jQuery) return;
        var id = cell.getAttribute('data-help-id'), lang = cell.getAttribute('data-help-lang');
        var url = new URL((host.greenPortalBaseUrl || '') + '/principal/html_edicao.php?id=' + encodeURIComponent(id) + '&idioma=' + encodeURIComponent(lang), window.location.href).toString();
        var frame = host.jQuery('<iframe>', {style: 'width:100%;height:100%;border:0;display:block;' }).attr('src', url);
        var modal = host.jQuery('#greenPortalTransacaoModal');
        var show = function () { popup.transacao({ titulo: 'Editar ajuda', conteudo: frame }); };
        if (modal.length) {
            modal.off('hidden.bs.modal.ajudaEdit').on('hidden.bs.modal.ajudaEdit', function () {
                modal.find('[data-popup-transacao-body]').empty();
            });
            if (modal.hasClass('show') && host.bootstrap) {
                modal.one('hidden.bs.modal', show);
                host.bootstrap.Modal.getOrCreateInstance(modal[0]).hide();
                return;
            }
        }
        show();
    }
    function bind() {
        $(document).off('dblclick.ajudaEdit', '.ajuda-editable').on('dblclick.ajudaEdit', '.ajuda-editable', function (event) { event.preventDefault(); event.stopPropagation(); openEditor(this); });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind, { once: true }); else bind();
}(window, window.jQuery));


