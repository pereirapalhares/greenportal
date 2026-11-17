(function ($) {
    'use strict';

    // Textos vêm da própria tela (data-texto-*), no idioma escolhido.
    $('[data-password-toggle]').on('click', function () {
        var $button = $(this);
        var $input = $button.closest('.input-wrap').find('input');
        var showing = $input.attr('type') === 'text';

        $input.attr('type', showing ? 'password' : 'text');
        $button.text(showing ? ($button.data('textoMostrar') || 'Mostrar') : ($button.data('textoOcultar') || 'Ocultar'));
        $button.attr('aria-label', showing ? ($button.data('dicaMostrar') || 'Mostrar senha') : ($button.data('dicaOcultar') || 'Ocultar senha'));
    });

    // Trocar o idioma na tela de login recarrega a tela já no idioma escolhido
    // (o navegador lembra a escolha e ela segue para o portal ao entrar).
    $('#idioma').on('change', function () {
        var languages = { pt: 'pt-BR', en: 'en', es: 'es' };
        var idioma = String($(this).val() || 'pt');
        document.documentElement.lang = languages[idioma] || 'pt-BR';
        if ($(this).is('[data-recarregar-idioma]')) {
            var url = new URL(window.location.href);
            url.searchParams.set('idioma', idioma);
            url.searchParams.delete('erro');
            window.location.href = url.toString();
        }
    });
}(jQuery));
