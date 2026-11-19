/*
 * Sessão expirada (19/11/2026): carregado no main.php (janela principal). Vigia as chamadas fetch/XHR da janela principal
 * e de todos os iframes e popups do portal (mesma origem, inclusive aninhados). Quando o servidor responde 401 com o
 * cabeçalho X-Sessao-Expirada (classes/SessaoExpirada.php), leva a janela principal para o login em vez de mostrar
 * "Sessão expirada" na tela.
 */
(function () {
    'use strict';
    var topo = window;
    if (topo.__gpSessaoExpirada) return;
    topo.__gpSessaoExpirada = true;
    var base = String(topo.greenPortalBaseUrl || '');
    var indo = false;
    function irLogin() {
        if (indo) return; indo = true;
        try { topo.location.replace(base + '/index.php?erro=sessao_expirada'); } catch (e) { topo.location.href = base + '/index.php?erro=sessao_expirada'; }
    }
    function expirou(status, cab) { return status === 401 && String(cab || '') === '1'; }
    function vigiar(w) {
        try {
            if (!w || w.__gpSessaoVigiada) return;
            w.__gpSessaoVigiada = true;
            if (typeof w.fetch === 'function') {
                var f = w.fetch;
                w.fetch = function () {
                    return f.apply(this, arguments).then(function (r) {
                        try { if (r && expirou(r.status, r.headers.get('X-Sessao-Expirada'))) irLogin(); } catch (e) {}
                        return r;
                    });
                };
            }
            if (w.XMLHttpRequest && w.XMLHttpRequest.prototype) {
                var send = w.XMLHttpRequest.prototype.send;
                w.XMLHttpRequest.prototype.send = function () {
                    var x = this;
                    try { x.addEventListener('loadend', function () { try { if (expirou(x.status, x.getResponseHeader('X-Sessao-Expirada'))) irLogin(); } catch (e) {} }); } catch (e) {}
                    return send.apply(this, arguments);
                };
            }
            var doc = w.document;
            var ligar = function (fr) {
                if (!fr || fr.__gpSessaoLigado) return;
                fr.__gpSessaoLigado = true;
                fr.addEventListener('load', function () { try { vigiar(fr.contentWindow); } catch (e) {} });
                try { if (fr.contentWindow && fr.contentDocument && fr.contentDocument.readyState !== 'uninitialized') vigiar(fr.contentWindow); } catch (e) {}
            };
            var varrer = function (raiz) { try { Array.prototype.forEach.call((raiz || doc).querySelectorAll('iframe, frame'), ligar); } catch (e) {} };
            varrer(doc);
            if (w.MutationObserver && doc.documentElement) {
                new w.MutationObserver(function (lista) {
                    lista.forEach(function (m) {
                        Array.prototype.forEach.call(m.addedNodes || [], function (n) {
                            if (n.nodeType !== 1) return;
                            if (n.tagName === 'IFRAME' || n.tagName === 'FRAME') ligar(n); else varrer(n);
                        });
                    });
                }).observe(doc.documentElement, { childList: true, subtree: true });
            }
        } catch (e) {}
    }
    vigiar(topo);
}());
