/*
 * Requisitos legais — Revisão G (migração 416), 26/11/2026:
 *   item 39  justificativa obrigatória ao mudar o nível de controle (window.gpPedirJustificativa) e histórico de quem mudou;
 *   itens 30, 32 e 40  Avaliação em lote: a mesma avaliação (ou só a data da próxima avaliação) nos requisitos filtrados —
 *            por exemplo a mesma lei em outras unidades, ou todas as leis de uma unidade, de um responsável ou de um grupo.
 * Modais Bootstrap na própria página (sem caixas nativas do navegador).
 */
(function ($) {
    'use strict';
    function T(chave, padrao) { return typeof window.greenT === 'function' ? window.greenT(chave, padrao) : padrao; }
    function esc(v) { return $('<div>').text(v == null ? '' : String(v)).html(); }
    function modal(id, titulo, corpo, rodape, tamanho) {
        $('#' + id).remove();
        $('body').append('<div class="modal fade" id="' + id + '" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable ' + (tamanho || '') + '"><div class="modal-content">'
            + '<div class="modal-header py-2"><h5 class="modal-title">' + titulo + '</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>'
            + '<div class="modal-body">' + corpo + '</div>' + (rodape ? '<div class="modal-footer py-2">' + rodape + '</div>' : '') + '</div></div></div>');
        var el = document.getElementById(id); bootstrap.Modal.getOrCreateInstance(el).show(); return $(el);
    }
    function dataBr(iso) { var m = String(iso || '').match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/); return m ? m[3] + '/' + m[2] + '/' + m[1] + (m[4] ? ' ' + m[4] + ':' + m[5] : '') : String(iso || ''); }

    // ------------------------------------------------------------ item 39: justificativa do nível de controle
    window.gpPedirJustificativa = function (mensagem, aoConfirmar) {
        var $m = modal('gpJustificativaNivel', esc(T('modulolegal_requisitoslegais.justificativa.titulo', 'Justificativa da mudança')),
            '<p class="mb-2">' + esc(mensagem) + '</p><label class="form-label small mb-1" for="gpJustificativaTexto">' + esc(T('modulolegal_requisitoslegais.justificativa.rotulo', 'Por que o nível de controle está mudando? (fica registrado com seu nome e a data)')) + '</label>'
            + '<textarea class="form-control" id="gpJustificativaTexto" rows="3" maxlength="2000"></textarea><div class="invalid-feedback">' + esc(T('modulolegal_requisitoslegais.justificativa.minimo', 'Informe pelo menos 5 caracteres.')) + '</div>',
            '<button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">' + esc(T('comum.botao.cancelar', 'Cancelar')) + '</button><button type="button" class="btn btn-sm btn-success" data-gp-justificativa-ok>' + esc(T('comum.botao.confirmar', 'Confirmar')) + '</button>');
        $m.on('shown.bs.modal', function () { $m.find('textarea').trigger('focus'); });
        $m.on('click', '[data-gp-justificativa-ok]', function () {
            var texto = $.trim($m.find('textarea').val());
            if (texto.length < 5) { $m.find('textarea').addClass('is-invalid').trigger('focus'); return; }
            bootstrap.Modal.getInstance($m[0]).hide(); aoConfirmar(texto);
        });
    };

    $('.modulo-legal-requisitos-fragment').each(function () {
        var $fragment = $(this), $table = $fragment.find('table').first();
        function selecionada() { var $r = $table.find('tbody tr.selected'); return $r.length ? { leg: $.trim($r.find('[data-column="CODLEGISLACAO"]').text()), unid: $.trim($r.find('[data-column="CODUNIDADE"]').text()), codigo: $.trim($r.find('[data-column="CODIGO"]').text()), unidade: $.trim($r.find('[data-column="UNIDADE"]').text()) } : null; }
        function atualizar() { $fragment.find('[data-poder-historico]').prop('disabled', !selecionada()); }
        $table.on('click', 'tbody tr', function () { window.setTimeout(atualizar, 0); });
        $fragment.on('gridPageLoaded', atualizar);

        // ------------------------------------------------------------ item 39: histórico do nível de controle
        $fragment.on('click', '[data-poder-historico]', function () {
            var s = selecionada(); if (!s) return;
            $.ajax({ url: window.location.href, method: 'POST', dataType: 'json', global: false, data: { action: 'control_history', codlegislacao: s.leg, codunidade: s.unid } }).done(function (r) {
                var itens = (r && r.itens) || [], corpo;
                if (!itens.length) corpo = '<p class="text-muted mb-0">' + esc(T('modulolegal_requisitoslegais.historico_nivel.vazio', 'Nenhuma mudança do nível de controle registrada para este requisito.')) + '</p>';
                else corpo = '<table class="table table-sm table-striped mb-0"><thead><tr><th>' + esc(T('comum.data', 'Data')) + '</th><th>' + esc(T('comum.usuario', 'Usuário')) + '</th><th>' + esc(T('modulolegal_requisitoslegais.historico_nivel.de', 'De')) + '</th><th>' + esc(T('modulolegal_requisitoslegais.historico_nivel.para', 'Para')) + '</th><th>' + esc(T('modulolegal_requisitoslegais.historico_nivel.justificativa', 'Justificativa')) + '</th></tr></thead><tbody>'
                    + itens.map(function (h) { return '<tr><td class="text-nowrap">' + esc(dataBr(h.data)) + '</td><td>' + esc(h.usuario) + '</td><td>' + esc(h.de || '—') + '</td><td>' + esc(h.para) + '</td><td style="white-space:pre-wrap">' + esc(h.justificativa) + '</td></tr>'; }).join('') + '</tbody></table>';
                modal('gpHistoricoNivel', esc(T('modulolegal_requisitoslegais.botao.historico_nivel', 'Histórico do nível de controle')) + ' — ' + esc(s.codigo + ' · ' + s.unidade), corpo, '', 'modal-lg');
            });
        });

        // ------------------------------------------------------------ itens 30, 32 e 40: avaliação em lote
        function filtrosAtuais() {
            var f = {}; try { f = JSON.parse($fragment.attr('data-context-filters') || '{}') || {}; } catch (e) { f = {}; }
            $fragment.find('[data-grid-filter], [data-column-filter]').each(function () { var v = String($(this).val() || '').trim(); if (v !== '') f[String($(this).data('grid-filter') || $(this).data('column-filter'))] = v; });
            return f;
        }
        $fragment.on('click', '[data-avaliacao-lote]', function () {
            var resp = []; try { resp = JSON.parse($(this).attr('data-responsaveis') || '[]'); } catch (e) { resp = []; }
            var origem = selecionada(), busca = String($fragment.find('.crud-search input').val() || '').trim();
            $.ajax({ url: window.location.href, method: 'POST', dataType: 'json', data: { action: 'lote_listar', filtros: JSON.stringify(filtrosAtuais()), busca: busca } }).done(function (r) {
                if (!r || !r.ok) return;
                if (!r.total) { modal('gpLote', esc(T('modulolegal_requisitoslegais.lote.titulo', 'Avaliação em lote')), '<p class="mb-0">' + esc(T('modulolegal_requisitoslegais.lote.nenhum', 'Nenhum requisito nos filtros atuais.')) + '</p>'); return; }
                if (r.total > r.limite) { modal('gpLote', esc(T('modulolegal_requisitoslegais.lote.titulo', 'Avaliação em lote')), '<p class="mb-0">' + esc(T('modulolegal_requisitoslegais.lote.muitos', 'Os filtros atuais trazem {n} requisitos. Filtre até {max} (por unidade, responsável, lei, grupo ou atendimento) para avaliar em lote.').replace('{n}', r.total).replace('{max}', r.limite)) + '</p>'); return; }
                var linhas = r.itens.map(function (i, k) { return '<tr><td><input class="form-check-input" type="checkbox" data-lote-item="' + k + '" checked></td><td>' + esc(i.codigo) + '</td><td>' + esc(i.unidade) + '</td><td>' + esc(i.atendido) + '</td></tr>'; }).join('');
                var opResp = '<option value="">' + esc(T('modulolegal_requisitoslegais.lote.resp_manter', '(manter o da última avaliação de cada requisito)')) + '</option>' + resp.map(function (u) { return '<option value="' + esc(u.id) + '">' + esc(u.nome) + '</option>'; }).join('');
                var corpo = '<div class="d-flex gap-3 mb-2"><label class="form-check"><input class="form-check-input" type="radio" name="gpLoteModo" value="nova" checked> ' + esc(T('modulolegal_requisitoslegais.lote.modo_nova', 'Nova avaliação (mesmo atendimento em todos)')) + '</label>'
                    + '<label class="form-check"><input class="form-check-input" type="radio" name="gpLoteModo" value="proxima"> ' + esc(T('modulolegal_requisitoslegais.lote.modo_proxima', 'Só a data da próxima avaliação')) + '</label></div>'
                    + '<div class="row g-2" data-lote-nova>'
                    + '<div class="col-md-4"><label class="form-label small mb-0">' + esc(T('modulolegal_requisitoslegais.lote.atendido', 'Atendido *')) + '</label><select class="form-select form-select-sm" data-lote-campo="atendido"><option value=""></option><option value="S">' + esc(T('comum.sim', 'Sim')) + '</option><option value="N">' + esc(T('comum.nao', 'Não')) + '</option><option value="PA">' + esc(T('modulolegal_requisitoslegais.lote.parcial', 'Parcialmente')) + '</option></select></div>'
                    + '<div class="col-md-4"><label class="form-label small mb-0">' + esc(T('modulolegal_requisitoslegais.lote.data_avaliacao', 'Data da avaliação')) + '</label><input type="date" class="form-control form-control-sm" data-lote-campo="data_de_avaliacao" value="' + new Date().toISOString().substring(0, 10) + '"></div>'
                    + '<div class="col-md-4"><label class="form-label small mb-0">' + esc(T('modulolegal_requisitoslegais.lote.responsavel', 'Responsável')) + '</label><select class="form-select form-select-sm" data-lote-campo="CODUSUARIO">' + opResp + '</select></div>'
                    + '<div class="col-md-6"><label class="form-label small mb-0">' + esc(T('modulolegal_requisitoslegais.lote.descricao', 'Descrição da situação')) + '</label><textarea class="form-control form-control-sm" rows="2" data-lote-campo="dscsituacao"></textarea></div>'
                    + '<div class="col-md-6"><label class="form-label small mb-0">' + esc(T('modulolegal_requisitoslegais.lote.obs', 'Observação')) + '</label><textarea class="form-control form-control-sm" rows="2" data-lote-campo="OBS"></textarea></div></div>'
                    + '<div class="row g-2 mt-0"><div class="col-md-4"><label class="form-label small mb-0">' + esc(T('modulolegal_requisitoslegais.lote.proxima', 'Próxima avaliação')) + '</label><input type="date" class="form-control form-control-sm" data-lote-campo="data_de_vencimento_avaliacao"></div>'
                    + (origem ? '<div class="col-md-8 d-flex align-items-end"><button type="button" class="btn btn-sm btn-outline-secondary" data-lote-copiar><i class="bi bi-copy"></i> ' + esc(T('modulolegal_requisitoslegais.lote.copiar', 'Copiar a última avaliação de {req}').replace('{req}', origem.codigo + ' · ' + origem.unidade)) + '</button></div>' : '') + '</div>'
                    + '<div class="d-flex justify-content-between align-items-center mt-3 mb-1"><strong class="small">' + esc(T('modulolegal_requisitoslegais.lote.requisitos', 'Requisitos (filtros atuais)')) + ': <span data-lote-qtd>' + r.itens.length + '</span>/' + r.itens.length + '</strong><label class="form-check small mb-0"><input class="form-check-input" type="checkbox" data-lote-todos checked> ' + esc(T('comum.todos', 'Todos')) + '</label></div>'
                    + '<div style="max-height:40vh;overflow:auto"><table class="table table-sm table-striped mb-0"><thead><tr><th></th><th>' + esc(T('modulolegal_requisitoslegais.lote.lei', 'Lei')) + '</th><th>' + esc(T('comum.unidade', 'Unidade')) + '</th><th>' + esc(T('modulolegal_requisitoslegais.lote.atendido_atual', 'Atendido hoje')) + '</th></tr></thead><tbody>' + linhas + '</tbody></table></div>'
                    + '<div class="alert alert-danger py-1 px-2 mt-2 mb-0 d-none small" data-lote-erro></div>';
                var $m = modal('gpLote', esc(T('modulolegal_requisitoslegais.lote.titulo', 'Avaliação em lote')), corpo,
                    '<button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">' + esc(T('comum.botao.cancelar', 'Cancelar')) + '</button><button type="button" class="btn btn-sm btn-success" data-lote-aplicar><i class="bi bi-check2"></i> ' + esc(T('modulolegal_requisitoslegais.lote.aplicar', 'Aplicar')) + '</button>', 'modal-xl');
                function contar() { $m.find('[data-lote-qtd]').text($m.find('[data-lote-item]:checked').length); }
                $m.on('change', '[name="gpLoteModo"]', function () { $m.find('[data-lote-nova]').toggle($m.find('[name="gpLoteModo"]:checked').val() === 'nova'); });
                $m.on('change', '[data-lote-todos]', function () { $m.find('[data-lote-item]').prop('checked', this.checked); contar(); });
                $m.on('change', '[data-lote-item]', contar);
                $m.on('click', '[data-lote-copiar]', function () {
                    $.ajax({ url: window.location.href, method: 'POST', dataType: 'json', global: false, data: { action: 'ultima_avaliacao', codlegislacao: origem.leg, codunidade: origem.unid } }).done(function (a) {
                        var v = a && a.avaliacao; if (!v) { $m.find('[data-lote-erro]').removeClass('d-none').text(T('modulolegal_requisitoslegais.lote.sem_origem', 'O requisito selecionado ainda não tem avaliação para copiar.')); return; }
                        $m.find('[data-lote-erro]').addClass('d-none');
                        ['atendido', 'dscsituacao', 'OBS', 'CODUSUARIO', 'data_de_vencimento_avaliacao'].forEach(function (c) { $m.find('[data-lote-campo="' + c + '"]').val(v[c] || ''); });
                    });
                });
                $m.on('click', '[data-lote-aplicar]', function () {
                    var $b = $(this), modo = $m.find('[name="gpLoteModo"]:checked').val(), dados = { action: 'lote_aplicar', modo: modo };
                    $m.find('[data-lote-campo]').each(function () { dados[$(this).data('lote-campo')] = $(this).val(); });
                    dados.itens = JSON.stringify($m.find('[data-lote-item]:checked').map(function () { var i = r.itens[Number($(this).data('lote-item'))]; return { leg: i.leg, unid: i.unid }; }).get());
                    $b.prop('disabled', true);
                    $.ajax({ url: window.location.href, method: 'POST', dataType: 'json', data: dados }).done(function (x) {
                        if (!x || !x.ok) { $m.find('[data-lote-erro]').removeClass('d-none').text((x && x.message) || 'Erro'); return; }
                        bootstrap.Modal.getInstance($m[0]).hide();
                        modal('gpLoteOk', esc(T('modulolegal_requisitoslegais.lote.titulo', 'Avaliação em lote')), '<p class="mb-0">' + esc(x.message) + '</p>');
                        $fragment.find('[data-reload-grid]').first().trigger('click');
                    }).fail(function (xhr) { $m.find('[data-lote-erro]').removeClass('d-none').text((xhr.responseJSON && xhr.responseJSON.message) || 'Erro'); })
                      .always(function () { $b.prop('disabled', false); });
                });
            });
        });
    });
}(jQuery));
