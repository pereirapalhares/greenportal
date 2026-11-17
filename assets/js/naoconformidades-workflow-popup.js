(function ($) {
    'use strict';
    // Lista de ocorrências de NC: duplo clique abre a ocorrência na tela única (naoconformidades_ocorrencia.php, c491),
    // com as abas Ocorrência, Avaliação do gestor, Análise da causa, Verificação da eficácia e Histórico.
    var $fragment = $('.nao-conformidades-grid-fragment');
    if (!$fragment.length) return;

    function openWorkflow($row) {
        var id = String($row.data('key') || '').trim();
        if (!/^\d+$/.test(id)) return;
        var url = 'naoconformidades_ocorrencia.php?codigo=' + encodeURIComponent(id);
        var host = window.parent && window.parent.GreenPortalPopups ? window.parent : window;
        if (host.GreenPortalPopups && host.jQuery) {
            var abs = new URL(url, window.location.href).toString();
            var $frame = host.jQuery('<iframe>', { title: 'Não conformidade ' + id, src: abs, style: 'width:100%;height:100%;border:0;display:block;' });
            host.GreenPortalPopups.transacaoIndependente({ id: 'greenPortalNcWorkflowModal', titulo: 'Não conformidade ' + id, conteudo: $frame, amplo: true, redimensionavel: true });
        } else window.open(url, '_blank');
    }

    $fragment.find('.menu-batch-grid').on('dblclick', 'tbody tr', function (event) {
        event.preventDefault(); event.stopPropagation(); openWorkflow($(this));
    });
}(jQuery));
