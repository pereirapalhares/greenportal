/* Portaria › Documentação de Fornecedor, de Funcionário e Treinamentos do funcionário.
   - Coluna "Situação" (campo SITUACAO_INDICADOR = SITUACAO_COR da view; no Excel, SITUACAO_DESCRICAO): mostra o ícone da cor + o texto (ex.: ● Reprovado) e pinta a linha.
   - Filtro de situação à esquerda da busca geral: combobox com ícone + texto; filtra no servidor (campo SITUACAO_COR).
   - Recarrega o grid quando a análise de IA de uma evidência é confirmada.
   Os textos de cada tela vêm do bloco <script type="application/json" data-pdf-situacoes> da página. */
(function ($) {
    'use strict';
    $(function () {
        var $fragment = $('.portaria-documentacao-fornecedor-grid-fragment, .portaria-documentacao-funcionario-grid-fragment, .portaria-documentacao-treinamento-grid-fragment').first();
        if (!$fragment.length) return;
        var cfg = {};
        try { cfg = JSON.parse($('script[data-pdf-situacoes]').first().text() || '{}') || {}; } catch (ignore) { cfg = {}; }
        var itens = cfg.itens || {}, ordem = Object.keys(itens);
        var rotulo = function (c) { return itens[c] || itens['sem-situacao'] || c; };
        var icone = function (c) { return $('<i>', { 'class': 'pdf-legenda-cor pdf-cor-' + c, 'aria-hidden': 'true' }); };

        // Filtro (campo escondido lido pela paginação do grid: data-grid-filter)
        var $campo = $('<input>', { type: 'hidden', 'data-grid-filter': 'SITUACAO_COR', value: '' });
        var $filtro = $('<div>', { 'class': 'pdf-filtro-situacao' });
        var $botao = $('<button>', { type: 'button', 'class': 'form-select form-select-sm pdf-filtro-botao', role: 'combobox', 'aria-haspopup': 'listbox', 'aria-expanded': 'false', title: cfg.titulo || '', 'aria-label': cfg.titulo || '' });
        var $lista = $('<ul>', { 'class': 'pdf-filtro-lista', role: 'listbox', hidden: true });
        function item(c, texto) {
            return $('<li>', { role: 'option', tabindex: 0, 'data-valor': c }).append(c ? icone(c) : $('<i>', { 'class': 'bi bi-funnel pdf-filtro-todas', 'aria-hidden': 'true' }), $('<span>').text(texto));
        }
        $lista.append(item('', cfg.todas || ''));
        ordem.forEach(function (c) { $lista.append(item(c, itens[c])); });
        function mostrar(valor) {
            $botao.empty().append(valor ? icone(valor) : $('<i>', { 'class': 'bi bi-funnel pdf-filtro-todas', 'aria-hidden': 'true' }), $('<span>', { 'class': 'pdf-filtro-texto' }).text(valor ? rotulo(valor) : (cfg.todas || '')));
            $lista.children().attr('aria-selected', 'false').filter(function () { return String($(this).attr('data-valor')) === valor; }).attr('aria-selected', 'true');
        }
        function abrir(sim) { $lista.prop('hidden', !sim); $botao.attr('aria-expanded', sim ? 'true' : 'false'); }
        $filtro.append($campo, $botao, $lista);
        $botao.on('click', function (e) { e.stopPropagation(); abrir($lista.prop('hidden')); });
        $lista.on('click keydown', 'li', function (e) {
            if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
            e.preventDefault();
            var valor = String($(this).attr('data-valor') || '');
            abrir(false);
            if (valor === String($campo.val() || '')) return;
            $campo.val(valor); mostrar(valor); $campo.trigger('change');
        });
        // Teclado: seta para baixo abre; setas navegam; Esc fecha
        $botao.on('keydown', function (e) { if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); abrir(true); ($lista.children('[aria-selected="true"]').first()[0] || $lista.children().first()[0]).focus(); } });
        $lista.on('keydown', 'li', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); ($(this).next()[0] || this).focus(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); ($(this).prev()[0] || this).focus(); }
            else if (e.key === 'Escape') { abrir(false); $botao.trigger('focus'); }
        });
        $(document).on('click', function (e) { if (!$(e.target).closest($filtro).length) abrir(false); });
        $(document).on('keydown', function (e) { if (e.key === 'Escape') abrir(false); });
        mostrar('');
        // À esquerda da busca geral da barra do grid
        function posicionar() {
            if ($.contains($fragment[0], $filtro[0])) return;
            var $busca = $fragment.find('.menu-grid-toolbar > .crud-search, .crud-toolbar .crud-search').first();
            if ($busca.length) $filtro.insertBefore($busca); else $fragment.find('.menu-grid-toolbar, .crud-toolbar').first().append($filtro);
        }

        // Coluna Situação: ícone + texto; a linha recebe a cor
        function pintar() {
            $fragment.find('.menu-batch-grid').first().find('tbody tr[data-key]').each(function () {
                var $row = $(this), $cell = $row.find('td[data-column="SITUACAO_INDICADOR"], td[data-column="SITUACAO_COR"]').first();
                var c = $.trim(String($cell.find('input,select').first().val() || $cell.attr('data-pdf-valor') || '')) || 'sem-situacao';
                $row.attr('data-pdf-cor', c);
                if ($cell.length && !$cell.find('.pdf-situacao').length) {
                    $cell.attr('data-pdf-valor', c).find('input,select').prop('hidden', true);
                    $cell.append($('<span>', { 'class': 'pdf-situacao', title: rotulo(c) }).append(icone(c), $('<span>').text(rotulo(c))));
                }
            });
            posicionar();
        }
        // Botões de associação com título "<base> - <valor da coluna>" (ex.: Treinamentos do funcionário - Fulano):
        // o título é ajustado antes do clique, pela linha selecionada.
        $fragment.on('mousedown keydown focusin', '[data-pdf-titulo-campo]', function () {
            var $b = $(this), campo = String($b.attr('data-pdf-titulo-campo') || ''), base = String($b.attr('data-pdf-titulo-base') || '');
            var $cell = $fragment.find('.menu-batch-grid tbody tr.selected').first().find('td[data-column="' + campo + '"]').first();
            var nome = $.trim(String($cell.find('input,select').first().val() || $cell.text() || ''));
            var titulo = nome ? base + ' - ' + nome : base;
            $b.attr('data-context-title', titulo).data('context-title', titulo);
        });
        $fragment.on('gridPageLoaded.pdfCores', function () { window.setTimeout(pintar, 0); });
        // Depois de confirmar a análise de IA na janela de arquivos, o grid recarrega (nova situação, validade e cor).
        try {
            var host = window.top || window;
            host.addEventListener('message', function (ev) {
                if (ev.origin !== window.location.origin || !ev.data || !/^gedDocumento(Fornecedor|Funcionario)Atualizado$/.test(String(ev.data.tipo || ''))) return;
                try { if (document.body && document.body.contains($fragment[0])) $fragment.find('[data-reload-grid]').first().trigger('click'); } catch (ignore) {}
            });
        } catch (ignore) {}
        pintar();
    });
}(jQuery));
