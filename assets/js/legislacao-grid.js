(function ($) {
    'use strict';
    var $fragment = $('.legislacao-grid-fragment'), $table = $('#legislacaoBatchGrid'), $form = $('#legislacaoGridForm');
    if (!$table.length) return;
    var preferences = $fragment.data('preferences') || {}, columns = $table.find('thead th').map(function () { return String($(this).data('column')); }).get();
    function installRclButtons() {
        var $pagination = $fragment.find('.grid-pagination').first();
        if (!$pagination.length || $fragment.find('[data-rcl-completa]').length) return;
        $pagination.append('<button type="button" class="btn btn-sm btn-outline-success grid-special-action" data-rcl-completa title="RCL Completa" aria-label="RCL Completa"><i class="bi bi-file-earmark-text"></i><span>RCL Completa</span></button>');
        $pagination.append('<button type="button" class="btn btn-sm btn-outline-success grid-special-action" data-rcl-padrao title="RCL Padrão" aria-label="RCL Padrão"><i class="bi bi-file-earmark"></i><span>RCL Padrão</span></button>');
    }
    function installAdvancedFilters() {
        if ($fragment.find('.legislacao-advanced-filters').length) return;
        var options = window.legislacaoGridOptions || {}, language = String(window.greenPortalIdioma || 'pt').toLowerCase();
        var labels = language === 'en' ? { company: 'Company', unit: 'Unit', period: 'Update month/year', pattern: 'Pattern' } : language === 'es' ? { company: 'Empresa', unit: 'Unidad', period: 'Mes/año de actualización', pattern: 'Patrón' } : { company: 'Empresa', unit: 'Unidades', period: 'Mês/Ano de atualização', pattern: 'Padrão' };
        var current = options.filtros || {};
        function select(name, label, values, disabled) {
            var html = '<div class="legislacao-filter-field"><label class="form-label" for="legislacaoFilter' + name + '">' + label + '</label><select class="form-select form-select-sm" id="legislacaoFilter' + name + '" data-grid-filter="' + name + '"' + (disabled ? ' disabled' : '') + '><option value="">' + (disabled ? 'Selecione uma empresa' : 'Todos') + '</option>';
            Object.keys(values || {}).forEach(function (key) { html += '<option value="' + $('<div>').text(key).html() + '">' + $('<div>').text(values[key]).html() + '</option>'; });
            return html + '</select></div>';
        }
        var selectedCompany = String(current.CODEMPRESA || ''), selectedUnit = String(current.CODUNIDADE || '');
        var initialUnits = selectedCompany ? (options.empresaUnidades || {})[selectedCompany] || {} : {};
        var html = '<div class="legislacao-advanced-filters">' + select('CODEMPRESA', labels.company, options.empresas || {}) + select('CODUNIDADE', labels.unit, initialUnits, !selectedCompany) + select('MES_ANO_ATUALIZACAO', labels.period, options.periodos || {}) + '<div class="legislacao-filter-field"><label class="form-label" for="legislacaoFilterPadrao">' + labels.pattern + '</label><input type="search" class="form-control form-control-sm" id="legislacaoFilterPadrao" data-grid-filter="PADRAO" placeholder="' + labels.pattern + '"></div></div>';
        $fragment.find('.crud-toolbar').after(html);
        $fragment.find('[data-grid-filter]').each(function () { $(this).val(current[String($(this).data('grid-filter'))] || ''); });
        function refreshUnits(companyValue, selectedValue) {
            var values = companyValue ? (options.empresaUnidades || {})[companyValue] || {} : {}, $unit = $fragment.find('#legislacaoFilterCODUNIDADE');
            $unit.empty().append($('<option>').val('').text(companyValue ? 'Todos' : 'Selecione uma empresa')).prop('disabled', !companyValue);
            Object.keys(values).forEach(function (key) { $('<option>').val(key).text(values[key]).appendTo($unit); });
            $unit.val(selectedValue || '');
        }
        $fragment.on('change', '#legislacaoFilterCODEMPRESA', function () { refreshUnits(String($(this).val() || ''), ''); });
    }
    function installContextFilters() {
        var options = window.legislacaoGridOptions || {};
        installAdvancedFilters();
        ['CODAMBITO', 'CODTIPO', 'MEIO_AMBIENTE', 'SEGURANCA', 'QUALIDADE', 'RESPOSABILIDADE', 'situacao_da_lei', 'devido_da_lei'].forEach(function (column) {
            var values = contextOptions(column);
            var $filter = $table.find('[data-column-filter="' + column + '"]');
            if (!$filter.length || $filter.is('select')) return;
            var selectedValue = $filter.val() || '';
            var $select = $('<select class="column-filter form-select form-select-sm" data-column-filter="' + column + '"></select>');
            $('<option value="">Todos</option>').appendTo($select);
            Object.keys(values).forEach(function (value) { $('<option>').val(value).text(values[value]).appendTo($select); });
            $select.val(selectedValue);
            $filter.replaceWith($select);
        });
        installContextFields();
        installPatternFields();
        installAssuntosFields();
    }
    function contextOptions(column) {
        var options = window.legislacaoGridOptions || {};
        if (column === 'CODAMBITO') return options.ambitos || {};
        if (column === 'CODTIPO') return options.tipos || {};
        var language = window.greenPortalIdioma || 'pt';
        if (['MEIO_AMBIENTE', 'SEGURANCA', 'QUALIDADE', 'RESPOSABILIDADE'].indexOf(column) >= 0) return language === 'en' ? { 'Sim': 'Yes', 'N\u00e3o': 'No' } : language === 'es' ? { 'Sim': 'S\u00ed', 'N\u00e3o': 'No' } : { 'Sim': 'Sim', 'N\u00e3o': 'N\u00e3o' };
        if (column === 'situacao_da_lei') return language === 'en' ? { 'Lei Alterada': 'Amended Law', 'Lei Inclusa': 'Included Law', 'Lei Revogada': 'Repealed Law' } : language === 'es' ? { 'Lei Alterada': 'Ley modificada', 'Lei Inclusa': 'Ley incluida', 'Lei Revogada': 'Ley derogada' } : { 'Lei Alterada': 'Lei Alterada', 'Lei Inclusa': 'Lei Inclusa', 'Lei Revogada': 'Lei Revogada' };
        if (column === 'devido_da_lei') return language === 'en' ? { 'Alterada pela Atualiza\u00e7\u00e3o': 'Changed by Update', 'Alterada pelo Ajuste': 'Changed by Adjustment', 'Alterado pela Atualiza\u00e7\u00e3o': 'Changed by Update', 'Alterado pelo Ajuste': 'Changed by Adjustment', 'Devido ao Ajuste': 'Due to Adjustment', 'Devido \u00e0 Atualiza\u00e7\u00e3o': 'Due to Update', 'Retirado pela Atualiza\u00e7\u00e3o': 'Removed by Update' } : language === 'es' ? { 'Alterada pela Atualiza\u00e7\u00e3o': 'Modificada por actualizaci\u00f3n', 'Alterada pelo Ajuste': 'Modificada por ajuste', 'Alterado pela Atualiza\u00e7\u00e3o': 'Modificado por actualizaci\u00f3n', 'Alterado pelo Ajuste': 'Modificado por ajuste', 'Devido ao Ajuste': 'Debido al ajuste', 'Devido \u00e0 Atualiza\u00e7\u00e3o': 'Debido a la actualizaci\u00f3n', 'Retirado pela Atualiza\u00e7\u00e3o': 'Retirado por actualizaci\u00f3n' } : { 'Alterada pela Atualiza\u00e7\u00e3o': 'Alterada pela Atualiza\u00e7\u00e3o', 'Alterada pelo Ajuste': 'Alterada pelo Ajuste', 'Alterado pela Atualiza\u00e7\u00e3o': 'Alterado pela Atualiza\u00e7\u00e3o', 'Alterado pelo Ajuste': 'Alterado pelo Ajuste', 'Devido ao Ajuste': 'Devido ao Ajuste', 'Devido \u00e0 Atualiza\u00e7\u00e3o': 'Devido \u00e0 Atualiza\u00e7\u00e3o', 'Retirado pela Atualiza\u00e7\u00e3o': 'Retirado pela Atualiza\u00e7\u00e3o' };
        if (['MEIO_AMBIENTE', 'SEGURANCA', 'QUALIDADE', 'RESPOSABILIDADE'].indexOf(column) >= 0) return language === 'en' ? { 'Sim': 'Yes', 'Não': 'No' } : language === 'es' ? { 'Sim': 'Sí', 'Não': 'No' } : { 'Sim': 'Sim', 'Não': 'Não' };
        if (column === 'situacao_da_lei') return language === 'en' ? { 'Lei Alterada': 'Amended Law', 'Lei Inclusa': 'Included Law', 'Lei Revogada': 'Repealed Law' } : language === 'es' ? { 'Lei Alterada': 'Ley modificada', 'Lei Inclusa': 'Ley incluida', 'Lei Revogada': 'Ley derogada' } : { 'Lei Alterada': 'Lei Alterada', 'Lei Inclusa': 'Lei Inclusa', 'Lei Revogada': 'Lei Revogada' };
        if (column === 'devido_da_lei') return language === 'en' ? { 'Alterada pela Atualização': 'Changed by Update', 'Alterada pelo Ajuste': 'Changed by Adjustment', 'Alterado pela Atualização': 'Changed by Update', 'Alterado pelo Ajuste': 'Changed by Adjustment', 'Devido ao Ajuste': 'Due to Adjustment', 'Devido à Atualização': 'Due to Update', 'Retirado pela Atualização': 'Removed by Update' } : language === 'es' ? { 'Alterada pela Atualização': 'Modificada por actualización', 'Alterada pelo Ajuste': 'Modificada por ajuste', 'Alterado pela Atualização': 'Modificado por actualización', 'Alterado pelo Ajuste': 'Modificado por ajuste', 'Devido ao Ajuste': 'Debido al ajuste', 'Devido à Atualização': 'Debido a la actualización', 'Retirado pela Atualização': 'Retirado por actualización' } : { 'Alterada pela Atualização': 'Alterada pela Atualização', 'Alterada pelo Ajuste': 'Alterada pelo Ajuste', 'Alterado pela Atualização': 'Alterado pela Atualização', 'Alterado pelo Ajuste': 'Alterado pelo Ajuste', 'Devido ao Ajuste': 'Devido ao Ajuste', 'Devido à Atualização': 'Devido à Atualização', 'Retirado pela Atualização': 'Retirado pela Atualização' };
        return {};
    }
    // Listas de edição das linhas. Tipo e Âmbito têm catálogos grandes (≈1.900 tipos): a linha leva só o valor atual e as
    // opções entram quando o usuário abre a lista (antes eram ~50 mil <option> na página e 13 s para abrir).
    var LAZY_COLUMNS = { CODAMBITO: true, CODTIPO: true }, sortedOptionsCache = {};
    function escHtml(text) { return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    function sortedOptions(column) {
        if (sortedOptionsCache[column]) return sortedOptionsCache[column];
        var options = contextOptions(column), collator = new Intl.Collator(window.greenPortalIdioma === 'en' ? 'en-US' : (window.greenPortalIdioma === 'es' ? 'es-ES' : 'pt-BR'), { sensitivity: 'base', numeric: true });
        var list = Object.keys(options).map(function (optionValue) { return { value: optionValue, label: String(options[optionValue] || '') }; })
            .sort(function (left, right) { return collator.compare(left.label, right.label); });
        list.html = list.map(function (option) { return '<option value="' + escHtml(option.value) + '">' + escHtml(option.label) + '</option>'; }).join('');
        return (sortedOptionsCache[column] = list);
    }
    function unknownOption(column, text) {
        // valor gravado que não está no catálogo (ex.: âmbito apagado): mantém, para a gravação não apagar o valor da lei
        return text !== '' && !Object.prototype.hasOwnProperty.call(contextOptions(column), text) ? '<option value="' + escHtml(text) + '">' + escHtml(text + ' (não cadastrado)') + '</option>' : '';
    }
    function contextSelectHtml(column, key, value) {
        var text = String(value === null || value === undefined ? '' : value), options = contextOptions(column);
        var html = '<select class="grid-input" name="rows[' + key + '][' + column + ']"' + (LAZY_COLUMNS[column] ? ' data-lazy-options="' + column + '"' : '') + '><option value="">Selecione</option>' + unknownOption(column, text);
        if (LAZY_COLUMNS[column]) {
            if (text !== '' && Object.prototype.hasOwnProperty.call(options, text)) html += '<option value="' + escHtml(text) + '">' + escHtml(options[text]) + '</option>';
        } else html += sortedOptions(column).html;
        html += '</select>';
        return text === '' ? html : html.replace('<option value="' + escHtml(text) + '">', '<option value="' + escHtml(text) + '" selected>');
    }
    function fillLazySelect(select) {
        var column = select.getAttribute('data-lazy-options');
        if (!column) return;
        var current = select.value;
        select.removeAttribute('data-lazy-options');
        select.innerHTML = '<option value="">Selecione</option>' + unknownOption(column, current) + sortedOptions(column).html;
        select.value = current;
    }
    $(document).on('mousedown focusin keydown touchstart', 'select[data-lazy-options]', function () { fillLazySelect(this); });
    function dateTimeLocal(value) {
        var text = String(value || '').trim().replace(' ', 'T');
        if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(text)) return text.substring(0, 16);
        if (/^\d{4}-\d{2}-\d{2}$/.test(text)) return text + 'T00:00';
        return '';
    }
    function installContextFields() {
        ['CODAMBITO', 'CODTIPO', 'MEIO_AMBIENTE', 'SEGURANCA', 'QUALIDADE', 'RESPOSABILIDADE', 'situacao_da_lei', 'devido_da_lei'].forEach(function (column) {
            $table.find('tbody td[data-column="' + column + '"]').each(function () {
                var $field = $(this).find('input,select').first();
                if (!$field.length || $field.is('select')) return;
                var value = $field.val() || '', key = String($field.attr('name') || '').match(/^rows\[([^\]]+)\]/);
                $(this).html(contextSelectHtml(column, key ? key[1] : '', value));
            });
        });
        $table.find('tbody td[data-column="data_atualizacao"]').each(function () {
            var $field = $(this).find('input').first();
            if (!$field.length || $field.attr('type') === 'datetime-local') return;
            var key = String($field.attr('name') || '').match(/^rows\[([^\]]+)\]/);
            $(this).html('<input class="grid-input" type="datetime-local" name="rows[' + (key ? key[1] : '') + '][data_atualizacao]" value="' + dateTimeLocal($field.val()) + '">');
        });
    }
    function patternCellHtml(key, value) {
        var escaped = $('<div>').text(value || '').html();
        return '<div class="pattern-cell-editor"><input class="grid-input pattern-readonly" value="' + escaped + '" readonly title="' + escaped + '"><textarea class="pattern-value" name="rows[' + key + '][PADRAO]" hidden>' + escaped + '</textarea><button type="button" class="btn btn-sm btn-outline-secondary pattern-edit-button" data-edit-pattern aria-label="Editar padrão" title="Editar padrão"><i class="bi bi-pencil-square"></i></button></div>';
    }
    function setPatternValue($row, value) {
        var text = String(value || '');
        $row.find('td[data-column="PADRAO"] input').first().data('pattern-value', text).val(text).attr('title', text);
        $row.find('td[data-column="PADRAO"] textarea.pattern-value').val(text);
    }
    function installPatternFields() {
        $table.find('tbody td[data-column="PADRAO"]').each(function () {
            var $input = $(this).find('input').first();
            if (!$input.length || $(this).find('[data-edit-pattern]').length) return;
            var key = String($input.attr('name') || '').match(/^rows\[([^\]]+)\]/);
            var rowKey = key ? key[1] : String($(this).closest('tr').data('key') || '');
            var sourceValue = Object.prototype.hasOwnProperty.call(window.legislacaoPatternValues || {}, rowKey) ? window.legislacaoPatternValues[rowKey] : ($input.val() || '');
            $(this).html(patternCellHtml(rowKey, sourceValue));
            setPatternValue($(this).closest('tr'), sourceValue);
        });
    }
    function assuntosCellHtml(value) {
        var escaped = $('<div>').text(value || '').html();
        return '<div class="assuntos-cell-editor"><input class="grid-input assuntos-readonly" value="' + escaped + '" readonly title="' + escaped + '"><button type="button" class="btn btn-sm btn-outline-secondary assuntos-edit-button" data-edit-assuntos aria-label="Editar assuntos" title="Editar assuntos"><i class="bi bi-pencil-square"></i></button></div>';
    }
    function installAssuntosFields() {
        $table.find('tbody td[data-column="ASSUNTOS"]').each(function () {
            if ($(this).find('[data-edit-assuntos]').length) return;
            var $input = $(this).find('input').first();
            $(this).html(assuntosCellHtml($input.val() || ''));
        });
    }
    function renderAssuntosList($modal, items) {
        items.sort(function (left, right) { if (left.associado !== right.associado) return left.associado ? -1 : 1; return String(left.descricao || '').localeCompare(String(right.descricao || ''), 'pt-BR', { sensitivity: 'base' }); });
        var html = items.length ? items.map(function (item) { return '<label class="assunto-option"><input type="checkbox" value="' + item.codigo + '"' + (item.associado ? ' checked' : '') + '> <span>' + $('<div>').text(item.descricao).html() + '</span></label>'; }).join('') : '<div class="text-muted small">Nenhum assunto encontrado.</div>';
        $modal.find('[data-assuntos-list]').html(html);
    }
    function loadAssuntos($modal, code) {
        $modal.find('[data-assuntos-list]').html('<div class="text-muted small">Carregando assuntos...</div>');
        $.post(window.location.href, { action: 'load_assuntos', CODLEGISLACAO: code, busca: $modal.find('[data-assuntos-search]').val() || '', ajax: 1 }, function (response) { if (response && response.ok) renderAssuntosList($modal, response.assuntos || []); else $modal.find('[data-assuntos-list]').text('Não foi possível carregar os assuntos.'); }, 'json').fail(function () { $modal.find('[data-assuntos-list]').text('Não foi possível carregar os assuntos.'); });
    }
    function openAssuntosEditor($row) {
        var code = String($row.data('key') || '').trim();
        if (!/^\d+$/.test(code)) return;
        var host = window.parent && window.parent.GreenPortalPopups ? window.parent : (window.top || window), popup = host.GreenPortalPopups;
        if (!popup || typeof popup.transacaoIndependente !== 'function' || !host.jQuery) { if (window.console) console.error('GreenPortalPopups.transacaoIndependente indisponível para Assuntos.'); return; }
        var lawCode = String($row.find('[data-column="CODIGO"] input').val() || code).trim();
        var url = new URL('manutencaolegal_legislacao_assuntos.php?CODLEGISLACAO=' + encodeURIComponent(code), window.location.href).toString();
        var frame = host.jQuery('<iframe>', { style: 'width:100%;height:100%;border:0;display:block;' }).attr('src', url);
        popup.transacaoIndependente({ titulo: 'Assuntos da legislação — ' + lawCode, conteudo: frame });
    }
    window.addEventListener('message', function (event) { if (event.data && event.data.type === 'legislacao-assuntos-saved') window.location.reload(); });
    // Migração 382: URL de pesquisa e Legislações filhas (popups; a contagem da linha é atualizada sem recarregar o grid)
    var assocPages = { urls: ['manutencaolegal_legislacao_urls.php', 'URL de pesquisa'], filhas: ['manutencaolegal_legislacao_filhas.php', 'Legislações filhas'], alteracoes: ['manutencaolegal_legislacao_alteracoes.php', 'Alterações na legislação'], checklist: ['manutencaolegal_legislacao_catalogo.php?tipo=checklist', 'CheckList'], palavras: ['manutencaolegal_legislacao_catalogo.php?tipo=palavras', 'Palavras-chave'] };
    function assocMessage(text) { var $m = $fragment.find('[data-assoc-msg]'); if (!$m.length) { $m = $('<span class="legislacao-assoc-msg small text-danger ms-2" data-assoc-msg></span>'); var $g = $fragment.find('.tb-grupo-associacoes').first(); if ($g.length) $g.after($m); else $fragment.find('.crud-toolbar').first().append($m); } $m.text(text || ''); clearTimeout($m.data('timer')); if (text) $m.data('timer', setTimeout(function () { $m.text(''); }, 3500)); }
    function openAssociation(kind, $row) {
        var page = assocPages[kind], code = String(($row && $row.data('key')) || '').trim();
        if (!page) return;
        if (!$row || !$row.length) return assocMessage('Selecione uma legislação no grid.');
        if (!/^\d+$/.test(code)) return assocMessage('Salve a legislação antes de associar.');
        var host = window.parent && window.parent.GreenPortalPopups ? window.parent : (window.top || window), popup = host.GreenPortalPopups;
        if (!popup || typeof popup.transacaoIndependente !== 'function' || !host.jQuery) { if (window.console) console.error('GreenPortalPopups.transacaoIndependente indisponível.'); return; }
        var lawCode = String($row.find('[data-column="CODIGO"] input').val() || code).trim();
        var url = new URL(page[0] + (page[0].indexOf('?') >= 0 ? '&' : '?') + 'CODLEGISLACAO=' + encodeURIComponent(code), window.location.href).toString();
        var frame = host.jQuery('<iframe>', { style: 'width:100%;height:100%;border:0;display:block;' }).attr('src', url);
        popup.transacaoIndependente({ titulo: page[1] + ' — ' + lawCode, conteudo: frame });
    }
    function assocCountHtml(kind, data) {
        var esc = function (v) { return $('<div>').text(String(v)).html(); };
        if (kind === 'URLS') { var n = Number(data.QTD_URLS || 0), s = Number(data.QTD_URLS_SUGERIDAS || 0); return '<button type="button" class="btn btn-sm btn-link p-0 legislacao-assoc-count" data-assoc-open="urls" title="' + esc(n + ' link(s) confirmado(s)' + (s ? ', ' + s + ' sugestão(ões) da IA a confirmar' : '')) + '">' + n + '</button>' + (s ? ' <span class="badge text-bg-warning" title="Sugestões da IA a confirmar">+' + s + '</span>' : ''); }
        var f = Number(data.QTD_FILHAS || 0), p = Number(data.QTD_PAIS || 0);
        return '<button type="button" class="btn btn-sm btn-link p-0 legislacao-assoc-count" data-assoc-open="filhas" title="' + esc(f + ' legislação(ões) filha(s)' + (p ? '; é filha de ' + p : '')) + '">' + f + '</button>' + (p ? ' <span class="text-muted small" title="É filha de ' + p + ' legislação(ões)">↑' + p + '</span>' : '');
    }
    window.addEventListener('message', function (event) {
        var d = event.data;
        if (!d || d.type !== 'legislacao-associacoes-saved' || d.QTD_URLS === undefined) return;
        var $row = $table.find('tbody tr[data-key="' + String(d.id).replace(/[^0-9]/g, '') + '"]');
        $row.find('td[data-column="URLS"]').html('<div class="legislacao-assoc-cell">' + assocCountHtml('URLS', d) + '</div>');
        $row.find('td[data-column="FILHAS"]').html('<div class="legislacao-assoc-cell">' + assocCountHtml('FILHAS', d) + '</div>');
    });
    // Migração 386: o popup de Alterações pode atualizar a situação, o devido e a data da lei — reflete na linha sem recarregar
    window.addEventListener('message', function (event) {
        var d = event.data;
        if (!d || d.type !== 'legislacao-alteracoes-saved') return;
        var $row = $table.find('tbody tr[data-key="' + String(d.id).replace(/[^0-9]/g, '') + '"]');
        if (!$row.length) return;
        var put = function (column, value) { var $f = $row.find('td[data-column="' + column + '"]').find('select,input').first(); if (!$f.length) return; if ($f.is('select') && value && !$f.find('option').filter(function () { return this.value === value; }).length) $f.append($('<option>').val(value).text(value)); $f.val(value); };
        put('situacao_da_lei', d.situacao || ''); put('devido_da_lei', d.devido || ''); put('data_atualizacao', d.data ? d.data + 'T00:00' : '');
    });
    $table.on('click', '[data-assoc-open]', function (event) { event.stopPropagation(); var $row = $(this).closest('tr'); $table.find('tbody tr').removeClass('selected'); $row.addClass('selected'); openAssociation(String($(this).data('assoc-open')), $row); });
    $fragment.find('.crud-toolbar').on('click', '[data-assoc-open]', function () { openAssociation(String($(this).data('assoc-open')), selected()); });
    // Botões que agem na linha selecionada: desabilitados até o usuário selecionar uma legislação já salva
    function updateAssocButtons() { var $row = selected(), ok = $row.length > 0 && /^\d+$/.test(String($row.data('key') || '')); $fragment.find('.crud-toolbar [data-assoc-open]').prop('disabled', !ok); if (ok) assocMessage(''); }
    $table.on('click', 'tbody tr', function () { setTimeout(updateAssocButtons, 0); });
    $fragment.on('gridPageLoaded', function () { setTimeout(updateAssocButtons, 0); });
    $(document).on('ajaxComplete', function () { setTimeout(updateAssocButtons, 0); });
    updateAssocButtons();
    // Migração 385: RCL Completa / RCL Padrão usam os filtros Empresa, Unidade e Mês/Ano da tela (os três obrigatórios)
    function rclFilters() {
        var clean = function (v) { return String(v || '').replace(/\|/g, '').trim(); };
        return { empresa: clean($fragment.find('#legislacaoFilterCODEMPRESA').val()), unidade: clean($fragment.find('#legislacaoFilterCODUNIDADE').val()), mes: clean($fragment.find('#legislacaoFilterMES_ANO_ATUALIZACAO').val()) };
    }
    function rclMessage(text, kind) {
        var $m = $fragment.find('[data-rcl-msg]');
        if (!$m.length) { $m = $('<span class="small ms-2" data-rcl-msg></span>'); var $g = $fragment.find('.tb-grupo-rcl').first(); if ($g.length) $g.after($m); else $fragment.find('.crud-toolbar').first().append($m); }
        $m.attr('class', 'small ms-2 text-' + (kind || 'danger')).text(text || ''); clearTimeout($m.data('timer')); if (text && kind !== 'muted') $m.data('timer', setTimeout(function () { $m.text(''); }, 6000));
    }
    function updateRclButtons() { var f = rclFilters(), ok = /^\d+$/.test(f.empresa) && /^\d+$/.test(f.unidade) && /^\d{4}-\d{2}$/.test(f.mes);
        // Migração 386: destaca o RCL definido para a unidade (Setup › Unidades: tipo de RCL)
        var tipos = (window.legislacaoGridOptions || {}).unidadeTipoRcl || {}, tipo = ((tipos['|' + f.empresa + '|'] || {})['|' + f.unidade + '|']) || '';
        $fragment.find('[data-rcl-completa]').toggleClass('btn-success', tipo === 'COMPLETA').toggleClass('btn-outline-success', tipo !== 'COMPLETA');
        $fragment.find('[data-rcl-padrao]').toggleClass('btn-success', tipo === 'PADRAO').toggleClass('btn-outline-success', tipo !== 'PADRAO'); $fragment.find('[data-rcl-completa],[data-rcl-padrao]').prop('disabled', !ok).attr('title', function () { var base = $(this).is('[data-rcl-completa]') ? 'RCL Completa' : 'RCL Padrão'; return ok ? base : base + ' — escolha Empresa, Unidade e Mês/Ano nos filtros'; }); }
    $fragment.on('change', '#legislacaoFilterCODEMPRESA,#legislacaoFilterCODUNIDADE,#legislacaoFilterMES_ANO_ATUALIZACAO', function () { setTimeout(updateRclButtons, 0); });
    $fragment.on('gridPageLoaded', function () { setTimeout(updateRclButtons, 0); });
    $fragment.find('.crud-toolbar').on('click', '[data-rcl-completa],[data-rcl-padrao]', function (event) {
        event.preventDefault(); event.stopImmediatePropagation();
        var f = rclFilters(), $b = $(this), completa = $b.is('[data-rcl-completa]');
        if (!/^\d+$/.test(f.empresa) || !/^\d+$/.test(f.unidade) || !/^\d{4}-\d{2}$/.test(f.mes)) return rclMessage('Escolha Empresa, Unidade e Mês/Ano nos filtros para gerar o RCL.');
        var url = new URL('manutencaolegal_legislacao_rcl.php', window.location.href); url.searchParams.set('empresa', f.empresa); url.searchParams.set('unidade', f.unidade); url.searchParams.set('mes', f.mes); url.searchParams.set('tipo', completa ? 'completa' : 'padrao');
        $fragment.find('[data-rcl-completa],[data-rcl-padrao]').prop('disabled', true); rclMessage('Gerando ' + (completa ? 'RCL Completa' : 'RCL Padrão') + '...', 'muted');
        fetch(url.toString(), { credentials: 'same-origin' }).then(function (response) {
            var type = response.headers.get('Content-Type') || '';
            if (!response.ok || type.indexOf('spreadsheetml') < 0) return response.text().then(function (t) { throw new Error($('<div>').html(t).text().trim() || 'Não foi possível gerar o RCL.'); });
            var disposition = response.headers.get('Content-Disposition') || '', match = /filename="([^"]+)"/.exec(disposition);
            return response.blob().then(function (blob) { var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = match ? match[1] : 'RCL.xlsx'; document.body.appendChild(a); a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000); rclMessage('RCL gerado.', 'success'); });
        }).catch(function (error) { rclMessage(error.message || 'Não foi possível gerar o RCL.'); }).finally(updateRclButtons);
    });
    updateRclButtons();
    function openPatternEditor($input) {
        var $modal = $('#legislacaoPatternModal');
        if (!$modal.length) {
            $modal = $('<div class="modal fade legislacao-pattern-modal" id="legislacaoPatternModal" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title">Editar padrão</h5><small class="text-muted" data-pattern-code></small></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div><div class="modal-body"><textarea class="form-control" rows="24" data-pattern-text></textarea></div><div class="modal-footer"><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-sm btn-success" data-confirm-pattern>Confirmar</button></div></div></div></div>').appendTo('body');
        }
        var legislationCode = String($input.closest('tr').find('[data-column="CODIGO"] input, [data-column="CODIGO"] select').val() || '');
        var currentValue = $input.data('pattern-value');
        if (currentValue === undefined) currentValue = $input.val() || '';
        $modal.data('pattern-input', $input).find('[data-pattern-text]').val(currentValue);
        $modal.find('[data-pattern-code]').text('Código da legislação: ' + (legislationCode || 'Novo registro'));
        $modal.off('click', '[data-confirm-pattern]').on('click', '[data-confirm-pattern]', function () {
            var $target = $modal.data('pattern-input');
            if ($target && $target.length) {
                var value = $modal.find('[data-pattern-text]').val() || '';
                setPatternValue($target.closest('tr'), value);
                $target.trigger('input').trigger('change');
                $form.data('grid-dirty', true);
            }
            bootstrap.Modal.getOrCreateInstance($modal[0]).hide();
        });
        bootstrap.Modal.getOrCreateInstance($modal[0]).show();
    }
    // Migração 382: coluna sem preferência gravada (URL de pesquisa / Leis filhas, novas) vai para depois das que o usuário já ordenou
    function columnOrder(column) { var hasAny = Object.keys(preferences || {}).length > 0, pref = (preferences || {})[column]; return pref ? Number(pref.ordem || 0) : (hasAny ? 900 + columns.indexOf(column) : 0); }
    function apply() {
        columns.sort(function (a, b) { return columnOrder(a) - columnOrder(b); });
        var assuntosIndex = columns.indexOf('ASSUNTOS'), ambitoIndex = columns.indexOf('CODAMBITO');
        if (assuntosIndex >= 0 && ambitoIndex >= 0) { columns.splice(assuntosIndex, 1); ambitoIndex = columns.indexOf('CODAMBITO'); columns.splice(ambitoIndex, 0, 'ASSUNTOS'); }
        columns.forEach(function (column) { $table.find('thead tr').append($table.find('th[data-column="' + column + '"]')); $table.find('tbody tr').each(function () { $(this).append($(this).find('td[data-column="' + column + '"]').detach()); }); });
        columns.forEach(function (column) { var p = preferences[column] || {}, visible = column === 'CODLEGISLACAO' ? false : (p.visivel === undefined ? true : !!Number(p.visivel)); $table.find('[data-column="' + column + '"]').toggle(visible).css('width', Math.max(60, Number(p.largura || 140)) + 'px'); });
    }
    function savePreferences() { $.post(window.location.href, { action: 'save_preferences', preferences: JSON.stringify(columns.map(function (column, index) { return { coluna: column, ordem: index, visivel: $table.find('[data-column="' + column + '"]:first').is(':visible') ? 1 : 0, largura: Math.round($table.find('th[data-column="' + column + '"]').outerWidth() || 140) }; })) }); }
    function selected() { return $table.find('tbody tr.selected').first(); }
    function ask(text, callback) { var $modal = $('#legislacaoConfirmModal'); if (!$modal.length) { $modal = $('<div class="modal fade" id="legislacaoConfirmModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Confirmação</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"></div><div class="modal-footer"><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-sm btn-success" data-confirm>Confirmar</button></div></div></div></div>').appendTo('body'); } $modal.find('.modal-body').text(text); $modal.off('click', '[data-confirm]').on('click', '[data-confirm]', function () { bootstrap.Modal.getInstance($modal[0]).hide(); if (callback) callback(); }); bootstrap.Modal.getOrCreateInstance($modal[0]).show(); }
    function associationContext() {
        var company = $.trim(String($fragment.find('#legislacaoFilterCODEMPRESA').val() || ''));
        var unit = $.trim(String($fragment.find('#legislacaoFilterCODUNIDADE').val() || ''));
        var language = String(window.greenPortalIdioma || 'pt').toLowerCase();
        var labels = language === 'en' ? { select: 'Select both Company and Unit before creating a linked law.' } : language === 'es' ? { select: 'Seleccione Empresa y Unidad antes de crear una ley vinculada.' } : { select: 'Selecione Empresa e Unidade antes de cadastrar uma lei vinculada.' };
        return { company: company, unit: unit, labels: labels, partial: (company !== '' && unit === '') || (company === '' && unit !== '') };
    }
    function guardAssociation() { var context = associationContext(); if (context.partial) { window.alert(context.labels.select); return null; } return context; }
    function rowHtml(key, source) { return columns.map(function (column) { var $sourceField = source && source.find('[data-column="' + column + '"] input, [data-column="' + column + '"] select').first(), value = column === 'CODIGO' && source ? '' : (column === 'PADRAO' && $sourceField ? ($sourceField.data('pattern-value') || $sourceField.val() || '') : ($sourceField && $sourceField.val() || '')); if (column === 'CODLEGISLACAO') return '<td data-column="' + column + '"><span class="text-muted">Novo</span></td>'; if (['CODAMBITO', 'CODTIPO', 'MEIO_AMBIENTE', 'SEGURANCA', 'QUALIDADE', 'RESPOSABILIDADE', 'situacao_da_lei', 'devido_da_lei'].indexOf(column) >= 0) return '<td data-column="' + column + '">' + contextSelectHtml(column, key, value) + '</td>'; if (column === 'data_atualizacao') return '<td data-column="' + column + '"><input class="grid-input" type="datetime-local" name="rows[' + key + '][' + column + ']" value="' + dateTimeLocal(value) + '"></td>'; if (column === 'PADRAO') return '<td data-column="' + column + '">' + patternCellHtml(key, value) + '</td>'; if (column === 'ASSUNTOS') return '<td data-column="' + column + '">' + assuntosCellHtml(value) + '</td>'; if (column === 'URLS' || column === 'FILHAS') return '<td data-column="' + column + '"><span class="text-muted">—</span></td>'; return '<td data-column="' + column + '"><input class="grid-input" name="rows[' + key + '][' + column + ']" value="' + $('<div>').text(value).html() + '"></td>'; }).join(''); }
    // Migração 390: as listas dos filtros das colunas mostram só os valores das leis que passam nos outros filtros
    // (o servidor manda os valores disponíveis na página inteira e, a cada mudança de filtro, no tbody: data-grid-opcoes).
    function optionKey(column, value) {
        var text = String(value === null || value === undefined ? '' : value).replace(/^[|\s]+|[|\s]+$/g, '');
        if (column === 'CODAMBITO' || column === 'CODTIPO') return /^\d+$/.test(text) ? String(parseInt(text, 10)) : text;
        return text.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }
    function applyAvailableOptions(available) {
        if (!available || typeof available !== 'object') return;
        var locale = window.greenPortalIdioma === 'en' ? 'en-US' : (window.greenPortalIdioma === 'es' ? 'es-ES' : 'pt-BR');
        Object.keys(available).forEach(function (column) {
            var $select = $table.find('thead select[data-column-filter="' + column + '"]');
            if (!$select.length || !Array.isArray(available[column])) return;
            var allowed = {}, values = contextOptions(column), current = String($select.val() || '');
            available[column].forEach(function (value) { allowed[optionKey(column, value)] = true; });
            var list = Object.keys(values).filter(function (value) { return allowed[optionKey(column, value)] || value === current; })
                .map(function (value) { return { value: value, label: String(values[value] || value) }; })
                .sort(function (a, b) { return a.label.localeCompare(b.label, locale, { sensitivity: 'base', numeric: true }); });
            $select[0].innerHTML = '<option value="">Todos</option>' + list.map(function (item) { return '<option value="' + escHtml(item.value) + '">' + escHtml(item.label) + '</option>'; }).join('');
            $select.val(current);
        });
    }
    installContextFilters();
    applyAvailableOptions((window.legislacaoGridOptions || {}).disponiveis);
    $fragment.on('gridPageLoaded', function () {
        var raw = $table.find('tbody').first().attr('data-grid-opcoes');
        if (!raw) return;
        try { applyAvailableOptions(JSON.parse(raw)); } catch (e) {}
    });
    apply();
    $table.on('click', 'tbody tr', function () { $table.find('tbody tr').removeClass('selected'); $(this).addClass('selected'); });
    $table.on('click', '[data-edit-pattern]', function (event) { event.stopPropagation(); openPatternEditor($(this).closest('[data-column="PADRAO"]').find('input').first()); });
    $table.on('click', '[data-edit-assuntos]', function (event) { event.stopPropagation(); openAssuntosEditor($(this).closest('tr')); });
    $fragment.on('gridPageLoaded', function () { installContextFields(); installPatternFields(); installAssuntosFields(); apply(); });
    $('[data-new-legislacao]').on('click', function () { var context = guardAssociation(); if (!context) return; var key = 'novo_' + Date.now(); $table.find('tbody').prepend('<tr data-row data-key="' + key + '">' + rowHtml(key) + '</tr>'); apply(); setPatternValue($table.find('tbody tr').first(), ''); $form.data('grid-dirty', true); });
    $('[data-duplicate-legislacao]').on('click', function () { var $row = selected(); if (!$row.length) return ask('Selecione uma linha para duplicar.'); var context = guardAssociation(); if (!context) return; ask('Confirma a duplicação do registro selecionado?', function () { var key = 'novo_' + Date.now(), patternValue = $row.find('td[data-column="PADRAO"] input').first().data('pattern-value') || $row.find('td[data-column="PADRAO"] input').first().val() || ''; $table.find('tbody').prepend('<tr data-row data-key="' + key + '">' + rowHtml(key, $row) + '</tr>'); apply(); setPatternValue($table.find('tbody tr').first(), patternValue); $form.data('grid-dirty', true); }); });
    $('[data-delete-legislacao]').on('click', function () { var $row = selected(); if (!$row.length) return; var key = String($row.data('key')); ask('Confirma a exclusão do registro selecionado?', function () { if (key.indexOf('novo_') === 0) return $row.remove(); $form.find('input[name="action"]').val('delete_row'); $('<input type="hidden" name="CODLEGISLACAO">').val(key).appendTo($form); $form.data('confirmed', true).trigger('submit'); }); });
        $form.on('submit', function () { var context = associationContext(); $form.find('[data-legislacao-context]').remove(); $('<input type="hidden" name="context_empresa" data-legislacao-context>').val(context.company).appendTo($form); $('<input type="hidden" name="context_unidade" data-legislacao-context>').val(context.unit).appendTo($form); });
    $form.on('submit', function (event) { if ($(this).data('confirmed')) { $(this).removeData('confirmed'); return; } event.preventDefault(); ask('Confirma a gravação das alterações?', function () { $form.data('confirmed', true).trigger('submit'); }); });
    $('[data-columns]').on('click', function () { var $modal = $('#legislacaoColumnsModal'); if (!$modal.length) $modal = $('<div class="modal fade" id="legislacaoColumnsModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Colunas do grid</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" id="legislacaoColumnsList"></div><div class="modal-footer"><button type="button" class="btn btn-sm btn-success" data-save-legislacao-columns>Salvar colunas</button></div></div></div></div>').appendTo('body'); $('#legislacaoColumnsList').html(columns.map(function (column) { return '<label class="column-option"><input type="checkbox" data-legislacao-column="' + column + '"' + ($table.find('[data-column="' + column + '"]:first').is(':visible') ? ' checked' : '') + '> ' + $('<div>').text($table.find('th[data-column="' + column + '"] span').text()).html() + '</label>'; }).join('')); bootstrap.Modal.getOrCreateInstance($modal[0]).show(); });
    $(document).on('click', '[data-save-legislacao-columns]', function () { $('[data-legislacao-column]').each(function () { preferences[$(this).data('legislacao-column')] = preferences[$(this).data('legislacao-column')] || {}; preferences[$(this).data('legislacao-column')].visivel = this.checked ? 1 : 0; }); apply(); savePreferences(); bootstrap.Modal.getInstance(document.getElementById('legislacaoColumnsModal')).hide(); });
}(jQuery));

(function ($) {
    'use strict';
    var $fragment = $('.legislacao-grid-fragment'), $table = $('#legislacaoBatchGrid');
    if (!$table.length) return;
    function installPdfButtons() {
        $table.find('td[data-column="CODIGO"]').each(function () { var $cell = $(this); if (!$cell.find('[data-open-legislacao-pdf]').length) { var language = window.greenPortalIdioma || 'pt', label = language === 'en' ? 'Full text of the standard' : (language === 'es' ? 'Texto íntegro de la norma' : 'Integra da norma'); $cell.prepend('<button type="button" class="btn btn-link btn-sm p-0 me-1 legislacao-pdf-button" data-open-legislacao-pdf data-bs-toggle="tooltip" data-bs-placement="top" title="' + label + '" aria-label="' + label + '"><i class="bi bi-file-earmark-pdf"></i></button>'); } });
        $table.find('[data-open-legislacao-pdf]').each(function () { if (window.bootstrap && bootstrap.Tooltip && !bootstrap.Tooltip.getInstance(this)) bootstrap.Tooltip.getOrCreateInstance(this); });
    }
    function loadPdfJs() {
        if (window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
        if (window.greenPortalPdfJsPromise) return window.greenPortalPdfJsPromise;
        window.greenPortalPdfJsPromise = new Promise(function (resolve, reject) {
            var script = document.createElement('script'); script.src = '../assets/vendor/pdfjs/pdf.min.js'; script.onload = function () { if (!window.pdfjsLib) return reject(new Error('PDF.js indisponível')); window.pdfjsLib.GlobalWorkerOptions.workerSrc = '../assets/vendor/pdfjs/pdf.worker.min.js'; resolve(window.pdfjsLib); }; script.onerror = reject; document.head.appendChild(script);
        });
        return window.greenPortalPdfJsPromise;
    }
    function openPdf($row) {
        var code = String($row.find('td[data-column="CODIGO"] input,td[data-column="CODIGO"] select').val() || '').trim();
        if (!/^[A-Za-z0-9_-]+$/.test(code)) { window.alert('Informe um código de legislação válido.'); return; }
        var url = 'legislacao_pdf.php?codigo=' + encodeURIComponent(code), $modal = $('#legislacaoPdfModal');
        if (!$modal.length) $modal = $('<div class="modal fade" id="legislacaoPdfModal" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content" style="height:90vh"><div class="modal-header py-2"><h5 class="modal-title"><i class="bi bi-file-earmark-pdf me-2"></i>Texto da Lei: <span data-legislacao-pdf-code></span></h5><div class="d-flex align-items-center gap-1"><button type="button" class="btn btn-sm btn-outline-secondary" data-pdf-prev title="Página anterior"><i class="bi bi-chevron-left"></i></button><span class="small text-muted" data-pdf-page>1 / 1</span><button type="button" class="btn btn-sm btn-outline-secondary" data-pdf-next title="Próxima página"><i class="bi bi-chevron-right"></i></button><a class="btn btn-sm btn-outline-primary" data-pdf-download download title="Baixar PDF"><i class="bi bi-download"></i></a><button type="button" class="btn-close ms-1" data-bs-dismiss="modal" aria-label="Fechar"></button></div></div><div class="modal-body p-2 text-center overflow-auto bg-secondary-subtle"><canvas data-legislacao-pdf-canvas class="shadow bg-white"></canvas><div class="small text-muted mt-2" data-pdf-message>Carregando documento...</div></div></div></div></div>').appendTo('body');
        var documentLabel = (window.greenPortalIdioma || 'pt') === 'en' ? 'Full text of the standard' : ((window.greenPortalIdioma || 'pt') === 'es' ? 'Texto íntegro de la norma' : 'Integra da norma'); $modal.find('.modal-title').contents().filter(function () { return this.nodeType === 3; }).first().replaceWith(documentLabel + ': ');
        var state = { pdf: null, page: 1, scale: 1.25, url: url }; $modal.data('pdf-state', state).find('[data-legislacao-pdf-code]').text(code).end().find('[data-pdf-download]').attr('href', url).end().find('[data-pdf-message]').text('Carregando documento...');
        function renderPage() { if (!state.pdf) return; state.pdf.getPage(state.page).then(function (page) { var viewport = page.getViewport({ scale: state.scale }), canvas = $modal.find('[data-legislacao-pdf-canvas]')[0], context = canvas.getContext('2d'); canvas.width = viewport.width; canvas.height = viewport.height; return page.render({ canvasContext: context, viewport: viewport }).promise; }).then(function () { $modal.find('[data-pdf-page]').text(state.page + ' / ' + state.pdf.numPages); $modal.find('[data-pdf-prev]').prop('disabled', state.page <= 1); $modal.find('[data-pdf-next]').prop('disabled', state.page >= state.pdf.numPages); $modal.find('[data-pdf-message]').text(''); }).catch(function () { $modal.find('[data-pdf-message]').text('Não foi possível exibir o PDF. Use o botão de download.'); }); }
        $modal.off('click', '[data-pdf-prev]').on('click', '[data-pdf-prev]', function () { if (state.page > 1) { state.page--; renderPage(); } }); $modal.off('click', '[data-pdf-next]').on('click', '[data-pdf-next]', function () { if (state.pdf && state.page < state.pdf.numPages) { state.page++; renderPage(); } });
        bootstrap.Modal.getOrCreateInstance($modal[0]).show(); loadPdfJs().then(function (pdfjs) { return pdfjs.getDocument(url).promise; }).then(function (pdf) { state.pdf = pdf; renderPage(); }).catch(function () { $modal.find('[data-pdf-message]').text('Não foi possível exibir o PDF. Use o botão de download.'); });
    }
    $fragment.on('click', '[data-open-legislacao-pdf]', function (event) { event.stopPropagation(); openPdf($(this).closest('tr')); });
    $fragment.on('click', '[data-export-cadastro]', function (event) { event.preventDefault(); var filters = {}; $fragment.find('[data-grid-filter], [data-column-filter]').each(function () { var key = String($(this).data('grid-filter') || $(this).data('column-filter')); var value = String($(this).val() || '').trim(); if (value !== '') filters[key] = value; }); $.post(window.location.href, { action: 'export_request', filtros: JSON.stringify(filters), busca: String($fragment.find('.crud-search input').val() || '').trim(), ajax: 1 }, function (response) { if (response && response.ok) window.alert(response.message || 'Exportação solicitada.'); else window.alert('Não foi possível solicitar a exportação.'); }, 'json').fail(function () { window.alert('Não foi possível solicitar a exportação.'); }); });
    $fragment.on('gridPageLoaded', installPdfButtons); installPdfButtons();
}(jQuery));
