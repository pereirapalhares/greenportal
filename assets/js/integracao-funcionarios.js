/* Migração 348 — Homologação › Integração de funcionários (principal/homologacaofornecedores_integracao.php):
 * lista (gerar link, decisão, certificado, cancelar, configuração), popup da decisão e popup da configuração.
 * As janelas abrem no popup do portal (GreenPortalPopups); as telas abertas avisam a lista pelo BroadcastChannel para recarregar. */
(function ($) {
    'use strict';
    if (!$) return;
    var C = window.greenIntegracao || {}, T = C.textos || {};
    var host = window.top || window, H = host.jQuery || $;
    var canal = null; try { canal = new BroadcastChannel('greenportal-integracao'); } catch (e) { canal = null; }
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
    var url = function (q) { return new URL(C.url + q, window.location.href).href; };
    function post(dados) { dados.csrf = C.csrf; return $.ajax({ url: C.url, method: 'POST', data: dados, dataType: 'json' }).then(function (r) { return r; }, function (x) { return (x && x.responseJSON) || { ok: false, message: T.erro }; }); }
    function popup(titulo, endereco) {
        if (host.GreenPortalPopups) host.GreenPortalPopups.transacaoIndependente({ titulo: titulo, conteudo: H('<iframe>', { src: endereco, style: 'width:100%;height:100%;border:0;display:block;' }), amplo: true, redimensionavel: true });
        else window.location.href = endereco;
    }
    function aviso(texto, confirmar) {
        if (host.GreenPortalPopups && host.GreenPortalPopups.mensagem) host.GreenPortalPopups.mensagem({ mensagem: texto, icone: confirmar ? 'bi-question-circle' : 'bi-info-circle', confirmar: confirmar || null });
        else if (!confirmar) window.alert(texto); else if (window.confirm(texto)) confirmar();
    }
    function avisarLista() { try { if (canal) canal.postMessage({ tipo: 'atualizada' }); } catch (e) { /* sem canal */ } }

    // ---------------------------------------------------------------- lista
    var $lista = $('.integracao-funcionarios-fragment');
    if ($lista.length) {
        if (canal) canal.onmessage = function () { window.location.reload(); };
        $lista.on('click', '[data-linha-acao="link"]', function () {
            var $b = $(this), gerar = function () {
                post({ action: 'gerar', codfuncionario: $b.attr('data-func') }).then(function (r) {
                    if (!r || !r.ok) { aviso((r && r.message) || T.erro); return; }
                    var html = '<div class="mb-2">' + esc(String(T.link_texto || '').replace('{d}', r.expira || '')) + '</div>'
                        + '<div class="input-group input-group-sm mb-2"><input class="form-control" readonly value="' + esc(r.url) + '" data-ifn-link><button class="btn btn-outline-success" type="button" data-ifn-copiar><i class="bi bi-clipboard me-1"></i>' + esc(T.copiar) + '</button></div>'
                        + '<button class="btn btn-sm btn-success" type="button" data-ifn-abrir-aqui><i class="bi bi-tablet me-1"></i>' + esc(T.abrir_aqui) + '</button> <span class="small text-success" data-ifn-copiado></span>';
                    if (host.GreenPortalPopups && host.GreenPortalPopups.mensagem) {
                        host.GreenPortalPopups.mensagem({ titulo: T.link_titulo + ' — ' + ($b.attr('data-nome') || ''), html: html, icone: 'bi-link-45deg' });
                        var $m = H('#greenPortalMensagemModal');
                        $m.find('[data-ifn-copiar]').off('click').on('click', function () {
                            var v = $m.find('[data-ifn-link]').val();
                            var ok = function () { $m.find('[data-ifn-copiado]').text(T.copiado); };
                            if (host.navigator.clipboard) host.navigator.clipboard.writeText(v).then(ok, function () { $m.find('[data-ifn-link]').trigger('select'); });
                            else { $m.find('[data-ifn-link]').trigger('select'); try { host.document.execCommand('copy'); ok(); } catch (e) { /* seleção manual */ } }
                        });
                        $m.find('[data-ifn-abrir-aqui]').off('click').on('click', function () { popup(T.link_titulo + ' — ' + ($b.attr('data-nome') || ''), r.url); });
                    } else window.prompt(T.link_titulo, r.url);
                    window.setTimeout(function () { window.location.reload(); }, 400);
                });
            };
            if ($b.attr('data-aguardando') === '1') aviso(T.gerar_conf, gerar); else gerar();
        });
        $lista.on('click', '[data-linha-acao="decidir"]', function () { popup(T.decisao + ' — ' + ($(this).attr('data-nome') || ''), url('?decisao=' + encodeURIComponent($(this).attr('data-cod')))); });
        $lista.on('click', '[data-linha-acao="certificado"]', function () { popup(T.certificado + ' — ' + ($(this).attr('data-nome') || ''), url('?certificado=' + encodeURIComponent($(this).attr('data-cod')))); });
        $lista.on('click', '[data-linha-acao="cancelar"]', function () {
            var cod = $(this).attr('data-cod');
            aviso(T.cancelar_conf, function () { post({ action: 'cancelar', codintegracao: cod }).then(function (r) { if (!r.ok) aviso(r.message || T.erro); else window.location.reload(); }); });
        });
        $lista.on('click', '[data-ifn-config]', function () { popup(T.config, url('?config=1')); });
        // Link do alerta (?integracao=N): seleciona a linha e abre a decisão
        if (C.abrir) window.setTimeout(function () {
            var $r = $lista.find('tr[data-integracao="' + C.abrir + '"]').first(); if (!$r.length) return;
            $r.trigger('click'); var $d = $r.find('[data-linha-acao="decidir"]'); if ($d.length) $d.trigger('click');
        }, 300);
    }

    // ---------------------------------------------------------------- decisão
    var $dec = $('[data-ifn-decidir]');
    if ($dec.length) $dec.on('click', '[data-ifn-dec]', function () {
        var d = $(this).attr('data-ifn-dec'), $msg = $dec.find('[data-ifn-msg]');
        $dec.find('button').prop('disabled', true); $msg.removeClass('text-danger text-success').text('');
        post({ action: 'decidir', codintegracao: $dec.attr('data-cod'), decisao: d, motivo: $dec.find('[data-ifn-motivo]').val() }).then(function (r) {
            $msg.addClass(r.ok ? 'text-success' : 'text-danger').text(r.message || T.erro);
            if (r.ok) { avisarLista(); $dec.find('textarea').prop('disabled', true); } else $dec.find('button').prop('disabled', false);
        });
    });

    // ---------------------------------------------------------------- configuração
    var $cfg = $('[data-ifn-cfg-linhas]');
    if ($cfg.length) {
        var $msgc = $('[data-ifn-msg]');
        var msg = function (r) { $msgc.removeClass('text-danger text-success').addClass(r.ok ? 'text-success' : 'text-danger').text(r.message || T.erro); };
        $('[data-ifn-cfg-nova]').on('click', function () { var t = document.querySelector('[data-ifn-cfg-modelo]'); if (t) $cfg.append(t.innerHTML); });
        $cfg.on('click', '[data-ifn-cfg-salvar]', function () {
            var $tr = $(this).closest('tr'), v = function (c) { var $x = $tr.find('[data-c="' + c + '"]'); return $x.is(':checkbox') ? ($x.prop('checked') ? 1 : 0) : $x.val(); };
            post({ action: 'config_salvar', codconfig: $tr.attr('data-ifn-cfg') || 0, tipo: v('tipo'), questionario: v('questionario'), nota: v('nota'), validade: v('validade'), link_dias: v('link_dias'), ativo: v('ativo') })
                .then(function (r) { msg(r); if (r.ok) { $tr.attr('data-ifn-cfg', r.codconfig); avisarLista(); } });
        });
        $cfg.on('click', '[data-ifn-cfg-excluir]', function () {
            var $tr = $(this).closest('tr'), cod = +($tr.attr('data-ifn-cfg') || 0);
            if (!cod) { $tr.remove(); return; }
            post({ action: 'config_excluir', codconfig: cod }).then(function (r) { msg(r); if (r.ok) { $tr.remove(); avisarLista(); } });
        });
    }
}(window.jQuery));
