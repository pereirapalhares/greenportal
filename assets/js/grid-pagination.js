(function ($) {
    'use strict';

    var pageSize = 25;
    var duplicateSelectors = '[data-crud-duplicate],[data-duplicate-lista],[data-duplicate-formulario],[data-duplicate-legislacao],[data-duplicate-legislacao-empresa],[data-duplicate-cadastro]';
    var messages = {
        pt: { duplicate: 'Selecione uma linha para duplicar', remove: 'Selecione uma linha para excluir', exportActive: 'Existe uma exportação em andamento', previous: 'Anterior', next: 'Próxima', loading: 'Carregando grid...', failed: 'Não foi possível carregar a página.', page: 'Página', of: 'de', records: 'registros', loaded: 'registros carregados', counting: 'Calculando total...' },
        en: { duplicate: 'Select a row to duplicate', remove: 'Select a row to delete', exportActive: 'An export is already in progress', previous: 'Previous', next: 'Next', loading: 'Loading grid...', failed: 'The page could not be loaded.', page: 'Page', of: 'of', records: 'records', loaded: 'records loaded', counting: 'Counting records...' },
        es: { duplicate: 'Seleccione una fila para duplicar', remove: 'Seleccione una fila para eliminar', exportActive: 'Hay una exportación en curso', previous: 'Anterior', next: 'Siguiente', loading: 'Cargando cuadrícula...', failed: 'No fue posible cargar la página.', page: 'Página', of: 'de', records: 'registros', loaded: 'registros cargados', counting: 'Calculando total...' }
    };

    // Textos do grupo COMUM (Administração › Textos e idiomas, window.greenT); os daqui são o padrão.
    function T(chave, padrao) { return typeof window.greenT === 'function' ? window.greenT(chave, padrao) : padrao; }
    function languageMessages() {
        var base = messages[String(window.greenPortalIdioma || 'pt').toLowerCase()] || messages.pt;
        return {
            duplicate: T('comum.msg.selecione_duplicar', base.duplicate), remove: T('comum.msg.selecione_excluir', base.remove),
            exportActive: T('comum.msg.exportacao_andamento', base.exportActive), previous: T('comum.paginacao.anterior', base.previous),
            next: T('comum.paginacao.proxima', base.next), loading: T('comum.carregando.grid', base.loading), failed: T('comum.paginacao.falha', base.failed),
            page: T('comum.paginacao.pagina', base.page), of: T('comum.paginacao.de', base.of), records: T('comum.paginacao.registros', base.records),
            loaded: T('comum.paginacao.carregados', base.loaded), counting: T('comum.paginacao.calculando', base.counting)
        };
    }

        function filtersFor($table, $fragment) {
            var filters = {};
            var contextFilters = $fragment.attr('data-context-filters');
            if (contextFilters) {
                try {
                    var parsedContext = JSON.parse(contextFilters);
                    // Context filters must be a JSON object. An empty array ("[]")
                    // cannot receive named column filters and is silently omitted
                    // by JSON.stringify, causing column filters to be ignored.
                    if (parsedContext && typeof parsedContext === 'object' && !Array.isArray(parsedContext)) filters = parsedContext;
                } catch (error) { filters = {}; }
            }
        // data-context-filters traz todos os filtros da última carga do servidor, inclusive os de coluna.
        // A caixa de cada coluna é quem manda no seu filtro: sem isso, um filtro de coluna vindo da URL
        // vira "contexto" e não sai mais quando o usuário apaga a caixa.
        // c577 (16/11/2026): filtro com operador vindo da URL ou do filtro padrão da tela (ex.: Etapa "diferente de Finalizado",
        // Validade "até 30 dias") não cabe na caixa da coluna; com a caixa vazia ele continua valendo — antes se perdia na contagem
        // do total e nas outras páginas (o card de Pendências mostrava 2 e o grid dizia "0 registros").
        // A caixa pode mostrar o valor do filtro (ex.: a data de "até"): se ainda é o mesmo valor, vale o filtro com operador.
        var comOperador = {};
        $table.find('[data-column-filter]').each(function () {
            var k = String($(this).data('column-filter')), v = filters[k], caixa = String($(this).val() || '');
            if (v && typeof v === 'object' && v.operator && (caixa === '' || caixa === String(v.value))) { comOperador[k] = true; return; }
            delete filters[k];
        });
        $fragment.find('[data-grid-filter]').each(function () { delete filters[String($(this).data('grid-filter'))]; });
        $table.find('[data-column-filter]').each(function () {
            var value = String($(this).val() || '');
            if (value !== '') { var $filter = $(this), filterColumn = String($filter.data('column-filter')); if (comOperador[filterColumn]) return; filters[filterColumn] = $filter.is('select') ? { value: value, exact: true } : value; }
        });
        $fragment.find('[data-grid-filter]').each(function () {
            var value = String($(this).val() || '');
            if (value !== '') { var filterName=String($(this).data('grid-filter')); if(filterName==='CODEMPRESA') value=value.replace(/^\|+|\|+$/g,''); filters[filterName] = value; }
        });
        return { filters: filters, search: String($fragment.find('.crud-search input').val() || '') };
    }

    $('.menu-grid-fragment .menu-batch-grid').each(function () {
        var $table = $(this), $body = $table.find('tbody'), $form = $table.closest('.menu-grid-form');
        if (!$body.length || !$form.length) return;
        var $fragment = $table.closest('.menu-grid-fragment');
        var formId = $form.attr('id');
        var $saveButton = $('button[type="submit"][form="' + formId + '"]');
        var $deleteButtons = $fragment.find('[data-delete-menu],[data-delete-group],[data-delete-user],[data-delete-permission],[data-delete-profile],[data-delete-formulario],[data-delete-lista],[data-delete-legislacao],[data-delete-legislacao-empresa]');
        var $duplicateButtons = $fragment.find(duplicateSelectors);
        var exportTransaction = String(document.body.getAttribute('data-transacao') || window.location.pathname.split('/').pop().replace(/\.php$/i, '')).toLowerCase();
        var $exportButtons = $fragment.find('[name="action"][value="export_request"],[data-export-lista],[data-export-formulario],[data-export-cadastro]');
        var exportTimer = null;
        var requestInFlight = false;
        var filterPending = false;
        var countRequest = null;
        var filterTimer = null;
        var totalRecords = null;
        var currentPage = Math.max(1, Number(new URLSearchParams(window.location.search).get('pagina') || 1));
        var $pagination = $form.next('.grid-pagination');
        if (!$pagination.length) $pagination = $fragment.find('.grid-pagination').first();

        function normalizeToolbar() {
            var $actions = $fragment.find('.crud-actions').first();
            if (!$actions.length) return;
            [
                'button[type="submit"][form="' + formId + '"]',
                '[data-new-menu],[data-new-group],[data-new-user],[data-new-permission],[data-new-profile],[data-new-formulario],[data-new-lista],[data-new-legislacao],[data-new-legislacao-empresa],[data-new-cadastro]',
                duplicateSelectors,
                '[data-delete-menu],[data-delete-group],[data-delete-user],[data-delete-permission],[data-delete-profile],[data-delete-formulario],[data-delete-lista],[data-delete-legislacao],[data-delete-legislacao-empresa]',
                '[data-reload-grid]',
                '[name="action"][value="export_request"],[data-export-lista],[data-export-formulario],[data-export-cadastro]',
                '[data-columns]'
            ].forEach(function (selector) { $actions.append($actions.find(selector).detach()); });
            compactToolbar($actions);
        }

        function compactToolbar($actions) {
            var idioma = String(window.greenPortalIdioma || 'pt').toLowerCase();
            var labels = ({
                pt: { save: 'Salvar', new: 'Novo', duplicate: 'Duplicar', remove: 'Excluir', reload: 'Recarregar', export: 'Exportar Excel', columns: 'Colunas' },
                en: { save: 'Save', new: 'New', duplicate: 'Duplicate', remove: 'Delete', reload: 'Reload', export: 'Export to Excel', columns: 'Columns' },
                es: { save: 'Guardar', new: 'Nuevo', duplicate: 'Duplicar', remove: 'Eliminar', reload: 'Recargar', export: 'Exportar a Excel', columns: 'Columnas' }
            }[idioma] || null) || { save: 'Salvar', new: 'Novo', duplicate: 'Duplicar', remove: 'Excluir', reload: 'Recarregar', export: 'Exportar Excel', columns: 'Colunas' };
            labels = { save: T('comum.botao.salvar', labels.save), new: T('comum.botao.novo', labels.new), duplicate: T('comum.botao.duplicar', labels.duplicate), remove: T('comum.botao.excluir', labels.remove), reload: T('comum.botao.recarregar', labels.reload), export: T('comum.botao.exportar', labels.export), columns: T('comum.botao.colunas', labels.columns) };
            [
                ['button[type="submit"][form="' + formId + '"]', labels.save],
                ['[data-new-menu],[data-new-group],[data-new-user],[data-new-permission],[data-new-profile],[data-new-formulario],[data-new-lista],[data-new-legislacao],[data-new-legislacao-empresa],[data-new-cadastro]', labels.new],
                [duplicateSelectors, labels.duplicate],
                ['[data-delete-menu],[data-delete-group],[data-delete-user],[data-delete-permission],[data-delete-profile],[data-delete-formulario],[data-delete-lista],[data-delete-legislacao],[data-delete-legislacao-empresa]', labels.remove],
                ['[data-reload-grid]', labels.reload],
                ['[name="action"][value="export_request"],[data-export-lista],[data-export-formulario],[data-export-cadastro]', labels.export],
                ['[data-columns]', labels.columns]
            ].forEach(function (item) { $actions.find(item[0]).each(function () { $(this).addClass('grid-icon-button').attr('title', $(this).attr('title') || item[1]).attr('aria-label', item[1]); }); });
            $actions.children('button, .crud-context-actions > button').each(function () {
                var $button = $(this);
                if (!$button.hasClass('grid-icon-button')) {
                    var label = String($button.attr('title') || $button.attr('aria-label') || '').trim();
                    $button.addClass('grid-icon-button');
                    if (label !== '') $button.attr('aria-label', label);
                }
            });
        }

        function setExportState(active) {
            var text = languageMessages().exportActive;
            $exportButtons.each(function () {
                var $button = $(this), locked = $button.attr('data-permission-locked') === '1';
                $button.prop('disabled', active || locked).attr('aria-disabled', active || locked ? 'true' : 'false').attr('data-export-active', active ? '1' : '0');
                if (active) $button.attr('title', text); else if (!locked) $button.attr('title', T('comum.botao.exportar', $button.attr('aria-label') || 'Exportar Excel'));
            });
        }

        function prepareExportForm(form) {
            if (!form) return;
            var state = filtersFor($table, $fragment);
            var columns = [];
            $table.find('thead th[data-column]:visible').each(function () {
                var $th = $(this), field = String($th.data('column') || ''), label = String($th.find('.column-heading span').first().text() || field).trim();
                if (field !== '') columns.push({ campo: field, rotulo: label || field });
            });
            $(form).find('input[data-grid-export-field]').remove();
            $('<input>', { type: 'hidden', name: 'filtros', value: JSON.stringify(state.filters), 'data-grid-export-field': '1' }).appendTo(form);
            $('<input>', { type: 'hidden', name: 'busca', value: state.search, 'data-grid-export-field': '1' }).appendTo(form);
            $('<input>', { type: 'hidden', name: 'colunas', value: JSON.stringify(columns), 'data-grid-export-field': '1' }).appendTo(form);
        }

        function checkExportState() {
            $.ajax({ url: new URL('export_status.php', window.location.href).toString(), data: { transacao: exportTransaction }, dataType: 'json', global: false })
                .done(function (response) { var active = !!(response && response.active); setExportState(active); if (active) { window.clearTimeout(exportTimer); exportTimer = window.setTimeout(checkExportState, 3000); } });
        }
        $(document).on('greenPortalExportRequested', function () { window.clearTimeout(exportTimer); exportTimer = window.setTimeout(checkExportState, 500); });

        function setSaveState(dirty) { $saveButton.prop('disabled', !dirty).attr('aria-disabled', dirty ? 'false' : 'true'); $form.data('grid-dirty', !!dirty); }
        function setDeleteState(selected) { $deleteButtons.filter('[data-permission-locked="1"]').prop('disabled', true); $deleteButtons.filter(':not([data-permission-locked="1"])').prop('disabled', !selected).attr('aria-disabled', selected ? 'false' : 'true').attr('title', selected ? '' : languageMessages().remove); }
        function setDuplicateState(selected) { $duplicateButtons.filter('[data-permission-locked="1"]').prop('disabled', true); $duplicateButtons.filter(':not([data-permission-locked="1"])').prop('disabled', !selected).attr('aria-disabled', selected ? 'false' : 'true').attr('title', selected ? '' : languageMessages().duplicate); }

        function render() {
            var hasNext = totalRecords !== null && currentPage * pageSize < totalRecords;
            // Formato compacto "página atual/total de páginas" (ex.: 1/9); o total
            // de registros fica na dica (title) do indicador.
            var msg = languageMessages();
            var totalPages = totalRecords === null ? null : Math.max(1, Math.ceil(totalRecords / pageSize));
            var visibleRecords = $body.find('tr').length;
            var $status = $pagination.find('.grid-page-status');
            $status.text(currentPage + '/' + (totalPages === null ? '\u2026' : totalPages));
            $status.attr('title', totalRecords === null
                ? (visibleRecords > 0 ? visibleRecords + ' ' + msg.loaded : msg.counting)
                : msg.page + ' ' + currentPage + ' ' + msg.of + ' ' + totalPages + ' \u00b7 ' + totalRecords + ' ' + msg.records);
            $pagination.find('.grid-page-prev').prop('disabled', requestInFlight || currentPage <= 1);
            $pagination.find('.grid-page-next').prop('disabled', requestInFlight || !hasNext);
        }

        function buildUrl(page) {
            var url = new URL(window.location.href);
            var state = filtersFor($table, $fragment);
            url.searchParams.set('pagina', String(Math.max(1, page)));
            if (Object.keys(state.filters).length) url.searchParams.set('filtros', JSON.stringify(state.filters)); else url.searchParams.delete('filtros');
            if (state.search) url.searchParams.set('busca', state.search); else url.searchParams.delete('busca');
            return url;
        }

        function countRecords(callback) {
            var state = filtersFor($table, $fragment);
            if (countRequest && countRequest.readyState !== 4) countRequest.abort();
            countRequest = $.ajax({ url: window.location.href, method: 'POST', data: { action: 'count_rows', filtros: JSON.stringify(state.filters), busca: state.search, ajax: 1 }, dataType: 'json' })
                .done(function (response) { totalRecords = response && response.ok ? Number(response.total || 0) : 0; render(); if (callback) callback(); })
                .fail(function (_xhr, status) { if (status !== 'abort') { totalRecords = null; render(); } });
        }

        function loadPage(page, callback) {
            if (requestInFlight) return;
            requestInFlight = true;
            render();
            var url = buildUrl(page);
            // Após uma gravação de cadastro, a resposta deve reconstruir o grid
            // no próprio iframe. O AJAX pode manter a tabela antiga quando a
            // relação acabou de ser criada, mesmo com a view já atualizada.
            if ($fragment.hasClass('cadastro-legal-fragment') && new URL(window.location.href).searchParams.has('salvo')) {
                url.searchParams.delete('salvo');
                var contextFilters = $fragment.attr('data-context-filters');
                if (contextFilters) url.searchParams.set('filtros', contextFilters);
                window.location.href = url.toString();
                return;
            }
            $.ajax({ url: url.toString(), method: 'GET', dataType: 'html' })
                .done(function (html) {
                    var parsed = $.parseHTML(String(html), document, true), $page = $(parsed), $newBody = $page.find('#' + $table.attr('id') + ' tbody').first();
                    if (!$newBody.length) { $pagination.find('.grid-page-status').text(languageMessages().failed); return; }
                    $body.replaceWith($newBody);
                    $body = $table.find('tbody');
                    currentPage = Math.max(1, page);
                    window.history.replaceState({}, '', url.toString());
                    $fragment.trigger('gridPageLoaded', [currentPage]);
                    if (callback) callback();
                    countRecords();
                })
                .fail(function () { $pagination.find('.grid-page-status').text(languageMessages().failed); })
                .always(function () { requestInFlight = false; render(); if (filterPending) window.setTimeout(reloadWithFilters, 0); });
        }

        function reloadWithFilters() {
            if (requestInFlight) { filterPending = true; return; }
            filterPending = false;
            currentPage = 1;
            totalRecords = null;
            render();
            countRecords(function () { if (totalRecords > 0) loadPage(1); else { loadPage(1); } });
        }

        checkExportState();
        setSaveState(false); setDeleteState(false); setDuplicateState(false);
        $form.on('input change', 'tbody input, tbody select, tbody textarea', function () {
            setSaveState(true);
            var $row = $(this).closest('tr'), key = String($row.data('key') || '');
            $row.attr('data-row-state', key.indexOf('novo_') === 0 ? T('comum.linha.inclusao_pendente', 'Inclusão pendente') : T('comum.linha.alteracao_pendente', 'Alteração pendente')).toggleClass('row-pending-edit', key.indexOf('novo_') !== 0).removeClass('row-operation-error');
        });
        $fragment.on('click', '[name="action"][value="export_request"],[data-export-lista],[data-export-formulario],[data-export-cadastro]', function (event) {
            if ($(this).attr('data-export-active') === '1') { event.preventDefault(); return false; }
            // O botão de exportação é o submitter do formulário. Desabilitá-lo
            // antes do envio nativo faz o navegador cancelar o POST. Submeta
            // explicitamente o formulário depois de bloquear novos cliques.
            if (this.name === 'action' && this.value === 'export_request' && this.form) {
                event.preventDefault();
                prepareExportForm(this.form);
                setExportState(true);
                HTMLFormElement.prototype.submit.call(this.form);
                return false;
            }
            setExportState(true);
        });
        $fragment.on('click', '[data-new-menu],[data-new-group],[data-new-user],[data-new-permission],[data-new-profile],[data-new-formulario],[data-new-lista],[data-new-legislacao],[data-new-legislacao-empresa],' + duplicateSelectors, function () { setSaveState(true); });
        $table.on('click', 'tbody tr', function () { $table.find('tbody tr').removeClass('selected'); $(this).addClass('selected'); setDeleteState(true); setDuplicateState(true); });

        if (!$pagination.length) $pagination = $('<nav class="grid-pagination" aria-label="Paginação do grid"></nav>').insertAfter($form);
        $pagination.find('.grid-page-prev').attr('title', languageMessages().previous).attr('aria-label', languageMessages().previous);
        $pagination.find('.grid-page-next').attr('title', languageMessages().next).attr('aria-label', languageMessages().next);
        $pagination.find('.grid-page-prev').on('click', function () { if (!requestInFlight && currentPage > 1) loadPage(currentPage - 1); });
        $pagination.find('.grid-page-next').on('click', function () { if (!requestInFlight && totalRecords !== null && currentPage * pageSize < totalRecords) loadPage(currentPage + 1); });
        function scheduleFilterReload() { window.clearTimeout(filterTimer); filterTimer = window.setTimeout(reloadWithFilters, 250); /* a espera de 3 s da digitação fica no filtro-atraso.js (11/11/2026) */ }
        // Os filtros de coluna pertencem ao table; o binding direto garante a
        // recarga server-side mesmo quando o grid troca o tbody por AJAX.
        $table.on('input change', '[data-column-filter]', scheduleFilterReload);
        $fragment.on('input change', '.crud-search input,[data-grid-filter]', scheduleFilterReload);
        $fragment.on('click', '[data-reload-grid]', function () { if (!requestInFlight) { totalRecords = null; currentPage = 1; reloadWithFilters(); } });
        normalizeToolbar();
        var $actions = $fragment.find('.crud-actions').first();
        var $context = $actions.find('.crud-context-separator, .crud-context-actions').detach();
        $actions.append($context);
        $actions.find('[data-pagination-separator]').remove();
        $('<span class="crud-pagination-separator" data-pagination-separator aria-hidden="true">|</span>').insertBefore($pagination);
        $actions.append($pagination);
        render();
        countRecords();
    });
}(jQuery));
