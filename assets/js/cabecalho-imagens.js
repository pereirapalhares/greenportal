(function ($) {
    'use strict';
    /*
     * Logo da empresa e foto do usuário no cabeçalho (GER-1, 12/11/2026).
     * - Nome da empresa: para ADMINISTRADOR e ADM DA EMPRESA abre a tela "Logo da empresa" (principal/empresa_logo.php) em popup.
     * - Mensagens das telas: logo salvo/removido (greenportal-empresa-logo) e foto salva/removida (greenportal-user-photo)
     *   atualizam o cabeçalho na hora, sem recarregar.
     */
    var base = window.greenPortalBaseUrl || '';
    function abrirLogo($chip) {
        var url = new URL(String($chip.attr('data-empresa-logo-abrir')), window.location.href).toString(), titulo = String($chip.attr('data-empresa-logo-titulo') || '');
        if (window.GreenPortalPopups && typeof window.GreenPortalPopups.transacao90 === 'function') window.GreenPortalPopups.transacao90({ titulo: titulo, url: url });
        else window.open(url, '_blank');
    }
    $(document).on('click', '[data-empresa-logo-abrir]', function (ev) { ev.preventDefault(); abrirLogo($(this)); });
    $(document).on('keydown', '[data-empresa-logo-abrir]', function (ev) { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); abrirLogo($(this)); } });

    window.addEventListener('message', function (event) {
        if (event.origin !== window.location.origin || !event.data) return;
        if (event.data.type === 'greenportal-empresa-logo') {
            var v = Number(event.data.versao || 0), $chip = $('.company-chip');
            $chip.toggleClass('tem-logo', v > 0);
            $chip.find('.company-logo').prop('hidden', !v).find('img').attr('src', v ? base + '/principal/empresa_logo_img.php?v=' + v : '');
            $chip.find('.company-avatar').prop('hidden', !!v);
        }
        if (event.data.type === 'greenportal-user-photo') {
            var f = Number(event.data.versao || 0), cod = Number(event.data.codusuario || 0);
            $('[data-user-avatar="' + cod + '"]').toggleClass('tem-foto', f > 0).css('background-image', f ? 'url(' + base + '/principal/usuario_foto.php?u=' + cod + '&v=' + f + ')' : '');
        }
    });
}(jQuery));
