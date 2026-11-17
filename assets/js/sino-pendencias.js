(function ($) {
    'use strict';
    /*
     * Sino do cabeçalho com abas Alertas | Pendências (GER-15, 12/11/2026 — decisão do usuário: "Duas abas no sino").
     * Pendências: resumo das mais urgentes do usuário logado (principal/api/pendencias_resumo.php, mesma regra de
     * Home › Minhas pendências) e "Ver todas" abre a aba Minhas pendências. O número do sino soma os alertas não lidos
     * (assets/js/alertas.js) e as pendências vencidas. O resumo é lido ao abrir o sino e a cada 5 minutos.
     */
    function T(chave, padrao) { return typeof window.greenT === 'function' ? window.greenT('portal.js.' + chave, padrao) : padrao; }
    var $box = $('#sinoPendencias');
    if (!$box.length) return;
    var url = String($box.attr('data-url') || ''), homeUrl = String($box.attr('data-home-url') || ''), carregando = null, vencidas = 0, total = null;
    function esc(t) { return $('<div>').text(String(t == null ? '' : t)).html(); }

    window.gpSinoAtualizarBadge = function () {
        var alertas = Number(window.gpSinoAlertasNaoLidos || 0), soma = alertas + vencidas;
        $('#alertBadge').text(soma > 99 ? '99+' : soma).toggleClass('d-none', soma === 0)
            .attr('title', T('sino_badge', '{a} alerta(s) não lido(s) · {p} pendência(s) vencida(s)').replace('{a}', alertas).replace('{p}', vencidas));
        $('#sinoNumAlertas').text(alertas ? '(' + alertas + ')' : '');
        $('#sinoNumPendencias').text(total ? '(' + total + ')' : '').toggleClass('sino-num-vencida', vencidas > 0);
    };

    function desenhar(r) {
        var $lista = $('#sinoPendLista').empty();
        if (!r.itens || !r.itens.length) {
            $lista.html('<div class="alert-empty"><i class="bi bi-check2-circle"></i><span>' + esc(T('sem_pendencias', 'Nenhuma pendência atribuída a você')) + '</span></div>');
            return;
        }
        $lista.append('<div class="sino-pend-resumo">' + esc(T('sino_resumo', '{t} pendência(s): {v} vencida(s), {a} a vencer em 30 dias').replace('{t}', r.total).replace('{v}', r.vencidas).replace('{a}', r.a_vencer)) + '</div>');
        r.itens.forEach(function (it) {
            var $i = $('<div class="alert-dropdown-item sino-pend-item" role="link" tabindex="0"></div>').addClass(it.sinal ? 'sino-' + it.sinal : '');
            $('<strong></strong>').text(it.pilar + ' · ' + it.titulo).appendTo($i);
            $('<span></span>').text(it.motivo).appendTo($i);
            if (it.prazo) $('<small class="alert-date"></small>').text(T('sino_prazo', 'Prazo: {d}').replace('{d}', it.prazo)).appendTo($i);
            $i.on('click keydown', function (ev) {
                if (ev.type === 'keydown' && ev.key !== 'Enter') return;
                var u = new URL(it.url, window.location.href).toString();
                if (typeof window.greenPortalOpenTab === 'function') window.greenPortalOpenTab('pend-' + (it.pilar + '-' + it.titulo).replace(/[^a-z0-9]+/gi, '-').toLowerCase().slice(0, 60), u, String(it.pilar));
                else window.location.href = u;
            });
            $lista.append($i);
        });
    }

    function carregar(desenha) {
        if (carregando) return carregando;
        carregando = $.ajax({ url: url, dataType: 'json', cache: false, global: false }).done(function (r) {
            if (!r || !r.ok) return;
            vencidas = Number(r.vencidas || 0); total = Number(r.total || 0);
            window.gpSinoAtualizarBadge();
            if (desenha) desenhar(r);
        }).fail(function () {
            if (desenha) $('#sinoPendLista').html('<div class="alert-empty"><span>' + esc(T('sino_erro', 'Não foi possível ler as pendências agora.')) + '</span></div>');
        }).always(function () { carregando = null; });
        return carregando;
    }

    function mostrar(aba) {
        $('[data-sino-aba]').each(function () { var on = $(this).attr('data-sino-aba') === aba; $(this).toggleClass('ativa', on).attr('aria-selected', on ? 'true' : 'false'); });
        $('[data-sino-painel]').each(function () { this.hidden = $(this).attr('data-sino-painel') !== aba; });
        if (aba === 'pendencias') carregar(true);
    }
    // Ligado na barra de abas (não no document): o stopPropagation impede que o clique chegue ao document, onde o
    // Bootstrap fecharia o sino.
    $('.sino-abas').on('click', '[data-sino-aba]', function (ev) { ev.preventDefault(); ev.stopPropagation(); mostrar(String($(this).attr('data-sino-aba'))); });
    // Passar o mouse sobre a aba já a seleciona (pedido de 12/11/2026); o clique continua valendo (toque, teclado).
    $('.sino-abas').on('mouseenter', '[data-sino-aba]', function () { var aba = String($(this).attr('data-sino-aba')); if (!$(this).hasClass('ativa')) mostrar(aba); });
    $box.on('click', '[data-sino-todas]', function () {
        if (typeof window.greenPortalOpenTab === 'function') window.greenPortalOpenTab('home-minhas-pendencias', homeUrl, T('minhas_pendencias', 'Minhas pendências'));
        else window.location.href = homeUrl;
    });
    // Ao abrir o sino na aba Pendências, atualiza a lista
    $('.alert-menu').on('show.bs.dropdown', function () { if (!$box.prop('hidden')) carregar(true); });

    carregar(false);
    window.setInterval(function () { carregar(!$box.prop('hidden')); }, 300000);
}(jQuery));
