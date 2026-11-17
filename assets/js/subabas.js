/*
 * Sub-abas genéricas (migração 368/369): [data-subabas] com botões [data-subaba="id"] e painéis [data-subaba-painel="id"]
 * (classes/DashboardGrid.php::subabas). Um quadro por vez; vale para os dashboards e para qualquer página
 * (ex.: detalhe do ranking de fornecedores). Lembra a última sub-aba aberta por página neste navegador.
 * v2: cartão com data-grid-filtro="id da tabela" data-grid-valor="GRUPO" data-grid-rotulo="..." (páginas fora do dashboard)
 * abre a sub-aba do grid e aplica o filtro de grupo do grid local (evento grid-local-grupo); clicar de novo no mesmo cartão limpa.
 */
(function () {
    'use strict';
    if (window.greenPortalSubabas) return;
    function abrir(grupo, id) {
        var painel = grupo.querySelector(':scope > [data-subaba-painel="' + id + '"]');
        if (!painel) return false;
        grupo.querySelectorAll(':scope > .dash-subabas-nav [data-subaba]').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-subaba') === id); });
        grupo.querySelectorAll(':scope > [data-subaba-painel]').forEach(function (p) { p.hidden = p !== painel; });
        return true;
    }
    function chave(grupo) { return 'gp.subaba:' + location.pathname + ':' + grupo.getAttribute('data-subabas'); }
    document.addEventListener('click', function (ev) {
        var b = ev.target.closest ? ev.target.closest('[data-subaba]') : null;
        if (!b) return;
        var grupo = b.closest('[data-subabas]');
        if (grupo && abrir(grupo, b.getAttribute('data-subaba'))) { try { sessionStorage.setItem(chave(grupo), b.getAttribute('data-subaba')); } catch (e) {} }
    });
    var filtros = {};
    document.addEventListener('click', function (ev) {
        var c = ev.target.closest ? ev.target.closest('[data-grid-filtro]') : null;
        if (!c) return;
        var id = c.getAttribute('data-grid-filtro'), tabela = document.getElementById(id), grid = tabela ? tabela.closest('[data-grid-local]') : null;
        if (!grid) return;
        var valor = c.getAttribute('data-grid-valor') || '';
        if (filtros[id] === valor) valor = '';
        filtros[id] = valor;
        var painel = grid.closest('[data-subaba-painel]'), grupo = painel ? painel.closest('[data-subabas]') : null;
        if (grupo) abrir(grupo, painel.getAttribute('data-subaba-painel'));
        grid.dispatchEvent(new CustomEvent('grid-local-grupo', { detail: { valor: valor, rotulo: c.getAttribute('data-grid-rotulo') || valor } }));
    });
    document.addEventListener('click', function (ev) {
        var a = ev.target.closest ? ev.target.closest('.grid-local-grupo-aviso') : null, grid = a ? a.closest('[data-grid-local]') : null, t = grid ? grid.querySelector('table') : null;
        if (t && t.id) filtros[t.id] = '';
    });
    function restaurar(raiz) {
        (raiz || document).querySelectorAll('[data-subabas][data-lembrar]').forEach(function (g) {
            var id = null; try { id = sessionStorage.getItem(chave(g)); } catch (e) {}
            if (id) abrir(g, id);
        });
    }
    window.greenPortalSubabas = { abrir: abrir, restaurar: restaurar };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { restaurar(); }); else restaurar();
}());
