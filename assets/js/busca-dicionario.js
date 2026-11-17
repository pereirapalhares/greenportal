/* Dicionário da pesquisa (principal/busca_dicionario.php): lista (filtros Situação / Base / texto), seleção em lote para Aprovar /
   Rejeitar / Excluir, inclusão e edição no formulário do topo. API: principal/api/busca_dicionario.php.
   Termos da base comum (UNIVERSAL) só o ADMINISTRADOR altera; a API confere de novo. */
(function ($) {
    'use strict';
    var T = window.DIC_TEXTOS || {}, P = window.DIC_PODE || {};
    var $d = $('[data-dic]'), csrf = String($d.attr('data-csrf') || '');
    var itens = [], requisicao = null, timer = null;
    var esc = function (v) { return $('<div>').text(String(v == null ? '' : v)).html(); };
    var fmt = function (texto, valores) { return String(texto || '').replace(/\{(\w+)\}/g, function (m, k) { return valores[k] != null ? valores[k] : m; }); };
    var podeAlterar = function (it) { return it.base !== 'UNIVERSAL' || P.admin; };
    var COR = { SUGESTAO: 'a-vencer', APROVADO: 'aprovado', REJEITADO: 'reprovado' };

    function mensagem(texto, tipo) {
        var $m = $d.find('[data-dic-mensagem]');
        if (!texto) { $m.prop('hidden', true); return; }
        $m.removeClass('alert-success alert-danger alert-warning').addClass('alert-' + (tipo || 'success')).text(texto).prop('hidden', false);
        clearTimeout($m.data('t')); $m.data('t', setTimeout(function () { $m.prop('hidden', true); }, 6000));
    }

    function listar() {
        if (requisicao) requisicao.abort();
        var p = { action: 'listar', status: $d.find('[data-dic-status]').val(), base: $d.find('[data-dic-base]').val(), texto: $.trim(String($d.find('[data-dic-texto]').val() || '')) };
        requisicao = $.ajax({ url: 'api/busca_dicionario.php', data: p, dataType: 'json' })
            .done(function (r) { itens = r.itens || []; render(r); })
            .fail(function (x) { if (x.statusText !== 'abort') mensagem((x.responseJSON || {}).message || 'Erro', 'danger'); })
            .always(function () { requisicao = null; });
    }

    function render(r) {
        var html = [];
        if (!itens.length) html.push('<tr><td colspan="10" class="dic-aviso">' + esc(T.nenhum) + '</td></tr>');
        itens.forEach(function (it, i) {
            var contexto = String(it.contexto || '').split(',').filter(Boolean).map(function (t) { return T['tipo.' + t] || t; }).join(', ');
            var alteravel = podeAlterar(it);
            var acoes = '';
            if (P.editar && alteravel) {
                if (it.status !== 'APROVADO') acoes += '<button type="button" class="btn btn-sm btn-link" data-dic-acao="APROVADO" data-i="' + i + '" title="' + esc(T.aprovar) + '"><i class="bi bi-check2-circle"></i></button>';
                if (it.status !== 'REJEITADO') acoes += '<button type="button" class="btn btn-sm btn-link" data-dic-acao="REJEITADO" data-i="' + i + '" title="' + esc(T.rejeitar) + '"><i class="bi bi-x-circle"></i></button>';
                if (it.status !== 'SUGESTAO') acoes += '<button type="button" class="btn btn-sm btn-link" data-dic-acao="SUGESTAO" data-i="' + i + '" title="' + esc(T.sugestao_voltar) + '"><i class="bi bi-arrow-counterclockwise"></i></button>';
                acoes += '<button type="button" class="btn btn-sm btn-link" data-dic-editar data-i="' + i + '" title="' + esc(T.editar) + '"><i class="bi bi-pencil"></i></button>';
            } else if (!alteravel) acoes = '<i class="bi bi-lock dic-cadeado" title="' + esc(T.so_admin) + '"></i>';
            html.push('<tr data-i="' + i + '" class="dic-' + esc(String(it.status).toLowerCase()) + '">'
                + '<td class="dic-col-sel"><input type="checkbox" class="form-check-input" data-dic-sel data-i="' + i + '"' + (alteravel ? '' : ' disabled') + '></td>'
                + '<td class="dic-termo" title="' + esc(it.exemplo ? T.exemplo + ': ' + it.exemplo : '') + '"><strong>' + esc(it.termo) + '</strong>' + (Number(it.sigla) ? ' <span class="dic-etq" title="' + esc(T.c_sigla) + '">' + esc(T.sigla_curta) + '</span>' : '') + '</td>'
                + '<td title="' + esc(it.significado) + '">' + esc(it.significado) + '</td>'
                + '<td title="' + esc(it.sinonimos || '') + '">' + esc(it.sinonimos || '') + '</td>'
                + '<td title="' + esc(contexto) + '">' + esc(contexto) + '</td>'
                + '<td>' + esc(T['b_' + it.base] || it.base) + '</td>'
                + '<td>' + esc(T['o_' + it.origem] || it.origem) + '</td>'
                + '<td class="dic-col-num">' + (Number(it.ocorrencias) || '') + '</td>'
                + '<td><span class="pdf-situacao"><i class="pdf-legenda-cor pdf-cor-' + (COR[it.status] || 'sem-situacao') + '" aria-hidden="true"></i><span>' + esc(T['s_' + it.status] || it.status) + '</span></span></td>'
                + '<td class="dic-col-acoes">' + acoes + '</td></tr>');
        });
        $d.find('[data-dic-linhas]').html(html.join(''));
        $d.find('[data-dic-todos]').prop('checked', false);
        var c = r.contagens || {};
        $d.find('[data-dic-rodape]').text(itens.length + ' · ' + ['SUGESTAO', 'APROVADO', 'REJEITADO'].map(function (s) { return (T['s_' + s] || s) + ': ' + (Number(c[s]) || 0); }).join(' · '));
        selecao();
    }

    function selecionados() { return $d.find('[data-dic-sel]:checked').map(function () { return itens[Number($(this).attr('data-i'))]; }).get(); }
    function selecao() {
        var n = selecionados().length;
        $d.find('[data-dic-lote], [data-dic-excluir]').prop('disabled', !n);
        $d.find('[data-dic-selecionados]').text(n ? fmt(T.selecionados, { n: n }) : '');
    }
    function postar(dados) { return $.ajax({ url: 'api/busca_dicionario.php', method: 'POST', data: $.extend({ csrf: csrf }, dados), dataType: 'json' }); }
    function mudarStatus(lista, status) {
        if (!lista.length) return;
        postar({ action: 'status', status: status, itens: JSON.stringify(lista.map(function (it) { return { base: it.base, id: it.coddicionario }; })) })
            .done(function (r) { mensagem(r.message || T.gravado, r.message ? 'warning' : 'success'); listar(); })
            .fail(function (x) { mensagem((x.responseJSON || {}).message || 'Erro', 'danger'); });
    }

    // Formulário (novo / editar)
    var $f = $d.find('[data-dic-form]');
    function abrirForm(it) {
        $f[0].reset();
        $f.find('[name=base]').val(it ? it.base : ''); $f.find('[name=id]').val(it ? it.coddicionario : '');
        if (it) {
            $f.find('[name=termo]').val(it.termo); $f.find('[name=significado]').val(it.significado); $f.find('[name=sinonimos]').val(it.sinonimos || '');
            $f.find('[name=sigla]').prop('checked', !!Number(it.sigla));
            var ctx = String(it.contexto || '').split(',');
            $f.find('[name=contexto]').each(function () { $(this).prop('checked', ctx.indexOf(this.value) >= 0); });
        } else $f.find('[name=sigla]').prop('checked', true);
        $f.find('[name=base_nova]').closest('label').prop('hidden', !!it);
        $f.prop('hidden', false).find('[name=termo]').trigger('focus');
    }
    $f.on('submit', function (e) {
        e.preventDefault();
        var dados = { action: 'gravar', base: $f.find('[name=base]').val(), id: $f.find('[name=id]').val(), termo: $f.find('[name=termo]').val(),
            significado: $f.find('[name=significado]').val(), sinonimos: $f.find('[name=sinonimos]').val(), sigla: $f.find('[name=sigla]').prop('checked') ? 1 : 0,
            base_nova: $f.find('[name=base_nova]').val() || 'EMPRESA', 'contexto[]': $f.find('[name=contexto]:checked').map(function () { return this.value; }).get() };
        postar(dados).done(function () { $f.prop('hidden', true); mensagem(T.gravado, 'success'); listar(); })
            .fail(function (x) { mensagem((x.responseJSON || {}).message || 'Erro', 'danger'); });
    });
    $d.on('click', '[data-dic-cancelar]', function () { $f.prop('hidden', true); });
    $d.on('click', '[data-dic-novo]', function () { abrirForm(null); });
    $d.on('click', '[data-dic-editar]', function () { abrirForm(itens[Number($(this).attr('data-i'))]); });

    // Ações
    $d.on('click', '[data-dic-acao]', function () { mudarStatus([itens[Number($(this).attr('data-i'))]], String($(this).attr('data-dic-acao'))); });
    $d.on('click', '[data-dic-lote]', function () { mudarStatus(selecionados(), String($(this).attr('data-dic-lote'))); });
    $d.on('click', '[data-dic-excluir]', function () {
        var lista = selecionados(); if (!lista.length) return;
        if (!window.confirm(fmt(T.confirmar_excluir, { n: lista.length }))) return;
        postar({ action: 'excluir', itens: JSON.stringify(lista.map(function (it) { return { base: it.base, id: it.coddicionario }; })) })
            .done(function (r) { mensagem(r.message || T.gravado, r.message ? 'warning' : 'success'); listar(); })
            .fail(function (x) { mensagem((x.responseJSON || {}).message || 'Erro', 'danger'); });
    });
    $d.on('change', '[data-dic-sel]', selecao);
    $d.on('change', '[data-dic-todos]', function () { $d.find('[data-dic-sel]:not(:disabled)').prop('checked', this.checked); selecao(); });
    $d.on('change', '[data-dic-status], [data-dic-base]', listar);
    $d.on('input', '[data-dic-texto]', function () { clearTimeout(timer); timer = setTimeout(listar, 300); });
    $d.on('click', '[data-dic-linhas] tr[data-i]', function (e) { if ($(e.target).is('input, button, i')) return; $(this).addClass('selected').siblings().removeClass('selected'); });
    $d.on('dblclick', '[data-dic-linhas] tr[data-i]', function () { var it = itens[Number($(this).attr('data-i'))]; if (P.editar && it && podeAlterar(it)) abrirForm(it); });
    listar();

    // Dentro da aba do portal o iframe tem altura mínima (main.css) e pode passar da área visível, ficando
    // atrás do rodapé do main.php: a tela usa a altura visível da aba, não a do iframe.
    (function (el) {
        if (!el) return;
        var aba = null;
        try { aba = window.frameElement ? window.frameElement.parentElement : null; } catch (e) { aba = null; }
        if (!aba) return;
        var ajustar = function () {
            var h = aba.clientHeight;
            if (h > 0) el.style.height = Math.min(h, window.innerHeight) + 'px';
        };
        ajustar();
        $(window).on('resize', ajustar);
        try { window.parent.addEventListener('resize', ajustar); } catch (e) { /* outro domínio */ }
        if (window.ResizeObserver) new ResizeObserver(ajustar).observe(aba);
    }($d[0]));
}(jQuery));
