(function ($) {
    'use strict';
    /*
     * Menu do usuário no cabeçalho (principal/main.php): o nome/e-mail abre um menu suspenso (Bootstrap) com Profile,
     * Alterar senha, Layout, Perfil de atribuições e Sair. Cada item (exceto Sair) abre a transação em popup do portal
     * (GreenPortalPopups.transacao90: 90% da janela, sempre na frente).
     * Mensagens das telas abertas: tema salvo (aplica na hora) e dados do Profile salvos (atualiza nome/e-mail do cabeçalho).
     */
    function applyTheme(theme) {
        if (!/^(verde|azul|cinza)$/.test(theme)) return;
        $('body').removeClass('theme-verde theme-azul theme-cinza').addClass('theme-' + theme);
        $('#workspaceTabPanes iframe').each(function () { try { $(this).contents().find('body').removeClass('theme-verde theme-azul theme-cinza').addClass('theme-' + theme); } catch (error) {} });
        window.greenPortalTheme = theme;
    }
    function applyProfile(dados) {
        if (dados.nome) {
            $('.user-menu strong, .user-account-dropdown-header strong').text(dados.nome);
            $('.user-menu .user-avatar').text(String(dados.nome).charAt(0).toUpperCase());
        }
        if (typeof dados.email === 'string') $('.user-menu small, .user-account-dropdown-header small').text(dados.email);
    }
    window.addEventListener('message', function (event) {
        if (event.origin !== window.location.origin || !event.data) return;
        if (event.data.type === 'greenportal-account-theme') applyTheme(String(event.data.theme || 'verde'));
        if (event.data.type === 'greenportal-account-default-transaction') window.greenPortalDefaultTransaction = String(event.data.transacao || 'menu-dashboard');
        if (event.data.type === 'greenportal-account-profile') applyProfile(event.data.dados || {});
    });
    $(document).on('click', '[data-conta-abrir]', function (event) {
        event.preventDefault();
        var url = new URL(String($(this).attr('data-conta-abrir')), window.location.href).toString(), titulo = String($(this).attr('data-conta-titulo') || '');
        var host = window.top || window;
        if (host.GreenPortalPopups && typeof host.GreenPortalPopups.transacao90 === 'function') host.GreenPortalPopups.transacao90({ titulo: titulo, url: url });
        else window.open(url, '_blank');
    });
}(jQuery));
