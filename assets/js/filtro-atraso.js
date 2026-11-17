/*
 * Filtros dos grids: só pesquisam 1,5 s depois da última tecla (pedido do usuário: 3 s em 11/11/2026; 1,5 s em 16/11/2026).
 * Vale para o filtro por coluna (cabeçalho do grid) e para a busca geral do grid ("Buscar no grid" e equivalentes).
 *
 * Como funciona: carregado no main.php, instala-se no documento do portal e em todos os iframes do mesmo
 * endereço (telas, abas, popups em iframe, também os abertos depois). Num campo de filtro de texto, os eventos
 * "input"/"change" são segurados na fase de captura e reenviados uma única vez 1,5 s depois da última digitação.
 * Enter, ou o "x" de limpar do campo de busca, pesquisa na hora. Listas (select) continuam imediatas.
 * Assim os scripts de cada grid (grid-local, grid-pagination, grid-column-filters, homologação, segurança...)
 * não precisam mudar: recebem o mesmo evento, só que mais tarde.
 */
(function () {
    'use strict';
    var ESPERA = 1500;
    var FILTROS = [
        '[data-column-filter]', '[data-coluna-filtro]', '[data-grid-filter]', '[data-filtro]', '.column-filter',
        '.crud-search input', '[data-busca-grid]', '[data-busca]', '[data-uni-busca]', '[data-pag-busca]',
        '[data-sol-filtro="busca"]', '[data-dic-texto]', '#permissionSearch', '#profileSearch', '#menuGridSearch',
        'input[name="busca"]'
    ].join(',');
    var TIPOS_TEXTO = { '': 1, text: 1, search: 1, number: 1 };

    function ehFiltro(el) {
        if (!el || el.tagName !== 'INPUT' || !el.closest) return false;
        if (!TIPOS_TEXTO[String(el.getAttribute('type') || '').toLowerCase()]) return false;
        if (el.closest('tbody')) return false; // campos editáveis das linhas do grid: não são filtro
        if (el.id === 'menuSearch') return false; // busca do menu lateral
        return el.matches(FILTROS) || !!el.closest('thead');
    }

    function instalar(win) {
        var doc;
        try { doc = win.document; } catch (e) { return; } // outro endereço: sem acesso
        if (!doc || doc.__gpFiltroAtraso) return;
        doc.__gpFiltroAtraso = true;
        var liberados = new WeakSet(), pendentes = new Map(), ultimo = new WeakMap();

        function enviar(el, tipo) {
            var ev = new win.Event(tipo, { bubbles: true });
            liberados.add(ev);
            el.dispatchEvent(ev);
        }
        function soltar(el) {
            var p = pendentes.get(el); if (!p) return;
            win.clearTimeout(p.timer); pendentes.delete(el);
            if (!el.isConnected) return;
            ultimo.set(el, el.value);
            enviar(el, 'input');
            if (p.change) enviar(el, 'change');
        }
        function agendar(el, change) {
            var p = pendentes.get(el) || { change: false, timer: 0 };
            win.clearTimeout(p.timer);
            if (change) p.change = true;
            p.timer = win.setTimeout(function () { soltar(el); }, ESPERA);
            pendentes.set(el, p);
        }

        doc.addEventListener('input', function (ev) {
            if (liberados.has(ev) || !ehFiltro(ev.target)) return;
            ev.stopImmediatePropagation();
            agendar(ev.target, false);
        }, true);
        doc.addEventListener('change', function (ev) {
            if (liberados.has(ev) || !ehFiltro(ev.target)) return;
            if (!pendentes.has(ev.target)) {
                // ao sair do campo depois de a busca já ter rodado, o "change" do navegador não repete a pesquisa
                if (ultimo.has(ev.target) && ultimo.get(ev.target) === ev.target.value) ev.stopImmediatePropagation();
                return;
            }
            ev.stopImmediatePropagation();
            agendar(ev.target, true);
        }, true);
        // "x" de limpar (type=search) e Enter: pesquisa na hora
        doc.addEventListener('search', function (ev) {
            if (liberados.has(ev) || !ehFiltro(ev.target)) return;
            if (pendentes.has(ev.target)) soltar(ev.target);
        }, true);
        doc.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' && ehFiltro(ev.target) && pendentes.has(ev.target)) soltar(ev.target);
        }, true);

        // iframes já abertos e os que carregarem depois (o "load" do iframe passa pela captura do documento)
        doc.addEventListener('load', function (ev) {
            var t = ev.target;
            if (t && t.tagName === 'IFRAME') { try { instalar(t.contentWindow); } catch (e) { /* outro endereço */ } }
        }, true);
        Array.prototype.forEach.call(doc.querySelectorAll('iframe'), function (f) { try { instalar(f.contentWindow); } catch (e) { /* idem */ } });
    }

    instalar(window);
}());
