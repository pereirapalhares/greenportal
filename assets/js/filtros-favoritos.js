/*
 * Filtros favoritos dos grids (Revisão G item 6; migração 421, 26/11/2026).
 * Carregado no main.php depois do filtro-memoria.js; instala-se no portal e em todos os iframes do mesmo endereço (como ele).
 * Em cada tela com grid, põe uma estrela na barra de botões: "Salvar o filtro atual" (com nome; pode compartilhar com a empresa)
 * e a lista dos filtros salvos (meus e compartilhados) para aplicar com um clique, ou apagar os meus.
 * Os campos de filtro e a identificação deles são os mesmos do filtro-memoria.js (filtro por coluna, busca e filtros da barra).
 * Dados: principal/api/grid_filtros.php (tela = caminho da tela + parâmetros de contexto).
 */
(function () {
    'use strict';
    var FILTROS = [
        '[data-column-filter]', '[data-coluna-filtro]', '[data-grid-filter]', '[data-filtro]', '.column-filter',
        '.crud-search input', '[data-busca-grid]', '[data-busca]', '[data-uni-busca]', '[data-pag-busca]',
        '[data-sol-filtro="busca"]', '[data-dic-texto]', '#permissionSearch', '#profileSearch', '#menuGridSearch', 'input[name="busca"]'
    ].join(',');
    var TIPOS_TEXTO = { '': 1, text: 1, search: 1, number: 1, date: 1 };
    var PARAMS_VOLATEIS = { pagina: 1, filtros: 1, busca: 1, contar: 1, salvo: 1, ajax: 1, v: 1, _: 1, t: 1, ts: 1 };
    var L = (String(document.documentElement.lang || 'pt').slice(0, 2));
    var TX = {
        pt: { botao: 'Filtros favoritos', salvar: 'Salvar o filtro atual', nome: 'Nome do filtro', compartilhar: 'Compartilhar com a empresa', gravar: 'Salvar', nenhum: 'Nenhum filtro salvo nesta tela.', vazio: 'Use algum filtro no grid antes de salvar.', apagar: 'Apagar', de: 'de', erro: 'Não foi possível.', meus: 'Meus', comp: 'Compartilhados' },
        en: { botao: 'Favorite filters', salvar: 'Save current filter', nome: 'Filter name', compartilhar: 'Share with the company', gravar: 'Save', nenhum: 'No saved filter on this screen.', vazio: 'Use some grid filter before saving.', apagar: 'Delete', de: 'by', erro: 'Could not do it.', meus: 'Mine', comp: 'Shared' },
        es: { botao: 'Filtros favoritos', salvar: 'Guardar el filtro actual', nome: 'Nombre del filtro', compartilhar: 'Compartir con la empresa', gravar: 'Guardar', nenhum: 'Ningún filtro guardado en esta pantalla.', vazio: 'Use algún filtro antes de guardar.', apagar: 'Borrar', de: 'de', erro: 'No fue posible.', meus: 'Míos', comp: 'Compartidos' }
    }[L] || null;
    if (!TX) TX = { botao: 'Filtros favoritos', salvar: 'Salvar o filtro atual', nome: 'Nome do filtro', compartilhar: 'Compartilhar com a empresa', gravar: 'Salvar', nenhum: 'Nenhum filtro salvo nesta tela.', vazio: 'Use algum filtro no grid antes de salvar.', apagar: 'Apagar', de: 'de', erro: 'Não foi possível.', meus: 'Meus', comp: 'Compartilhados' };

    function base() { try { return String((window.top && window.top.greenPortalBaseUrl) || window.greenPortalBaseUrl || ''); } catch (e) { return ''; } }
    function ehFiltro(el) {
        if (!el || !el.closest || !el.matches) return false;
        var tag = el.tagName;
        if (tag !== 'INPUT' && tag !== 'SELECT') return false;
        if (tag === 'INPUT' && !TIPOS_TEXTO[String(el.getAttribute('type') || '').toLowerCase()]) return false;
        if (el.closest('tbody') || el.id === 'menuSearch' || el.hasAttribute('data-multi-empresa') || el.closest('.modal') || el.closest('.gp-fav')) return false;
        if (el.hasAttribute('data-sem-memoria') || el.closest('[data-sem-memoria]')) return false;
        return el.matches(FILTROS) || !!el.closest('thead');
    }
    function nomeCampo(el) {
        var nome = '';
        if (el.hasAttribute('data-column-filter')) nome = 'c:' + el.getAttribute('data-column-filter');
        else if (el.hasAttribute('data-coluna-filtro')) nome = 'c:' + el.getAttribute('data-coluna-filtro');
        else if (el.hasAttribute('data-grid-filter')) nome = 'g:' + el.getAttribute('data-grid-filter');
        else if (el.getAttribute('data-filtro')) nome = 'f:' + el.getAttribute('data-filtro');
        else if (el.matches('.crud-search input')) nome = 'busca';
        else if (el.id) nome = '#' + el.id;
        else if (el.name) nome = 'n:' + el.name;
        else { var th = el.closest('th[data-column]'); if (th) nome = 'c:' + th.getAttribute('data-column'); }
        if (!nome) return '';
        var escopo = el.closest('table[id],section[id],form[id],[data-grid-local],.menu-grid-fragment'), id = '';
        if (escopo) {
            id = escopo.id || '';
            if (!id) { var tab = escopo.querySelector('table[id]'); id = tab ? tab.id : ''; }
            if (!id) id = 'grid' + Array.prototype.indexOf.call(el.ownerDocument.querySelectorAll('[data-grid-local],.menu-grid-fragment'), escopo);
        }
        return id + '|' + nome;
    }
    function tela(win) {
        var loc = win.location, params = [];
        try { new URLSearchParams(loc.search).forEach(function (v, n) { if (!PARAMS_VOLATEIS[n.toLowerCase()]) params.push(n + '=' + v); }); } catch (e) { }
        params.sort();
        return loc.pathname.replace(/^.*\/principal\//, '') + (params.length ? '?' + params.join('&') : '');
    }
    function campos(doc) { return Array.prototype.filter.call(doc.querySelectorAll('input,select'), ehFiltro); }
    function atual(doc) { var o = {}; campos(doc).forEach(function (el) { var n = nomeCampo(el); if (n && String(el.value || '') !== '') o[n] = String(el.value); }); return o; }
    function aplicar(win, dados) {
        var doc = win.document, Ev = win.Event;
        campos(doc).forEach(function (el) {
            var n = nomeCampo(el); if (!n) return;
            var v = Object.prototype.hasOwnProperty.call(dados, n) ? String(dados[n]) : '';
            if (String(el.value || '') === v) return;
            if (el.tagName === 'SELECT' && v !== '' && !Array.prototype.some.call(el.options, function (o) { return o.value === v; })) return;
            el.value = v;
            el.dispatchEvent(new Ev('input', { bubbles: true })); el.dispatchEvent(new Ev('change', { bubbles: true }));
            if (el.tagName === 'INPUT') el.dispatchEvent(new Ev('search', { bubbles: true }));
        });
    }
    function esc(v) { return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

    function instalar(win) {
        var doc; try { doc = win.document; } catch (e) { return; }
        if (!doc || doc.__gpFavoritos) return;
        doc.__gpFavoritos = true;
        var url = base() + '/principal/api/grid_filtros.php', csrf = '', lista = [];
        function botao() {
            if (doc.querySelector('[data-gp-fav]')) return;
            var barra = doc.querySelector('.crud-actions');
            if (!barra || !campos(doc).length) return;
            var b = doc.createElement('button');
            b.type = 'button'; b.className = 'btn btn-sm btn-outline-warning grid-crud-button'; b.setAttribute('data-gp-fav', ''); b.title = TX.botao;
            b.innerHTML = '<i class="bi bi-star"></i>';
            barra.appendChild(b);
            b.addEventListener('click', function (ev) { ev.stopPropagation(); abrir(b); });
        }
        var painel = null;
        function fechar() { if (painel) { painel.remove(); painel = null; } }
        doc.addEventListener('click', function (ev) { if (painel && !painel.contains(ev.target)) fechar(); });
        function carregar() {
            return win.fetch(url + '?tela=' + encodeURIComponent(tela(win)), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) { if (r.ok) { csrf = r.csrf; lista = r.filtros || []; } return r; });
        }
        function post(dados) {
            var b = new win.URLSearchParams(dados); b.set('csrf', csrf); b.set('tela', tela(win));
            return win.fetch(url, { method: 'POST', credentials: 'same-origin', body: b }).then(function (r) { return r.json(); });
        }
        function desenhar() {
            if (!painel) return;
            var meus = lista.filter(function (f) { return f.meu; }), comp = lista.filter(function (f) { return !f.meu; });
            var item = function (f) { return '<div class="d-flex align-items-center gap-1 py-1 border-bottom"><a href="#" data-gp-fav-usar="' + f.id + '" class="flex-grow-1 text-decoration-none">' + (f.compartilhado ? '<i class="bi bi-people me-1" title="' + esc(TX.compartilhar) + '"></i>' : '<i class="bi bi-star-fill text-warning me-1"></i>') + esc(f.nome) + (f.meu ? '' : ' <small class="text-muted">' + esc(TX.de) + ' ' + esc(f.usuario) + '</small>') + '</a>' + (f.meu ? '<button type="button" class="btn btn-sm btn-link text-danger p-0" data-gp-fav-apagar="' + f.id + '" title="' + esc(TX.apagar) + '"><i class="bi bi-trash"></i></button>' : '') + '</div>'; };
            painel.querySelector('[data-gp-fav-lista]').innerHTML = lista.length ? (meus.length ? '<div class="small fw-semibold text-muted">' + esc(TX.meus) + '</div>' + meus.map(item).join('') : '') + (comp.length ? '<div class="small fw-semibold text-muted mt-1">' + esc(TX.comp) + '</div>' + comp.map(item).join('') : '') : '<div class="small text-muted">' + esc(TX.nenhum) + '</div>';
        }
        function abrir(b) {
            if (painel) { fechar(); return; }
            painel = doc.createElement('div');
            painel.className = 'gp-fav shadow border rounded bg-white p-2';
            painel.style.cssText = 'position:absolute;z-index:3000;width:300px;font-size:12px';
            var r = b.getBoundingClientRect();
            painel.style.top = (r.bottom + win.scrollY + 4) + 'px';
            painel.style.left = Math.max(4, Math.min(r.left + win.scrollX, doc.documentElement.clientWidth - 310)) + 'px';
            painel.innerHTML = '<div data-gp-fav-lista style="max-height:220px;overflow:auto"><span class="spinner-border spinner-border-sm"></span></div>'
                + '<div class="mt-2 pt-2 border-top"><div class="fw-semibold mb-1">' + esc(TX.salvar) + '</div><input class="form-control form-control-sm mb-1" maxlength="100" placeholder="' + esc(TX.nome) + '" data-gp-fav-nome>'
                + '<label class="d-block mb-1"><input type="checkbox" class="form-check-input me-1" data-gp-fav-comp>' + esc(TX.compartilhar) + '</label>'
                + '<div class="d-flex align-items-center gap-2"><button type="button" class="btn btn-sm btn-success" data-gp-fav-salvar>' + esc(TX.gravar) + '</button><small class="text-danger" data-gp-fav-erro></small></div></div>';
            doc.body.appendChild(painel);
            painel.addEventListener('click', function (ev) {
                var u = ev.target.closest('[data-gp-fav-usar]'), a = ev.target.closest('[data-gp-fav-apagar]'), s = ev.target.closest('[data-gp-fav-salvar]');
                if (u) { ev.preventDefault(); var f = lista.filter(function (x) { return String(x.id) === u.getAttribute('data-gp-fav-usar'); })[0]; if (f) { fechar(); aplicar(win, f.filtros || {}); } return; }
                if (a) { post({ acao: 'excluir', id: a.getAttribute('data-gp-fav-apagar') }).then(function (r) { if (r.ok) { lista = r.filtros || []; desenhar(); } else painel.querySelector('[data-gp-fav-erro]').textContent = r.message || TX.erro; }); return; }
                if (s) {
                    var filtros = atual(doc), nome = painel.querySelector('[data-gp-fav-nome]').value.trim(), er = painel.querySelector('[data-gp-fav-erro]');
                    if (!Object.keys(filtros).length) { er.textContent = TX.vazio; return; }
                    if (!nome) { painel.querySelector('[data-gp-fav-nome]').focus(); return; }
                    post({ acao: 'salvar', nome: nome, filtros: JSON.stringify(filtros), compartilhar: painel.querySelector('[data-gp-fav-comp]').checked ? '1' : '0' }).then(function (r) {
                        if (r.ok) { lista = r.filtros || []; painel.querySelector('[data-gp-fav-nome]').value = ''; er.textContent = ''; desenhar(); } else er.textContent = r.message || TX.erro;
                    }).catch(function () { er.textContent = TX.erro; });
                }
            });
            carregar().then(function (r) { if (!r.ok) painel.querySelector('[data-gp-fav-lista]').innerHTML = '<div class="small text-danger">' + esc(r.message || TX.erro) + '</div>'; else desenhar(); }).catch(function () { if (painel) painel.querySelector('[data-gp-fav-lista]').innerHTML = '<div class="small text-danger">' + esc(TX.erro) + '</div>'; });
        }
        // a barra dos grids é montada pelos scripts da tela: tenta ao carregar e quando o DOM muda
        var t = 0;
        function agendar() { win.clearTimeout(t); t = win.setTimeout(botao, 300); }
        // 27/11/2026: quando a tela já estava carregada (aba aberta pelo menu), o observador nunca era ligado e a estrela não aparecia
        function observar() {
            agendar();
            if (win.MutationObserver) new win.MutationObserver(function () { if (!doc.querySelector('[data-gp-fav]')) agendar(); }).observe(doc.documentElement, { childList: true, subtree: true });
        }
        if (doc.readyState === 'complete') observar(); else win.addEventListener('load', observar);
        doc.addEventListener('load', function (ev) { var f = ev.target; if (f && f.tagName === 'IFRAME') { try { instalar(f.contentWindow); } catch (e) { } } }, true);
        Array.prototype.forEach.call(doc.querySelectorAll('iframe'), function (f) { try { instalar(f.contentWindow); } catch (e) { } });
    }
    instalar(window);
}());
