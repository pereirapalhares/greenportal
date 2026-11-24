/*
 * Memória dos filtros dos grids (24/11/2026, pedido do usuário): o grid volta com o último filtro pedido
 * ao recarregar (F5) ou ao fechar e abrir a tela de novo.
 *
 * Como funciona: carregado no main.php, instala-se no documento do portal e em todos os iframes do mesmo endereço
 * (telas, abas, popups em iframe, também os abertos depois), como o filtro-atraso.js. Vale para qualquer grid:
 * - guarda neste navegador (localStorage), por usuário/empresa e por tela, o valor de cada campo de filtro
 *   (filtro por coluna do cabeçalho, busca geral do grid e filtros da barra) a cada pesquisa;
 * - ao abrir a tela, preenche os campos de filtro que vieram vazios com o último valor e dispara a pesquisa,
 *   do mesmo jeito que o usuário faria (cada grid continua com o seu próprio script).
 * Não restaura quando a tela foi aberta já com um filtro explícito na URL (filtros/busca, ex.: cartão de dashboard),
 * nem mexe em campos que já vieram preenchidos. Limpar o filtro também é guardado (volta limpo).
 * Campos das linhas do grid (tbody), a busca do menu e o combo Empresa (data-multi-empresa) ficam de fora.
 */
(function () {
    'use strict';
    var FILTROS = [
        '[data-column-filter]', '[data-coluna-filtro]', '[data-grid-filter]', '[data-filtro]', '.column-filter',
        '.crud-search input', '[data-busca-grid]', '[data-busca]', '[data-uni-busca]', '[data-pag-busca]',
        '[data-sol-filtro="busca"]', '[data-dic-texto]', '#permissionSearch', '#profileSearch', '#menuGridSearch',
        'input[name="busca"]'
    ].join(',');
    var TIPOS_TEXTO = { '': 1, text: 1, search: 1, number: 1, date: 1 };
    var PARAMS_VOLATEIS = { pagina: 1, filtros: 1, busca: 1, contar: 1, salvo: 1, ajax: 1, v: 1, _: 1, t: 1, ts: 1 };
    var PREFIXO = 'gpFiltros:';

    function chaveUsuario() {
        try { return String((window.top && window.top.greenPortalFiltroChave) || window.greenPortalFiltroChave || ''); } catch (e) { return String(window.greenPortalFiltroChave || ''); }
    }

    function ehFiltro(el) {
        if (!el || !el.closest || !el.matches) return false;
        var tag = el.tagName;
        if (tag !== 'INPUT' && tag !== 'SELECT') return false;
        if (tag === 'INPUT' && !TIPOS_TEXTO[String(el.getAttribute('type') || '').toLowerCase()]) return false;
        if (el.closest('tbody')) return false;               // campos editáveis das linhas
        if (el.id === 'menuSearch') return false;            // busca do menu lateral
        if (el.hasAttribute('data-multi-empresa')) return false; // combo Empresa: sempre a empresa logada
        if (el.hasAttribute('data-sem-memoria') || el.closest('[data-sem-memoria]')) return false;
        if (el.closest('.modal')) return false;              // campos de janelas de diálogo
        return el.matches(FILTROS) || !!el.closest('thead');
    }

    // Identificação estável do campo dentro da tela
    function nomeCampo(el) {
        var nome = '';
        if (el.hasAttribute('data-column-filter')) nome = 'c:' + el.getAttribute('data-column-filter');
        else if (el.hasAttribute('data-coluna-filtro')) nome = 'c:' + el.getAttribute('data-coluna-filtro');
        else if (el.hasAttribute('data-grid-filter')) nome = 'g:' + el.getAttribute('data-grid-filter');
        else if (el.getAttribute('data-filtro')) nome = 'f:' + el.getAttribute('data-filtro');
        else if (el.matches('.crud-search input')) nome = 'busca';
        else if (el.id) nome = '#' + el.id;
        else if (el.name) nome = 'n:' + el.name;
        else {
            var th = el.closest('th[data-column]');
            if (th) nome = 'c:' + th.getAttribute('data-column');
        }
        if (!nome) return '';
        // vários grids na mesma página (sub-abas): o grid de origem entra na chave
        var escopo = el.closest('table[id],section[id],form[id],[data-grid-local],.menu-grid-fragment'), idEscopo = '';
        if (escopo) {
            idEscopo = escopo.id || '';
            if (!idEscopo) { var tab = escopo.querySelector('table[id]'); idEscopo = tab ? tab.id : ''; }
            if (!idEscopo) {
                // sem id: a posição do grid na página (várias sub-abas com a mesma transação)
                var todos = el.ownerDocument.querySelectorAll('[data-grid-local],.menu-grid-fragment');
                idEscopo = 'grid' + Array.prototype.indexOf.call(todos, escopo);
            }
        }
        return idEscopo + '|' + nome;
    }

    function chaveTela(win) {
        var loc = win.location, params = [];
        try {
            new URLSearchParams(loc.search).forEach(function (valor, nome) { if (!PARAMS_VOLATEIS[nome.toLowerCase()]) params.push(nome + '=' + valor); });
        } catch (e) { /* sem URLSearchParams */ }
        params.sort();
        return PREFIXO + chaveUsuario() + ':' + loc.pathname.replace(/^.*\/principal\//, '') + (params.length ? '?' + params.join('&') : '');
    }

    function ler(win) {
        try { var o = JSON.parse(win.localStorage.getItem(chaveTela(win)) || '{}'); return o && typeof o === 'object' ? o : {}; } catch (e) { return {}; }
    }
    function gravar(win, el) {
        var nome = nomeCampo(el);
        if (!nome) return;
        try {
            var chave = chaveTela(win), dados = ler(win);
            if (String(el.value || '') === '') delete dados[nome]; else dados[nome] = String(el.value);
            if (Object.keys(dados).length) win.localStorage.setItem(chave, JSON.stringify(dados)); else win.localStorage.removeItem(chave);
        } catch (e) { /* armazenamento indisponível */ }
    }

    function instalar(win) {
        var doc;
        try { doc = win.document; } catch (e) { return; } // outro endereço: sem acesso
        if (!doc || doc.__gpFiltroMemoria) return;
        doc.__gpFiltroMemoria = true;

        var explicito = false;
        try { var sp = new URLSearchParams(win.location.search); explicito = sp.has('filtros') || sp.has('busca'); } catch (e) { }
        var vistos = new WeakSet(), restaurando = false;

        // Guardar: depois que o grid recebeu a pesquisa (fase de bolha; o filtro-atraso.js segura e reenvia na captura)
        function aoMudar(ev) {
            if (restaurando || !ehFiltro(ev.target)) return;
            vistos.add(ev.target);
            gravar(win, ev.target);
        }
        doc.addEventListener('input', aoMudar, false);
        doc.addEventListener('change', aoMudar, false);
        doc.addEventListener('search', aoMudar, false);

        function disparar(el) {
            var Ev = win.Event;
            el.dispatchEvent(new Ev('input', { bubbles: true }));
            el.dispatchEvent(new Ev('change', { bubbles: true }));
            // "search" faz o filtro-atraso.js soltar a pesquisa na hora, sem a espera da digitação
            if (el.tagName === 'INPUT') el.dispatchEvent(new Ev('search', { bubbles: true }));
        }

        function restaurar() {
            if (explicito) return;
            var dados = ler(win);
            if (!Object.keys(dados).length) { marcarTodos(); return; }
            var campos = doc.querySelectorAll('input,select'), alterados = [];
            Array.prototype.forEach.call(campos, function (el) {
                if (vistos.has(el) || !ehFiltro(el)) return;
                vistos.add(el);
                var nome = nomeCampo(el);
                if (!nome || !Object.prototype.hasOwnProperty.call(dados, nome)) return;
                if (String(el.value || '') !== '') return; // já veio preenchido (servidor ou outro script)
                var valor = String(dados[nome]);
                if (el.tagName === 'SELECT') {
                    var existe = Array.prototype.some.call(el.options, function (o) { return o.value === valor; });
                    if (!existe) return;
                }
                el.value = valor;
                if (String(el.value) === valor) alterados.push(el);
            });
            if (!alterados.length) return;
            restaurando = true;
            try { alterados.forEach(disparar); } finally { restaurando = false; }
        }
        function marcarTodos() {
            Array.prototype.forEach.call(doc.querySelectorAll('input,select'), function (el) { if (ehFiltro(el)) vistos.add(el); });
        }

        // Os scripts dos grids montam no carregamento: restaurar logo depois. Grids que entram depois
        // (sub-abas carregadas por fetch, dashboards) são vistos pelo observador.
        var agendado = 0;
        function agendar(espera) { win.clearTimeout(agendado); agendado = win.setTimeout(restaurar, espera); }
        if (doc.readyState === 'complete') agendar(50);
        else win.addEventListener('load', function () { agendar(50); });
        if (win.MutationObserver) {
            win.addEventListener('load', function () {
                new win.MutationObserver(function (lista) {
                    for (var i = 0; i < lista.length; i++) {
                        var nos = lista[i].addedNodes;
                        for (var j = 0; j < nos.length; j++) {
                            var n = nos[j];
                            if (n.nodeType === 1 && (n.matches('input,select') || n.querySelector('input,select'))) { agendar(150); return; }
                        }
                    }
                }).observe(doc.documentElement, { childList: true, subtree: true });
            });
        }

        // iframes já abertos e os que carregarem depois (o "load" do iframe passa pela captura do documento)
        doc.addEventListener('load', function (ev) {
            var t = ev.target;
            if (t && t.tagName === 'IFRAME') { try { instalar(t.contentWindow); } catch (e) { /* outro endereço */ } }
        }, true);
        Array.prototype.forEach.call(doc.querySelectorAll('iframe'), function (f) { try { instalar(f.contentWindow); } catch (e) { /* idem */ } });
    }

    instalar(window);
}());
