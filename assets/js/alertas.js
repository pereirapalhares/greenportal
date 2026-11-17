(function ($) {
    'use strict';
    // Textos no idioma do usuário (Administração › Textos e idiomas, grupo PORTAL).
    function T(chave, padrao) { return typeof window.greenT === 'function' ? window.greenT('portal.js.' + chave, padrao) : padrao; }
    var alertRequest = null;
    function loadAlerts() {
        if (alertRequest) return;
        var url = (window.greenPortalBaseUrl || '') + '/principal/alertas.php?_=' + Date.now();
        alertRequest = $.ajax({ url: url, dataType: 'json', cache: false, global: false }).done(function (response) {
            var alerts = response.alerts || [], unread = alerts.filter(function (item) { return Number(item.lido) === 0; });
            // Sino com abas (12/11/2026): o número soma alertas não lidos + pendências vencidas (assets/js/sino-pendencias.js)
            window.gpSinoAlertasNaoLidos = unread.length;
            if (typeof window.gpSinoAtualizarBadge === 'function') window.gpSinoAtualizarBadge(); else $('#alertBadge').text(unread.length).toggleClass('d-none', unread.length === 0);
            $('#alertCountLabel').text(T(unread.length === 1 ? 'alertas_novo' : 'alertas_novos', unread.length === 1 ? '{n} novo' : '{n} novos').replace('{n}', unread.length));
            if (!alerts.length) { $('#alertEmpty').removeClass('d-none').html('<i class="bi bi-check2-circle"></i><span>' + $('<div>').text(T('sem_alertas', 'Nenhum alerta novo')).html() + '</span>'); return; }
            $('#alertEmpty').addClass('d-none'); $('#alertList .alert-dropdown-item').remove(); // só os itens de alerta: a aba Pendências do sino usa a mesma classe
            alerts.forEach(function (alert) {
                var link = alert.link ? (window.greenPortalBaseUrl || '') + alert.link : '#', statusLink = alert.link_status ? (window.greenPortalBaseUrl || '') + alert.link_status : '', dateValue = String(alert.criado_em || '').replace(' ', 'T'), date = new Date(dateValue), idioma = window.greenPortalIdioma || 'pt', locale = idioma === 'en' ? 'en-US' : idioma === 'es' ? 'es-ES' : 'pt-BR', dateText = isNaN(date.getTime()) ? String(alert.criado_em || '') : date.toLocaleString(locale, { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
                var $item = $('<div class="alert-dropdown-item"></div>');
                if (alert.link) $item.attr('role', 'link').attr('tabindex', '0').attr('data-codalerta', alert.codalerta || '').on('click', function (event) { if ($(event.target).is('a,button')) return; marcarLido(alert.codalerta); abrirLinkAlerta(link, alert.titulo); });
                if (Number(alert.lido) === 0) $item.addClass('alert-nao-lido');
                $('<strong></strong>').text(alert.titulo).appendTo($item);
                $('<span></span>').text(alert.mensagem).appendTo($item);
                if (statusLink) $('<a class="alert-status-link" href="#" data-alert-status="1"></a>').attr('data-status-url', statusLink).text(idioma === 'en' ? 'View status' : idioma === 'es' ? 'Ver estado' : 'Ver status').appendTo($item);
                if (alert.link) $('<a class="alert-download-link" href="#" data-alert-open-link="1"></a>').attr('data-alert-url', link).text(/perfil_atribuicoes/i.test(link) ? (idioma === 'en' ? 'Open profile' : 'Abrir perfil') : /ia_potenciais_resultado\.php|gestao_arquivos\.php|minhas_solicitacoes\.php/i.test(link) ? (idioma === 'en' ? 'View result' : idioma === 'es' ? 'Ver resultado' : 'Ver resultado') : (ehDownload(link) ? (idioma === 'en' ? 'Download Excel' : idioma === 'es' ? 'Descargar Excel' : 'Baixar Excel') : (idioma === 'en' ? 'Open' : 'Abrir'))).appendTo($item);
                $('<small class="alert-date"></small>').text(dateText).appendTo($item);
                $item.insertBefore('#alertEmpty');
            });
        }).always(function () { alertRequest = null; });
    }
    loadAlerts();
    window.setInterval(loadAlerts, 5000);
    $(window).on('focus', loadAlerts);
    // Abriu o alerta: deixa de contar como novo
    function marcarLido(codalerta) {
        if (!Number(codalerta)) return;
        $.ajax({ url: (window.greenPortalBaseUrl || '') + '/principal/alertas_lido.php', method: 'POST', dataType: 'json', global: false, data: { codalerta: codalerta } }).always(loadAlerts);
    }
    // Link de arquivo (exportação para Excel e afins): baixa; o resto é tela do portal (c518)
    function ehDownload(url) { return /exporta|download|baixar|\.(xlsx?|csv|zip|pdf)(\?|#|$)/i.test(String(url || '')); }
    function abrirLinkAlerta(url, titulo) {
        // Perfil de atribuições: popup largo e redimensionável (as respostas precisam de espaço)
        if (/perfil_atribuicoes/i.test(url) && window.GreenPortalPopups && typeof window.GreenPortalPopups.transacaoIndependente === 'function') {
            window.GreenPortalPopups.transacaoIndependente({ titulo: titulo || '', conteudo: $('<iframe>', { style: 'width:100%;height:100%;border:0;display:block;' }).attr('src', url), amplo: true, redimensionavel: true });
            return;
        }
        if (/ia_potenciais_resultado\.php|gestao_arquivos\.php|minhas_solicitacoes\.php|perfil_atribuicoes/i.test(url) && window.GreenPortalPopups && window.jQuery) { var frame = window.jQuery('<iframe>', {style: 'width:100%;height:100%;border:0;display:block;' }).attr('src', url); window.GreenPortalPopups.transacao({ titulo: titulo || 'Resultado da análise', conteudo: frame }); }
        // Tela do portal (ex.: não conformidade): abre como aba do main.php, reaproveitando a aba da mesma tela (c518)
        else if (!ehDownload(url) && typeof window.greenPortalOpenTab === 'function') {
            var u = new URL(url, window.location.href), id = 'link-' + (u.pathname + u.search).replace(/[^a-z0-9]+/gi, '-').replace(/^-+|-+$/g, '').toLowerCase().slice(-80);
            var m = /^(NC [^:]+):/.exec(String(titulo || '')), curto = m ? m[1] : String(titulo || '');
            window.greenPortalOpenTab(id, u.toString(), curto.length > 40 ? curto.slice(0, 40) + '…' : curto);
        }
        else window.open(url, '_blank'); }
    $(document).on('click', '[data-alert-open-link]', function (event) { event.preventDefault(); marcarLido($(this).closest('.alert-dropdown-item').attr('data-codalerta')); abrirLinkAlerta(String($(this).attr('data-alert-url') || ''), $(this).closest('.alert-dropdown-item').find('strong').text()); });
    $(document).on('click', '[data-alert-status]', function (event) {
        event.preventDefault();
        var url = String($(this).attr('data-status-url') || ''), idioma = window.greenPortalIdioma || 'pt', title = idioma === 'en' ? 'Export status' : idioma === 'es' ? 'Estado de exportación' : 'Status da exportação';
        if (!url) return;
        if (/ia_potenciais_resultado\.php|gestao_arquivos\.php|minhas_solicitacoes\.php|perfil_atribuicoes/i.test(url)) { abrirLinkAlerta(url, idioma === 'en' ? 'AI analysis status' : idioma === 'es' ? 'Estado del análisis de IA' : 'Status da análise de IA'); return; }
        var id = 'alertExportStatusModal', $modal = $('#' + id); if ($modal.length) $modal.remove();
        $modal = $('<div class="modal fade" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"></h5><button type="button" class="btn-close" data-bs-dismiss="modal" title="' + T('fechar', 'Fechar') + '"></button></div><div class="modal-body"><div class="progress mb-3"><div class="progress-bar" role="progressbar"></div></div><dl class="row small mb-0"></dl></div></div></div></div>').attr('id', id).appendTo('body');
        $modal.find('.modal-title').text(title); var modal = bootstrap.Modal.getOrCreateInstance($modal[0]);
        function refresh() { $.getJSON(url).done(function (response) { var job = response.job || {}, status = String(job.status || ''); $modal.find('.progress-bar').css('width', Number(job.percentual || 0) + '%').text(Number(job.percentual || 0) + '%'); var labels = idioma === 'en' ? ['Request', 'Status', 'Requested', 'Started', 'Finished'] : idioma === 'es' ? ['Solicitud', 'Estado', 'Solicitada', 'Iniciada', 'Finalizada'] : ['Solicitação', 'Status', 'Solicitada', 'Iniciada', 'Finalizada']; var $dl = $modal.find('dl').empty(); [[labels[0], job.codexportacaogrid], [labels[1], status], [labels[2], job.solicitado_em || '-'], [labels[3], job.iniciada_em || '-'], [labels[4], job.concluido_em || '-']].forEach(function (item) { $('<dt class="col-5"></dt>').text(item[0]).appendTo($dl); $('<dd class="col-7"></dd>').text(item[1]).appendTo($dl); }); if (job.erro) $('<div class="alert alert-danger mt-3 mb-0"></div>').text(job.erro).appendTo($modal.find('.modal-body')); if (status === 'SOLICITADA' || status === 'PROCESSANDO') window.setTimeout(refresh, 3000); }); }
        $modal.on('hidden.bs.modal', function () { $modal.remove(); }); modal.show(); refresh();
    });
    $('[data-clear-alerts]').on('click', function (event) {
        event.preventDefault();
        var idioma = window.greenPortalIdioma || 'pt', question = idioma === 'en' ? 'Clear all alerts?' : idioma === 'es' ? '¿Limpiar todas las alertas?' : 'Limpar todos os alertas?';
        if (!window.confirm(question)) return;
        var $button = $(this);
        $button.prop('disabled', true);
        $.post((window.greenPortalBaseUrl || '') + '/principal/alertas_limpar.php', { limpar: 1 }).always(function () { $button.prop('disabled', false); loadAlerts(); });
    });
}(jQuery));


