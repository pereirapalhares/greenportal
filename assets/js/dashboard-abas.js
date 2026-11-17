/*
 * Dashboards em abas (empresa: dashboard_admin.php; pessoal: dashboard.php).
 * Configuração no <main class="admin-dashboard">:
 *   data-dash-fonte   página que devolve cada aba (?aba=...)
 *   data-dash-chave   chave do localStorage para lembrar a última aba
 *   data-dash-textos  JSON com os textos (relatorio, erro, erro_aba, situacao)
 * Ações por atributo:
 *   data-aba / data-ir-aba            troca de aba
 *   data-abrir-transacao + data-titulo abre a transação em popup (90% da área do dashboard)
 *   data-situacao="leg:unid"          abre a situação do requisito
 *   data-ir-ancora="id"              rola até o quadro com esse id na aba aberta
 *   data-gerar-relatorio='{"filtros"}' pede o Relatório de Atendimento Legal com esses filtros
 *   data-equipe-todos                  mostra/esconde linhas .dash-sem-pendencia
 *   data-dash-filtro="id" data-dash-valor="G" (migração 367): o cartão filtra a tabela #id da mesma aba para as linhas com
 *       G em data-grupo (separados por espaço; "*" = todas) e rola até ela; "Mostrar como antes" volta ao padrão
 *       (linhas com data-padrao-oculta ficam escondidas no padrão).
 *   Migração 368: listas no grid padrão (grid-local.js) e quadros em sub-abas ([data-subaba] / [data-subaba-painel]).
 *       Se #id é um grid padrão, o cartão abre a sub-aba dele e aplica o filtro do grid (evento grid-local-grupo; "*" limpa).
 *       Recarregar do grid recarrega só a aba. data-ir-ancora também abre a sub-aba do destino.
 */
(function () {
    'use strict';
    var main = document.querySelector('.admin-dashboard[data-dash-fonte]');
    if (!main) return;
    var fonte = main.getAttribute('data-dash-fonte'), chave = main.getAttribute('data-dash-chave') || 'greenportal.dashboard.aba';
    var T = {}; try { T = JSON.parse(main.getAttribute('data-dash-textos') || '{}'); } catch (e) { T = {}; }
    // Abas que já vieram com a página ficam marcadas; as que mostram "carregando" são buscadas ao abrir
    var carregadas = {};
    document.querySelectorAll('[data-painel]').forEach(function (p) { if (!p.querySelector('.dash-carregando')) carregadas[p.getAttribute('data-painel')] = true; });

    // Janela do portal que tem os popups: o dashboard pode estar dentro da aba do portal ou dentro da Home (um nível a mais)
    function host() {
        var w = window;
        for (var i = 0; i < 6 && w; i++) {
            try { if (w.GreenPortalPopups && w.jQuery) return w; } catch (e) { break; }
            if (w === w.parent) break;
            w = w.parent;
        }
        try { if (window.top.GreenPortalPopups && window.top.jQuery) return window.top; } catch (e) {}
        return null;
    }

    // Popups: o portal (popups.js) abre com 90% da área do dashboard, centralizado nela e sempre na frente.
    function abrirPopup(titulo, url) {
        var h = host(), src = new URL(url, window.location.href).toString();
        if (!h) { window.location.href = src; return; }
        var $iframe = h.jQuery('<iframe>').attr('src', src);
        if (typeof h.GreenPortalPopups.transacaoIndependente !== 'function') { h.GreenPortalPopups.transacao({ titulo: titulo, conteudo: $iframe.css({ width: '100%', height: '100%', border: 0, display: 'block' }) }); return; }
        h.GreenPortalPopups.transacaoIndependente({ titulo: titulo, conteudo: $iframe, redimensionavel: true });
    }
    function mensagem(texto, ok) {
        var h = host();
        if (h && h.GreenPortalPopups.mensagem) h.GreenPortalPopups.mensagem({ titulo: T.relatorio || '', mensagem: texto, icone: ok ? 'bi-check-circle' : 'bi-exclamation-triangle' });
        else window.alert(texto);
    }
    function mostrar(id) {
        if (!document.querySelector('[data-aba="' + id + '"]')) id = 'geral';
        document.querySelectorAll('[data-aba]').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-aba') === id); });
        document.querySelectorAll('[data-painel]').forEach(function (p) { p.hidden = p.getAttribute('data-painel') !== id; });
        try { localStorage.setItem(chave, id); } catch (e) {}
        if (carregadas[id]) return;
        carregadas[id] = true;
        var painel = document.querySelector('[data-painel="' + id + '"]');
        fetch(fonte + (fonte.indexOf('?') >= 0 ? '&' : '?') + 'aba=' + encodeURIComponent(id), { credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error(r.status); return r.text(); })
            .then(function (html) { painel.innerHTML = html; montarGrids(painel); })
            .catch(function () { carregadas[id] = false; painel.innerHTML = '<div class="alert alert-danger small">' + (T.erro_aba || 'Erro') + '</div>'; });
    }
    // Migração 368: grids padrão e sub-abas
    function montarGrids(raiz) { if (window.greenPortalGridLocal) window.greenPortalGridLocal.iniciar(raiz); }
    function abrirSubaba(el) {
        var painel = el && el.closest ? el.closest('[data-subaba-painel]') : null;
        while (painel) {
            var grupo = painel.closest('[data-subabas]'), id = painel.getAttribute('data-subaba-painel');
            grupo.querySelectorAll(':scope > .dash-subabas-nav [data-subaba]').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-subaba') === id); });
            grupo.querySelectorAll(':scope > [data-subaba-painel]').forEach(function (p) { p.hidden = p !== painel; });
            painel = grupo.parentNode && grupo.parentNode.closest ? grupo.parentNode.closest('[data-subaba-painel]') : null;
        }
    }
    document.addEventListener('grid-local-recarregar', function (ev) {
        var painel = ev.target.closest('[data-painel]');
        if (!painel) return;
        ev.preventDefault();
        var id = painel.getAttribute('data-painel');
        carregadas[id] = false;
        mostrar(id);
    });
    // Migração 367: filtro de tabela pelo cartão
    function filtrarTabela(id, valor, rotulo) {
        var tabela = document.getElementById(id);
        if (!tabela) return;
        var grid = tabela.closest('[data-grid-local]');
        if (grid) {
            abrirSubaba(grid);
            grid.dispatchEvent(new CustomEvent('grid-local-grupo', { detail: { valor: valor === '*' ? '' : valor, rotulo: rotulo } }));
            grid.scrollIntoView({ behavior: 'smooth', block: 'start' });
            return;
        }
        var linhas = tabela.querySelectorAll('tbody tr[data-grupo]'), n = 0;
        linhas.forEach(function (tr) {
            var ok = valor === '' ? !tr.hasAttribute('data-padrao-oculta') : (valor === '*' || (' ' + tr.getAttribute('data-grupo') + ' ').indexOf(' ' + valor + ' ') >= 0);
            tr.classList.toggle('d-none', !ok);
            if (ok) n++;
        });
        var aviso = tabela.parentNode.querySelector(':scope > .dash-filtro-aviso');
        if (!aviso) {
            aviso = document.createElement('div');
            aviso.className = 'dash-filtro-aviso';
            tabela.parentNode.insertBefore(aviso, tabela);
        }
        if (valor === '') { aviso.hidden = true; return; }
        aviso.hidden = false;
        aviso.innerHTML = '';
        var txt = document.createElement('span');
        txt.textContent = (T.filtro || 'Filtro') + ': ' + (rotulo || valor) + ' (' + n + (tabela.getAttribute('data-limite') && n >= +tabela.getAttribute('data-limite') ? '+' : '') + ')';
        var bt = document.createElement('button');
        bt.type = 'button'; bt.className = 'btn btn-link btn-sm p-0 ms-2';
        bt.textContent = T.limpar_filtro || 'Mostrar como antes';
        bt.addEventListener('click', function () { filtrarTabela(id, '', ''); });
        aviso.appendChild(txt); aviso.appendChild(bt);
        if (!n && tabela.getAttribute('data-vazio')) { var v = document.createElement('div'); v.className = 'dash-nota'; v.textContent = tabela.getAttribute('data-vazio'); aviso.appendChild(v); }
        (tabela.closest('.admin-panel') || tabela).scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    document.addEventListener('click', function (ev) {
        var filtro = ev.target.closest('[data-dash-filtro]');
        if (filtro) { filtrarTabela(filtro.getAttribute('data-dash-filtro'), filtro.getAttribute('data-dash-valor') || '*', filtro.getAttribute('data-dash-rotulo') || ''); return; }
        var link = ev.target.closest('a[data-abrir-arquivo]');
        if (link) { ev.preventDefault(); abrirPopup(link.getAttribute('data-titulo') || link.textContent.trim(), link.getAttribute('href')); return; }
        // data-ir-ancora (migração 341): rola até o quadro da mesma aba (ex.: pendências críticas)
        var ancora = ev.target.closest('[data-ir-ancora]');
        if (ancora) { var destino = document.getElementById(ancora.getAttribute('data-ir-ancora')); if (destino) { abrirSubaba(destino); destino.scrollIntoView({ behavior: 'smooth', block: 'start' }); } return; }
        var sub = ev.target.closest('[data-subaba]');
        if (sub) {
            var grupo = sub.closest('[data-subabas]'), alvoSub = grupo.querySelector(':scope > [data-subaba-painel="' + sub.getAttribute('data-subaba') + '"]');
            if (alvoSub) abrirSubaba(alvoSub.firstElementChild || alvoSub);
            return;
        }
        var alvo = ev.target.closest('[data-aba],[data-ir-aba],[data-abrir-transacao],[data-situacao],[data-gerar-relatorio],[data-equipe-todos]');
        if (!alvo) return;
        if (alvo.hasAttribute('data-aba')) mostrar(alvo.getAttribute('data-aba'));
        else if (alvo.hasAttribute('data-ir-aba')) mostrar(alvo.getAttribute('data-ir-aba'));
        else if (alvo.hasAttribute('data-abrir-transacao')) abrirPopup(alvo.getAttribute('data-titulo') || '', alvo.getAttribute('data-abrir-transacao'));
        else if (alvo.hasAttribute('data-situacao')) {
            var p = alvo.getAttribute('data-situacao').split(':');
            abrirPopup((T.situacao || '') + ' - ' + (alvo.getAttribute('data-codigo') || p[0]), 'manutencaolegalrequisitoslegais_situacao.php?CODLEGISLACAO=' + encodeURIComponent(p[0]) + '&CODUNIDADE=' + encodeURIComponent(p[1]));
        } else if (alvo.hasAttribute('data-equipe-todos')) {
            document.querySelectorAll('.dash-sem-pendencia').forEach(function (tr) { tr.classList.toggle('d-none', !alvo.checked); });
        } else if (alvo.hasAttribute('data-gerar-relatorio')) {
            var filtros = alvo.getAttribute('data-gerar-relatorio') || '';
            if (filtros.charAt(0) !== '{') filtros = JSON.stringify({ LEI_REVOGADA: 'NAO' });
            var aba = (alvo.closest('[data-painel]') || {}).getAttribute ? alvo.closest('[data-painel]').getAttribute('data-painel') : null;
            alvo.disabled = true;
            fetch('modulolegal_requisitoslegais.php', { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ action: 'relatorio_request', filtros: filtros, busca: '', ajax: '1' }) })
                .then(function (r) { return r.json(); })
                .then(function (r) { mensagem((r && r.message) || T.erro || '', r && r.ok); if (r && r.ok && aba) { carregadas[aba] = false; setTimeout(function () { mostrar(aba); }, 400); } })
                .catch(function () { mensagem(T.erro || '', false); })
                .finally(function () { alvo.disabled = false; });
        }
    });
    // O dashboard só lê dados: os filtros da tela não marcam "Alterações não salvas" no rodapé do portal
    ['input', 'change'].forEach(function (tipo) { main.addEventListener(tipo, function (ev) { ev.stopPropagation(); }); });
    montarGrids(document);
    var inicial = 'geral'; try { inicial = localStorage.getItem(chave) || 'geral'; } catch (e) {}
    mostrar(inicial);
}());
