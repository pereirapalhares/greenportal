/*
 * Homologação › Fornecedor › Plano de ação: botão "Abrir RNC do fornecedor" (Revisão G item 148, 26/11/2026).
 * Abre uma nova ocorrência de Não conformidade do tipo Fornecedor já com o fornecedor, o sumário e a descrição vindos da
 * ação selecionada. A ocorrência só é criada quando o usuário salvar. Só é carregado para quem pode incluir NC de Fornecedor.
 */
(function ($) {
    'use strict';
    function T(k, p) { return typeof window.greenT === 'function' ? window.greenT('homologacao_plano_rnc.' + k, p) : p; }
    $('.homologacao-fornecedor-planos-grid-fragment').each(function () {
        var $f = $(this), $t = $f.find('.menu-batch-grid').first();
        var $b = $('<button type="button" class="btn btn-sm btn-outline-danger grid-crud-button" data-plano-rnc disabled><i class="bi bi-exclamation-octagon"></i></button>')
            .attr('title', T('botao', 'Abrir RNC do fornecedor a partir da ação selecionada'));
        $f.find('.crud-actions').first().append($b);
        function linha() { var $r = $t.find('tbody tr.selected').first(); return $r.length && /^\d+$/.test(String($r.attr('data-key') || '')) ? $r : null; }
        function valor($r, c) { var $c = $r.find('[data-column="' + c + '"]'); return $.trim(String($c.find('select').val() || $c.find('textarea, input').first().val() || $c.text() || '')); }
        $t.on('click', 'tbody tr', function () { window.setTimeout(function () { $b.prop('disabled', !linha()); }, 0); });
        $f.on('gridPageLoaded', function () { $b.prop('disabled', !linha()); });
        $b.on('click', function () {
            var $r = linha(); if (!$r) return;
            var id = $r.attr('data-key'), acao = valor($r, 'ACAO'), forn = valor($r, 'CODFORNECEDOR');
            if (!forn) { try { forn = String((JSON.parse(new URLSearchParams(location.search).get('filtros') || '{}') || {}).CODFORNECEDOR || ''); } catch (e) { forn = ''; } }
            var pre = { CODFORNECEDOR: forn, IDENTIFICACAO: (T('sumario', 'Plano de ação') + ' #' + id + ': ' + acao).substring(0, 150),
                DESCRICAO: T('descricao', 'Aberta a partir da ação #{id} do plano de ação do fornecedor (Homologação de fornecedores).').replace('{id}', id) + '\n\n' + acao };
            var host = window.top || window, base = String(host.greenPortalBaseUrl || '..'), url = base + '/principal/naoconformidades_ocorrencia.php?tipo=1&prefill=' + encodeURIComponent(JSON.stringify(pre));
            if (host.GreenPortalPopups && host.jQuery) {
                var $frame = host.jQuery('<iframe>', { src: url, style: 'width:100%;height:100%;border:0' });
                if (typeof host.GreenPortalPopups.transacaoIndependente === 'function') host.GreenPortalPopups.transacaoIndependente({ titulo: T('titulo', 'Nova não conformidade de fornecedor'), conteudo: $frame, amplo: true, redimensionavel: true });
                else host.GreenPortalPopups.transacao({ titulo: T('titulo', 'Nova não conformidade de fornecedor'), conteudo: $frame });
            } else window.open(url, '_blank');
        });
    });
}(jQuery));
