/* Perfis de atribuições — gestão (principal/perfil_atribuicoes_gestao.php): lista a situação da equipe / de todos,
   mostra as respostas de um envio e aprova ou devolve (api/perfil_atribuicoes.php: equipe, detalhe, decidir). */
(function ($) {
    'use strict';
    var T = window.PAG_TEXTOS || {};
    var $m = $('[data-pag]'), csrf = String($m.attr('data-csrf') || ''), api = 'api/perfil_atribuicoes.php';
    var itens = [], filtro = '', atual = null;
    var ORDEM = ['PENDENTE_APROVACAO', 'DEVOLVIDO', 'NUNCA_RESPONDEU', 'VENCIDO', 'APROVADO'];
    var esc = function (v) { return $('<div>').text(String(v == null ? '' : v)).html(); };
    var fmt = function (s, v) { return String(s || '').replace(/\{(\w+)\}/g, function (a, k) { return v[k] != null ? v[k] : a; }); };
    var data = function (d) {
        if (!d) return '';
        var x = new Date(String(d).replace(' ', 'T'));
        return isNaN(x) ? String(d) : x.toLocaleDateString(document.documentElement.lang || 'pt-BR');
    };
    var situacao = function (s) { return '<span class="pag-sit pag-sit-' + esc(s) + '"><i></i>' + esc(T['s_' + s] || s) + '</span>'; };

    function carregar() {
        $.getJSON(api, { action: 'equipe' }).done(function (r) {
            itens = r.itens || [];
            $m.find('[data-pag-sub]').text(r.todos ? T.sub_todos : T.sub_equipe);
            render();
            var abrir = Number($m.attr('data-abrir')) || 0;
            if (abrir) { $m.attr('data-abrir', '0'); detalhe(abrir); }
        }).fail(function (x) { $m.find('[data-pag-linhas]').html('<tr><td colspan="7" class="text-danger">' + esc((x.responseJSON || {}).message || T.erro) + '</td></tr>'); });
    }
    function render() {
        var busca = $.trim(String($m.find('[data-pag-busca]').val() || '')).toLowerCase();
        var cont = {}; itens.forEach(function (i) { cont[i.situacao] = (cont[i.situacao] || 0) + 1; });
        $m.find('[data-pag-resumo]').html('<span data-pag-filtro=""' + (filtro ? '' : ' class="ativo"') + '>' + esc(fmt(T.total, { n: itens.length })) + '</span>'
            + ORDEM.filter(function (s) { return cont[s]; }).map(function (s) { return '<span data-pag-filtro="' + s + '"' + (filtro === s ? ' class="ativo"' : '') + '>' + situacao(s) + ' ' + cont[s] + '</span>'; }).join(''));
        var lista = itens.filter(function (i) {
            return (!filtro || i.situacao === filtro) && (!busca || (String(i.usuario) + ' ' + String(i.login) + ' ' + String(i.gestor || '')).toLowerCase().indexOf(busca) >= 0);
        }).sort(function (a, b) { return (ORDEM.indexOf(a.situacao) - ORDEM.indexOf(b.situacao)) || String(a.usuario).localeCompare(String(b.usuario)); });
        var html = lista.map(function (i) {
            var acao = i.codperfilatrib ? '<button type="button" class="btn btn-sm ' + (i.pode_decidir ? 'btn-success' : 'btn-outline-secondary') + '" data-pag-ver="' + i.codperfilatrib + '">' + esc(i.pode_decidir ? T.decidir : T.ver) + '</button>' : '';
            return '<tr><td>' + esc(i.usuario) + ' <span class="text-muted small">' + esc(i.login) + '</span></td><td>' + (i.gestor ? esc(i.gestor) : '<span class="text-muted">' + esc(T.sem_gestor) + '</span>')
                + '</td><td>' + situacao(i.situacao) + '</td><td>' + esc(data(i.enviado_em)) + '</td><td>' + esc(data(i.aprovado_em)) + '</td><td>' + esc(data(i.valido_ate)) + '</td><td class="text-end">' + acao + '</td></tr>';
        }).join('');
        $m.find('[data-pag-linhas]').html(html || '<tr><td colspan="7" class="text-muted">' + esc(T.nada) + '</td></tr>');
    }
    function detalhe(id) {
        $.getJSON(api, { action: 'detalhe', id: id }).done(function (r) {
            atual = r.envio;
            var e = r.envio;
            $m.find('[data-pag-det-titulo]').text(e.usuario);
            var h = '<div class="mb-2">' + situacao(e.status === 'APROVADO' && e.valido_ate && e.valido_ate < new Date().toISOString().slice(0, 10) ? 'VENCIDO' : e.status) + '</div>'
                + '<div class="text-muted small">' + esc(fmt(T.info_envio, { enviado: data(e.enviado_em), gestor: e.gestor || T.sem_gestor, versao: e.versao })) + '</div>'
                + (e.versao_atual && Number(e.versao_atual) !== Number(e.versao) ? '<div class="small text-info"><i class="bi bi-info-circle me-1"></i>' + esc(fmt(T.versao_respondida || '', { versao: e.versao, atual: e.versao_atual })) + '</div>' : '');
            if (e.decidido_em) h += '<div class="text-muted small">' + esc(fmt(T.info_decisao, { situacao: T['s_' + e.status] || e.status, quem: e.decidido_por || '', quando: data(e.decidido_em) })) + (e.motivo ? ' — «' + esc(e.motivo) + '»' : '') + '</div>';
            (r.blocos || []).forEach(function (b) {
                h += '<h3>' + esc(b.titulo) + '</h3><dl>';
                b.perguntas.forEach(function (p) {
                    h += '<dt>' + esc(p.texto) + '</dt><dd>' + (p.resposta && p.resposta.length ? esc(p.resposta.join(', ')) : '<span class="pag-vazio">' + esc(T.sem_resposta) + '</span>') + '</dd>';
                });
                h += '</dl>';
            });
            $m.find('[data-pag-det-corpo]').html(h);
            $m.find('[data-pag-det-rodape]').prop('hidden', !r.pode_decidir);
            $m.find('[data-pag-motivo]').val(''); $m.find('[data-pag-msg]').text('');
            $m.find('[data-pag-detalhe]').prop('hidden', false);
        }).fail(function (x) { window.alert((x.responseJSON || {}).message || T.erro); });
    }
    function fechar() { $m.find('[data-pag-detalhe]').prop('hidden', true); atual = null; }

    $m.on('input', '[data-pag-busca]', render);
    $m.on('click', '[data-pag-filtro]', function () { filtro = String($(this).attr('data-pag-filtro') || ''); render(); });
    $m.on('click', '[data-pag-ver]', function () { detalhe(Number($(this).attr('data-pag-ver'))); });
    $m.on('click', '[data-pag-fechar]', fechar);
    $m.on('click', '[data-pag-detalhe]', function (e) { if (e.target === this) fechar(); });
    $(document).on('keydown', function (e) { if (e.key === 'Escape' && atual) fechar(); });
    $m.on('click', '[data-pag-decidir]', function () {
        if (!atual) return;
        var acao = String($(this).attr('data-pag-decidir')), motivo = $.trim(String($m.find('[data-pag-motivo]').val() || ''));
        var $b = $m.find('[data-pag-decidir]').prop('disabled', true);
        $.ajax({ url: api, method: 'POST', dataType: 'json', data: { action: 'decidir', csrf: csrf, id: atual.id, acao: acao, motivo: motivo } })
            .done(function (r) { $m.find('[data-pag-msg]').attr('class', 'pag-mensagem text-success').text(r.message); setTimeout(function () { fechar(); carregar(); }, 900); })
            .fail(function (x) { $m.find('[data-pag-msg]').attr('class', 'pag-mensagem text-danger').text((x.responseJSON || {}).message || T.erro); })
            .always(function () { $b.prop('disabled', false); });
    });
    carregar();

    // Dentro da aba do portal o iframe pode ser mais alto que a área visível (altura mínima do main.css): usa a altura visível.
    (function (el) {
        if (!el) return;
        var aba = null;
        try { aba = window.frameElement ? window.frameElement.parentElement : null; } catch (e) { aba = null; }
        if (!aba || !/workspace-tab-pane/.test(aba.className || '')) return;
        var ajustar = function () { var h = aba.clientHeight; if (h > 0) el.style.height = Math.min(h, window.innerHeight) + 'px'; };
        ajustar();
        $(window).on('resize', ajustar);
        try { window.parent.addEventListener('resize', ajustar); } catch (e) { /* outro domínio */ }
        if (window.ResizeObserver) new ResizeObserver(ajustar).observe(aba);
    }($m[0]));
}(jQuery));
