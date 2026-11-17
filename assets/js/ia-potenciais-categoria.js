(function () {
    'use strict';
    function init() {
        var fragment = document.querySelector('[data-ia-category]');
        if (!fragment) return;
        fragment.classList.remove('grid-is-loading');
        fragment.classList.add('grid-ready');
        var table = fragment.querySelector('.menu-batch-grid'), scroll = fragment.querySelector('.menu-grid-scroll'), rows = Array.prototype.slice.call(fragment.querySelectorAll('tbody tr[data-row]'));
        if (!table || !scroll) return;
        var search = fragment.querySelector('[data-grid-search]'), status = fragment.querySelector('[data-grid-status]'), pager = fragment.querySelector('.grid-pagination'), pageStatus = fragment.querySelector('.grid-page-status'), previous = fragment.querySelector('.grid-page-prev'), next = fragment.querySelector('.grid-page-next'), page = 1, size = 10;
        var actions = fragment.querySelector('.crud-actions');
        if (pager) {
            if (previous && pageStatus) pager.insertBefore(previous, pageStatus);
            if (next) pager.appendChild(next);
            if (actions) {
                var separator = document.createElement('span'); separator.className = 'crud-pagination-separator'; separator.setAttribute('data-pagination-separator', ''); separator.setAttribute('aria-hidden', 'true'); separator.textContent = '|';
                actions.appendChild(separator); actions.appendChild(pager);
            }
        }
        var filters = fragment.querySelector('.crud-search');
        if (filters) {
            filters.classList.add('ia-potenciais-filters');
            filters.style.display = 'flex'; filters.style.alignItems = 'center'; filters.style.justifyContent = 'flex-end'; filters.style.gap = '8px'; filters.style.width = 'min(100%, 460px)';
            var searchIcon = filters.querySelector('i'); if (searchIcon) searchIcon.style.display = 'none';
            status?.classList.remove('mt-1');
            if (status && search) filters.insertBefore(status, search);
            if (status) { status.style.width = '150px'; status.style.flex = '0 0 150px'; status.style.marginTop = '0'; }
            if (search) { search.style.minWidth = '0'; search.style.flex = '1'; }
        }
        var selectionHeader = table.querySelector('thead th:first-child');
        if (['LICENCA', 'MONITORAMENTO', 'TREINAMENTO', 'INFRAESTRUTURA', 'CONTROLE'].indexOf(fragment.getAttribute('data-ia-category')) >= 0) {
            var headerRow = table.querySelector('thead tr'), statusHeader = headerRow?.cells[5], confidenceHeader = headerRow?.cells[4];
            if (headerRow && statusHeader && confidenceHeader) {
                headerRow.insertBefore(statusHeader, headerRow.cells[1]);
                headerRow.insertBefore(confidenceHeader, headerRow.cells[2]);
                rows.forEach(function (row) {
                    var statusCell = row.cells[5], confidenceCell = row.cells[4];
                    if (statusCell && confidenceCell) { row.insertBefore(statusCell, row.cells[1]); row.insertBefore(confidenceCell, row.cells[2]); }
                });
            }
        }
        if (selectionHeader) selectionHeader.style.display = 'none';
        rows.forEach(function (row) { var cell = row.querySelector('td:first-child'); if (cell) cell.style.display = 'none'; });
        fragment.querySelector('[data-reload-grid]')?.addEventListener('click', function () { window.location.reload(); });
        function render() {
            var term = String(search?.value || '').trim().toLocaleLowerCase(), wanted = String(status?.value || ''), visible = rows.filter(function (row) { var badge = row.querySelector('.badge'), current = badge ? badge.textContent.trim().toUpperCase() : ''; return (!term || row.textContent.toLocaleLowerCase().indexOf(term) >= 0) && (!wanted || current === wanted || (wanted === 'APROVADO' && current === 'CONVERTIDO')); });
            var pages = Math.max(1, Math.ceil(visible.length / size)); page = Math.min(page, pages); rows.forEach(function (row) { row.classList.add('grid-page-hidden'); }); visible.slice((page - 1) * size, page * size).forEach(function (row) { row.classList.remove('grid-page-hidden'); });
            if (pageStatus) pageStatus.textContent = visible.length + ' registros · Página ' + page;
            if (previous) previous.disabled = page <= 1; if (next) next.disabled = page >= pages;
        }
        search?.addEventListener('input', function () { page = 1; render(); }); status?.addEventListener('change', function () { page = 1; render(); }); previous?.addEventListener('click', function () { if (page > 1) { page--; render(); } }); next?.addEventListener('click', function () { page++; render(); }); render();
    }
    document.addEventListener('DOMContentLoaded', init);
}());
