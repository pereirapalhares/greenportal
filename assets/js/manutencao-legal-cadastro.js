(function ($) {
    'use strict';
    // Textos do grupo COMUM (Administração › Textos e idiomas, window.greenT); os daqui são o padrão.
    // TH escapa o texto para uso dentro de HTML montado aqui.
    function T(chave, padrao) { return typeof window.greenT === 'function' ? window.greenT(chave, padrao) : padrao; }
    // Mensagem enviada pelo servidor (já no idioma do usuário) ou, sem ela, o texto padrão.
    function mensagemServidor(xhr, chave, padrao) { var m = xhr && xhr.responseJSON && xhr.responseJSON.message; return m ? String(m).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') : TH(chave, padrao); }
    function TH(chave, padrao) { return String(T(chave, padrao)).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    function greenPortalRoute(host, path) {
        var base = String((host && host.greenPortalBaseUrl) || window.greenPortalBaseUrl || '');
        if (!base) {
            var currentPath = String((host && host.location && host.location.pathname) || window.location.pathname || ''), principalPos = currentPath.indexOf('/principal/');
            if (principalPos >= 0) base = currentPath.substring(0, principalPos);
        }
        return base + (String(path || '').charAt(0) === '/' ? String(path || '') : '/' + String(path || ''));
    }
    if (!window.greenPortalBaseUrl) {
        var portalPath = String(window.location.pathname || ''), portalPrincipal = portalPath.indexOf('/principal/');
        if (portalPrincipal >= 0) window.greenPortalBaseUrl = portalPath.substring(0, portalPrincipal);
    }
    // Corrige textos antigos que foram publicados com dupla conversão de UTF-8.
    function normalizarAcentos(root) { var mapa = {'\u00c3\u00a7\u00c3\u00a3':'\u00e7\u00e3','\u00c3\u00a7\u00c3\u00b5':'\u00e7\u00f5','\u00c3\u00a1':'\u00e1','\u00c3\u00a9':'\u00e9','\u00c3\u00ad':'\u00ed','\u00c3\u00b3':'\u00f3','\u00c3\u00ba':'\u00fa','\u00c3\u00a2':'\u00e2','\u00c3\u00aa':'\u00ea','\u00c3\u00b4':'\u00f4','\u00c3\u00a3':'\u00e3','\u00c3\u00b5':'\u00f5','\u00c3\u00a7':'\u00e7','\u00c3\u2030':'\u00c9','\u00c2\u00bf':'\u00bf'}; /* Somente sequências corrompidas (UTF-8 lido como Latin-1); texto correto não é alterado. */ var corrigir = function (valor) { var texto = String(valor || ''); Object.keys(mapa).forEach(function (chave) { texto = texto.split(chave).join(mapa[chave]); }); return texto; }; var walker = document.createTreeWalker(root || document.body, NodeFilter.SHOW_TEXT), node; while ((node = walker.nextNode())) { var original = node.nodeValue, corrigido = corrigir(original); if (corrigido !== original) node.nodeValue = corrigido; } (root || document.body).querySelectorAll('[title],[aria-label]').forEach(function (elemento) { ['title','aria-label'].forEach(function (atributo) { if (elemento.hasAttribute(atributo)) { var original = elemento.getAttribute(atributo), corrigido = corrigir(original); if (corrigido !== original) elemento.setAttribute(atributo, corrigido); } }); }); }
    $(function () {
        normalizarAcentos(document.body);
        if (!window.MutationObserver) return;
        var queued = false;
        new MutationObserver(function (records) {
            if (queued) return;
            queued = true;
            window.requestAnimationFrame(function () {
                queued = false;
                records.forEach(function (record) {
                    Array.prototype.forEach.call(record.addedNodes || [], function (node) {
                        if (node.nodeType === 1) normalizarAcentos(node);
                    });
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    });
    $('.cadastro-legal-fragment').each(function () {
        var standardLanguage = window.greenPortalIdioma || 'pt', standardLabel = standardLanguage === 'en' ? 'Files' : (standardLanguage === 'es' ? 'Archivos' : 'Arquivos');
        var $fragment = $(this), $table = $fragment.find('.menu-batch-grid'), $form = $fragment.find('.menu-grid-form'), cfg = $fragment.data('cadastro-config') || {}, prefs = $fragment.data('preferences') || {}, permission = window.greenPortalCrudPermission || {}, cols = $table.find('thead th[data-column]').map(function () { return String($(this).data('column')); }).get();
        $table.find('th[data-column="ARQUIVO"] span').text(standardLabel);
        if ($fragment.hasClass('licencas-condicionantes-planodeacao-grid-fragment')) $table.find('th[data-column="ARQUIVO"] span').text(standardLanguage === 'en' ? 'Evidence' : (standardLanguage === 'es' ? 'Evidencia' : 'Evidência'));
        var primary = String(cfg.primary || cols[0] || ''), formId = $form.attr('id');
        Object.keys(cfg.fields || {}).forEach(function (field) { if ((cfg.fields[field] || {}).hidden) $table.find('[data-column="' + field + '"]').hide(); });
        function installDatePickers() { $fragment.find('input[type="date"], input[data-calendar-input="1"], input[name*="[DATA_"], input[name*="[PROTOCOLADO_EM]"]').each(function () { var $input = $(this); if ($input.data('calendar-ready')) return; var language = window.greenPortalIdioma || 'pt', value = String($input.val() || ''), iso = value.match(/^(\d{4})-(\d{2})-(\d{2})$/), match = value.match(/^(\d{2})\/(\d{2})\/(\d{4})$/); if (iso) value = language === 'en' ? iso[2] + '/' + iso[3] + '/' + iso[1] : iso[3] + '/' + iso[2] + '/' + iso[1]; else if (match && language === 'en') value = match[2] + '/' + match[1] + '/' + match[3]; $input.val(value).prop('readonly', false).attr('type', 'text').attr('data-calendar-input', '1').data('calendar-ready', true); var $button = $('<button>', { type: 'button', class: 'btn btn-link btn-sm p-0 calendar-picker', title: T('comum.calendario.abrir', 'Abrir calendário'), 'aria-label': T('comum.calendario.abrir', 'Abrir calendário') }).css({ width: '22px', 'min-width': '22px', 'font-size': '0.72rem', 'line-height': '1' }).append($('<i>', { class: 'bi bi-calendar3' })); $input.wrap('<div class="input-group input-group-sm flex-nowrap"></div>').parent().append($button); $button.on('click', function () { var current = String($input.val() || ''), parts = current.split('/'), day = language === 'en' ? Number(parts[1]) : Number(parts[0]), month = language === 'en' ? Number(parts[0]) : Number(parts[1]), year = Number(parts[2]); if (!day || !month || !year) { var now = new Date(); day = now.getDate(); month = now.getMonth() + 1; year = now.getFullYear(); } var $popup = $('.green-portal-date-picker'); if (!$popup.length) { $popup = $('<div class="green-portal-date-picker shadow border rounded bg-white p-2" role="dialog"></div>').css({ position: 'fixed', zIndex: 2000, width: '260px' }).appendTo('body'); } var draw = function () { var first = new Date(year, month - 1, 1), last = new Date(year, month, 0), start = first.getDay(); if (language !== 'en') start = (start + 6) % 7; var labels = language === 'en' ? ['Su','Mo','Tu','We','Th','Fr','Sa'] : (language === 'es' ? ['Lu','Ma','Mi','Ju','Vi','Sá','Do'] : ['Seg','Ter','Qua','Qui','Sex','Sáb','Dom']); var head = $('<div class="d-flex justify-content-between align-items-center mb-2"><button type="button" class="btn btn-sm btn-outline-secondary" data-date-prev>‹</button><strong></strong><button type="button" class="btn btn-sm btn-outline-secondary" data-date-next>›</button></div>'); head.find('strong').text(String(month).padStart(2,'0') + '/' + year); var table = $('<table class="table table-sm text-center mb-0"><thead><tr></tr></thead><tbody></tbody></table>'); labels.forEach(function (label) { table.find('thead tr').append($('<th class="small">').text(label)); }); var row; for (var i = 0; i < start + last.getDate(); i++) { if (i % 7 === 0) { row = $('<tr></tr>'); table.find('tbody').append(row); } if (i < start) row.append('<td></td>'); else { var number = i - start + 1, cell = $('<button type="button" class="btn btn-sm w-100">').text(number).data('day', number); if (number === day) cell.addClass('btn-success'); row.append($('<td class="p-0"></td>').append(cell)); } } $popup.empty().append(head, table); head.find('[data-date-prev]').on('click', function () { month--; if (month < 1) { month = 12; year--; } draw(); }); head.find('[data-date-next]').on('click', function () { month++; if (month > 12) { month = 1; year++; } draw(); }); table.find('button[data-day]').on('click', function () { var selected = Number($(this).data('day')); $input.val(language === 'en' ? String(month).padStart(2,'0') + '/' + String(selected).padStart(2,'0') + '/' + year : String(selected).padStart(2,'0') + '/' + String(month).padStart(2,'0') + '/' + year); $popup.remove(); $input.trigger('change'); }); }; draw(); var position = $input[0].getBoundingClientRect(); $popup.css({ left: Math.min(position.left, window.innerWidth - 270), top: Math.min(position.bottom + 4, window.innerHeight - 310) }); }); }); }
        function confirmAction(message, callback) { var id = 'cadastroConfirmModal'; $('#' + id).remove(); $('body').append('<div class="modal fade" id="' + id + '" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">' + TH('comum.confirmacao.titulo', 'Confirmação') + '</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body">' + String(message).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</div><div class="modal-footer"><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">' + TH('comum.confirmacao.cancelar', 'Cancelar') + '</button><button type="button" class="btn btn-sm btn-success" data-confirm-cadastro>' + TH('comum.confirmacao.confirmar', 'Confirmar') + '</button></div></div></div></div>'); var modal = bootstrap.Modal.getOrCreateInstance(document.getElementById(id)); $(document).off('click', '[data-confirm-cadastro]').on('click', '[data-confirm-cadastro]', function () { modal.hide(); callback(); }); modal.show(); }
        function cellValue($cell) { var $field = $cell.find('input,textarea,select').first(); return $field.is(':checkbox') ? ($field.is(':checked') ? '1' : '0') : String($field.val() || $cell.text() || ''); }
        function installInlineEditors() { Object.keys(cfg.fields || {}).forEach(function (field) { var fieldConfig = cfg.fields[field] || {}; if (!fieldConfig.inline_edit) return; $table.find('tbody tr').each(function () { var $row = $(this), $cell = $row.find('[data-column="' + field + '"]'), $select = $cell.find('select.grid-input').first(); if (!$select.length || String($row.data('key') || '').indexOf('novo_') === 0 || $cell.data('inline-ready')) return; var value = String($select.val() || ''), label = $select.find('option:selected').text() || value, $display = $('<span>', { class: 'grid-inline-display', 'data-inline-display': '1', text: label, title: 'Clique para alterar' }); $select.hide().attr('data-inline-select', '1'); $cell.prepend($display).attr('data-inline-editor', '1').data('inline-ready', true); }); }); }
        function openInlineEditor($row) { if (permission.edit === false) return; Object.keys(cfg.fields || {}).forEach(function (field) { var fieldConfig = cfg.fields[field] || {}; if (!fieldConfig.inline_edit) return; var $cell = $row.find('[data-column="' + field + '"]'), $select = $cell.find('select[data-inline-select]'); if (!$select.length) return; $cell.find('[data-inline-display]').hide(); $select.show().trigger('focus'); }); }
        function enforceEditPermission() { if (permission.edit !== false) return; $table.find('tbody tr:not([data-key^="novo_"]) input, tbody tr:not([data-key^="novo_"]) select, tbody tr:not([data-key^="novo_"]) textarea').prop('readonly', true).prop('disabled', true); }
        function apply() { var legacyOrder = Array.isArray(cfg.legacy_order) ? cfg.legacy_order : []; if (legacyOrder.length) { var configured = legacyOrder.filter(function (col) { return cols.indexOf(col) >= 0; }), remaining = cols.filter(function (col) { return configured.indexOf(col) < 0; }); cols = configured.concat(remaining); } else cols.sort(function (a, b) { return Number((prefs[a] || {}).ordem || 0) - Number((prefs[b] || {}).ordem || 0); });
            var unitColumn = ['DSCUNIDADE', 'UNIDADES', 'UNIDADE', 'CODUNIDADE'].find(function (col) { var field = (cfg.fields || {})[col] || {}; return cols.indexOf(col) >= 0 && !field.hidden; });
            if (unitColumn) cols = [unitColumn].concat(cols.filter(function (col) { return col !== unitColumn; }));
            cols.forEach(function (col) { $table.find('thead tr').append($table.find('th[data-column="' + col + '"]')); $table.find('tbody tr').each(function () { $(this).append($(this).find('td[data-column="' + col + '"]').detach()); }); }); cols.forEach(function (col) { var p = prefs[col] || {}, fieldConfig = (cfg.fields || {})[col] || {}, fixed = !!cfg.legacy_fixed, visible = !fieldConfig.hidden && (fieldConfig.force_visible || (fixed && fieldConfig.force_legacy_visible) || (!fixed && (p.visivel === undefined ? col !== primary : !!Number(p.visivel)))), width = Math.max(60, Number((fixed && fieldConfig.force_legacy_visible) ? fieldConfig.width : (p.largura || fieldConfig.width || 180))); $table.find('[data-column="' + col + '"]').css('width', width + 'px').toggle(visible); }); enforceEditPermission(); }
        function savePrefs() { var values = cols.map(function (col, i) { prefs[col] = prefs[col] || {}; return { coluna: col, ordem: i, visivel: $table.find('[data-column="' + col + '"]:first').is(':visible') ? 1 : 0, largura: Math.round($table.find('th[data-column="' + col + '"]').outerWidth() || 180) }; }); $.post(window.location.href, { action: 'save_preferences', preferences: JSON.stringify(values) }); }
        function rowHtml(key, source) { var html = '<tr data-row data-key="' + key + '">'; cols.forEach(function (col) { var value = source && source[col] ? String(source[col]).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;') : '', fieldConfig = (cfg.fields || {})[col] || {}, options = fieldConfig.options, foreign = fieldConfig.foreign || {}; html += '<td data-column="' + col + '">'; if (col !== primary && fieldConfig.readonly && !fieldConfig.new_editable) html += '<input class="grid-input" name="rows[' + key + '][' + col + ']" value="' + value + '" readonly tabindex="-1">'; else if (col === primary) html += '<input class="grid-input" name="rows[' + key + '][' + col + ']" value="" placeholder="Código">'; else if (fieldConfig.lazy_foreign && foreign.view) { html += '<select class="grid-input" name="rows[' + key + '][' + col + ']" data-lazy-foreign="1" data-lazy-view="' + String(foreign.view).replace(/"/g, '&quot;') + '" data-lazy-value="' + String(foreign.value || col).replace(/"/g, '&quot;') + '" data-lazy-label="' + String(foreign.label || '').replace(/"/g, '&quot;') + '" data-lazy-filters=\'' + JSON.stringify(foreign.filters || {}).replace(/'/g, '&#039;') + '\'><option value="' + value + '" selected>' + value + '</option></select>'; } else if (options) { html += '<select class="grid-input" name="rows[' + key + '][' + col + ']">'; Object.keys(options).forEach(function (option) { var optionLabel = options[option]; if (typeof optionLabel === 'object') optionLabel = optionLabel[window.greenPortalIdioma || 'pt'] || optionLabel.pt || ''; html += '<option value="' + option + '"' + (String(option) === value ? ' selected' : '') + '>' + optionLabel + '</option>'; }); html += '</select>'; } else { var field = fieldConfig.type === 'textarea' ? 'textarea' : 'input'; var type = fieldConfig.type && fieldConfig.type !== 'textarea' ? ' type="' + fieldConfig.type + '"' : ''; html += '<' + field + ' class="grid-input" name="rows[' + key + '][' + col + ']"' + type + (field === 'textarea' ? ' rows="2"' : ' value="' + value + '"') + '>' + (field === 'textarea' ? value + '</textarea>' : ''); } html += '</td>'; }); return html + '</tr>'; }
        $fragment.on('click', '[data-new-cadastro]', function () { var key = 'novo_' + Date.now(); $table.find('tbody').prepend(rowHtml(key)); $table.find('tbody tr').removeClass('selected'); $table.find('tbody tr:first').addClass('selected'); installDatePickers(); $form.trigger('change'); });
        $fragment.on('click', '[data-duplicate-cadastro]', function () { var $row = $table.find('tbody tr.selected'); if (!$row.length) return; var key = 'novo_' + Date.now(), source = {}; cols.forEach(function (col) { source[col] = col === primary ? '' : cellValue($row.find('[data-column="' + col + '"]')); }); $table.find('tbody').prepend(rowHtml(key, source)); $table.find('tbody tr').removeClass('selected'); $table.find('tbody tr:first').addClass('selected'); installDatePickers(); $form.trigger('change'); });
        if (String($fragment.attr('data-context-crud') || '') === '1' || $fragment.hasClass('homologacao-fornecedores-grid-fragment')) {
            function filterUniqueContextOptions() { var uniqueField = String(cfg.unique_context_field || ''); if (!uniqueField) return; var used = []; $table.find('tbody tr:not([data-key^="novo_"]) [data-column="' + uniqueField + '"] input, tbody tr:not([data-key^="novo_"]) [data-column="' + uniqueField + '"] select').each(function () { var value = String($(this).attr('data-raw-value') || $(this).val() || '').trim(); if (value !== '') used.push(value); }); $table.find('tbody tr[data-key^="novo_"] [data-column="' + uniqueField + '"] select option').each(function () { if (used.indexOf(String($(this).val() || '').trim()) >= 0) $(this).remove(); }); }
            $fragment.on('click', '[data-new-cadastro]', function () { window.setTimeout(filterUniqueContextOptions, 20); });
            function ajaxReload(message) {
                var context = $fragment.data('context-filters') || {};
                if (!context || typeof context !== 'object' || (!context.CODLEGISLACAO && !context.CODUNIDADE)) {
                    try { context = JSON.parse($fragment.attr('data-context-filters') || '{}'); } catch (error) { context = {}; }
                }
                var filtros = encodeURIComponent(JSON.stringify({ CODLEGISLACAO: String(context.CODLEGISLACAO || ''), CODUNIDADE: String(context.CODUNIDADE || '') }));
                var reloadUrl = window.location.pathname + '?CODLEGISLACAO=' + encodeURIComponent(String(context.CODLEGISLACAO || '')) + '&CODUNIDADE=' + encodeURIComponent(String(context.CODUNIDADE || '')) + '&filtros=' + filtros + '&_atualizado=' + Date.now();
                // Recarrega o próprio documento do iframe com o contexto explícito.
                // Isso evita que o POST seja persistido no banco, mas o grid permaneça
                // com o HTML anterior por causa de cache ou de um fragmento incompleto.
                window.location.replace(reloadUrl);
                return $.Deferred().resolve().promise();
            }
            $fragment.on('click', '[data-delete-cadastro]', function (event) {
                event.preventDefault(); event.stopImmediatePropagation();
                var $row = $table.find('tbody tr.selected'); if (!$row.length) return;
                var key = String($row.data('key')); if (key.indexOf('novo_') === 0) { $row.remove(); return; }
                confirmAction(T('comum.confirmacao.excluir', 'Confirma a exclusão do registro selecionado?'), function () {
                    var data = $form.serializeArray(); data.push({ name: 'action', value: 'delete_row' }); data.push({ name: 'key', value: key }); data.push({ name: 'ajax', value: '1' });
                    $.post(window.location.href, $.param(data), null, 'json').done(function (response) { if (response && response.ok) ajaxReload(response.message); }).fail(function (xhr) { $('<div class="alert alert-danger py-2 mb-2" data-ajax-message>' + mensagemServidor(xhr, 'comum.msg.erro_excluir', 'Não foi possível excluir o registro.') + '</div>').insertBefore($form).delay(7000).fadeOut(300, function () { $(this).remove(); }); });
                });
            });
            $form.on('submit', function (event) {
                event.preventDefault(); event.stopImmediatePropagation();
                confirmAction(T('comum.confirmacao.associacao', 'Confirma a gravação da associação?'), function () {
                    var data = $form.serializeArray(); data.push({ name: 'ajax', value: '1' });
                    $.post(window.location.href, $.param(data), null, 'json').done(function (response) { if (response && response.ok) ajaxReload(response.message); }).fail(function (xhr) { $('<div class="alert alert-danger py-2 mb-2" data-ajax-message>' + mensagemServidor(xhr, 'comum.msg.erro_associacao', 'Não foi possível salvar a associação.') + '</div>').insertBefore($form).delay(7000).fadeOut(300, function () { $(this).remove(); }); });
                });
            });
            $fragment.on('click', '[data-crud-action="save"]', function (event) {
                event.preventDefault(); event.stopImmediatePropagation();
                confirmAction(T('comum.confirmacao.associacao', 'Confirma a gravação da associação?'), function () {
                    var data = $form.serializeArray(); data.push({ name: 'ajax', value: '1' });
                    $.post(window.location.href, $.param(data), null, 'json').done(function (response) { if (response && response.ok) ajaxReload(response.message); }).fail(function (xhr) { $('<div class="alert alert-danger py-2 mb-2" data-ajax-message>' + mensagemServidor(xhr, 'comum.msg.erro_associacao', 'Não foi possível salvar a associação.') + '</div>').insertBefore($form).delay(7000).fadeOut(300, function () { $(this).remove(); }); });
                });
            });
        }
        $fragment.on('click', '[data-delete-cadastro]', function () { var $row = $table.find('tbody tr.selected'); if (!$row.length) return; var key = String($row.data('key')); if (key.indexOf('novo_') === 0) { $row.remove(); return; } confirmAction(T('comum.confirmacao.excluir', 'Confirma a exclusão do registro selecionado?'), function () { $('<input>', { type: 'hidden', name: 'action', value: 'delete_row' }).appendTo($form); $('<input>', { type: 'hidden', name: 'key', value: key }).appendTo($form); $form.data('confirmed-submit', true).submit(); }); });
        // Campos 'required' da configuração: confere as linhas novas antes de pedir confirmação, para não perder o que foi digitado.
        function camposObrigatoriosOk() { var faltando = null; $form.find('[data-ajax-message][data-obrigatorio]').remove(); $table.find('tbody tr[data-key^="novo_"]').each(function () { var $row = $(this); Object.keys(cfg.fields || {}).some(function (col) { var fc = cfg.fields[col] || {}; if (!fc.required) return false; var $campo = $row.find('[data-column="' + col + '"]').find('input,select,textarea').first(); if (!$campo.length || String($campo.val() || '').trim() !== '') return false; var rotulo = fc.label; if (rotulo && typeof rotulo === 'object') rotulo = rotulo[window.greenPortalIdioma || 'pt'] || rotulo.pt || col; faltando = { campo: $campo, rotulo: String(rotulo || col) }; return true; }); return !faltando; }); if (!faltando) return true; var texto = T('comum.erro.campo_obrigatorio', 'Informe o campo {campo}.').replace('{campo}', faltando.rotulo); $('<div class="alert alert-danger py-2 mb-2" data-ajax-message data-obrigatorio></div>').text(texto).insertBefore($form).delay(7000).fadeOut(300, function () { $(this).remove(); }); faltando.campo.addClass('is-invalid').one('change input', function () { $(this).removeClass('is-invalid'); }).trigger('focus'); return false; }
        function normalizarDatasParaEnvio() { $form.find('input[data-calendar-input="1"]').each(function () { var $input = $(this), value = String($input.val() || '').trim(), match = window.greenPortalIdioma === 'en' ? value.match(/^(\d{2})\/(\d{2})\/(\d{4})$/) : value.match(/^(\d{2})\/(\d{2})\/(\d{4})$/); if (!match) return; var iso = window.greenPortalIdioma === 'en' ? match[3] + '-' + match[1] + '-' + match[2] : match[3] + '-' + match[2] + '-' + match[1]; $input.val(iso); }); }
        // Usado também por cadastro-legal-fixes.js, que assume o envio do formulário: false cancela a gravação.
        $form.data('antes-de-gravar', function () { if (!camposObrigatoriosOk()) return false; normalizarDatasParaEnvio(); return true; });
        $form.on('submit', function (event) { if ($form.data('confirmed-submit')) { event.preventDefault(); normalizarDatasParaEnvio(); $form.removeData('confirmed-submit'); HTMLFormElement.prototype.submit.call($form[0]); return; } event.preventDefault(); if (!camposObrigatoriosOk()) return; confirmAction(T('comum.confirmacao.gravar', 'Confirma a gravação das alterações?'), function () { $form.data('confirmed-submit', true).trigger('submit'); }); });
        $fragment.on('click', '[data-export-cadastro]', function () { var filters = {}, columns = []; $table.find('thead th[data-column]:visible').each(function () { columns.push(String($(this).data('column'))); }); $table.find('[data-column-filter]').each(function () { if ($(this).val() !== '') filters[String($(this).data('column-filter'))] = String($(this).val()); }); $fragment.find('[data-grid-filter]').each(function () { if ($(this).val() !== '' && $(this).val() !== null) filters[String($(this).data('grid-filter'))] = String($(this).val()); }); var search = String($fragment.find('.crud-search input').val() || ''); var $button = $(this); $button.prop('disabled', true).attr('data-export-active', '1'); $.post(window.location.href, { action: 'export_request', filtros: JSON.stringify(filters), busca: search, colunas: JSON.stringify(columns) }).done(function (r) { if (r && r.ok === false) { $button.prop('disabled', false).attr('data-export-active', '0'); $fragment.find('.gp-export-recusada').remove(); $('<div class="alert alert-warning alert-dismissible fade show gp-export-recusada" role="alert"><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button></div>').prepend(document.createTextNode(String(r.message || ''))).insertAfter($fragment.find('.crud-toolbar').first()); return; } $button.attr('title', T('comum.msg.exportacao_solicitada', 'Exportação solicitada; acompanhe os alertas.')); $(document).trigger('greenPortalExportRequested'); }).fail(function () { $(document).trigger('greenPortalExportRequested'); }); });
        $table.on('click', 'tbody tr', function () { $table.find('tbody tr').removeClass('selected'); $(this).addClass('selected'); $fragment.find('[data-delete-cadastro],[data-duplicate-cadastro],[data-open-nc-pdf]').filter(':not([data-permission-locked="1"])').prop('disabled', false); });
        $table.on('click', 'tbody tr', function () { if (String($(this).data('key') || '').indexOf('novo_') !== 0) openInlineEditor($(this)); });
        $table.on('change', '[data-inline-select]', function () { var $select = $(this), $cell = $select.closest('[data-inline-editor]'); if (!$cell.length) return; $cell.find('[data-inline-display]').text($select.find('option:selected').text()).show(); $select.hide(); });
        $table.on('click', 'tbody tr', function () { $fragment.find('[data-context-child]').each(function () { $(this).prop('disabled', false).removeAttr('aria-disabled'); }); });
        $fragment.on('focus', '[data-lazy-foreign="1"]', function () {
            var $select = $(this); if ($select.data('lazy-loaded') || $select.data('lazy-loading')) return;
            $select.data('lazy-loading', true).prop('disabled', true).append($('<option>', { value: '', text: 'Carregando...' }));
            var portalBaseUrl = (window.top && window.top.greenPortalBaseUrl) || window.greenPortalBaseUrl || '';
            if (!portalBaseUrl) {
                var principalIndex = String(window.location.pathname || '').indexOf('/principal/');
                portalBaseUrl = principalIndex > 0 ? String(window.location.pathname).slice(0, principalIndex) : '';
            }
            $.getJSON(portalBaseUrl + '/principal/api/grid_opcoes_lazy.php', { view: $select.data('lazy-view'), label: $select.data('lazy-label') || '', filters: $select.attr('data-lazy-filters') || '{}' })
                .done(function (response) { if (!response || !response.ok) return; var current = String($select.val() || ''), currentLabel = $select.find('option:selected').text(), found = false, vazio = current === '' || $select.find('option').filter(function () { return !this.value && $(this).text() !== 'Carregando...'; }).length > 0; $select.empty(); if (vazio) $select.append($('<option>', { value: '', text: '' })); /* 24/11/2026: mantém a opção vazia dos campos opcionais (allow_empty), para poder limpar o valor */ response.rows.forEach(function (row) { var value = String(row[$select.data('lazy-value')] || ''), label = String(row[$select.data('lazy-label')] || ''); if (value === current) found = true; if (value !== '') $select.append($('<option>', { value: value, text: label, selected: value === current })); }); if (current !== '' && !found) $select.prepend($('<option>', { value: current, text: currentLabel || current, selected: true })); $select.val(current); $select.data('lazy-loaded', true); })
                .fail(function (xhr) { var message = (xhr && xhr.responseJSON && xhr.responseJSON.message) || T('comum.erro.carregar_opcoes', 'Não foi possível carregar a lista.'); $select.find('option').filter(function () { return !this.value; }).remove(); $select.append($('<option>', { value: '', text: message, disabled: true })); })
                .always(function () { $select.data('lazy-loading', false).prop('disabled', false); $select.find('option').filter(function () { return !this.value && $(this).text() === 'Carregando...'; }).remove(); });
        });
        $fragment.find('[data-license-condicionantes],[data-license-palavras-chave],[data-license-responsabilidades],[data-license-arquivos]').prop('disabled', true);
        $table.on('click', 'tbody tr', function () { $fragment.find('[data-license-condicionantes],[data-license-palavras-chave],[data-license-responsabilidades],[data-license-arquivos]').prop('disabled', false); });
        function openLicenseChild(selector, path, title) { $fragment.on('click', selector, function (event) { event.preventDefault(); event.stopPropagation(); var $row = $table.find('tbody tr.selected'), code = String($row.data('key') || '').trim(); if (!$row.length || !code || code.indexOf('novo_') === 0) return; var host = window.top || window, url = (host.greenPortalBaseUrl || '') + path + '?filtros=' + encodeURIComponent(JSON.stringify({ CODLICENCA: code })); if (host.GreenPortalPopups && host.jQuery) { var frame = host.jQuery('<iframe>', {style: 'width:100%;height:100%;border:0;display:block;' }).attr('src', url); host.GreenPortalPopups.transacao({ titulo: title, conteudo: frame }); } else if (host.greenPortalOpenTab) host.greenPortalOpenTab('licenca-' + code, url, title); else window.open(url, '_blank'); }); }
        openLicenseChild('[data-license-condicionantes]', '/principal/manutencaolegal_licencas_condicionantes.php', T('comum.popup.condicionantes', 'Condicionantes'));
        openLicenseChild('[data-license-palavras-chave]', '/principal/manutencaolegal_licencas_palavraschave.php', T('comum.popup.palavras_chave', 'Palavras-chave'));
        $fragment.on('click', '[data-license-arquivos]', function (event) { event.preventDefault(); event.stopPropagation(); var $row = $table.find('tbody tr.selected'), code = String($row.data('key') || '').trim(); if (!$row.length || !code || code.indexOf('novo_') === 0) return; var host = window.top || window, title = standardLanguage === 'en' ? 'License files' : (standardLanguage === 'es' ? 'Archivos de la licencia' : 'Arquivos da licença'), url = (host.greenPortalBaseUrl || '') + '/principal/gestao_arquivos.php?tipo=licencas&chave=CODLICENCA&valor=' + encodeURIComponent(code); if (host.GreenPortalPopups && host.jQuery) { var frame = host.jQuery('<iframe>', {style: 'width:100%;height:100%;border:0;display:block;' }).attr('src', url); host.GreenPortalPopups.transacao({ titulo: title + ' - ' + code, conteudo: frame }); } else window.open(url, '_blank'); });
        if ($fragment.find('[data-control-relacionamento="plano-acao"]').length) $fragment.find('.grid-row-actions-heading span').text(T('comum.popup.plano_acao', 'Plano de ação'));
        function updatePlanActionCounts() { $fragment.find('tbody tr').each(function () { var $row = $(this), $button = $row.find('[data-control-relacionamento="plano-acao"]'), total = String($row.find('[data-column="QTD_PLANOS_ACAO"] input').val() || '0'); if ($button.length && !$button.find('[data-plan-action-count]').length) $button.append(' <span data-plan-action-count></span>'); $button.find('[data-plan-action-count]').text(total); }); }
        $fragment.on('click', '[data-control-relacionamento="plano-acao"]', function (event) {
            event.preventDefault(); event.stopImmediatePropagation();
            var $row = $(this).closest('tr'), contextFilters = $fragment.data('context-filters') || {}, conditionCode = String($row.data('key') || '').trim(), licenseCode = String(contextFilters.CODLICENCA || '').trim();
            if (!conditionCode || conditionCode.indexOf('novo_') === 0) return;
            var host = window.top || window, title = T('comum.popup.plano_acao', 'Plano de ação'), filters = { CODLICENCA: licenseCode, CODCONDICIONANTE: conditionCode }, url = (host.greenPortalBaseUrl || '') + '/principal/manutencaolegal_licencas_condicionantesplanodeacao.php?filtros=' + encodeURIComponent(JSON.stringify(filters));
            if (host.GreenPortalPopups && host.jQuery && host.bootstrap) {
                var id = 'greenPortalPlanoAcaoModal_' + Date.now(), $modal = host.jQuery('<div class="modal fade green-portal-popup-transacao" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content" style="height:90vh"><div class="modal-header py-2"><h5 class="modal-title"></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar" title="Fechar"></button></div><div class="modal-body p-0" style="min-height:0;flex:1"></div></div></div></div>').attr('id', id).appendTo(host.document.body);
                var frame = host.jQuery('<iframe>', {style: 'width:100%;height:100%;border:0;display:block;' }).attr('src', url);
                $modal.find('.modal-title').text(title); $modal.find('.modal-body').append(frame);
                $modal.on('hidden.bs.modal', function () { $modal.remove(); });
                host.bootstrap.Modal.getOrCreateInstance($modal[0]).show();
            } else if (host.greenPortalOpenTab) host.greenPortalOpenTab('plano-acao-' + conditionCode, url, title); else window.open(url, '_blank');
        });
        $fragment.on('click', '[data-license-responsabilidades]', function (event) { event.preventDefault(); event.stopPropagation(); var $row = $table.find('tbody tr.selected'), code = String($row.data('key') || '').trim(); if (!$row.length || !code || code.indexOf('novo_') === 0) return; var host = window.top || window, title = T('comum.popup.responsabilidades', 'Responsabilidades'), url = (host.greenPortalBaseUrl || '') + '/principal/licenca_responsabilidades.php?CODLICENCA=' + encodeURIComponent(code); if (host.GreenPortalPopups && host.jQuery) { var frame = host.jQuery('<iframe>', {style: 'width:100%;height:100%;border:0;display:block;' }).attr('src', url); host.GreenPortalPopups.transacao({ titulo: title, conteudo: frame }); } else window.open(url, '_blank'); });
        $fragment.on('click', '[data-context-child]', function () {
            var $button = $(this), $row = $table.find('tbody tr.selected'), fields = String($button.data('context-fields') || '').split(',').filter(Boolean), filters = {};
            if (!$row.length) return;
            fields.forEach(function (field) { var $cell = $row.find('[data-column="' + field + '"]'), $input = $cell.find('input,select,textarea').first(); filters[field] = String($input.length ? $input.val() : $cell.text()).trim(); });
            var base = String($button.data('context-url') || ''), title = String($button.data('context-title') || 'Transação'), id = String($button.data('context-id') || 'contexto') + '-' + Object.keys(filters).map(function (key) { return filters[key]; }).join('-');
            // Os botoes de relacionamento armazenam a rota a partir de
            // /principal. Em instalacoes publicadas em subdiretorio (como
            // /greenportal), nao podemos concatenar diretamente com a raiz
            // do host, pois isso abre uma URL 404 fora da aplicacao.
            var appBase = String((window.top && window.top.greenPortalBaseUrl) || window.greenPortalBaseUrl || '');
            if (!appBase) {
                var currentPath = String((window.top && window.top.location && window.top.location.pathname) || window.location.pathname || ''), principalPos = currentPath.indexOf('/principal/');
                if (principalPos >= 0) appBase = currentPath.substring(0, principalPos);
            }
            base = appBase + (base.charAt(0) === '/' ? base : '/' + base);
            var url = base + (base.indexOf('?') >= 0 ? '&' : '?') + 'filtros=' + encodeURIComponent(JSON.stringify(filters));
            var host = window.top || window;
            if (host.GreenPortalPopups && host.jQuery) {
                var frame = host.jQuery('<iframe>', {style: 'width:100%;height:100%;border:0;display:block;' }).attr('src', new URL(url, window.location.href).toString());
                host.GreenPortalPopups.transacao({ titulo: title, conteudo: frame });
            } else if (host.greenPortalOpenTab) host.greenPortalOpenTab(id, url, title); else window.open(url, '_blank');
        });
        // Migração 317: cronograma da auditoria de homologação (aba Auditoria) — só nos tipos com "Necessita auditoria?" = Sim.
        function auditScheduleNeeded(row) {
            var cell = row ? row.querySelector('[data-column="NECESSITA_AUDITORIA"]') : null, input = cell ? cell.querySelector('input,select') : null;
            return String(input ? input.value : (cell ? cell.textContent : '')).trim().toLowerCase() === 'sim';
        }
        function syncAuditSchedule() {
            $fragment.find('[data-control-relacionamento="cronograma_auditoria"]').each(function () {
                var wrap = this.closest('.grid-virtual-action') || this;
                wrap.style.visibility = auditScheduleNeeded(this.closest('tr')) ? '' : 'hidden';
            });
        }
        if ($fragment.find('[data-control-relacionamento="cronograma_auditoria"]').length || $fragment.find('th[data-column="CRONOGRAMA"]').length) {
            syncAuditSchedule();
            if (window.MutationObserver && $fragment[0]) new MutationObserver(function () { syncAuditSchedule(); }).observe($fragment[0], { childList: true, subtree: true });
        }
        $fragment.on('click', '[data-control-relacionamento="cronograma_auditoria"]', function (event) {
            event.preventDefault(); event.stopImmediatePropagation();
            var $row = $(this).closest('tr'), id = String($row.data('key') || '').trim();
            if (!id || id.indexOf('novo_') === 0 || !auditScheduleNeeded($row[0])) return;
            var host = window.parent && window.parent.GreenPortalPopups ? window.parent : (window.top || window),
                title = (window.greenPortalIdioma === 'en' ? 'Audit schedule' : (window.greenPortalIdioma === 'es' ? 'Cronograma de la auditoría' : 'Cronograma da auditoria')) + ' - ' + id,
                url = (host.greenPortalBaseUrl || '') + '/principal/eventos.php?codauditoria=' + encodeURIComponent(id);
            if (host.GreenPortalPopups && host.jQuery) {
                var frame = host.jQuery('<iframe>', { title: title, style: 'width:100%;height:100%;border:0;display:block;' }).attr('src', new URL(url, window.location.href).toString());
                host.GreenPortalPopups.transacao({ titulo: title, conteudo: frame });
            } else if (host.greenPortalOpenTab) host.greenPortalOpenTab('cronograma-auditoria-' + id, url, title); else window.open(url, '_blank');
        });
        $fragment.on('click', '[data-control-relacionamento="cronograma"]', function (event) {
            event.preventDefault(); event.stopImmediatePropagation();
            var $row = $(this).closest('tr'), id = String($row.data('key') || '').trim();
            if (!id || id.indexOf('novo_') === 0) return;
            var unitCell = $row.find('[data-column="CODUNIDADE"]'), unitInput = unitCell.find('input,select').first();
            var unit = String(unitInput.length ? unitInput.val() : unitCell.text()).trim();
            var host = window.parent && window.parent.GreenPortalPopups ? window.parent : (window.top || window),
                title = (window.greenPortalIdioma === 'en' ? 'Schedule' : (window.greenPortalIdioma === 'es' ? 'Cronograma' : 'Cronograma')) + ' - ' + id,
                url = (host.greenPortalBaseUrl || '') + '/principal/eventos.php?codmonitoramento=' + encodeURIComponent(id) + '&codunidade=' + encodeURIComponent(unit);
            if (host.GreenPortalPopups && host.jQuery) {
                var frame = host.jQuery('<iframe>', { title: title, style: 'width:100%;height:100%;border:0;display:block;' }).attr('src', new URL(url, window.location.href).toString());
                host.GreenPortalPopups.transacao({ titulo: title, conteudo: frame });
            } else if (host.greenPortalOpenTab) host.greenPortalOpenTab('cronograma-' + id, url, title); else window.open(url, '_blank');
        });
        function installLegislationIcons() {
            if (String(cfg.primary || '') !== 'CODLEGISLACAO' || !$table.find('th[data-column="CODIGO"]').length) return;
            $table.find('td[data-column="CODIGO"]').each(function () { var $cell = $(this); if (!$cell.find('[data-open-legislation-text]').length) { var language = window.greenPortalIdioma || 'pt', label = language === 'en' ? 'Full text of the standard' : (language === 'es' ? 'Texto íntegro de la norma' : 'Integra da norma'); $cell.prepend('<button type="button" class="btn btn-link btn-sm p-0 me-1 legislation-code-icon" data-open-legislation-text data-bs-toggle="tooltip" data-bs-placement="top" title="' + label + '" aria-label="' + label + '"><i class="bi bi-file-earmark-pdf"></i></button>'); } });
            $table.find('[data-open-legislation-text]').each(function () { if (window.bootstrap && bootstrap.Tooltip && !bootstrap.Tooltip.getInstance(this)) bootstrap.Tooltip.getOrCreateInstance(this); });
        }
        $fragment.on('click', '[data-open-legislation-text]', function (event) { event.preventDefault(); event.stopPropagation(); var $row = $(this).closest('tr'), code = String($row.find('[data-column="CODIGO"] input,[data-column="CODIGO"] select').val() || $row.find('[data-column="CODIGO"]').clone().children().remove().end().text() || '').trim(); if (!code) return; var host = window.top || window, language = window.greenPortalIdioma || 'pt', title = language === 'en' ? 'Full text of the standard' : (language === 'es' ? 'Texto íntegro de la norma' : 'Integra da norma'), url = (host.greenPortalBaseUrl || '') + '/principal/legislacao_pdf.php?codigo=' + encodeURIComponent(code); if (host.GreenPortalPopups && host.jQuery) { var frame = host.jQuery('<iframe>', {style: 'width:100%;height:100%;border:0;display:block;' }).attr('src', url); host.GreenPortalPopups.transacao({ titulo: title, conteudo: frame }); } else window.open(url, '_blank'); });
        $fragment.on('click', '[data-columns]', function () { var body = '<div class="row g-2">'; cols.forEach(function (col) { var checked = $table.find('[data-column="' + col + '"]:first').is(':visible') ? ' checked' : ''; body += '<div class="col-md-6"><label class="form-check"><input class="form-check-input" type="checkbox" data-column-toggle="' + col + '"' + checked + '> <span class="form-check-label">' + $table.find('th[data-column="' + col + '"] span').text() + '</span></label></div>'; }); body += '</div>'; var id = 'cadastroColumnsModal'; $('#' + id).remove(); $('body').append('<div class="modal fade" id="' + id + '" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">' + TH('comum.colunas.titulo', 'Colunas do grid') + '</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body">' + body + '</div><div class="modal-footer"><button type="button" class="btn btn-sm btn-success" data-save-cadastro-columns>' + TH('comum.botao.salvar', 'Salvar') + '</button></div></div></div></div>'); bootstrap.Modal.getOrCreateInstance(document.getElementById(id)).show(); });
        $(document).on('click', '[data-save-cadastro-columns]', function () { $('[data-column-toggle]').each(function () { prefs[$(this).data('column-toggle')] = prefs[$(this).data('column-toggle')] || {}; prefs[$(this).data('column-toggle')].visivel = this.checked ? 1 : 0; }); apply(); savePrefs(); bootstrap.Modal.getInstance(document.getElementById('cadastroColumnsModal')).hide(); });
        $table.find('th').each(function () { var $head = $(this); $head.attr('draggable', true).append('<span class="column-resizer"></span>').on('dragstart', function (event) { event.originalEvent.dataTransfer.setData('text/plain', String($head.data('column'))); }).on('dragover', function (event) { event.preventDefault(); }).on('drop', function (event) { event.preventDefault(); var from = event.originalEvent.dataTransfer.getData('text/plain'), to = String($head.data('column')), i = cols.indexOf(from), j = cols.indexOf(to); if (i < 0 || j < 0) return; cols.splice(i, 1); cols.splice(j, 0, from); cols.forEach(function (col, n) { prefs[col] = prefs[col] || {}; prefs[col].ordem = n; }); apply(); savePrefs(); }); });
        $table.on('pointerdown', '.column-resizer', function (event) { event.preventDefault(); var $head = $(this).closest('th'), start = event.clientX, width = $head.outerWidth(), col = String($head.data('column')); function move(e) { $table.find('[data-column="' + col + '"]').css('width', Math.max(60, width + e.clientX - start) + 'px'); } function stop() { $(document).off('pointermove', move).off('pointerup', stop); prefs[col] = prefs[col] || {}; prefs[col].largura = $head.outerWidth(); savePrefs(); } $(document).on('pointermove', move).on('pointerup', stop); });
        // Todos os botões da grade devem exibir uma orientação visual uniforme.
        // O escopo fica restrito ao fragmento da transação para não criar
        // tooltips em abas ou elementos flutuantes externos.
        function instalarTooltipsBotoes() { $fragment.find('button[title], [role="button"][title]').each(function () { var button = this, $button = $(button), $host = $button; if ($button.is(':disabled')) { if (!$button.parent().is('[data-disabled-tooltip-wrapper]')) { $button.wrap($('<span>', { 'data-disabled-tooltip-wrapper': '', class: 'd-inline-flex align-items-center' }).css({ 'vertical-align': 'middle' })); } $host = $button.parent(); } $host.attr('data-bs-toggle', 'tooltip').attr('data-bs-placement', 'top').attr('title', $button.attr('title') || $host.attr('title') || ''); if (window.bootstrap && bootstrap.Tooltip && !bootstrap.Tooltip.getInstance($host[0])) bootstrap.Tooltip.getOrCreateInstance($host[0]); }); }
        instalarTooltipsBotoes();
        if (window.MutationObserver && $fragment[0]) new MutationObserver(instalarTooltipsBotoes).observe($fragment[0], { childList: true, subtree: true });
        $fragment.find('input[type="date"]').each(function () { var $input = $(this); if ($input.data('calendar-ready')) return; $input.data('calendar-ready', true); var $button = $('<button>', { type: 'button', class: 'btn btn-link btn-sm p-0 calendar-picker', title: T('comum.calendario.abrir', 'Abrir calendário'), 'aria-label': T('comum.calendario.abrir', 'Abrir calendário') }).css({ width: '22px', 'min-width': '22px', 'font-size': '0.72rem', 'line-height': '1' }).append($('<i>', { class: 'bi bi-calendar3' })); $input.wrap('<div class="input-group input-group-sm flex-nowrap"></div>').parent().append($button); $button.on('click', function () { try { if (typeof $input[0].showPicker === 'function') $input[0].showPicker(); else $input.trigger('focus'); } catch (error) { $input.trigger('focus'); } }); });
        function enforceHiddenFields() { Object.keys(cfg.fields || {}).forEach(function (field) { if ((cfg.fields[field] || {}).hidden) $table.find('[data-column="' + field + '"]').hide(); }); }
        if (cfg.primary_editable) {
            var originalRowHtml = rowHtml;
            rowHtml = function (key, source) {
                var originalPrimary = primary;
                primary = '__primary_editable__';
                var html = originalRowHtml(key, source);
                primary = originalPrimary;
                return html.replace(/data-column="__primary_editable__"/g, 'data-column="' + originalPrimary + '"');
            };
        }
        function alignRowActions() {
            var $template = $table.find('tbody tr .grid-row-actions').first().closest('td');
            if (!$table.find('thead .grid-row-actions-heading').length) return;
            $table.find('tbody tr').each(function () {
                var $row = $(this);
                if (!$row.find('.grid-row-actions').length) $row.append($template.length ? $template.clone(true, true) : $('<td class="grid-row-actions" data-grid-row-actions></td>'));
            });
        }
        function moveRowActionsLast() {
            var $heading = $table.find('thead .grid-row-actions-heading');
            if (!$heading.length) return;
            $table.find('thead tr').append($heading);
            $table.find('tbody tr').each(function () { var $actions = $(this).find('.grid-row-actions').closest('td'); if ($actions.length) $(this).append($actions); });
        }
        $fragment.on('click', '[data-new-cadastro],[data-duplicate-cadastro]', function () { window.setTimeout(function () { enforceHiddenFields(); moveRowActionsLast(); alignRowActions(); updatePlanActionCounts(); }, 0); });
        // Comboboxes das linhas pertencem à edição do registro. Eles não
        // devem propagar seus eventos para filtros, paginação ou ações que
        // possam reconstruir o grid ao clicar ou alterar uma opção.
        $table.on('mousedown click change', 'tbody select.grid-input', function (event) {
            if (event.type === 'click') {
                var $row = $(this).closest('tr');
                $table.find('tbody tr').removeClass('selected');
                $row.addClass('selected');
                $fragment.find('[data-delete-cadastro],[data-duplicate-cadastro],[data-open-nc-pdf]').filter(':not([data-permission-locked="1"])').prop('disabled', false);
                $fragment.find('[data-context-child]').each(function () { $(this).prop('disabled', false).removeAttr('aria-disabled'); });
            }
            event.stopPropagation();
        });
        installDatePickers = function () { $fragment.find('input[type="date"], input[data-calendar-input="1"], input[name*="[DATA_"], input[name*="[PROTOCOLADO_EM]"]').each(function () { var $input = $(this); if ($input.data('calendar-ready') || $input.is('[data-somente-leitura]')) return; var value = String($input.val() || ''), match = value.match(/^(\d{2})\/(\d{2})\/(\d{4})$/); if (match) $input.val(match[3] + '-' + match[2] + '-' + match[1]); $input.prop('readonly', false).attr('type', 'date').attr('data-calendar-input', '1').attr('lang', window.greenPortalIdioma === 'en' ? 'en-US' : (window.greenPortalIdioma === 'es' ? 'es-ES' : 'pt-BR')).data('calendar-ready', true); var $button = $('<button>', { type: 'button', class: 'btn btn-link btn-sm p-0 calendar-picker', title: T('comum.calendario.abrir', 'Abrir calendário'), 'aria-label': T('comum.calendario.abrir', 'Abrir calendário') }).css({ width: '22px', 'min-width': '22px', 'font-size': '0.72rem', 'line-height': '1' }).append($('<i>', { class: 'bi bi-calendar3' })); $input.wrap('<div class="input-group input-group-sm flex-nowrap"></div>').parent().append($button); $button.on('click', function () { var element = $input[0]; if (!element) return; try { if (typeof element.showPicker === 'function') element.showPicker(); else element.focus(); } catch (error) { element.focus(); } }); }); };
        // Migração 332: registros do sistema anterior (chave abaixo de legacy_below) só consulta, menos as colunas de legacy_editable (ex.: Ativo)
        function protegerLegado() {
            var limite = Number(cfg.legacy_below || 0); if (!limite) return;
            var livres = (cfg.legacy_editable || []).map(String);
            $table.find('tbody tr[data-key]').each(function () {
                var key = String($(this).attr('data-key') || ''); if (!/^\d+$/.test(key) || Number(key) >= limite) return;
                $(this).addClass('linha-legado').find('td[data-column]').each(function () {
                    if (livres.indexOf(String($(this).attr('data-column'))) >= 0) return;
                    $(this).find('input, select, textarea').prop('readonly', true).filter('select').prop('disabled', true);
                    $(this).attr('title', 'Registro do sistema anterior: altere-o lá até a virada');
                });
            });
        }
        apply(); moveRowActionsLast(); enforceHiddenFields(); installDatePickers(); installLegislationIcons(); installInlineEditors(); alignRowActions(); updatePlanActionCounts(); protegerLegado();
        $fragment.on('gridPageLoaded', function () { apply(); moveRowActionsLast(); enforceHiddenFields(); installDatePickers(); installLegislationIcons(); installInlineEditors(); alignRowActions(); updatePlanActionCounts(); protegerLegado(); });
    });
        $(document).off('click.greenPortalDateOpen', '.calendar-picker').on('click.greenPortalDateOpen', '.calendar-picker', function () { window.greenPortalDateInput = $(this).closest('.input-group').find('input[data-calendar-input="1"]').first()[0] || null; });
        $(document).off('click.greenPortalDatePicker', '.green-portal-date-picker button[data-day]').on('click.greenPortalDatePicker', '.green-portal-date-picker button[data-day]', function (event) { event.preventDefault(); event.stopPropagation(); var input = window.greenPortalDateInput, popup = $(this).closest('.green-portal-date-picker'), selected = Number($(this).data('day')), header = String(popup.find('strong').text() || '').split('/'), month = Number(header[0]), year = Number(header[1]); if (!input || !selected || !month || !year) return; var language = window.greenPortalIdioma || 'pt'; input.value = language === 'en' ? String(month).padStart(2, '0') + '/' + String(selected).padStart(2, '0') + '/' + year : String(selected).padStart(2, '0') + '/' + String(month).padStart(2, '0') + '/' + year; popup.remove(); $(input).trigger('input').trigger('change'); });
        // Datas são editadas como texto para não depender da localidade do navegador.
        if (!document.getElementById('greenPortalDateMaskStyle')) $('<style id="greenPortalDateMaskStyle">.calendar-picker{display:inline-block!important}</style>').appendTo('head');
        $('.calendar-picker').show();
        $(document).off('input.greenPortalDateMask', 'input[data-calendar-input="1"]').on('input.greenPortalDateMask', 'input[data-calendar-input="1"]', function () { var input = this, language = window.greenPortalIdioma || 'pt', digits = String(input.value || '').replace(/\D/g, '').slice(0, 8), parts = []; if (digits.length > 2) parts.push(digits.slice(0, 2)); if (digits.length > 4) parts.push(digits.slice(2, 4)); if (digits.length > 4) parts.push(digits.slice(4)); else if (digits.length > 2) parts.push(digits.slice(2)); input.value = parts.join('/'); input.maxLength = 10; input.inputMode = 'numeric'; input.placeholder = language === 'en' ? 'mm/dd/yyyy' : 'dd/mm/yyyy'; });
        $(document).off('click.greenPortalNativeBridge', '.calendar-picker').on('click.greenPortalNativeBridge', '.calendar-picker', function (event) { event.preventDefault(); event.stopImmediatePropagation(); var $input = $(this).closest('.input-group').find('input[data-calendar-input="1"]').first(), language = window.greenPortalIdioma || 'pt', value = String($input.val() || ''), parts = value.split('/'), day = language === 'en' ? parts[1] : parts[0], month = language === 'en' ? parts[0] : parts[1], year = parts[2], native = $('<input type="date" aria-hidden="true">').css({ position: 'fixed', opacity: 0, width: 1, height: 1, left: 0, top: 0, pointerEvents: 'none' }).attr('lang', language === 'en' ? 'en-US' : (language === 'es' ? 'es-ES' : 'pt-BR')).appendTo('body'); if (/^\d{2}$/.test(day || '') && /^\d{2}$/.test(month || '') && /^\d{4}$/.test(year || '')) native.val(year + '-' + month + '-' + day); native.on('change', function () { var selected = String(native.val() || '').match(/^(\d{4})-(\d{2})-(\d{2})$/); if (selected) $input.val(language === 'en' ? selected[2] + '/' + selected[3] + '/' + selected[1] : selected[3] + '/' + selected[2] + '/' + selected[1]).trigger('input').trigger('change'); native.remove(); }); native.on('blur', function () { window.setTimeout(function () { native.remove(); }, 300); }); try { native[0].showPicker(); } catch (error) { native.trigger('focus'); } });
        // O calendario oficial e o input nativo type=date sao a unica interface de datas.
        $(document).off('click.greenPortalDateOpen', '.calendar-picker');
        $(document).off('click.greenPortalDatePicker', '.green-portal-date-picker button[data-day]');
        $(document).off('input.greenPortalDateMask', 'input[data-calendar-input="1"]');
        $(document).off('click.greenPortalNativeBridge', '.calendar-picker');
}(jQuery));

(function ($) {
    'use strict';
    $(function () {
        $('.cadastro-legal-fragment').each(function () {
            var $fragment = $(this), configuration;
            try { configuration = JSON.parse($fragment.attr('data-cadastro-config') || '{}'); } catch (error) { configuration = {}; }
            if (configuration.transaction !== 'manutencaolegalrequisitoslegais_controles') return;
            $fragment.on('click.associationReadOnly', '[data-new-cadastro]', function () {
                window.setTimeout(function () {
                    var primary = String(configuration.primary || 'CODDOCUMENTO'), $row = $fragment.find('tbody tr[data-key^="novo_"]').first();
                    $row.find('[data-column]').each(function () {
                        if (String($(this).data('column') || '') === primary) return;
                        $(this).find('input,textarea').prop('readonly', true).attr('aria-readonly', 'true');
                        $(this).find('select').prop('disabled', true).attr('aria-disabled', 'true');
                    });
                }, 30);
            });
        });
    });
}(jQuery));
