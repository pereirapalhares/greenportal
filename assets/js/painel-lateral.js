/* Próximos prazos, Aprovações e Calendário: o elemento (linha, item) com data-abrir abre o registro em popup do portal (90% da tela de onde veio,
   sempre na frente). Fora do portal, abre na própria janela. */
(function () {
    'use strict';
    function host() {
        var w = window;
        for (var i = 0; i < 6 && w; i++) {
            try { if (w.GreenPortalPopups && w.jQuery) return w; } catch (e) { return null; }
            if (w === w.parent) break;
            w = w.parent;
        }
        return null;
    }
    function abrir(tr) {
        var url = new URL(tr.getAttribute('data-abrir'), window.location.href).toString(), titulo = tr.getAttribute('data-titulo') || '', h = host();
        if (h) h.GreenPortalPopups.transacao90({ titulo: titulo, url: url }); else window.location.href = url;
    }
    document.addEventListener('click', function (ev) { var tr = ev.target.closest ? ev.target.closest('[data-abrir]') : null; if (tr) { ev.preventDefault(); abrir(tr); } });
    document.addEventListener('keydown', function (ev) { if (ev.key !== 'Enter' && ev.key !== ' ') return; var tr = ev.target.closest ? ev.target.closest('[data-abrir]') : null; if (tr) { ev.preventDefault(); abrir(tr); } });
}());
