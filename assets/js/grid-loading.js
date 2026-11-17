(function ($) {
    'use strict';

    // Textos do grupo COMUM (Administração › Textos e idiomas, window.greenT); os daqui são o padrão.
    function T(chave, padrao) { return typeof window.greenT === 'function' ? window.greenT(chave, padrao) : padrao; }
    var $grid = $('.menu-grid-fragment');
    if (!$grid.length) return;

    $grid.removeClass('grid-ready').addClass('grid-is-loading');

    var $modal = $('<div class="modal fade grid-loading-modal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false"><div class="modal-dialog modal-dialog-centered modal-sm"><div class="modal-content"><div class="modal-body text-center py-4"><div class="spinner-border text-success mb-3" role="status"><span class="visually-hidden"></span></div><div class="grid-loading-message"></div></div></div></div></div>').appendTo('body');
    $modal.find('.visually-hidden').text(T('comum.carregando.grid', 'Carregando grid...'));
    var modal = bootstrap.Modal.getOrCreateInstance($modal[0], { backdrop: 'static', keyboard: false });
    var safetyTimer = null;
    var initialFinished = false;
    var loadingActive = false;

    function hideModal() {
        window.clearTimeout(safetyTimer);
        modal.hide();
    }

    function finishLoading() {
        window.clearTimeout(safetyTimer);
        loadingActive = false;
        $grid.removeClass('grid-is-loading').addClass('grid-ready');
        hideModal();
        window.setTimeout(function () {
            if (!$('.modal.show').length) {
                $('.modal-backdrop').remove();
                $('body').removeClass('modal-open').css('padding-right', '');
            }
        }, 450);
    }

    function show(message) {
        loadingActive = true;
        $grid.removeClass('grid-ready').addClass('grid-is-loading');
        $modal.find('.grid-loading-message').text(message || T('comum.carregando.grid', 'Carregando grid...'));
        modal.show();
        window.clearTimeout(safetyTimer);
        safetyTimer = window.setTimeout(finishLoading, 10000);
    }

    function finishInitialLoading() {
        if (initialFinished) return;
        initialFinished = true;
        window.requestAnimationFrame(finishLoading);
    }

    $modal.on('shown.bs.modal', function () {
        if (!loadingActive) hideModal();
    });

    $modal.on('hidden.bs.modal', function () {
        if (!$('.modal.show').length) {
            $('.modal-backdrop').remove();
            $('body').removeClass('modal-open').css('padding-right', '');
        }
    });

    show(T('comum.carregando.grid', 'Carregando grid...'));
    if (document.readyState === 'complete') {
        window.setTimeout(finishInitialLoading, 0);
    } else {
        $(window).one('load', finishInitialLoading);
    }
    window.setTimeout(finishInitialLoading, 1500);

    $(document).ajaxStart(function () {
        show(T('comum.carregando.processando', 'Processando...'));
    }).ajaxStop(function () {
        finishLoading();
    });

    $grid.find('form').add($('form[id$="ExportForm"]')).on('submit', function (event) {
        if (event.isDefaultPrevented && event.isDefaultPrevented()) return;
        show(T('comum.carregando.grid', 'Carregando grid...'));
    });
    $grid.find('[data-reload-grid]').on('click', function () {
        show(T('comum.carregando.recarregando', 'Recarregando grid...'));
    });
    $grid.find('[data-crud-duplicate]').on('click', function () {
        show(T('comum.carregando.duplicando', 'Preparando duplica\u00e7\u00e3o...'));
        window.setTimeout(finishLoading, 400);
    });
    $grid.find('[data-columns]').on('click', finishLoading);

    $(document).on('hidden.bs.modal', function (event) {
        if (event.target !== $modal[0]) finishLoading();
    });
}(jQuery));
