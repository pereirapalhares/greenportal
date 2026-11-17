/* Migração 349 — Gestão de Arquivos › aba "Adulteração" (documentos de fornecedor e de funcionário da homologação).
 * Lista as análises de adulteração do registro, marca cada arquivo da Lista de arquivos com o risco, mostra os indícios e registra
 * a ciência com o motivo (exigida para aprovar documento de risco alto). Usa as variáveis da página (api, params, data, selected).
 * Textos em window.gedAdulteracaoTextos (IdiomaService). A análise só aponta: a decisão do documento é de quem revisa. */
(function ($) {
    'use strict';
    if (!$ || typeof api === 'undefined' || typeof params === 'undefined') return;
    var T = window.gedAdulteracaoTextos || {};
    var t = function (k) { return T[k] || k; };
    var esc = function (v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
    var fmt = function (v) { var m = String(v || '').match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/); return m ? m[3] + '/' + m[2] + '/' + m[1] + (m[4] ? ' ' + m[4] + ':' + m[5] : '') : String(v || ''); };
    var COR = { ALTO: 'danger', MEDIO: 'warning', BAIXO: 'secondary', SEM_INDICIOS: 'success' };
    var ICONE = { ALTO: 'bi-shield-exclamation text-danger', MEDIO: 'bi-shield-exclamation text-warning', BAIXO: 'bi-shield text-secondary', SEM_INDICIOS: 'bi-shield-check text-success' };
    var GCOR = { ALTA: 'danger', MEDIA: 'warning', BAIXA: 'secondary', INFO: 'light' };
    var lista = [], pode = false, aberta = 0, timer = null, disponivel = true;
    var EXT = /\.(pdf|jpe?g|png|tiff?|bmp|docx?)$/i;

    function pendente(a) { return a.STATUS === 'AGUARDANDO_TEXTO' || a.STATUS === 'NA_IA'; }
    function situacao(a) {
        if (pendente(a)) return '<span class="badge text-bg-light border"><i class="bi bi-hourglass-split me-1"></i>' + esc(t('na_fila')) + '</span>';
        if (a.STATUS === 'ERRO') return '<span class="badge text-bg-dark">' + esc(t('erro')) + '</span>';
        var r = a.RISCO || 'SEM_INDICIOS';
        return '<span class="badge text-bg-' + (COR[r] || 'secondary') + '">' + esc(t('risco.' + r)) + '</span>' + (Number(a.ARQUIVO_PRESENTE) === 0 ? ' <span class="badge text-bg-light border">' + esc(t('arquivo_excluido')) + '</span>' : '');
    }
    function ultimaPorArquivo() { var m = {}; lista.forEach(function (a) { if (!m[a.ARQUIVO] || Number(a.CODANALISE) > Number(m[a.ARQUIVO].CODANALISE)) m[a.ARQUIVO] = a; }); return m; }

    // Lista de arquivos: ícone do risco ao lado do nome (a tabela é redesenhada pela página; o observador recoloca o ícone)
    function marcarArquivos() {
        var m = ultimaPorArquivo();
        $('#gedGrid tbody tr[data-name]').each(function () {
            var $td = $(this).find('td').first(), nome = String($(this).data('name'));
            $td.find('[data-ged-adult]').remove();
            var a = m[nome]; if (!a) return;
            var ic = pendente(a) ? 'bi-hourglass-split text-muted' : (a.STATUS === 'ERRO' ? 'bi-shield-slash text-muted' : (ICONE[a.RISCO] || 'bi-shield'));
            var dica = t('aba') + ': ' + (pendente(a) ? t('na_fila') : (a.STATUS === 'ERRO' ? t('erro') : t('risco.' + (a.RISCO || 'SEM_INDICIOS')))) + (a.CIENTE_EM ? ' · ' + t('ciente') : '');
            $('<i class="bi ms-1" data-ged-adult role="img"></i>').addClass(ic).attr({ title: dica, 'aria-label': dica }).appendTo($td);
        });
    }

    function botao() {
        var nome = typeof selected !== 'undefined' ? selected : '';
        var ja = nome && ultimaPorArquivo()[nome];
        var ok = pode && nome && EXT.test(nome) && (!ja || ja.STATUS === 'ERRO');
        $('#gedAdultAnalisar').prop('disabled', !ok).attr('title', !nome ? t('selecione') : (ja && ja.STATUS !== 'ERRO' ? t('ja_analisado') : (!pode ? t('sem_permissao') : t('analisar_dica'))));
    }

    function desenhar() {
        var h = '';
        lista.forEach(function (a) {
            h += '<tr data-adult="' + esc(a.CODANALISE) + '" style="cursor:pointer"' + (Number(a.CODANALISE) === aberta ? ' class="table-active"' : '') + '><td style="white-space:nowrap">' + esc(fmt(a.CRIADO_EM)) + '</td><td>' + esc(a.ARQUIVO) + '</td><td style="white-space:nowrap">' + situacao(a)
                + '</td><td>' + esc(a.STATUS === 'ERRO' ? (a.MENSAGEM || '') : (a.RESUMO || '')) + '</td><td>' + (a.CIENTE_EM ? '<i class="bi bi-check2-circle text-success me-1"></i>' + esc(a.CIENTE_NOME || '') : (Number(a.PENDENTE_CIENCIA) ? '<span class="text-danger">' + esc(t('pendente_ciencia')) + '</span>' : '')) + '</td></tr>';
        });
        if (!h) h = '<tr><td colspan="5" class="text-muted">' + esc(disponivel ? t('nenhuma') : t('indisponivel')) + '</td></tr>';
        $('#gedAdultLista tbody').html(h);
        detalhe();
        marcarArquivos();
        botao();
    }

    function detalhe() {
        var a = lista.find(function (x) { return Number(x.CODANALISE) === aberta; });
        var $d = $('#gedAdultDetalhe');
        if (!a) { $d.html('<div class="text-muted small">' + esc(t('clique')) + '</div>'); return; }
        var h = '<div class="d-flex flex-wrap align-items-center gap-2 mb-2"><strong>' + esc(a.ARQUIVO) + '</strong>' + situacao(a) + '<span class="small text-muted">' + esc(t('origem.' + a.ORIGEM)) + ' · ' + esc(fmt(a.CRIADO_EM)) + (a.CONCLUIDO_EM ? ' → ' + esc(fmt(a.CONCLUIDO_EM)) : '') + (Number(a.IA_CONSULTADA) ? ' · ' + esc(t('com_ia')) : '') + '</span></div>';
        if (a.RESUMO) h += '<div class="mb-2">' + esc(a.RESUMO) + '</div>';
        if (a.STATUS === 'ERRO' && a.MENSAGEM) h += '<div class="alert alert-secondary py-1 small">' + esc(a.MENSAGEM) + '</div>';
        var grupos = {}, ordem = ['ASSINATURA', 'METADADOS', 'IMAGEM', 'CONTEUDO', 'IA'], pesos = { ALTA: 0, MEDIA: 1, BAIXA: 2, INFO: 3 };
        (a.INDICIOS || []).forEach(function (i) { (grupos[i.grupo] = grupos[i.grupo] || []).push(i); });
        var tem = false;
        ordem.forEach(function (g) {
            var l = grupos[g]; if (!l || !l.length) return; tem = true;
            l.sort(function (x, y) { return (pesos[x.gravidade] || 9) - (pesos[y.gravidade] || 9); });
            h += '<div class="small fw-semibold mt-2">' + esc(t('grupo.' + g)) + '</div><ul class="list-unstyled small mb-1">';
            l.forEach(function (i) { h += '<li class="mb-1"><span class="badge text-bg-' + (GCOR[i.gravidade] || 'secondary') + (i.gravidade === 'INFO' ? ' border' : '') + ' me-1">' + esc(t('grav.' + i.gravidade)) + '</span>' + esc(i.texto) + (i.evidencia ? '<div class="text-muted ms-4">“' + esc(i.evidencia) + '”</div>' : '') + '</li>'; });
            h += '</ul>';
        });
        if (!tem && !pendente(a) && a.STATUS !== 'ERRO') h += '<div class="small text-muted">' + esc(t('sem_indicios')) + '</div>';
        var tc = a.TECNICO || {}, linhas = [];
        [['produtor', 'tec.produtor'], ['criador', 'tec.criador'], ['criado_em', 'tec.criado'], ['modificado_em', 'tec.modificado'], ['revisoes', 'tec.revisoes'], ['modificado_por', 'tec.modificado_por']].forEach(function (p) { if (tc[p[0]]) linhas.push(esc(t(p[1])) + ': ' + esc(/_em$/.test(p[0]) ? fmt(tc[p[0]]) : tc[p[0]])); });
        if (tc.software && tc.software.length) linhas.push(esc(t('tec.software')) + ': ' + esc(tc.software.join(', ')));
        if (tc.exif && tc.exif.Make) linhas.push(esc(t('tec.camera')) + ': ' + esc((tc.exif.Make || '') + ' ' + (tc.exif.Model || '')));
        if (linhas.length) h += '<details class="small mt-2"><summary class="text-muted">' + esc(t('tec.titulo')) + '</summary><div class="mt-1">' + linhas.join('<br>') + '</div></details>';
        if (a.CIENTE_EM) h += '<div class="alert alert-success py-2 small mt-2 mb-0"><i class="bi bi-check2-circle me-1"></i>' + esc(t('ciente_por').replace('{n}', a.CIENTE_NOME || '').replace('{d}', fmt(a.CIENTE_EM))) + '<div class="mt-1">' + esc(a.CIENTE_MOTIVO || '') + '</div></div>';
        else if (a.STATUS === 'CONCLUIDA' && (a.RISCO === 'ALTO' || a.RISCO === 'MEDIO')) {
            if (pode && Number(a.ARQUIVO_PRESENTE) !== 0) h += '<div class="border rounded p-2 mt-2"><label class="form-label small mb-1" for="gedAdultMotivo">' + esc(t(a.RISCO === 'ALTO' ? 'ciencia_alto' : 'ciencia_medio')) + '</label><textarea class="form-control form-control-sm" id="gedAdultMotivo" rows="2" maxlength="1000" placeholder="' + esc(t('motivo_dica')) + '"></textarea><div class="d-flex align-items-center gap-2 mt-2"><button type="button" class="btn btn-sm btn-outline-success" id="gedAdultCiente"><i class="bi bi-check2-circle me-1"></i>' + esc(t('registrar_ciencia')) + '</button><span class="small" id="gedAdultMsgCiencia"></span></div></div>';
            else if (a.RISCO === 'ALTO') h += '<div class="small text-danger mt-2">' + esc(t('pendente_ciencia_info')) + '</div>';
        }
        h += '<div class="small text-muted mt-2">' + esc(t('so_aponta')) + '</div>';
        $d.html(h);
    }

    function carregar(silencioso) {
        if (!silencioso) $('#gedAdultLista tbody').html('<tr><td colspan="5" class="text-muted">' + esc(t('carregando')) + '</td></tr>');
        return $.getJSON(api, $.extend({}, params, { action: 'adulteracao' })).done(function (r) {
            disponivel = !(r && r.disponivel === false);
            lista = (r && r.analises) || []; pode = !!(r && r.pode_ciencia);
            if (!aberta && lista.length && typeof selected !== 'undefined' && selected) { var s = ultimaPorArquivo()[selected]; if (s) aberta = Number(s.CODANALISE); }
            desenhar(); agendar();
        }).fail(function (x) { $('#gedAdultLista tbody').html('<tr><td colspan="5" class="text-danger">' + esc(((x && x.responseJSON) || {}).message || t('erro_carregar')) + '</td></tr>'); });
    }
    // Enquanto houver análise na fila, atualiza sozinho (a cada 20 s, só com a aba visível)
    function agendar() {
        if (timer) { clearTimeout(timer); timer = null; }
        if (lista.some(pendente)) timer = setTimeout(function () { if (!document.hidden) carregar(true); else agendar(); }, 20000);
    }

    $(function () {
        // Lista com altura limitada (o detalhe fica visível embaixo); campo de texto sem o estilo da tela de login (app.css)
        $('<style>#gedAdultPane .ged-adult-lista{max-height:38vh;overflow:auto;border:1px solid var(--bs-border-color,#dee2e6);border-radius:.5rem;background:#fff}#gedAdultPane .ged-adult-lista table{margin-bottom:0}#gedAdultPane textarea.form-control{padding:.375rem .5rem;height:auto;min-height:3.5rem;font-size:.875rem}#gedAdultPane label.form-label{font-size:.875rem;font-weight:600;color:inherit}#gedAdultLista td{vertical-align:top}</style>').appendTo('head');
        $('<li class="nav-item" role="presentation"><button class="nav-link" id="gedAdultTab" type="button" role="tab" aria-selected="false"><i class="bi bi-shield-exclamation me-1"></i>' + esc(t('aba')) + '</button></li>').appendTo('#gedTabs');
        $('<div class="ged-tab-pane" id="gedAdultPane" role="tabpanel"><div class="d-flex align-items-center gap-2 mb-2 flex-wrap"><button type="button" class="btn btn-sm btn-outline-primary" id="gedAdultAnalisar" disabled><i class="bi bi-shield-check me-1"></i>' + esc(t('analisar')) + '</button><button type="button" class="btn btn-sm btn-outline-secondary" id="gedAdultAtualizar" title="' + esc(t('atualizar')) + '" aria-label="' + esc(t('atualizar')) + '"><i class="bi bi-arrow-clockwise"></i></button><span class="small text-muted" id="gedAdultInfo">' + esc(t('info')) + '</span></div>'
            + '<div class="ged-adult-lista mb-2"><table class="table table-sm table-hover menu-batch-grid" id="gedAdultLista"><thead><tr><th>' + esc(t('col.data')) + '</th><th>' + esc(t('col.arquivo')) + '</th><th>' + esc(t('col.risco')) + '</th><th>' + esc(t('col.resumo')) + '</th><th>' + esc(t('col.ciencia')) + '</th></tr></thead><tbody></tbody></table></div><div id="gedAdultDetalhe" class="border rounded p-2 bg-white"></div></div>').appendTo('.ged-tab-content');
        $('#gedAdultTab').on('click', function () {
            $('#gedTabs .nav-link').removeClass('active').attr('aria-selected', 'false'); $(this).addClass('active').attr('aria-selected', 'true');
            $('.ged-tab-pane').removeClass('active'); $('#gedAdultPane').addClass('active'); carregar();
        });
        $('#gedAdultAtualizar').on('click', function () { carregar(); });
        $('#gedAdultLista').on('click', 'tbody tr[data-adult]', function () { aberta = Number($(this).attr('data-adult')); $('#gedAdultLista tbody tr').removeClass('table-active'); $(this).addClass('table-active'); detalhe(); });
        $('#gedAdultAnalisar').on('click', function () {
            var nome = typeof selected !== 'undefined' ? selected : ''; if (!nome) return;
            var $b = $(this).prop('disabled', true);
            $.post(api, $.extend({}, params, { action: 'adulteracao_analisar', arquivo: nome }), null, 'json').done(function (r) {
                lista = r.analises || lista; aberta = Number(r.codanalise || 0); desenhar(); agendar(); $('#gedAdultInfo').text(r.message || '');
            }).fail(function (x) { $('#gedAdultInfo').text(((x && x.responseJSON) || {}).message || t('erro_carregar')); $b.prop('disabled', false); });
        });
        $('#gedAdultDetalhe').on('click', '#gedAdultCiente', function () {
            var motivo = String($('#gedAdultMotivo').val() || '').trim(), $m = $('#gedAdultMsgCiencia');
            if (motivo.length < 10) { $m.attr('class', 'small text-danger').text(t('motivo_curto')); return; }
            var $b = $(this).prop('disabled', true);
            $.post(api, $.extend({}, params, { action: 'adulteracao_ciente', codanalise: aberta, motivo: motivo }), null, 'json').done(function (r) {
                lista = r.analises || lista; desenhar(); $('#gedAdultInfo').text(r.message || '');
                try { if (window.parent && window.parent !== window) window.parent.postMessage({ tipo: 'greenportal-ged-alterado' }, '*'); } catch (e) { /* sem janela pai */ }
            }).fail(function (x) { $m.attr('class', 'small text-danger').text(((x && x.responseJSON) || {}).message || t('erro_carregar')); $b.prop('disabled', false); });
        });
        // Seleção na Lista de arquivos: atualiza o botão e abre a análise do arquivo
        $(document).on('gedArquivoSelecionado', function () { var s = ultimaPorArquivo()[selected]; if (s) aberta = Number(s.CODANALISE); botao(); if ($('#gedAdultPane').hasClass('active')) desenhar(); });
        $('#gedGrid').on('click', 'tbody tr[data-name]', function () { setTimeout(botao, 0); });
        var tb = document.querySelector('#gedGrid tbody');
        if (tb && window.MutationObserver) new MutationObserver(function (ms) { if (ms.some(function (m) { return Array.prototype.some.call(m.addedNodes, function (n) { return n.nodeType === 1 && n.tagName === 'TR'; }); })) marcarArquivos(); }).observe(tb, { childList: true });
        $(document).one('gedArquivosCarregados', function () {
            var url = new URLSearchParams(location.search);
            carregar(true).always(function () {
                if (url.get('aba') !== 'adulteracao') return;
                var nome = url.get('arquivo') || '';
                if (nome && (data.files || []).some(function (f) { return String(f.name) === nome; })) { selected = nome; if (typeof render === 'function') render(); var s = ultimaPorArquivo()[nome]; if (s) aberta = Number(s.CODANALISE); }
                $('#gedAdultTab').trigger('click');
            });
        });
    });
}(window.jQuery));
