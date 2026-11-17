/* Lupa "Pesquisar documentos" do cabeçalho: abre a aba "Pesquisa de documentos" (principal/busca_documentos.php),
   que carrega vazia. Se a aba já estiver aberta, só mostra a aba (sem recarregar e sem perder a pesquisa feita).
   A aba usa o mesmo id do menu (menu-busca_documentos). */
(function ($) {
    'use strict';
    $(function () {
        var $botao = $('[data-busca-cabecalho]');
        if (!$botao.length) return;
        var id = 'menu-busca_documentos';
        $botao.on('click', function (e) {
            e.preventDefault();
            var url = String($botao.attr('data-busca-url') || ''), titulo = String($botao.attr('data-busca-titulo') || '');
            var $tab = $('#tab-' + id);
            if ($tab.length) {
                $tab.trigger('click');
                try { var frame = $('#pane-' + id).find('iframe').get(0); var campo = frame && frame.contentDocument ? frame.contentDocument.querySelector('[data-busca-q]') : null; if (campo) campo.focus(); } catch (ignore) {}
            } else if (typeof window.greenPortalOpenTab === 'function') {
                window.greenPortalOpenTab(id, url, titulo);
            } else {
                window.location.href = url;
            }
        });
    });
}(jQuery));
