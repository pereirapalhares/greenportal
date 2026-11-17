/* Meu perfil de atribuições (principal/perfil_atribuicoes.php): coleta as respostas, envia para aprovação do gestor
   (api/perfil_atribuicoes.php action=salvar) e, no popup do login, "Lembrar depois" (action=adiar). No popup, avisa a página
   principal para fechar (postMessage greenportal-perfil-atribuicoes). */
(function ($) {
    'use strict';
    var T = window.PA_TEXTOS || {}, popup = !!window.PA_POPUP;
    var $f = $('[data-pa]'), csrf = String($f.attr('data-csrf') || '');
    var api = 'api/perfil_atribuicoes.php';
    var mostrandoFalta = false; // a lista de obrigatórias está na tela: acompanha as respostas até sumir

    function mensagem(texto, tipo, lista) {
        var $m = $f.find('[data-pa-mensagem]');
        mostrandoFalta = false;
        if (!texto) { $m.prop('hidden', true); return; }
        $m.attr('class', 'pa-mensagem pa-mensagem-' + (tipo || 'ok')).empty().text(texto);
        if (lista && lista.length) { var $ul = $('<ul>'); lista.forEach(function (x) { $('<li>').text(x).appendTo($ul); }); $m.append($ul); }
        $m.prop('hidden', false);
    }
    function avisarPrincipal(acao) {
        try { (window.top || window.parent).postMessage({ type: 'greenportal-perfil-atribuicoes', acao: acao }, window.location.origin); } catch (e) { /* fora do portal */ }
    }

    function respostas() {
        var r = {};
        $f.find('[data-pa-pergunta]').each(function () {
            var $p = $(this), cod = String($p.attr('data-pa-pergunta')), tipo = String($p.attr('data-tipo'));
            if (tipo === 'MULTIPLA_ESCOLHA') r[cod] = $p.find('input[type=checkbox]:checked').map(function () { return this.value; }).get();
            else if (tipo === 'SIM_NAO') r[cod] = String($p.find('input[type=radio]:checked').val() || '');
            else if (tipo === 'UNICA_ESCOLHA') r[cod] = String($p.find('select').val() || '');
            else r[cod] = $.trim(String($p.find('input[type=text]').val() || ''));
        });
        return r;
    }
    function faltando() {
        var nomes = [];
        $f.find('[data-pa-pergunta][data-obrigatoria]').removeClass('pa-falta').each(function () {
            var $p = $(this), tipo = String($p.attr('data-tipo')), ok;
            if (tipo === 'MULTIPLA_ESCOLHA') ok = $p.find('input:checked').length > 0;
            else if (tipo === 'SIM_NAO') ok = $p.find('input:checked').length > 0;
            else if (tipo === 'UNICA_ESCOLHA') ok = !!$p.find('select').val();
            else ok = !!$.trim(String($p.find('input').val() || ''));
            if (!ok) { $p.addClass('pa-falta'); nomes.push($.trim($p.find('.pa-pergunta-texto').text().replace(/\*$/, ''))); }
        });
        return nomes;
    }

    $f.on('submit', function (e) {
        e.preventDefault();
        var falta = faltando();
        if (falta.length) {
            mensagem(T.obrigatorias || 'Responda as perguntas obrigatórias:', 'erro', falta);
            mostrandoFalta = true;
            var $p = $f.find('.pa-falta').first(); if ($p.length) $p[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
        var $b = $f.find('[data-pa-enviar]').prop('disabled', true);
        mensagem(T.enviando, 'info');
        $.ajax({ url: api, method: 'POST', dataType: 'json', data: { action: 'salvar', csrf: csrf, respostas: JSON.stringify(respostas()) } })
            .done(function (r) {
                mensagem(r.message, 'ok');
                if (popup) setTimeout(function () { avisarPrincipal('enviado'); }, 1800);
                else setTimeout(function () { window.location.reload(); }, 1500);
            })
            .fail(function (x) { var r = x.responseJSON || {}; mensagem(r.message || T.erro, 'erro', r.faltando); })
            .always(function () { $b.prop('disabled', false); });
    });
    $f.on('click', '[data-pa-depois]', function () {
        $.ajax({ url: api, method: 'POST', dataType: 'json', data: { action: 'adiar', csrf: csrf } }).always(function () { avisarPrincipal('adiado'); });
    });
    $f.on('click', '[data-pa-todas]', function () {
        var $c = $(this).closest('.pa-chips').find('input[type=checkbox]'), todas = $c.length === $c.filter(':checked').length;
        $c.prop('checked', !todas);
    });
    $f.on('change', 'input, select', function () {
        $(this).closest('[data-pa-pergunta]').removeClass('pa-falta');
        if (!mostrandoFalta) return;
        var falta = faltando();
        if (falta.length) { mensagem(T.obrigatorias || 'Responda as perguntas obrigatórias:', 'erro', falta); mostrandoFalta = true; }
        else mensagem('');
    });

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
    }($f[0]));
}(jQuery));
