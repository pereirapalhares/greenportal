(function () {
    'use strict';
    // Somente sequências corrompidas (UTF-8 lido como Latin-1). Texto correto não é alterado:
    // antes, "Â" e "Ã" sozinhos eram apagados ("Âmbito" virava "mbito") e "çã" ganhava um "o" a mais.
    var mapa = {
        '\u00c3\u00a7\u00c3\u00a3': '\u00e7\u00e3', '\u00c3\u00a7\u00c3\u00b5': '\u00e7\u00f5',
        '\u00c3\u00a1': '\u00e1', '\u00c3\u00a9': '\u00e9', '\u00c3\u00ad': '\u00ed', '\u00c3\u00b3': '\u00f3', '\u00c3\u00ba': '\u00fa',
        '\u00c3\u00a2': '\u00e2', '\u00c3\u00aa': '\u00ea', '\u00c3\u00b4': '\u00f4', '\u00c3\u00a3': '\u00e3', '\u00c3\u00b5': '\u00f5',
        '\u00c3\u00a7': '\u00e7', '\u00c3\u2030': '\u00c9', '\u00c2\u00bf': '\u00bf', '\u00c2\u00a0': ' '
    };
    function corrigir(valor) {
        var texto = String(valor || '');
        Object.keys(mapa).forEach(function (origem) { texto = texto.split(origem).join(mapa[origem]); });
        return texto;
    }
    function varrer(root) {
        var walker = document.createTreeWalker(root || document.body, NodeFilter.SHOW_TEXT), node;
        while ((node = walker.nextNode())) { var novo = corrigir(node.nodeValue); if (novo !== node.nodeValue) node.nodeValue = novo; }
        (root || document.body).querySelectorAll('[title],[aria-label]').forEach(function (elemento) {
            ['title', 'aria-label'].forEach(function (atributo) { if (elemento.hasAttribute(atributo)) elemento.setAttribute(atributo, corrigir(elemento.getAttribute(atributo))); });
        });
    }
    function iniciar() { var root = document.body; if (!root) return; var observer = new MutationObserver(function () { varrer(root); }); varrer(root); observer.observe(root, { childList: true, subtree: true, characterData: true }); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar); else iniciar();
}());
