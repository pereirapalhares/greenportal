(function () {
    'use strict';
    // Não remover "Â" sozinho: é letra válida (ex.: Âmbito). Só o resto de codificação antes de espaço inseparável.
    var replacements = [
        ['ÃƒÂ§ÃƒÂ£', 'ção'], ['ÃƒÂ§ÃƒÂµ', 'ções'], ['ÃƒÂ¡', 'á'], ['ÃƒÂ©', 'é'], ['ÃƒÂ­', 'í'], ['ÃƒÂ³', 'ó'], ['ÃƒÂº', 'ú'], ['ÃƒÂ£', 'ã'], ['ÃƒÂµ', 'õ'], ['ÃƒÂ¢', 'â'], ['ÃƒÂª', 'ê'], ['ÃƒÂ´', 'ô'], ['ÃƒÂ§', 'ç'], ['Ã‚Â¿', '¿'], ['Ã‚Â', ''],
        ['Ã§Ã£', 'ção'], ['Ã§Ãµ', 'ções'], ['Ã¡', 'á'], ['Ã©', 'é'], ['Ã­', 'í'], ['Ã³', 'ó'], ['Ãº', 'ú'], ['Ã£', 'ã'], ['Ãµ', 'õ'], ['Ã¢', 'â'], ['Ãª', 'ê'], ['Ã´', 'ô'], ['Ã§', 'ç'], ['Â¿', '¿'], ['Â\u00a0', '\u00a0'], ['â€”', '—'], ['â€“', '–'], ['â€œ', '“'], ['â€', '”'], ['â€¦', '…']
    ];
    function fix(value) { var text = String(value == null ? '' : value); replacements.forEach(function (pair) { text = text.split(pair[0]).join(pair[1]); }); return text; }
    function normalize(root) {
        var scope = root && root.nodeType === 1 ? root : document.body;
        if (!scope) return;
        var walker = document.createTreeWalker(scope, NodeFilter.SHOW_TEXT), node;
        while ((node = walker.nextNode())) { var corrected = fix(node.nodeValue); if (corrected !== node.nodeValue) node.nodeValue = corrected; }
        scope.querySelectorAll('[title],[aria-label],[placeholder]').forEach(function (element) { ['title', 'aria-label', 'placeholder'].forEach(function (attribute) { if (element.hasAttribute(attribute)) { var value = element.getAttribute(attribute), corrected = fix(value); if (corrected !== value) element.setAttribute(attribute, corrected); } }); });
    }
    normalize(document);
    if (window.MutationObserver) new MutationObserver(function (records) { records.forEach(function (record) { record.addedNodes.forEach(function (node) { if (node.nodeType === 1) normalize(node); }); }); }).observe(document.documentElement, { childList: true, subtree: true });
}());
