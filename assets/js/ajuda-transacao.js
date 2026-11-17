(function (window) {
    'use strict';

    function bind() {
        var document = window.document;
        if (!document || document.documentElement.dataset.ajudaTransacaoBound === '1') return;
        document.documentElement.dataset.ajudaTransacaoBound = '1';
        document.addEventListener('click', function (event) {
            var button = event.target.closest ? event.target.closest('[data-open-transacao-ajuda]') : null;
            if (!button) return;
            var transaction = String(button.getAttribute('data-help-transaction') || '');
            if (!transaction) return;
            var host = window.top || window;
            var popup = host.GreenPortalPopups;
            if (!popup || !host.jQuery) return;
            var base = String(host.greenPortalBaseUrl || '');
            var url = new URL(base + '/principal/setup_ajuda.php?transacao=' + encodeURIComponent(transaction), window.location.href).toString();
            var frame = host.jQuery('<iframe>', {style: 'width:100%;height:100%;border:0;display:block;' }).attr('src', url);
            var options = { titulo: button.getAttribute('title') || 'Ajuda', conteudo: frame };
            if (typeof popup.transacaoIndependente === 'function') popup.transacaoIndependente(options); else popup.transacao(options);
        });
    }

    if (window.document.readyState === 'loading') window.document.addEventListener('DOMContentLoaded', bind, { once: true });
    else bind();
}(window));


