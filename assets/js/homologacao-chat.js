/*
 * Mensagens sobre documentos da homologação (migração 420; Revisão G itens 146 e 147 — 26/11/2026).
 * Botão "Mensagens" nos grids de documentos do fornecedor e do funcionário (Portaria e Homologação), com o número de
 * mensagens não lidas do outro lado na própria linha. A conversa também começa sozinha quando a IA reprova o documento.
 * Dados: principal/api/homologacao_chat.php. Modal Bootstrap da própria tela.
 */
(function ($) {
    'use strict';
    var API = 'api/homologacao_chat.php';
    var TELAS = [
        { sel: '.portaria-documentacao-fornecedor-grid-fragment', ctx: 'fornecedorcriterios', desc: 'DSCRITERIO' },
        { sel: '.homologacao-fornecedor-criterios-grid-fragment', ctx: 'fornecedorcriterios', desc: 'DSCRITERIO' },
        { sel: '.portaria-documentacao-funcionario-grid-fragment', ctx: 'funcionariocriterios', desc: 'FUNCIONARIO' }
    ];
    function T(k, p) { return typeof window.greenT === 'function' ? window.greenT('homologacao_chat.' + k, p) : p; }
    function esc(v) { return $('<div>').text(v == null ? '' : String(v)).html(); }
    function data(v) { var m = String(v || '').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/); return m ? m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5] : String(v || ''); }
    TELAS.forEach(function (tela) {
        $(tela.sel).each(function () {
            var $f = $(this), $t = $f.find('.menu-batch-grid').first();
            if (!$t.length || $f.find('[data-hom-chat]').length) return;
            var $b = $('<button type="button" class="btn btn-sm btn-outline-primary grid-crud-button position-relative" data-hom-chat disabled><i class="bi bi-chat-dots"></i><span class="position-absolute top-0 start-100 translate-middle badge rounded-pill text-bg-danger d-none" data-hom-chat-n style="font-size:9px"></span></button>')
                .attr('title', T('botao', 'Mensagens com o fornecedor sobre o documento selecionado'));
            $f.find('.crud-actions').first().append($b);
            function linha() { var $r = $t.find('tbody tr.selected').first(); return $r.length && /^\d+$/.test(String($r.attr('data-key') || '')) ? $r : null; }
            function marcar() {
                var ids = $t.find('tbody tr[data-key]').map(function () { var k = String($(this).attr('data-key') || ''); return /^\d+$/.test(k) ? k : null; }).get();
                if (!ids.length) return;
                $.ajax({ url: API, data: { contexto: tela.ctx, acao: 'resumo', ids: ids.join(',') }, dataType: 'json', global: false }).done(function (r) {
                    if (!r || !r.ok) return;
                    var tot = 0;
                    $t.find('tbody tr[data-key]').each(function () {
                        var c = (r.contagem || {})[String($(this).attr('data-key'))], $i = $(this).find('[data-hom-chat-ico]');
                        if (!c) { $i.remove(); return; }
                        tot += c.nao_lidas;
                        if (!$i.length) $i = $('<i data-hom-chat-ico class="bi ms-1"></i>').appendTo($(this).find('td').first());
                        $i.attr('class', 'bi ms-1 ' + (c.nao_lidas ? 'bi-chat-dots-fill text-danger' : 'bi-chat-dots text-secondary')).attr('title', T('na_linha', '{t} mensagem(ns), {n} não lida(s)').replace('{t}', c.total).replace('{n}', c.nao_lidas));
                    });
                    $b.find('[data-hom-chat-n]').text(tot).toggleClass('d-none', !tot);
                });
            }
            function abrir() {
                var $r = linha(); if (!$r) return;
                var id = $r.attr('data-key'), $d = $r.find('[data-column="' + tela.desc + '"]'), nome = $.trim(String($d.find('select option:selected').text() || $d.find('input, textarea').first().val() || $d.text() || ''));
                function carregar(dados, metodo) {
                    return $.ajax({ url: API, method: metodo || 'GET', data: $.extend({ contexto: tela.ctx, registro: id }, dados || {}), dataType: 'json', global: false });
                }
                carregar().done(function (r) {
                    var csrf = r && r.csrf;
                    $('#homChatModal').remove();
                    $('body').append('<div class="modal fade" id="homChatModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg"><div class="modal-content">'
                        + '<div class="modal-header py-2"><h5 class="modal-title"><i class="bi bi-chat-dots me-1"></i>' + esc(T('titulo', 'Mensagens')) + (nome ? ' — ' + esc(nome.substring(0, 80)) : '') + '</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>'
                        + '<div class="modal-body" data-hom-chat-lista style="min-height:220px;background:#f6f9f7"></div>'
                        + '<div class="modal-footer py-2 d-block"><div class="alert alert-danger py-1 px-2 small d-none mb-1" data-hom-chat-erro></div><div class="d-flex gap-2"><textarea class="form-control form-control-sm" rows="2" maxlength="4000" data-hom-chat-texto placeholder="' + esc(T('escreva', 'Escreva a mensagem...')) + '"></textarea>'
                        + '<button type="button" class="btn btn-sm btn-success" data-hom-chat-enviar><i class="bi bi-send"></i> ' + esc(T('enviar', 'Enviar')) + '</button></div></div></div></div></div>');
                    var $m = $('#homChatModal');
                    function desenhar(res) {
                        if (!res || !res.ok) { $m.find('[data-hom-chat-lista]').html('<div class="alert alert-danger">' + esc((res && res.message) || 'Erro') + '</div>'); return; }
                        var html = res.mensagens.length ? res.mensagens.map(function (x) {
                            var meu = x.lado === res.meu_lado;
                            return '<div class="d-flex ' + (meu ? 'justify-content-end' : 'justify-content-start') + ' mb-2"><div class="p-2 rounded-3 shadow-sm" style="max-width:78%;background:' + (x.automatica ? '#fff3cd' : (meu ? '#d1e7dd' : '#fff')) + '">'
                                + '<div class="small text-muted mb-1">' + esc(x.autor || (x.lado === 'FORNECEDOR' ? T('fornecedor', 'Fornecedor') : T('empresa', 'Empresa'))) + ' · ' + esc(x.lado === 'FORNECEDOR' ? T('fornecedor', 'Fornecedor') : T('empresa', 'Empresa')) + ' · ' + esc(data(x.quando)) + '</div>'
                                + '<div style="white-space:pre-wrap">' + esc(x.texto) + '</div>' + (meu ? '<div class="text-end small text-muted">' + (x.pode_excluir ? '<button type="button" class="btn btn-link btn-sm p-0 me-2 text-danger" data-hom-chat-excluir="' + x.id + '" title="' + esc(T('excluir', 'Excluir (só enquanto não foi lida)')) + '"><i class="bi bi-trash"></i></button>' : '') + (x.lida ? '<i class="bi bi-check2-all"></i> ' + esc(T('lida', 'lida')) : '<i class="bi bi-check2"></i>') + '</div>' : '') + '</div></div>';
                        }).join('') : '<p class="text-muted small text-center mt-4">' + esc(T('vazio', 'Nenhuma mensagem sobre este documento. Escreva abaixo para começar a conversa.')) + '</p>';
                        var $l = $m.find('[data-hom-chat-lista]').html(html); $l.scrollTop($l[0].scrollHeight);
                    }
                    desenhar(r);
                    $m.on('click', '[data-hom-chat-enviar]', function () {
                        var $bt = $(this), txt = $.trim($m.find('[data-hom-chat-texto]').val());
                        if (!txt) return;
                        $bt.prop('disabled', true);
                        carregar({ texto: txt, csrf: csrf }, 'POST').done(function (x) {
                            if (x && x.ok) { $m.find('[data-hom-chat-texto]').val(''); $m.find('[data-hom-chat-erro]').addClass('d-none'); desenhar(x); }
                            else $m.find('[data-hom-chat-erro]').removeClass('d-none').text((x && x.message) || 'Erro');
                        }).fail(function (x) { $m.find('[data-hom-chat-erro]').removeClass('d-none').text((x.responseJSON || {}).message || 'Erro'); }).always(function () { $bt.prop('disabled', false); });
                    });
                    $m.on('click', '[data-hom-chat-excluir]', function () {
                        var $bt = $(this);
                        if (!$bt.data('confirmar')) { $bt.data('confirmar', 1).html('<i class="bi bi-trash"></i> ' + esc(T('excluir_confirma', 'Clique de novo para excluir'))); return; }
                        carregar({ acao: 'excluir', id: $bt.data('hom-chat-excluir'), csrf: csrf }, 'POST').done(function (x) {
                            if (x && x.ok) { $m.find('[data-hom-chat-erro]').addClass('d-none'); desenhar(x); }
                            else $m.find('[data-hom-chat-erro]').removeClass('d-none').text((x && x.message) || 'Erro');
                        }).fail(function (x) { $m.find('[data-hom-chat-erro]').removeClass('d-none').text((x.responseJSON || {}).message || 'Erro'); });
                    });
                    $m.on('hidden.bs.modal', marcar);
                    bootstrap.Modal.getOrCreateInstance($m[0]).show();
                });
            }
            $t.on('click', 'tbody tr', function () { window.setTimeout(function () { $b.prop('disabled', !linha()); }, 0); });
            $f.on('gridPageLoaded', function () { $b.prop('disabled', !linha()); marcar(); });
            $b.on('click', abrir);
            marcar();
        });
    });
}(jQuery));
