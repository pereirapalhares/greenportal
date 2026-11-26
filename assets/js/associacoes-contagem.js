/*
 * Associações do registro selecionado (26/11/2026): número pequeno em cada botão de associação (verde com itens,
 * cinza com 0) e o resumo "Associações: x de y" no título da barra "Etapas do processo" (ao passar o mouse, a lista
 * do que tem e do que falta). Uma chamada por linha (api/associacoes_contagem.php); atualiza ao trocar de linha,
 * ao fechar uma janela de associação e ao voltar a clicar na grade.
 */
(function ($) {
    'use strict';
    if (!$ || window.gpAssociacoesContagem) return;
    window.gpAssociacoesContagem = true;

    var lang = String(window.greenPortalIdioma || document.documentElement.lang || 'pt').slice(0, 2).toLowerCase();
    var TX = ({
        pt: { resumo: 'Associações: {n} de {t}', tem: 'Com itens', falta: 'Sem itens', resp: 'Responsáveis', dica: 'Itens associados ao registro selecionado' },
        en: { resumo: 'Associations: {n} of {t}', tem: 'With items', falta: 'Without items', resp: 'Responsible parties', dica: 'Items linked to the selected record' },
        es: { resumo: 'Asociaciones: {n} de {t}', tem: 'Con ítems', falta: 'Sin ítems', resp: 'Responsables', dica: 'Ítems asociados al registro seleccionado' }
    })[lang] || null;
    if (!TX) TX = { resumo: 'Associações: {n} de {t}', tem: 'Com itens', falta: 'Sem itens', resp: 'Responsáveis', dica: 'Itens associados ao registro selecionado' };

    function valorCelula(tr, col) { var td = tr.find('[data-column="' + col + '"]').first(); var el = td.find('input, select').first(); return $.trim(el.length ? String(el.val() || '') : td.text()); }
    function botaoFilha(chave) { return '[data-context-url="/principal/' + chave + '.php"]'; }
    function legUnid(tr) { var leg = valorCelula(tr, 'CODLEGISLACAO'), unid = valorCelula(tr, 'CODUNIDADE') || valorCelula(tr, 'codunidade'); return /^\d+$/.test(leg) && /^\d+$/.test(unid) ? { leg: leg, unid: unid } : null; }
    var TELAS = [
        { sel: '.controle-documentos-grid-fragment', modulo: 'controles', botao: function (chave) { return '[data-control-relacionamento="' + chave + '"]'; } },
        { sel: '.modulo-legal-treinamentos-grid-fragment', modulo: 'treinamentos', botao: function (chave) { return '[data-ml-relacionamento="' + chave + '"]'; } },
        { sel: '.modulo-legal-infraestrutura-grid-fragment', modulo: 'infraestrutura', botao: function (chave) { return '[data-ml-relacionamento="' + chave + '"]'; } },
        // "Responsabilidades" (cadastro do legado) só aparece quando a licença tem algo nele (26/11/2026).
        // Fase 3: Requisitos legais (linha = legislação × unidade; mesmas colunas usadas pelas janelas de associação)
        { sel: '.modulo-legal-requisitos-fragment', modulo: 'requisitos', botao: function (chave) { return '[data-open-requisito-popup="' + chave + '"]'; },
          parametros: function (tr) { var leg = $.trim(tr.find('[data-column="CODLEGISLACAO"]').text()), unid = $.trim(tr.find('[data-column="CODUNIDADE"]').text()); return /^\d+$/.test(leg) && /^\d+$/.test(unid) ? { leg: leg, unid: unid, id: String(tr.attr('data-key') || '') } : null; } },
        // Fase 3: Legislação (base compartilhada) — botões da barra com data-assoc-open
        { sel: '.legislacao-grid-fragment', modulo: 'legislacao', botao: function (chave) { return '.crud-toolbar [data-assoc-open="' + chave + '"]'; } },
        // Fase 3: Correlacionamentos — botões data-context-url (a chave é o nome da tela filha)
        { sel: '.modulolegal-correlacionamento-ambiental-fragment', modulo: 'corr_ambiental', botao: botaoFilha, parametros: legUnid },
        { sel: '.modulolegal-correlacionamento-ocupacional-fragment', modulo: 'corr_ocupacional', botao: botaoFilha, parametros: legUnid },
        { sel: '.modulolegal-correlacionamento-social-fragment', modulo: 'corr_social', botao: botaoFilha, parametros: legUnid },
        // Fase 4: telas com botões de transação filha (data-context-child). A contagem pergunta à própria tela filha
        // (action=count_rows com os mesmos filtros que a janela recebe), então o número é o mesmo da janela aberta.
        { sel: '.modulo-laia-grid-fragment', contexto: true },
        { sel: '.modulo-lpro-grid-fragment', contexto: true },
        { sel: '.modulo-lais-grid-fragment', contexto: true },
        { sel: '.homologacao-fornecedor-grid-fragment', contexto: true },
        { sel: '.homologacao-funcionario-grid-fragment', contexto: true },
        { sel: '.homologacao-tipos-fornecedores-grid-fragment', contexto: true },
        { sel: '.licencas-grid-fragment', modulo: 'licencas', botao: function (chave) { return '[data-license-' + chave + ']'; }, ocultarSeZero: ['responsabilidades'] }
    ];

    if (!document.getElementById('gp-assoc-css')) {
        $('<style id="gp-assoc-css">' +
            '.gp-assoc-n{position:absolute;top:-6px;right:-6px;min-width:16px;height:16px;padding:0 4px;border-radius:8px;font-size:10px;line-height:16px;font-weight:600;text-align:center;pointer-events:none;background:#adb5bd;color:#fff;box-shadow:0 0 0 1px #fff}' +
            '.gp-assoc-n.tem{background:#2e7d4f}' +
            '.gp-assoc-host{position:relative;overflow:visible!important}' +
            '.gp-assoc-resumo{display:inline-flex;align-items:center;gap:4px;margin-left:10px;padding:1px 8px;border-radius:10px;font-size:.75rem;background:#eef5f0;color:#2e6b4f;border:1px solid #cfe3d6;cursor:help;white-space:nowrap;font-weight:500}' +
            '.gp-assoc-resumo.vazio{background:#f3f4f5;color:#6c757d;border-color:#dee2e6}' +
            '</style>').appendTo(document.head);
    }

    function rotulo($b) { return $.trim($b.attr('aria-label') || $b.attr('data-bs-original-title') || $b.attr('title') || $b.text()); }

    function preparar(tela, $frag) {
        if ($frag.data('gp-assoc')) return;
        $frag.data('gp-assoc', { tela: tela, id: '', dados: null, quando: 0 });
    }

    function desenharBadge($b, n) {
        if (!$b.length) return;
        $b.addClass('gp-assoc-host');
        var s = $b.children('.gp-assoc-n');
        if (n === null || n === undefined) { s.remove(); return; }
        if (!s.length) s = $('<span class="gp-assoc-n" aria-hidden="true"></span>').appendTo($b);
        s.text(n > 99 ? '99+' : String(n)).toggleClass('tem', n > 0);
    }

    function desenhar($frag) {
        var st = $frag.data('gp-assoc'); if (!st) return;
        var d = st.dados, tela = st.tela, linhas = [], com = 0, total = 0;
        st.resumo = false;
        var itens = d ? (d.itens || []).slice() : [];
        if (d && d.responsaveis !== null && d.responsaveis !== undefined) itens.push({ chave: '__resp', n: d.responsaveis });
        itens.forEach(function (it) {
            var $b = it.el ? $(it.el) : it.chave === '__resp' ? $frag.find('[data-resp-registro]').first() : $frag.find(tela.botao(it.chave)).first();
            desenharBadge($b, it.n);
            if ((tela.ocultarSeZero || []).indexOf(it.chave) >= 0) { $b.toggleClass('d-none', it.n === 0); if (it.n === 0) return; }
            if (it.n === null || it.n === undefined || !$b.length) return;
            total++; if (it.n > 0) com++;
            linhas.push({ nome: it.chave === '__resp' ? TX.resp : (rotulo($b) || it.chave), n: it.n });
        });
        if (!d) $frag.find('.gp-assoc-n').remove();
        // Resumo na barra de etapas (a barra é redesenhada pelo fluxo-etapas.js; o resumo é recolocado quando some).
        var $topo = $frag.find('.gp-fluxo-topo').first();
        if (!$topo.length) $topo = $(document).find('.gp-fluxo[data-gp-fluxo="' + tela.modulo + '"] .gp-fluxo-topo').first();
        $topo.find('.gp-assoc-resumo').remove();
        if (!d || !total || !$topo.length) return;
        var dica = TX.dica + '\n' + linhas.filter(function (l) { return l.n > 0; }).map(function (l) { return '✓ ' + l.nome + ': ' + l.n; }).join('\n')
            + '\n' + linhas.filter(function (l) { return l.n === 0; }).map(function (l) { return '○ ' + l.nome + ': 0'; }).join('\n');
        var chip = $('<span class="gp-assoc-resumo" tabindex="0">').toggleClass('vazio', com === 0).attr('title', dica.replace(/\n+$/, ''))
            .html('<i class="bi bi-link-45deg"></i>').append(document.createTextNode(TX.resumo.replace('{n}', com).replace('{t}', total)));
        var $tog = $topo.children('[data-gp-fluxo-toggle]').first();
        if ($tog.length) chip.insertBefore($tog); else chip.appendTo($topo);
        st.resumo = true;
    }

    function carregar($frag, forcar) {
        var st = $frag.data('gp-assoc'); if (!st) return;
        var tr = $frag.find('.menu-batch-grid tbody tr.selected').first(), id = String(tr.attr('data-key') || '');
        if (st.tela.contexto) { carregarContexto($frag, st, tr, forcar); return; }
        var dadosPedido = st.tela.parametros ? (tr.length ? st.tela.parametros(tr) : null) : (/^\d+$/.test(id) ? { id: id } : null);
        if (!dadosPedido) { st.id = ''; st.dados = null; desenhar($frag); return; }
        var chavePedido = JSON.stringify(dadosPedido);
        if (!forcar && st.id === chavePedido && Date.now() - st.quando < 4000) { desenhar($frag); return; }
        var pedido = st.id = chavePedido; st.quando = Date.now();
        dadosPedido.modulo = st.tela.modulo;
        $.ajax({ url: 'api/associacoes_contagem.php', data: dadosPedido, dataType: 'json', global: false })
            .done(function (r) { if (st.id !== pedido) return; st.dados = r && r.ok ? r : null; desenhar($frag); })
            .fail(function () { if (st.id === pedido) { st.dados = null; desenhar($frag); } });
    }

    function urlFilha($b, filtros) {
        var base = String($b.data('context-url') || ''), app = '';
        try { app = String((window.top && window.top.greenPortalBaseUrl) || window.greenPortalBaseUrl || ''); } catch (e) { app = ''; }
        if (!app) { var p = String(window.location.pathname || ''), i = p.indexOf('/principal/'); app = i > 0 ? p.slice(0, i) : ''; }
        base = app + (base.charAt(0) === '/' ? base : '/' + base);
        return base + (base.indexOf('?') >= 0 ? '&' : '?') + 'filtros=' + encodeURIComponent(JSON.stringify(filtros));
    }
    function carregarContexto($frag, st, tr, forcar) {
        var key = String(tr.attr('data-key') || '');
        if (!tr.length || !/^\d+$/.test(key)) { st.id = ''; st.dados = null; desenhar($frag); return; }
        if (!forcar && st.id === key && Date.now() - st.quando < 4000) { desenhar($frag); return; }
        var pedido = st.id = key; st.quando = Date.now();
        var botoes = $frag.find('[data-context-child][data-context-url]').get(), itens = [], faltam = botoes.length;
        if (!faltam) { st.dados = null; desenhar($frag); return; }
        botoes.forEach(function (el, idx) {
            var $b = $(el), filtros = {};
            String($b.data('context-fields') || '').split(',').filter(Boolean).forEach(function (campo) { var $c = tr.find('[data-column="' + campo + '"]'), $i = $c.find('input,select,textarea').first(); filtros[campo] = String($i.length ? $i.val() : $c.text()).trim(); });
            itens[idx] = { chave: 'ctx' + idx, el: el, n: null };
            $.ajax({ url: urlFilha($b, filtros), method: 'POST', data: { action: 'count_rows', filtros: JSON.stringify(filtros) }, dataType: 'json', global: false })
                .done(function (r) { if (r && r.ok && typeof r.total === 'number') itens[idx].n = r.total; })
                .always(function () { if (--faltam === 0 && st.id === pedido) { st.dados = { itens: itens, responsaveis: null }; desenhar($frag); } });
        });
    }

    $(function () {
        TELAS.forEach(function (tela) {
            $(tela.sel).each(function () {
                var $frag = $(this); preparar(tela, $frag);
                $frag.on('click', '.menu-batch-grid tbody tr', function () { setTimeout(function () { carregar($frag, false); }, 30); });
                // Voltou para a grade (ex.: depois de fechar a janela de associação ou de Responsáveis): confere de novo.
                $frag.on('mousedown', function () { var st = $frag.data('gp-assoc'); if (st && st.id && Date.now() - st.quando > 4000) carregar($frag, true); });
                $(document).on('hidden.bs.modal', function () { carregar($frag, true); });
                $(document).on('gpRelacoesAtualizadas gpUnidadesAtualizadas', function () { carregar($frag, true); });
                // A barra de etapas é redesenhada a cada linha: recoloca o resumo quando ele sai.
                var obs = new MutationObserver(function () { var $t = $('.gp-fluxo-topo').first(); var st = $frag.data('gp-assoc'); if (st && st.resumo && $t.length && !$('.gp-assoc-resumo').length) desenhar($frag); });
                obs.observe(document.body, { childList: true, subtree: true });
                setTimeout(function () { carregar($frag, true); }, 300);
            });
        });
    });
})(window.jQuery);
