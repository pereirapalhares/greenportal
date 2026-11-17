// Popups do grid de Requisitos legais (Padrão e Texto da Lei): 90% da largura e da altura da área do grid, com o conteúdo ocupando o popup
(function () { if (document.getElementById('requisitos-modal-90-css')) return; var st = document.createElement('style'); st.id = 'requisitos-modal-90-css';
    st.textContent = '.requisitos-modal-90 .modal-dialog{width:90vw;max-width:90vw;height:90vh;margin:5vh auto}.requisitos-modal-90 .modal-content{height:100%;display:flex;flex-direction:column}'
        + '.requisitos-modal-90 .modal-body{flex:1 1 auto;min-height:0;display:flex;padding:10px}.requisitos-modal-90 .modal-body.p-0{padding:0}.requisitos-modal-90 .modal-body>textarea{flex:1 1 auto;height:auto!important;resize:none;padding:10px 12px!important;font-size:13px;line-height:1.5}'
        + '.requisitos-modal-90 .modal-body>iframe{flex:1 1 auto;width:100%;height:100%}';
    (document.head || document.documentElement).appendChild(st); }());
(function ($) {
    'use strict';
    if (!window.greenPortalBaseUrl) { var portalPath = String(window.location.pathname || ''), portalPrincipal = portalPath.indexOf('/principal/'); if (portalPrincipal >= 0) window.greenPortalBaseUrl = portalPath.substring(0, portalPrincipal); }
    // Textos de Administração › Textos e idiomas (window.greenT); os daqui são o padrão.
    function T(chave, padrao) { return typeof window.greenT === 'function' ? window.greenT(chave, padrao) : padrao; }
    $('.modulo-legal-requisitos-fragment').each(function () {
        var $fragment = $(this), $table = $fragment.find('.menu-batch-grid'), prefs = $fragment.data('preferences') || {}, cols = $table.find('thead th[data-column]').map(function () { return String($(this).data('column')); }).get();
        if (!$table.length) return;
        var technicalColumns = ['CODLEGISLACAO', 'CODLEGISLACAXEMPRESA', 'CODUNIDADE', 'CODTIPO', 'CODAMBITO', 'CODEMPRESA', 'RESPONSAVEL_IDS'], functionalColumns = ['CODIGO', 'UNIDADE'];
        function apply() {
            cols.sort(function (a, b) { return Number((prefs[a] || {}).ordem || 0) - Number((prefs[b] || {}).ordem || 0); });
            if (cols.indexOf('UNIDADE') >= 0) cols = ['UNIDADE'].concat(cols.filter(function (column) { return column !== 'UNIDADE'; }));
            cols.forEach(function (column) { $table.find('thead tr').append($table.find('th[data-column="' + column + '"]')); $table.find('tbody tr').each(function () { $(this).append($(this).find('td[data-column="' + column + '"]').detach()); }); });
            cols.forEach(function (column) { var preference = prefs[column] || {}, isFunctional = functionalColumns.indexOf(column) >= 0, visible = technicalColumns.indexOf(column) < 0 && (preference.visivel === undefined ? true : !!Number(preference.visivel)); if (isFunctional && preference.visivel === undefined) visible = true; $table.find('[data-column="' + column + '"]').toggle(visible).css('width', Math.max(60, Number(preference.largura || 150)) + 'px'); });
            installLegislationIcons();
            installPatternIcons();
        }
        function installPatternIcons() {
            $table.find('tbody tr').each(function () {
                var $cell = $(this).find('td[data-column="PADRAO"]').first();
                if (!$cell.length || $cell.find('[data-open-padrao]').length) return;
                var texto = $.trim($cell.text());
                if (!texto) return;
                $cell.css('white-space', 'nowrap').prepend($('<button>', { type: 'button', class: 'btn btn-link btn-sm p-0 me-1 requisitos-padrao-button', 'data-open-padrao': '', title: T('modulolegal_requisitoslegais.dica.ler_padrao', 'Ler Padrão'), 'aria-label': T('modulolegal_requisitoslegais.dica.ler_padrao', 'Ler Padrão') }).append($('<i>', { class: 'bi bi-eye' }))).data('padrao-texto', texto);
            });
            if (window.bootstrap && bootstrap.Tooltip) $table.find('[data-open-padrao]').each(function () { if (!bootstrap.Tooltip.getInstance(this)) bootstrap.Tooltip.getOrCreateInstance(this); });
        }
        function installLegislationIcons() {
            $table.find('tbody tr').each(function () {
                var $cell = $(this).find('td[data-column="CODIGO"]').first();
                if (!$cell.length || $cell.find('[data-open-legislacao-pdf]').length) return;
                var code = $.trim($cell.text());
                if (!code) return;
                $cell.prepend($('<button>', { type: 'button', class: 'btn btn-link btn-sm p-0 me-1 legislation-code-icon', 'data-open-legislacao-pdf': '', title: T('modulolegal_requisitoslegais.dica.texto_norma', 'Texto da Norma'), 'aria-label': T('modulolegal_requisitoslegais.dica.abrir_texto_norma', 'Abrir Texto da Norma') }).append($('<i>', { class: 'bi bi-file-earmark-pdf' })));
            });
        }
        function savePreferences() {
            var values = cols.map(function (column, index) { prefs[column] = prefs[column] || {}; return { coluna: column, ordem: index, visivel: $table.find('[data-column="' + column + '"]:first').is(':visible') ? 1 : 0, largura: Math.round($table.find('th[data-column="' + column + '"]').outerWidth() || 150) }; });
            $.post(window.location.href, { action: 'save_preferences', preferences: JSON.stringify(values), ajax: 1 });
        }
        $fragment.on('click', '[data-export-requisitos]', function (event) {
            event.preventDefault();
            var $button = $(this), filters = {}, columns = [];
            $table.find('thead th[data-column]:visible').each(function () { columns.push(String($(this).data('column'))); });
            $fragment.find('[data-grid-filter], [data-column-filter]').each(function () { var value = String($(this).val() || '').trim(); if (value !== '') filters[String($(this).data('grid-filter') || $(this).data('column-filter'))] = value; });
            $button.prop('disabled', true).attr('data-export-active', '1');
            $.post(window.location.href, { action: 'export_request', filtros: JSON.stringify(filters), busca: String($fragment.find('.crud-search input').val() || '').trim(), colunas: JSON.stringify(columns), ajax: 1 }, function (response) { window.alert(response && response.ok ? (response.message || T('comum.msg.exportacao_solicitada', 'Exportação solicitada; acompanhe os alertas.')) : T('modulolegal_requisitoslegais.erro.exportacao', 'Não foi possível solicitar a exportação.')); }, 'json').fail(function () { window.alert(T('modulolegal_requisitoslegais.erro.exportacao', 'Não foi possível solicitar a exportação.')); }).always(function () { $button.prop('disabled', false).removeAttr('data-export-active'); });
        });
        $fragment.on('click', '[data-columns]', function () {
            var id = 'requisitosColumnsModal', body = '<div class="row g-2">';
            cols.forEach(function (column) { var label = $table.find('th[data-column="' + column + '"] span').text(); body += '<div class="col-md-6"><label class="form-check"><input class="form-check-input" type="checkbox" data-requisitos-column="' + column + '"' + ($table.find('[data-column="' + column + '"]:first').is(':visible') ? ' checked' : '') + '> ' + $('<div>').text(label).html() + '</label></div>'; });
            body += '</div>'; $('#' + id).remove(); $('body').append('<div class="modal fade" id="' + id + '" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Colunas do grid</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body">' + body + '</div><div class="modal-footer"><button type="button" class="btn btn-sm btn-success" data-save-requisitos-columns>Salvar</button></div></div></div></div>'); bootstrap.Modal.getOrCreateInstance(document.getElementById(id)).show();
        });
        $fragment.on('click', '[data-save-requisitos-columns]', function () { $('#' + 'requisitosColumnsModal [data-requisitos-column]').each(function () { prefs[$(this).data('requisitos-column')] = prefs[$(this).data('requisitos-column')] || {}; prefs[$(this).data('requisitos-column')].visivel = this.checked ? 1 : 0; }); apply(); savePreferences(); bootstrap.Modal.getInstance(document.getElementById('requisitosColumnsModal')).hide(); });
        function updateButtons() { var $row = $table.find('tbody tr.selected'), hasRow = $row.length > 0; $fragment.find('[data-open-requisito-popup]').prop('disabled', !hasRow); $fragment.find('[data-request-ia-analysis], [data-open-questionnaire]').prop('disabled', !hasRow); }
        function updateControlButton() { var dirty = false; $table.find('[data-control-field]').each(function () { if (String($(this).val() || '') !== String($(this).attr('data-original-value') || '')) dirty = true; }); $fragment.find('[data-confirm-requisitos]').prop('disabled', !dirty || $fragment.find('[data-confirm-requisitos]').attr('data-permission-locked') === '1'); }
        $table.on('change', '[data-control-field]', function () { var $row = $(this).closest('tr'), dirty = String($(this).val() || '') !== String($(this).attr('data-original-value') || ''); $row.toggleClass('row-pending-edit', dirty); updateControlButton(); });
        $table.on('click', 'tbody tr', function () { $table.find('tbody tr').removeClass('selected'); $(this).addClass('selected'); updateButtons(); });
        $fragment.on('click', '[data-open-requisito-popup]', function () { var $row = $table.find('tbody tr.selected'), leg = $.trim($row.find('[data-column="CODLEGISLACAO"]').text()), unit = $.trim($row.find('[data-column="CODUNIDADE"]').text()), code = $.trim($row.find('[data-column="CODIGO"]').text()) || leg, pages = { situacao: 'manutencaolegalrequisitoslegais_situacao.php', licencas: 'manutencaolegalrequisitoslegais_licencas.php', controles: 'manutencaolegalrequisitoslegais_controles.php', medicaomonitoramento: 'manutencaolegalrequisitoslegais_medicaomonitoramento.php', checklist: 'manutencaolegalrequisitoslegais_checklist.php', alteracoes: 'manutencaolegalrequisitoslegais_alteracoes.php', homologacao: 'manutencaolegalrequisitoslegais_homologacao.php' }, page = pages[String($(this).data('open-requisito-popup') || '')]; if (!$row.length || !leg || !page) return; var host = window.parent && window.parent.GreenPortalPopups ? window.parent : window, frame = host.jQuery('<iframe>', { style: 'width:100%;height:100%;border:0;display:block;' }).attr('src', new URL(page + '?CODLEGISLACAO=' + encodeURIComponent(leg) + '&CODUNIDADE=' + encodeURIComponent(unit), window.location.href).toString()); if (host.GreenPortalPopups) host.GreenPortalPopups.transacao({ titulo: String($(this).attr('title') || '') + ' - ' + code, conteudo: frame }); });
        $fragment.on('click', '[data-confirm-requisitos]', function () {
            var $button = $(this), rows = [];
            $table.find('tbody tr').each(function () { var $row = $(this), $field = $row.find('[data-control-field]'), value = String($field.val() || ''), oldValue = String($field.attr('data-original-value') || ''); if (!$field.length || value === oldValue) return; rows.push({ CODLEGISLACAO: $row.find('[data-column="CODLEGISLACAO"]').text().trim(), CODUNIDADE: $row.find('[data-column="CODUNIDADE"]').text().trim(), PODER: value, PODER_ANTERIOR: oldValue }); });
            if (!rows.length) return;
            var idioma = String(window.greenPortalIdioma || 'pt').toLowerCase(), host = window.parent && window.parent.GreenPortalPopups ? window.parent : window, popup = host.GreenPortalPopups;
            var confirmar = function () {
                $button.prop('disabled', true);
                $.post(window.location.href, { action: 'save_control', rows: rows, ajax: 1 }, function (response) { if (!response || !response.ok) { if (popup) popup.mensagem({ titulo: T('comum.popup.mensagem', idioma === 'en' ? 'Message' : (idioma === 'es' ? 'Mensaje' : 'Mensagem')), mensagem: response && response.message ? response.message : T('modulolegal_requisitoslegais.erro.confirmar_alteracao', 'Não foi possível confirmar a alteração.'), icone: 'bi-exclamation-triangle' }); updateControlButton(); return; } $table.find('[data-control-field]').each(function () { $(this).attr('data-original-value', String($(this).val() || '')); }); $table.find('tbody tr').removeClass('row-pending-edit'); if (popup) popup.mensagem({ titulo: T('comum.popup.sucesso', idioma === 'en' ? 'Success' : (idioma === 'es' ? 'Éxito' : 'Sucesso')), mensagem: response.message || T('modulolegal_requisitoslegais.msg.alteracao_confirmada', 'Alteração confirmada.'), icone: 'bi-check-circle' }); updateControlButton(); }, 'json').fail(function (xhr) { if (popup) popup.mensagem({ titulo: T('comum.popup.mensagem', idioma === 'en' ? 'Message' : (idioma === 'es' ? 'Mensaje' : 'Mensagem')), mensagem: (xhr.responseJSON && xhr.responseJSON.message) || T('modulolegal_requisitoslegais.erro.confirmar_alteracao', 'Não foi possível confirmar a alteração.'), icone: 'bi-exclamation-triangle' }); updateControlButton(); });
            };
            if (popup) popup.mensagem({ titulo: T('comum.confirmacao.titulo', idioma === 'en' ? 'Confirmation' : (idioma === 'es' ? 'Confirmación' : 'Confirmação')), mensagem: T('modulolegal_requisitoslegais.confirmacao.nivel', idioma === 'en' ? 'Confirm the control level changes?' : (idioma === 'es' ? '¿Confirmar los cambios del nivel de control?' : 'Confirmar as alterações do nível de controle?')), icone: 'bi-question-circle', confirmar: confirmar }); else confirmar();
        });
        $fragment.on('click', '[data-open-padrao]', function (event) { event.stopPropagation(); var $cell = $(this).closest('td'), text = String($cell.data('padrao-texto') || $cell.text()).trim(), code = $.trim($cell.closest('tr').find('[data-column="CODIGO"]').text()), $modal = $('#requisitosPadraoModal'); if (!$modal.length) $modal = $('<div class="modal fade requisitos-modal-90" id="requisitosPadraoModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header py-2"><h5 class="modal-title" data-requisitos-padrao-titulo></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><textarea class="form-control" readonly data-requisitos-padrao-texto></textarea></div></div></div></div>').appendTo('body'); $modal.find('[data-requisitos-padrao-titulo]').text(T('modulolegal_requisitoslegais.coluna.PADRAO', 'Padrão') + (code ? ' - ' + code : '')); $modal.find('[data-requisitos-padrao-texto]').val(text); bootstrap.Modal.getOrCreateInstance($modal[0]).show(); });
        $fragment.on('click', '[data-open-legislacao-pdf]', function (event) { event.stopPropagation(); var code = $.trim($(this).closest('tr').find('[data-column="CODIGO"]').text()); if (!code) return; var url = 'legislacao_pdf.php?codigo=' + encodeURIComponent(code), $modal = $('#requisitosPdfModal'); if (!$modal.length) $modal = $('<div class="modal fade requisitos-modal-90" id="requisitosPdfModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Texto da Lei: ' + $('<div>').text(code).html() + '</h5><a class="btn btn-sm btn-outline-primary" data-requisitos-pdf-download download title="Baixar PDF"><i class="bi bi-download"></i></a><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body p-0"><iframe data-requisitos-pdf-frame style="width:100%;height:100%;border:0"></iframe></div></div></div></div>').appendTo('body'); $modal.find('[data-requisitos-pdf-download]').attr('href', url).end().find('[data-requisitos-pdf-frame]').attr('src', url); bootstrap.Modal.getOrCreateInstance($modal[0]).show(); });
        $fragment.on('click', '[data-open-questionnaire]', function () { var $row = $table.find('tbody tr.selected'), leg = $.trim($row.find('[data-column="CODLEGISLACAO"]').text()), unit = $.trim($row.find('[data-column="CODUNIDADE"]').text()); if (!$row.length || !leg) return; var host = window.parent && window.parent.GreenPortalPopups ? window.parent : window, title = String($(this).attr('title') || T('modulolegal_requisitoslegais.botao.questionario', 'Questionário Legal')), url = (host.greenPortalBaseUrl || '') + '/principal/questionario_execucao.php?contexto=LEGAL&filtros=' + encodeURIComponent(JSON.stringify({ CODLEGISLACAO: leg, CODUNIDADE: unit })); if (host.GreenPortalPopups && host.jQuery) { var frame = host.jQuery('<iframe>', { title: title, style: 'width:100%;height:100%;border:0;display:block;' }).attr('src', url); host.GreenPortalPopups.transacao({ titulo: title, conteudo: frame }); } else window.open(url, '_blank'); });
        $fragment.on('gridPageLoaded', function () { apply(); updateButtons(); updateControlButton(); });
        apply(); updateButtons(); updateControlButton();
    });
}(jQuery));
(function($){
    function T(chave, padrao) { return typeof window.greenT === 'function' ? window.greenT(chave, padrao) : padrao; }
    $(function(){
        function openIaResult(requestId, code, resultUrl) {
            var host = window.parent && window.parent.GreenPortalPopups ? window.parent : window, popup = host.GreenPortalPopups, title = T('modulolegal_requisitoslegais.titulo.requisito', 'Requisito legal') + ' - ' + (code || '');
            if (!requestId || !popup || !host.jQuery) return;
            var url = resultUrl || new URL('ia_potenciais_resultado.php?codsolicitacao=' + encodeURIComponent(requestId), window.location.href).toString();
            if (url.charAt(0) === '/') url = new URL(url, window.location.origin).toString();
            var frame = host.jQuery('<iframe>', { title: title, style: 'width:100%;height:100%;border:0;display:block;' }).attr('src', url);
            popup.transacao({ titulo: title, conteudo: frame });
        }
        $(document).on('click', '[data-request-ia-analysis]', function(){
            var $button=$(this), $fragment=$button.closest('[data-grid-config]'), $row=$fragment.find('tbody tr.selected').first();
            if(!$row.length){ alert(T('modulolegal_requisitoslegais.msg.selecione_requisito', 'Selecione um requisito legal.')); return; }
            var context={};
            $row.find('[data-column]').each(function(){ context[$(this).data('column')]=$.trim($(this).text()); });
            $button.prop('disabled',true);
            var code=$.trim(context.CODIGO || context.CODLEGISLACAO || ''), latest=new FormData(); latest.append('action','ia_latest_request'); latest.append('codlegislacao',String(parseInt(context.CODLEGISLACAO || '0',10)));
            fetch(window.location.href,{method:'POST',body:latest,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
                .then(function(response){return response.json().then(function(body){return {ok:response.ok,body:body};});})
                .then(function(result){
                    if(!result.body.ok) throw new Error(result.body.message || T('modulolegal_requisitoslegais.erro.consultar_analise', 'Não foi possível consultar a última análise.'));
                    if(result.body.exists && result.body.codsolicitacao){ openIaResult(result.body.codsolicitacao, code, result.body.url); return; }
                    var data=new FormData(); data.append('action','request_ia_analysis'); data.append('contexto',JSON.stringify(context));
                    return fetch(window.location.href,{method:'POST',body:data,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
                        .then(function(response){return response.json().then(function(body){return {ok:response.ok,body:body};});})
                        .then(function(requestResult){ if(!requestResult.body.ok) throw new Error(requestResult.body.message || T('modulolegal_requisitoslegais.erro.solicitar_analise', 'Não foi possível solicitar a análise.')); openIaResult(requestResult.body.codsolicitacao, code); });
                })
                .catch(function(error){ alert(error.message || T('modulolegal_requisitoslegais.erro.solicitar_analise_ia', 'Não foi possível solicitar a análise de IA.')); $button.prop('disabled',false); });
        });
    });
}(jQuery));
