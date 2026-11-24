/*
 * Botão "Categorias" em Requisitos legais (migração 407; onda 2 da Revisão G, LEG-26/27/28 — 24/11/2026).
 * Vincula a lei da linha selecionada a uma ou mais categorias do catálogo central, SÓ para esta empresa. Sem vínculo próprio,
 * a lei usa as categorias da base central. As regras de "Responsáveis e avisos (Requisitos legais)" usam essas categorias.
 * Carregado no main.php; instala-se no portal e nos iframes do mesmo endereço. Usa o jQuery e o Bootstrap da própria tela.
 * Dados: principal/api/legislacao_categorias.php. Textos com acento escritos como \u.... .
 */
(function () {
    'use strict';
    var topo = window;
    try { while (topo.parent && topo.parent !== topo && topo.parent.document) topo = topo.parent; } catch (e) { /* outro endere\u00e7o */ }
    var idioma = String(topo.greenPortalIdioma || 'pt').slice(0, 2).toLowerCase();
    var BASE = String(topo.greenPortalBaseUrl || '..');
    function L(pt, en, es) { return idioma === 'en' ? en : (idioma === 'es' ? es : pt); }
    function tr(k, pt, en, es) { var p = L(pt, en, es); try { if (typeof topo.greenT === 'function') return topo.greenT('legislacao_categorias.js.' + k, p); } catch (e) { /* */ } return p; }
    var TX = {
        botao: tr('botao', 'Categorias da lei nesta empresa (usadas nas regras de Respons\u00e1veis do Legal)', 'Law categories in this company (used by the Legal responsibility rules)', 'Categor\u00edas de la ley en esta empresa (usadas en las reglas de Responsables del Legal)'),
        titulo: tr('titulo', 'Categorias da lei', 'Law categories', 'Categor\u00edas de la ley'),
        explica: tr('explica', 'Marque as categorias desta lei para a sua empresa. Elas decidem quem responde pela lei nas regras de Respons\u00e1veis e avisos (Requisitos legais). O cat\u00e1logo de categorias \u00e9 mantido pelo administrador do Green.', 'Check the categories of this law for your company. They decide who is responsible in the Legal responsibility rules. The category catalog is kept by the Green administrator.', 'Marque las categor\u00edas de esta ley para su empresa. Deciden qui\u00e9n responde en las reglas de Responsables del Legal. El cat\u00e1logo lo mantiene el administrador de Green.'),
        CENTRAL: tr('origem.central', 'Hoje: categorias da base central (a empresa ainda n\u00e3o definiu as dela).', 'Now: central categories (the company has not set its own).', 'Hoy: categor\u00edas de la base central (la empresa a\u00fan no defini\u00f3 las suyas).'),
        EMPRESA: tr('origem.empresa', 'Hoje: categorias definidas pela empresa.', 'Now: categories set by the company.', 'Hoy: categor\u00edas definidas por la empresa.'),
        NENHUMA: tr('origem.nenhuma', 'Hoje: a empresa definiu esta lei sem categoria.', 'Now: the company set this law with no category.', 'Hoy: la empresa defini\u00f3 esta ley sin categor\u00eda.'),
        SEM: tr('origem.sem', 'Hoje: sem categoria (nem na base central).', 'Now: no category (not even central).', 'Hoy: sin categor\u00eda (ni en la base central).'),
        central: tr('central', 'na base central', 'central', 'en la base central'),
        salvar: tr('salvar', 'Gravar para esta empresa', 'Save for this company', 'Guardar para esta empresa'),
        padrao: tr('padrao', 'Usar as da base central', 'Use the central ones', 'Usar las centrales'),
        fechar: tr('fechar', 'Fechar', 'Close', 'Cerrar'),
        leitura: tr('leitura', 'Seu perfil s\u00f3 pode consultar.', 'Your profile can only view.', 'Su perfil solo puede consultar.'),
        carregando: tr('carregando', 'Carregando\u2026', 'Loading\u2026', 'Cargando\u2026'),
        erro: tr('erro', 'N\u00e3o foi poss\u00edvel carregar as categorias.', 'Could not load the categories.', 'No fue posible cargar las categor\u00edas.')
    };

    function esc(s) { return String(s === null || s === undefined ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function celula(linha, col) { var td = linha.querySelector('td[data-column="' + col + '"]'); return td ? String(td.textContent || '').trim() : ''; }

    function preparar(win) {
        var doc = win.document;
        Array.prototype.forEach.call(doc.querySelectorAll('.modulo-legal-requisitos-fragment'), function (frag) {
            if (frag.querySelector('[data-legcat-botao]')) return;
            var grupo = frag.querySelector('[data-req-grupo="correlacionamentos"] .req-grupo-botoes') || frag.querySelector('.req-grupo-botoes');
            if (!grupo) return;
            var b = doc.createElement('button');
            b.type = 'button'; b.className = 'btn btn-sm btn-outline-secondary'; b.disabled = true;
            b.setAttribute('data-legcat-botao', ''); b.title = TX.botao; b.setAttribute('aria-label', TX.botao);
            b.innerHTML = '<i class="bi bi-tags"></i>';
            grupo.appendChild(b);
            var atualizar = function () { b.disabled = !frag.querySelector('#requisitosLegaisBatchGrid tbody tr.selected'); };
            frag.addEventListener('click', function (ev) { if (ev.target.closest && ev.target.closest('tbody tr')) win.setTimeout(atualizar, 0); });
            b.addEventListener('click', function () { var l = frag.querySelector('#requisitosLegaisBatchGrid tbody tr.selected'); if (l) abrir(win, l); });
        });
    }

    function abrir(win, linha) {
        var doc = win.document, leg = celula(linha, 'CODLEGISLACAO');
        if (!/^\d+$/.test(leg)) return;
        var alvo = [celula(linha, 'CODIGO'), celula(linha, 'UNIDADE')].filter(Boolean).join(' \u00b7 ');
        var m = doc.getElementById('legcatModal');
        if (!m) {
            m = doc.createElement('div');
            m.className = 'modal fade'; m.id = 'legcatModal'; m.tabIndex = -1;
            m.innerHTML = '<div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header py-2"><h5 class="modal-title"></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>'
                + '<div class="modal-body"><div class="fw-semibold mb-1" data-legcat-alvo></div><p class="small text-muted mb-2" data-legcat-explica></p><div class="small mb-2" data-legcat-origem></div>'
                + '<div data-legcat-lista style="max-height:46vh;overflow:auto;border:1px solid #e3ebe7;border-radius:6px;padding:6px 10px"></div><div class="small mt-2" data-legcat-msg></div></div>'
                + '<div class="modal-footer py-2"><button type="button" class="btn btn-sm btn-outline-secondary me-auto" data-legcat-padrao></button><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal" data-legcat-fechar></button><button type="button" class="btn btn-sm btn-success" data-legcat-salvar></button></div></div></div>';
            doc.body.appendChild(m);
            m.querySelector('.modal-title').textContent = TX.titulo;
            m.querySelector('[data-legcat-explica]').textContent = TX.explica;
            m.querySelector('[data-legcat-padrao]').textContent = TX.padrao;
            m.querySelector('[data-legcat-fechar]').textContent = TX.fechar;
            m.querySelector('[data-legcat-salvar]').textContent = TX.salvar;
        }
        var lista = m.querySelector('[data-legcat-lista]'), msg = m.querySelector('[data-legcat-msg]');
        m.querySelector('[data-legcat-alvo]').textContent = alvo;
        m.querySelector('[data-legcat-origem]').textContent = '';
        lista.textContent = TX.carregando; msg.textContent = ''; msg.className = 'small mt-2';
        var url = BASE + '/principal/api/legislacao_categorias.php';
        var dados = null;
        var gravar = function (acao) {
            if (!dados) return;
            var fd = new win.FormData();
            fd.append('leg', leg); fd.append('csrf', dados.csrf); fd.append('acao', acao);
            if (acao === 'salvar') Array.prototype.forEach.call(lista.querySelectorAll('input:checked'), function (c) { fd.append('cats[]', c.value); });
            win.fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
                msg.textContent = (j && j.message) || TX.erro;
                msg.className = 'small mt-2 ' + (j && j.ok ? 'text-success' : 'text-danger');
                if (j && j.ok) carregar();
            }).catch(function () { msg.textContent = TX.erro; msg.className = 'small mt-2 text-danger'; });
        };
        var carregar = function () {
            win.fetch(url + '?leg=' + encodeURIComponent(leg), { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (j) {
                if (!j || !j.ok) { lista.textContent = (j && j.message) || TX.erro; return; }
                dados = j;
                m.querySelector('[data-legcat-origem]').textContent = TX[j.origem] || '';
                lista.innerHTML = j.categorias.map(function (c) {
                    var marcada = j.selecionadas.indexOf(c.id) >= 0, central = j.central.indexOf(c.id) >= 0;
                    return '<div class="form-check"><input class="form-check-input" type="checkbox" id="legcat_' + c.id + '" value="' + c.id + '"' + (marcada ? ' checked' : '') + (j.pode_editar ? '' : ' disabled') + '>'
                        + '<label class="form-check-label" for="legcat_' + c.id + '">' + esc(c.nome) + (central ? ' <span class="text-muted small">(' + esc(TX.central) + ')</span>' : '') + '</label></div>';
                }).join('');
                m.querySelector('[data-legcat-salvar]').disabled = !j.pode_editar;
                m.querySelector('[data-legcat-padrao]').disabled = !j.pode_editar || j.origem === 'CENTRAL' || j.origem === 'SEM';
                if (!j.pode_editar) { msg.textContent = TX.leitura; msg.className = 'small mt-2 text-muted'; }
            }).catch(function () { lista.textContent = TX.erro; });
        };
        m.querySelector('[data-legcat-salvar]').onclick = function () { gravar('salvar'); };
        m.querySelector('[data-legcat-padrao]').onclick = function () { gravar('padrao'); };
        carregar();
        if (win.bootstrap && win.bootstrap.Modal) win.bootstrap.Modal.getOrCreateInstance(m).show();
    }

    function instalar(win) {
        var doc;
        try { doc = win.document; } catch (e) { return; }
        if (!doc || doc.__gpLegCat) return;
        doc.__gpLegCat = true;
        var agendado = 0;
        var rodar = function () { win.clearTimeout(agendado); agendado = win.setTimeout(function () { try { preparar(win); } catch (e) { /* n\u00e3o interrompe a tela */ } }, 150); };
        var iniciar = function () {
            rodar(); win.setTimeout(rodar, 900);
            if (win.MutationObserver && doc.body) new win.MutationObserver(function () { if (!doc.querySelector('[data-legcat-botao]') && doc.querySelector('.modulo-legal-requisitos-fragment')) rodar(); }).observe(doc.body, { childList: true, subtree: true });
        };
        if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', iniciar); else iniciar();
        doc.addEventListener('load', function (ev) { var f = ev.target; if (f && f.tagName === 'IFRAME') { try { instalar(f.contentWindow); } catch (e) { /* */ } } }, true);
        var abertos = function () { Array.prototype.forEach.call(doc.querySelectorAll('iframe'), function (f) { try { instalar(f.contentWindow); } catch (e) { /* */ } }); };
        if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', abertos); else abertos();
    }
    instalar(window);
}());
