(function ($) {
    'use strict';
    var pdfPromise;
    function carregarPdfJs() {
        if (window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
        if (pdfPromise) return pdfPromise;
        pdfPromise = new Promise(function (resolve, reject) {
            var script = document.createElement('script');
            script.src = '../assets/vendor/pdfjs/pdf.min.js?v=2';
            script.onload = function () { if (!window.pdfjsLib) return reject(new Error('PDF.js indisponível')); window.pdfjsLib.GlobalWorkerOptions.workerSrc = '../assets/vendor/pdfjs/pdf.worker.min.js?v=2'; resolve(window.pdfjsLib); };
            script.onerror = reject; document.head.appendChild(script);
        });
        return pdfPromise;
    }
    function escapar(texto) { return $('<div>').text(texto || '').html(); }
    function montarTexto(page, viewport, state, pdfjs) {
        return page.getTextContent().then(function (content) {
            var $layer = state.modal.find('[data-pdf-text-layer]').empty();
            content.items.forEach(function (item) {
                var tx = pdfjs.Util.transform(viewport.transform, item.transform), fontHeight = Math.max(8, Math.hypot(tx[2], tx[3])), left = tx[4], top = tx[5] - fontHeight, width = Math.max(1, item.width * state.scale), text = String(item.str || ''), matched = state.term && text.toLocaleLowerCase().indexOf(state.term) >= 0;
                $('<span>').css({ left: left + 'px', top: top + 'px', width: width + 'px', height: (fontHeight + 4) + 'px', fontSize: fontHeight + 'px' }).html(matched ? '<mark>' + escapar(text) + '</mark>' : escapar(text)).appendTo($layer);
            });
        });
    }
    function atualizarBusca(state) {
        var term = $.trim(state.modal.find('[data-pdf-search]').val() || '').toLocaleLowerCase(); state.term = term; state.matches = [];
        if (term) state.textPages.forEach(function (text, index) { var pos = 0, lower = text.toLocaleLowerCase(); while ((pos = lower.indexOf(term, pos)) >= 0) { state.matches.push(index + 1); pos += term.length; } });
        state.modal.find('[data-pdf-search-count]').text(state.matches.length + (state.matches.length === 1 ? ' encontrada' : ' encontradas')); renderPage(state, window.pdfjsLib);
    }
    function carregarTextos(state) { var jobs = []; for (var page = 1; page <= state.pdf.numPages; page++) jobs.push(state.pdf.getPage(page).then(function (p) { return p.getTextContent().then(function (c) { return c.items.map(function (i) { return i.str || ''; }).join(' '); }); })); return Promise.all(jobs).then(function (texts) { state.textPages = texts; }); }
    function renderPage(state, pdfjs) {
        if (!state.pdf || state.modal.data('pdf-state') !== state) return;
        state.pdf.getPage(state.page).then(function (page) { var viewport = page.getViewport({ scale: state.scale }), canvas = state.modal.find('[data-pdf-canvas]')[0], context = canvas.getContext('2d'); canvas.width = viewport.width; canvas.height = viewport.height; state.modal.find('[data-pdf-text-layer]').css({ width: viewport.width + 'px', height: viewport.height + 'px' }); return page.render({ canvasContext: context, viewport: viewport }).promise.then(function () { return montarTexto(page, viewport, state, pdfjs); }); }).then(function () { state.modal.find('[data-pdf-page]').text(state.page + ' / ' + state.pdf.numPages); state.modal.find('[data-pdf-prev]').prop('disabled', state.page <= 1); state.modal.find('[data-pdf-next]').prop('disabled', state.page >= state.pdf.numPages); state.modal.find('[data-pdf-message]').text(''); }).catch(function () { state.modal.find('[data-pdf-message]').text('Não foi possível exibir o PDF. Use o botão de download.'); });
    }
    function abrir($row, transaction) {
        var codigo = $.trim($row.find('td[data-column="CODIGO"]').clone().find('button').remove().end().text() || $row.find('td[data-column="CODIGO"] input').val() || $row.find('td[data-column="CODOCORRENCIA"] input').val() || $row.data('key') || ''); if (!/^[A-Za-z0-9_-]+$/.test(codigo)) return;
        var isNc = transaction === 'NAO_CONFORMIDADES_LISTA_OCORRENCIAS';
        var endpoint = isNc ? 'naoconformidade_ocorrenciapdf.php' : 'visualizadopdf.php';
        var url = endpoint + '?codigo=' + encodeURIComponent(codigo) + '&transacao=' + encodeURIComponent(transaction), $modal = $('#greenPortalPdfModal');
        if (!$modal.length) $modal = $('<div class="modal fade" id="greenPortalPdfModal" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content" style="height:90vh"><div class="modal-header py-2"><div><h5 class="modal-title"><i class="bi bi-file-earmark-pdf me-2"></i>Texto da Lei: <span data-pdf-code></span></h5><div class="d-flex align-items-center gap-1 mt-1"><input type="search" class="form-control form-control-sm" style="width:230px" placeholder="Buscar no PDF" data-pdf-search><span class="small text-muted text-nowrap" data-pdf-search-count>0 encontradas</span></div></div><div class="d-flex align-items-center gap-1"><button type="button" class="btn btn-sm btn-outline-secondary" data-pdf-prev title="Página anterior"><i class="bi bi-chevron-left"></i></button><span class="small text-muted" data-pdf-page>1 / 1</span><button type="button" class="btn btn-sm btn-outline-secondary" data-pdf-next title="Próxima página"><i class="bi bi-chevron-right"></i></button><a class="btn btn-sm btn-outline-primary" data-pdf-download download title="Baixar PDF"><i class="bi bi-download"></i></a><button type="button" class="btn-close ms-1" data-bs-dismiss="modal" aria-label="Fechar"></button></div></div><div class="modal-body p-2 text-center overflow-auto bg-secondary-subtle"><div data-pdf-page-wrap style="position:relative;display:inline-block;text-align:left"><canvas data-pdf-canvas class="shadow bg-white"></canvas><div data-pdf-text-layer></div></div><div class="small text-muted mt-2" data-pdf-message>Carregando documento...</div></div></div></div></div>').appendTo('body');
        var documentLabel = isNc ? 'PDF da ocorrência' : ((window.greenPortalIdioma || 'pt') === 'en' ? 'Full text of the standard' : ((window.greenPortalIdioma || 'pt') === 'es' ? 'Texto íntegro de la norma' : 'Integra da norma')); $modal.find('.modal-title').contents().filter(function () { return this.nodeType === 3; }).first().replaceWith(documentLabel + ': ');
        var previousState = $modal.data('pdf-state'); if (previousState && previousState.pdf) previousState.pdf.destroy();
        var state = { pdf: null, page: 1, scale: 1.25, textPages: [], matches: [], term: '', modal: $modal };
        $modal.data('pdf-state', state).find('[data-pdf-canvas]').attr({ width: 1, height: 1 }).end().find('[data-pdf-text-layer]').empty().end().find('[data-pdf-code]').text(codigo).end().find('[data-pdf-download]').attr('href', url).end().find('[data-pdf-search]').val('').end().find('[data-pdf-search-count]').text('0 encontradas').end().find('[data-pdf-message]').text('Carregando documento...');
        $modal.off('input', '[data-pdf-search]').on('input', '[data-pdf-search]', function () { atualizarBusca(state); });
        $modal.off('click', '[data-pdf-prev]').on('click', '[data-pdf-prev]', function () { if (state.page > 1) { state.page--; renderPage(state, window.pdfjsLib); } });
        $modal.off('click', '[data-pdf-next]').on('click', '[data-pdf-next]', function () { if (state.pdf && state.page < state.pdf.numPages) { state.page++; renderPage(state, window.pdfjsLib); } });
        $modal.off('hidden.bs.modal').on('hidden.bs.modal', function () { var current = $(this).data('pdf-state'); if (current && current.pdf) current.pdf.destroy(); $(this).removeData('pdf-state').find('[data-pdf-canvas]').attr({ width: 1, height: 1 }).end().find('[data-pdf-text-layer]').empty().end().find('[data-pdf-code]').text(''); });
        bootstrap.Modal.getOrCreateInstance($modal[0]).show(); carregarPdfJs().then(function (pdfjs) { return pdfjs.getDocument(url).promise.then(function (pdf) { if ($modal.data('pdf-state') !== state) { pdf.destroy(); return; } state.pdf = pdf; return carregarTextos(state).then(function () { renderPage(state, pdfjs); }); }); }).catch(function () { if ($modal.data('pdf-state') === state) $modal.find('[data-pdf-message]').text('Não foi possível exibir o PDF. Use o botão de download.'); });
    }
    $('.legislacao-grid-fragment').on('click.visualizadorPdf', '[data-open-legislacao-pdf]', function (event) { event.preventDefault(); event.stopImmediatePropagation(); abrir($(this).closest('tr'), 'LEGISLACAO'); });
    $('.modulo-legal-requisitos-fragment').on('click.visualizadorPdf', '[data-open-legislacao-pdf]', function (event) { event.preventDefault(); event.stopImmediatePropagation(); abrir($(this).closest('tr'), 'REQUISITOS_LEGAIS'); });
    $('.cadastro-legal-fragment').on('click.visualizadorPdf', '[data-open-legislation-text]', function (event) { event.preventDefault(); event.stopImmediatePropagation(); var config = $(this).closest('.cadastro-legal-fragment').data('cadastro-config') || {}; abrir($(this).closest('tr'), String(config.pdf_transaction || config.transaction || 'LEGISLACAO')); });
    // PDF da não conformidade (c528): popup padrão do portal (GreenPortalPopups.transacaoIndependente na janela principal: título,
    // minimizar, maximizar, fechar), com o PDF gerado por naoconformidade_ocorrenciapdf.php. Sem o portal: o visualizador acima.
    function abrirPdfNc($row) {
        var codigo = String($row.data('key') || '').trim(); if (!/^\d+$/.test(codigo)) return;
        var numero = $.trim($row.find('td[data-column="NRANO"]').text()) || codigo, host = null;
        try { host = window.top && window.top.GreenPortalPopups && window.top.jQuery ? window.top : null; } catch (e) { host = null; }
        if (!host) return abrir($row, 'NAO_CONFORMIDADES_LISTA_OCORRENCIAS');
        var url = new URL('naoconformidade_ocorrenciapdf.php?codigo=' + encodeURIComponent(codigo), window.location.href).toString();
        var titulo = 'PDF da não conformidade ' + numero;
        host.GreenPortalPopups.transacaoIndependente({ titulo: titulo, conteudo: host.jQuery('<iframe>', { src: url, title: titulo, style: 'width:100%;height:100%;border:0;display:block;' }), redimensionavel: true });
    }
    $('.nao-conformidades-grid-fragment').on('click.visualizadorPdf', '[data-open-nc-pdf]', function (event) { event.preventDefault(); event.stopImmediatePropagation(); var $fragment = $(this).closest('.nao-conformidades-grid-fragment'), $row = $fragment.find('tbody tr.selected'); if (!$row.length) return; abrirPdfNc($row); });
}(jQuery));
