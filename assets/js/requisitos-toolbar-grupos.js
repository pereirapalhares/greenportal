/*
 * Requisitos legais — mantém os botões da barra nos grupos Operações,
 * Correlacionamentos e Avaliação. Carregado depois de grid-pagination.js, que
 * reordena botões padrão (Recarregar, Excel, Colunas) no fim da barra.
 */
(function ($) {
    'use strict';
    function agrupar($fragment) {
        $fragment.find('[data-req-grupo]').each(function () {
            var $grupo = $(this), id = String($grupo.data('req-grupo')), $destino = $grupo.children('.req-grupo-botoes');
            var $botoes = $fragment.find('[data-req-slot^="' + id + '-"]').get().sort(function (a, b) {
                return String(a.getAttribute('data-req-slot')).localeCompare(String(b.getAttribute('data-req-slot')));
            });
            $botoes.forEach(function (botao) { $(botao).addClass('grid-icon-button'); $destino.append(botao); });
        });
        // A paginação fica sempre depois dos grupos.
        var $acoes = $fragment.find('[data-req-toolbar]').first();
        $acoes.append($acoes.children('[data-pagination-separator]')).append($acoes.children('.grid-pagination'));
    }
    $('.modulo-legal-requisitos-fragment').each(function () {
        var $fragment = $(this);
        agrupar($fragment);
        $fragment.on('gridPageLoaded', function () { agrupar($fragment); });
    });
}(jQuery));
