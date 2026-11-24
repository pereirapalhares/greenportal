/*
 * Grid local: grids do padrão do portal cujas linhas já vêm prontas do servidor (várias fontes juntas), como
 * Home › Minhas pendências, Agenda e Alertas. Marcação: section.menu-grid-fragment[data-grid-local] com a barra padrão
 * (greenPortalCrudButtons), table.menu-batch-grid, tr[data-row], tr[data-vazio] e nav.grid-pagination.
 * Atributos: data-page-size (25), data-default-sort-column, data-default-sort-direction (asc|desc).
 * Comportamentos do grid padrão feitos no navegador:
 * busca geral, filtro por coluna, ordenação ao clicar no título (datas pela chave AAAA-MM-DD de data-sort-value),
 * paginação na barra (25 por página, indicador "1/9" com o total na dica), Recarregar e abrir a pendência em popup.
 * c537: ações da linha selecionada na barra (data-grid-acao-linha / data-linha-acao), duplo clique (data-acao-duplo) e coluna de
 * seleção em lote (data-grid-marcar-pagina / data-grid-marcar).
 * c568 (dashboards, migração 368): window.greenPortalGridLocal.iniciar(raiz) monta os grids que entram depois na página (abas
 * carregadas por fetch); cada grid é montado uma vez. Filtro por grupo: linhas com data-grupo="A B" e o evento
 * 'grid-local-grupo' {valor, rotulo} na section (valor '' limpa; aparece "Mostrando: … ×" na barra). Recarregar dispara o evento
 * cancelável 'grid-local-recarregar' antes de recarregar a página (o dashboard recarrega só a aba). data-grupo-inicial (+ -rotulo)
 * na section: filtro de grupo já aplicado ao abrir.
 * c574 (v7): section.gridLocalFiltradas() devolve as linhas filtradas de todas as páginas (Gestão de pendências).
 * v8 (24/11/2026): filtros ativos lidos uma vez por render e colunas ocultas por CSS — grids com milhares de linhas (Homes).
 */
(function ($) {
    'use strict';
    function T(chave, padrao) { return typeof window.greenT === 'function' ? window.greenT(chave, padrao) : padrao; }
    function normalize(v) { return String(v || '').toLocaleLowerCase('pt-BR').normalize('NFD').replace(/[̀-ͯ]/g, '').trim(); }

    function montar() {
        var $fragment = $(this);
        if ($fragment.attr('data-grid-pronto') === '1') return;
        $fragment.attr('data-grid-pronto', '1');
        var grupo = '', $grupoAviso = $();
        var $table = $fragment.find('table.menu-batch-grid'), $body = $table.find('tbody');
        var $rows = $body.find('tr[data-row]'), $empty = $body.find('tr[data-vazio]'), $pagination = $fragment.find('.grid-pagination');
        var pageSize = parseInt($fragment.attr('data-page-size'), 10) || 25, page = 1, sort = { column: null, direction: 1 };

        // Paginação na barra de ações, como nos grids padrão (grid-pagination.js).
        var $actions = $fragment.find('.crud-actions').first();
        if ($actions.length) { $('<span class="crud-pagination-separator" aria-hidden="true">|</span>').appendTo($actions); $actions.append($pagination); }

        function cellText($row, column) { return String($row.children('[data-column="' + column + '"]').text() || '').trim(); }
        function sortValue($row, column) { var $cell = $row.children('[data-column="' + column + '"]'), v = $cell.attr('data-sort-value'); return v !== undefined ? v : String($cell.text() || '').trim(); }
        // v8 (24/11/2026, Homes com milhares de linhas): os filtros ativos são lidos uma vez por render, não uma vez por linha
        // (com 4.600 linhas a montagem levava 6 s).
        var filtrosAtivos = null;
        function lerFiltros() {
            var lista = [];
            $table.find('[data-column-filter]').each(function () {
                var $f = $(this), value = normalize($f.val());
                if (value) lista.push({ column: String($f.data('column-filter')), value: value, exato: $f.is('select') });
            });
            return { general: normalize($fragment.find('.crud-search input').val()), colunas: lista };
        }
        function matches($row) {
            if (grupo !== '' && (' ' + String($row.attr('data-grupo') || '') + ' ').indexOf(' ' + grupo + ' ') === -1) return false;
            var fa = filtrosAtivos || lerFiltros();
            if (fa.general && normalize($row.text()).indexOf(fa.general) === -1) return false;
            for (var i = 0; i < fa.colunas.length; i++) {
                var c = fa.colunas[i], text = normalize(cellText($row, c.column));
                if (c.exato ? text !== c.value : text.indexOf(c.value) === -1) return false;
            }
            return true;
        }
        // c574 (v7): linhas que passam no filtro (busca, colunas e grupo), de todas as páginas — ex.: "Limpar todas as filtradas"
        this.gridLocalFiltradas = function () { filtrosAtivos = lerFiltros(); try { return $rows.filter(function () { return matches($(this)); }).get(); } finally { filtrosAtivos = null; } };
        function render() {
            filtrosAtivos = lerFiltros();
            var visible = $rows.filter(function () { return matches($(this)); }), total = visible.length;
            filtrosAtivos = null;
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
        $fragment.on('click', '[data-reload-grid]', function () {
            var ev = new CustomEvent('grid-local-recarregar', { bubbles: true, cancelable: true });
            if (this.dispatchEvent(ev)) window.location.reload();
        });
        // Filtro por grupo (cartões dos dashboards): mostra só as linhas com o grupo em data-grupo
        $fragment.on('grid-local-grupo', function (event) {
            var d = (event.originalEvent && event.originalEvent.detail) || event.detail || {};
            grupo = String(d.valor || '');
            $grupoAviso.remove();
            if (grupo !== '') {
                $grupoAviso = $('<span class="grid-local-grupo-aviso badge text-bg-success ms-2" style="cursor:pointer"></span>')
                    .attr('title', T('comum.filtro.limpar', 'Limpar filtro'))
                    .text(String(d.rotulo || grupo) + ' ×')
                    .on('click', function () { grupo = ''; $grupoAviso.remove(); page = 1; render(); });
                $fragment.find('.crud-search').before($grupoAviso);
            }
            page = 1; render();
        });

        // Abrir a pendência na tela de origem, em popup do portal (ou nova aba fora do portal).
        // data-abrir-aba (Home › Alertas, c517): abre como aba do portal (main.php), reaproveitando a aba da mesma tela.
        function abrir(urlRelativa, title) {
            if (!urlRelativa) return;
            var host = window.top || window, url = new URL(urlRelativa, window.location.href).toString();
            title = title || document.title || '';
            if ($fragment.is('[data-abrir-aba]') && typeof host.greenPortalOpenTab === 'function') {
                var u = new URL(url), id = 'link-' + (u.pathname + u.search).replace(/[^a-z0-9]+/gi, '-').replace(/^-+|-+$/g, '').toLowerCase().slice(-80);
                var m = /^(NC [^:]+):/.exec(String(title)), curto = m ? m[1] : String(title);
                host.greenPortalOpenTab(id, url, curto.length > 40 ? curto.slice(0, 40) + '…' : curto);
                return;
            }
            if (host.GreenPortalPopups && host.jQuery) {
                var frame = host.jQuery('<iframe>', { title: title, style: 'width:100%;height:100%;border:0;display:block;' }).attr('src', url);
                if (typeof host.GreenPortalPopups.transacaoIndependente === 'function') host.GreenPortalPopups.transacaoIndependente({ titulo: title, conteudo: frame, amplo: true, redimensionavel: true });
                else host.GreenPortalPopups.transacao({ titulo: title, conteudo: frame });
            } else window.open(url, '_blank');
        }
        $fragment.on('click', '[data-grid-abrir]', function (event) {
            event.preventDefault();
            abrir($(this).attr('data-grid-abrir'), $(this).attr('data-grid-titulo'));
        });

        // Seleção de linha como no grid padrão: o clique seleciona e habilita o botão Abrir da barra; duplo clique abre.
        var $abrirSel = $fragment.find('[data-grid-abrir-selecionado]');
        // Ações da linha selecionada (c537): botão da barra com data-grid-acao-linha="nome" fica habilitado quando a linha selecionada
        // tem um elemento [data-linha-acao="nome"] (normalmente escondido na linha) e, ao clicar, aciona esse elemento. Assim Editar,
        // Excluir, Abrir etc. ficam na barra, como no grid padrão, e o comportamento continua sendo o do botão da linha.
        // data-acao-duplo="nome" na section: ação do duplo clique quando a linha não tem data-grid-url.
        var $acoesLinha = $fragment.find('[data-grid-acao-linha]');
        function acaoDaLinha($row, nome) { return $row && $row.length ? $row.find('[data-linha-acao="' + nome + '"]').first() : $(); }
        function atualizarAcoes($row) {
            $acoesLinha.each(function () {
                var $b = $(this);
                if ($b.is('[data-permission-locked]')) return;
                $b.prop('disabled', !acaoDaLinha($row, String($b.attr('data-grid-acao-linha'))).length);
            });
        }
        atualizarAcoes($());
        $acoesLinha.on('click', function (event) {
            event.preventDefault();
            var $el = acaoDaLinha($rows.filter('.selected').first(), String($(this).attr('data-grid-acao-linha')));
            if ($el.length) $el[0].click();
        });
        function selecionar($row) {
            $rows.removeClass('selected');
            $row.addClass('selected');
            var temUrl = !!$row.attr('data-grid-url');
            $abrirSel.prop('disabled', !temUrl).attr('title', temUrl ? $abrirSel.attr('data-titulo-ativo') : $abrirSel.attr('data-titulo-inativo'));
            atualizarAcoes($row);
        }
        $body.on('click', 'tr[data-row]', function (event) { if (!$(event.target).closest('button,a,input,select,textarea,label').length) selecionar($(this)); });
        $body.on('dblclick', 'tr[data-row]', function (event) {
            if ($(event.target).closest('button,a,input,select,textarea').length) return;
            var $r = $(this); selecionar($r);
            if ($r.attr('data-grid-url')) { abrir($r.attr('data-grid-url'), $r.attr('data-grid-titulo')); return; }
            var duplo = String($fragment.attr('data-acao-duplo') || ''), $el = duplo ? acaoDaLinha($r, duplo) : $();
            if ($el.length) $el[0].click();
        });
        // Coluna de seleção (lote): a caixa do cabeçalho marca/desmarca as linhas visíveis (página e filtro atuais)
        $table.on('change', '[data-grid-marcar-pagina]', function () {
            var marcar = this.checked;
            $rows.filter(':not([hidden])').find('[data-grid-marcar]:not(:disabled)').prop('checked', marcar);
        });
        $abrirSel.on('click', function (event) {
            event.preventDefault();
            var $r = $rows.filter('.selected').first();
            if ($r.length) abrir($r.attr('data-grid-url'), $r.attr('data-grid-titulo'));
        });

        // Colunas (como o botão Colunas do grid padrão): mostrar/ocultar; a escolha fica neste navegador, por tela.
        var chaveColunas = 'gridLocalColunas:' + String($fragment.attr('data-transacao') || window.location.pathname);
        // v8: colunas ocultas por uma regra de CSS do grid (antes: .toggle() em cada célula, lento com milhares de linhas)
        var idEstilo = 'gridLocalColunasCss_' + String($table.attr('id') || Math.random().toString(36).slice(2));
        function aplicarColunas(ocultas) {
            var sel = '#' + ($table.attr('id') ? $table.attr('id').replace(/[^A-Za-z0-9_-]/g, '') : '');
            if (!$table.attr('id')) { $table.find('[data-column]').each(function () { $(this).toggle(ocultas.indexOf(String($(this).attr('data-column'))) === -1); }); return; }
            var css = ocultas.map(function (c) { var k = String(c).replace(/["\\]/g, ''); return sel + ' [data-column="' + k + '"]{display:none!important}'; }).join('');
            var el = document.getElementById(idEstilo);
            if (!el) { el = document.createElement('style'); el.id = idEstilo; document.head.appendChild(el); }
            el.textContent = css;
        }
        try { var salvas = JSON.parse(window.localStorage.getItem(chaveColunas) || '[]'); if ($.isArray(salvas) && salvas.length) aplicarColunas(salvas); } catch (e) { }
        $fragment.on('click', '[data-columns]', function () {
            var corpo = $('<div class="row g-2"></div>');
            $table.find('thead th[data-column]').each(function () {
                var col = String($(this).attr('data-column')), rotulo = $(this).find('.column-heading > span').first().text().trim();
                corpo.append($('<div class="col-md-6"></div>').append($('<label class="form-check"></label>')
                    .append($('<input class="form-check-input" type="checkbox">').attr('data-coluna', col).prop('checked', $(this).is(':visible')))
                    .append($('<span class="form-check-label"></span>').text(' ' + rotulo))));
            });
            $('#gridLocalColunasModal').remove();
            var $modal = $('<div class="modal fade" id="gridLocalColunasModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"></div><div class="modal-footer"><button type="button" class="btn btn-sm btn-success" data-aplicar-colunas></button></div></div></div></div>');
            $modal.find('.modal-title').text(T('comum.colunas.titulo', 'Colunas do grid'));
            $modal.find('.modal-body').append(corpo);
            $modal.find('[data-aplicar-colunas]').text(T('comum.botao.aplicar', 'Aplicar')).on('click', function () {
                var ocultas = [];
                $modal.find('[data-coluna]').each(function () { if (!this.checked) ocultas.push(String($(this).attr('data-coluna'))); });
                aplicarColunas(ocultas);
                try { window.localStorage.setItem(chaveColunas, JSON.stringify(ocultas)); } catch (e) { }
                bootstrap.Modal.getOrCreateInstance($modal[0]).hide();
            });
            $('body').append($modal);
            bootstrap.Modal.getOrCreateInstance($modal[0]).show();
        });

        // Ordem inicial (as linhas já vêm nessa ordem do servidor): só marca o ícone da coluna.
        var defaultColumn = String($fragment.attr('data-default-sort-column') || ''), $default = defaultColumn ? $table.find('thead th[data-column="' + defaultColumn + '"]') : $();
        if ($default.length) {
            var desc = String($fragment.attr('data-default-sort-direction') || 'asc').toLowerCase() === 'desc';
            sort = { column: defaultColumn, direction: desc ? -1 : 1 };
            $default.addClass(desc ? 'sort-desc' : 'sort-asc').find('.column-sort-icon').removeClass('bi-arrow-down-up').addClass(desc ? 'bi-arrow-down' : 'bi-arrow-up');
        }
        render();
        // Filtro inicial por grupo (ex.: só as que precisam de ação), removível pelo × da barra
        if ($fragment.attr('data-grupo-inicial')) $fragment[0].dispatchEvent(new CustomEvent('grid-local-grupo', { detail: { valor: $fragment.attr('data-grupo-inicial'), rotulo: $fragment.attr('data-grupo-inicial-rotulo') || '' } }));
        $fragment.addClass('grid-ready'); // main.css esconde .menu-grid-fragment até o grid ficar pronto
    }
    function iniciar(raiz) { $(raiz || document).find('[data-grid-local]').addBack('[data-grid-local]').each(montar); }
    window.greenPortalGridLocal = { iniciar: iniciar };
    iniciar(document);
}(jQuery));
