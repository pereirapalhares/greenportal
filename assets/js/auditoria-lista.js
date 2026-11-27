/*
 * Auditoria › Gestão de Auditorias / Minhas Auditorias (migração 424): ações da lista no grid padrão.
 * Clique seleciona a linha e habilita os botões [data-aud-sel]; duplo clique ou Abrir abrem a auditoria em popup
 * (auditoria_gestao.php?id=); Checklist, Constatações, RNC e Histórico abrem a auditoria na aba; Arquivos abre a pasta (GED);
 * Relatório abre o PDF. Quando a ficha grava algo, avisa pelo BroadcastChannel "greenportal-auditoria" e a lista recarrega.
 * A coluna Situação recebe a cor da etapa.
 */
(function ($) {
    'use strict';
    var $f = $('.auditoria-grid-fragment');
    if (!$f.length) return;
    var C = {};
    try { C = JSON.parse((document.getElementById('audListaCfg') || {}).textContent || '{}'); } catch (e) { C = {}; }
    var tela = C.tela || 'auditoria_gestao.php';
    var CORES = { 'Em Planejamento': '#6c757d', 'Em Avaliação': '#e0a800', 'Recusada': '#d64545', 'Em Execução': '#0d6efd', 'Encerrada': '#2e9d6a', 'Cancelada': '#343a40' };

    function selecionada() {
        var $r = $f.find('.menu-batch-grid tbody tr.selected').first(), id = String($r.data('key') || '').trim();
        return /^\d+$/.test(id) ? id : '';
    }
    function habilitar() { var id = selecionada(); $f.find('[data-aud-sel]').prop('disabled', !id); }
    function colorir() {
        $f.find('.menu-batch-grid tbody td[data-column="SITUACAO"] input').each(function () {
            var cor = CORES[String(this.value || '').trim()] || '';
            this.style.borderLeft = cor ? '4px solid ' + cor : '';
            this.style.fontWeight = cor ? '600' : '';
        });
        $f.find('.menu-batch-grid tbody td[data-column="ATRASADA"] input').each(function () {
            var sim = /^(Sim|Yes|Sí)$/i.test(String(this.value || '').trim());
            this.style.color = sim ? '#b42318' : ''; this.style.fontWeight = sim ? '600' : '';
        });
    }
    function host() {
        try { if (window.parent && window.parent !== window && window.parent.GreenPortalPopups) return window.parent; } catch (e) {}
        try { if (window.top && window.top.GreenPortalPopups) return window.top; } catch (e) {}
        return window;
    }
    function popup(url, titulo) {
        var h = host(), abs = new URL(url, window.location.href).toString();
        if (h.GreenPortalPopups && h.jQuery) {
            var $fr = h.jQuery('<iframe>', { title: titulo, src: abs, style: 'width:100%;height:100%;border:0;display:block;' });
            h.GreenPortalPopups.transacaoIndependente({ id: 'greenPortalAuditoriaModal', titulo: titulo, conteudo: $fr, amplo: true, redimensionavel: true });
        } else window.open(abs, '_blank');
    }
    function abrir(id, aba) {
        popup(tela + '?id=' + encodeURIComponent(id) + (C.minhas ? '&minhas=1' : '') + (aba ? '&aba=' + encodeURIComponent(aba) : ''), (C.titulo || 'Auditoria') + ' #' + id);
    }

    $f.on('click', '.menu-batch-grid tbody tr', function () { window.setTimeout(habilitar, 0); });
    $f.on('dblclick', '.menu-batch-grid tbody tr', function (ev) {
        var id = String($(this).data('key') || '').trim();
        if (!/^\d+$/.test(id)) return;
        ev.preventDefault(); ev.stopPropagation(); abrir(id, '');
    });
    $f.on('click', '[data-aud-nova]', function () {
        popup(tela + '?novo=1' + (C.fornecedor ? '&CODFORNECEDOR=' + encodeURIComponent(C.fornecedor) : ''), C.nova || 'Nova auditoria');
    });
    $f.on('click', '[data-aud-abrir]', function () { var id = selecionada(); if (id) abrir(id, ''); });
    $f.on('click', '[data-aud-aba]', function () { var id = selecionada(); if (id) abrir(id, String($(this).attr('data-aud-aba') || '')); });
    $f.on('click', '[data-aud-arquivos]', function () {
        var id = selecionada(); if (!id) return;
        var h = host(), url = (h.greenPortalBaseUrl || '..') + '/principal/gestao_arquivos.php?tipo=auditoria&chave=CODAUDITORIA&valor=' + encodeURIComponent(id);
        popup(url, (C.arquivos || 'Arquivos') + ' #' + id);
    });
    $f.on('click', '[data-aud-relatorio]', function () { var id = selecionada(); if (id) popup('auditoria_relatorio.php?id=' + encodeURIComponent(id), (C.relatorio || 'Relatório') + ' #' + id); });

    $(document).on('gridPageLoaded', function () { habilitar(); colorir(); });
    var obs = new MutationObserver(function () { colorir(); });
    $f.find('.menu-batch-grid tbody').each(function () { obs.observe(this, { childList: true }); });
    colorir(); habilitar();

    // a ficha (popup) gravou algo: recarrega a lista mantendo filtros e página
    try {
        var canal = new BroadcastChannel('greenportal-auditoria');
        canal.onmessage = function () { var b = $f.find('[data-crud-action="reload"]').get(0); if (b) b.click(); };
    } catch (e) { /* navegador sem BroadcastChannel: o usuário recarrega pelo botão */ }
}(jQuery));
