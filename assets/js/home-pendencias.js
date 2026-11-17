/*
 * Home › Minhas pendências (principal/home_pendencias.php).
 * As linhas vêm prontas do servidor (várias fontes juntas); aqui ficam os comportamentos do grid padrão feitos no navegador:
 * busca geral, filtro por coluna, ordenação ao clicar no título (datas pela chave AAAA-MM-DD de data-sort-value),
 * paginação na barra (25 por página, indicador "1/9" com o total na dica), Recarregar e abrir a pendência em popup.
 */
(function ($) {
    'use strict';
    function T(chave, padrao) { return typeof window.greenT === 'function' ? window.greenT(chave, padrao) : padrao; }
    function normalize(v) { return String(v || '').toLocaleLowerCase('pt-BR').normalize('NFD').replace(/[̀-ͯ]/g, '').trim(); }

    $('[data-home-pendencias]').each(function () {
        var $fragment = $(this), $table = $fragment.find('table.menu-batch-grid'), $body = $table.find('tbody');
        var $rows = $body.find('tr[data-row]'), $empty = $body.find('tr[data-vazio]'), $pagination = $fragment.find('.grid-pagination');
        var pageSize = parseInt($fragment.attr('data-page-size'), 10) || 25, page = 1, sort = { column: null, direction: 1 };

        // Paginação na barra de ações, como nos grids padrão (grid-pagination.js).
        var $actions = $fragment.find('.crud-actions').first();
        if ($actions.length) { $('<span class="crud-pagination-separator" aria-hidden="true">|</span>').appendTo($actions); $actions.append($pagination); }

        function cellText($row, column) { return String($row.children('[data-column="' + column + '"]').text() || '').trim(); }
        function sortValue($row, column) { var $cell = $row.children('[data-column="' + column + '"]'), v = $cell.attr('data-sort-value'); return v !== undefined ? v : String($cell.text() || '').trim(); }
        function matches($row) {
            var general = normalize($fragment.find('.crud-search input').val()), ok = true;
            if (general && normalize($row.text()).indexOf(general) === -1) return false;
            $table.find('[data-column-filter]').each(function () {
                var $f = $(this), value = normalize($f.val()), column = String($f.data('column-filter'));
                if (!value) return;
                var text = normalize(cellText($row, column));
                if ($f.is('select') ? text !== value : text.indexOf(value) === -1) { ok = false; return false; }
            });
            return ok;
        }
        function render() {
            var visible = $rows.filter(function () { return matches($(this)); }), total = visible.length;
            var pages = Math.max(1, Math.ceil(total / pageSize));
            if (page > pages) page = pages;
            $rows.prop('hidden', true);
            visible.slice((page - 1) * pageSize, page * pageSize).prop('hidden', false);
            var $txt = $empty.find('[data-vazio-texto]');
            $txt.text($rows.length ? $txt.attr('data-com-filtro') : $txt.attr('data-sem-filtro'));
            $empty.prop('hidden', total > 0);
            $pagination.find('.grid-page-status').text(page + '/' + pages)
                .attr('title', T('comum.paginacao.pagina', 'Página') + ' ' + page + ' ' + T('comum.paginacao.de', 'de') + ' ' + pages + ' · ' + total + ' ' + T('comum.paginacao.registros', 'registros'));
            $pagination.find('.grid-page-prev').prop('disabled', page <= 1);
            $pagination.find('.grid-page-next').prop('disabled', page >= pages);
        }
        function compare(a, b) {
            var na = Number(a.replace(',', '.')), nb = Number(b.replace(',', '.'));
            if (a !== '' && b !== '' && !isNaN(na) && !isNaN(nb)) return na - nb;
            return a.localeCompare(b, 'pt-BR', { numeric: true, sensitivity: 'base' });
        }
        function sortBy($th, column, direction) {
            sort = { column: column, direction: direction };
            $table.find('thead th').removeClass('sort-asc sort-desc');
            $table.find('.column-sort-icon').removeClass('bi-arrow-up bi-arrow-down').addClass('bi-arrow-down-up');
            $th.addClass(direction === 1 ? 'sort-asc' : 'sort-desc').find('.column-sort-icon').removeClass('bi-arrow-down-up').addClass(direction === 1 ? 'bi-arrow-up' : 'bi-arrow-down');
            var list = $rows.get().sort(function (x, y) { return compare(sortValue($(x), column), sortValue($(y), column)) * direction; });
            $.each(list, function (_, row) { $body.append(row); });
            $body.append($empty);
            $rows = $body.find('tr[data-row]');
            page = 1; render();
        }

        $table.find('thead th[data-column] .column-heading > span').append('<i class="bi bi-arrow-down-up column-sort-icon" aria-hidden="true"></i>');
        $table.on('click', 'thead th[data-column]', function (event) {
            if ($(event.target).is('input,select,option,.column-filter')) return;
            var $th = $(this), column = String($th.data('column'));
            sortBy($th, column, sort.column === column ? sort.direction * -1 : 1);
        });
        $table.on('input change', '[data-column-filter]', function () { page = 1; render(); });
        $fragment.find('.crud-search input').on('input search', function () { page = 1; render(); });
        $pagination.find('.grid-page-prev').on('click', function () { if (page > 1) { page--; render(); } });
        $pagination.find('.grid-page-next').on('click', function () { page++; render(); });
        $fragment.on('click', '[data-reload-grid]', function () { window.location.reload(); });

        // Abrir a pendência na tela de origem, em popup do portal (ou nova aba fora do portal).
        $fragment.on('click', '[data-pending-url]', function (event) {
            event.preventDefault();
            var $link = $(this), host = window.top || window, url = new URL($link.attr('data-pending-url'), window.location.href).toString();
            var title = $link.attr('data-pending-title') || T('home.coluna.pendencia', 'Pendência');
            if (host.GreenPortalPopups && host.jQuery) {
                var frame = host.jQuery('<iframe>', { title: title, style: 'width:100%;height:100%;border:0;display:block;' }).attr('src', url);
                if (typeof host.GreenPortalPopups.transacaoIndependente === 'function') host.GreenPortalPopups.transacaoIndependente({ titulo: title, conteudo: frame, amplo: true, redimensionavel: true });
                else host.GreenPortalPopups.transacao({ titulo: title, conteudo: frame });
            } else window.open(url, '_blank');
        });

        var $prazo = $table.find('thead th[data-column="PRAZO"]');
        if ($prazo.length) { sort = { column: 'PRAZO', direction: 1 }; $prazo.addClass('sort-asc').find('.column-sort-icon').removeClass('bi-arrow-down-up').addClass('bi-arrow-up'); }
        render();
        $fragment.addClass('grid-ready'); // main.css esconde .menu-grid-fragment até o grid ficar pronto
    });
}(jQuery));
