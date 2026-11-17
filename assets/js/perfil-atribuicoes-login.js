/* Perfil de atribuições no login (main.php): se o perfil do usuário está pendente (nunca respondeu, devolvido, revisão
   anual, questionário novo ou módulo novo) e ele não escolheu "Lembrar depois" nesta sessão, abre o questionário em popup.
   O popup avisa por postMessage (greenportal-perfil-atribuicoes) quando foi enviado ou adiado, para fechar. */
(function (window, $) {
    'use strict';
    var cfg = window.PA_LOGIN || {}, base = window.greenPortalBaseUrl || '';
    if (!cfg.api) return;
    var $modal = null, decidido = false;

    function fechar() {
        if (!$modal) return;
        try { var inst = window.bootstrap && window.bootstrap.Modal.getInstance($modal[0]); if (inst) inst.hide(); } catch (e) { /* já fechado */ }
    }
    function adiar() { $.ajax({ url: cfg.api, method: 'POST', dataType: 'json', data: { action: 'adiar', csrf: cfg.csrf } }); }

    function abrir() {
        if (!window.GreenPortalPopups || typeof window.GreenPortalPopups.transacaoIndependente !== 'function') return;
        var $frame = $('<iframe>', { title: cfg.titulo, style: 'width:100%;height:100%;border:0;display:block;', 'data-perfil-atribuicoes': '1' }).attr('src', cfg.url);
        window.GreenPortalPopups.transacaoIndependente({ titulo: cfg.titulo, conteudo: $frame, amplo: true, redimensionavel: true });
        $modal = $frame.closest('.modal');
        // Fechou pelo X sem enviar: vale como "Lembrar depois" (volta no próximo login)
        $modal.on('hidden.bs.modal', function () { if (!decidido) adiar(); $modal = null; });
    }

    window.addEventListener('message', function (e) {
        if (e.origin !== window.location.origin || !e.data || e.data.type !== 'greenportal-perfil-atribuicoes') return;
        decidido = true;
        fechar();
    });

    $(function () {
        setTimeout(function () {
            $.getJSON(cfg.api, { action: 'status' }).done(function (r) {
                if (r && r.ok && r.disponivel && r.precisa_responder && !r.adiado) abrir();
            });
        }, 1200);
    });
}(window, jQuery));
