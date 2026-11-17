(function ($) {
    'use strict';
    var $text = $('#footerStatusText'), $icon = $('#footerStatusIcon'), $context = $('#footerContext'), idioma = window.greenPortalIdioma || 'pt';
    var translations = {
        pt: { ready: 'Pronto', loading: 'Carregando', editing: 'Alterações não salvas', saving: 'Salvando', saved: 'Salvo', exporting: 'Exportação em andamento', deleted: 'Registro excluído' },
        en: { ready: 'Ready', loading: 'Loading', editing: 'Unsaved changes', saving: 'Saving', saved: 'Saved', exporting: 'Export in progress', deleted: 'Record deleted' },
        es: { ready: 'Listo', loading: 'Cargando', editing: 'Cambios no guardados', saving: 'Guardando', saved: 'Guardado', exporting: 'Exportación en curso', deleted: 'Registro eliminado' }
    };
    var labels = translations[idioma] || translations.pt;
    var icons = { ready: 'bi-check-circle', loading: 'bi-arrow-repeat', editing: 'bi-pencil-square', saving: 'bi-cloud-arrow-up', saved: 'bi-check-circle-fill', exporting: 'bi-file-earmark-excel', deleted: 'bi-trash3' };
    function status(type, context) { type = labels[type] ? type : 'ready'; $text.text(labels[type]); $icon.attr('class', 'bi ' + icons[type]); if (context) $context.text(context); $text.parent().toggleClass('footer-status-warning', type === 'editing').toggleClass('footer-status-working', type === 'loading' || type === 'saving' || type === 'exporting'); }
    window.greenPortalFooterStatus = status;
    window.addEventListener('message', function (event) { if (!event.data || event.data.source !== 'greenportal') return; status(event.data.status || 'ready', event.data.context); });
    status('ready', $context.text());
}(jQuery));
