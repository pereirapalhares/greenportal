(function () {
    'use strict';
    function validDate(day, month, year) {
        var date = new Date(year, month - 1, day);
        return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day;
    }
    function sanitize(root) {
        var language = String(window.greenPortalIdioma || 'pt').toLowerCase().substring(0, 2);
        var locale = language === 'en' ? 'en-US' : (language === 'es' ? 'es-ES' : 'pt-BR');
        document.documentElement.lang = locale;
        (root || document).querySelectorAll('input[name*="[DATA_"], input[name*="[PROTOCOLADO_EM]"]').forEach(function (input) {
            input.lang = locale;
            var value = String(input.value || ''), slash = value.match(/^(\d{2})\/(\d{2})\/(\d{4})$/), iso = value.match(/^(\d{4})-(\d{2})-(\d{2})$/);
            if (slash && !validDate(Number(slash[1]), Number(slash[2]), Number(slash[3]))) input.value = '';
            if (iso && !validDate(Number(iso[3]), Number(iso[2]), Number(iso[1]))) input.value = '';
        });
    }
    sanitize(document);
    if (window.jQuery) {
        window.jQuery(function () { sanitize(document); });
        window.jQuery(document).on('gridPageLoaded.dateNativeSanitize', function (event) { sanitize(event.target); });
    }
}());
