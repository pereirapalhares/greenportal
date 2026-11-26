/*
 * Grupos de leis — Requisitos legais (migração 419; Revisão G itens 33 e 34 — 26/11/2026).
 * Botão "Grupos de leis" (grupo Associações), sempre a partir da linha selecionada:
 *   - criar um grupo a partir da lei: a IA gera as palavras-chave, o sistema acha as leis da empresa com elas e o usuário
 *     escolhe quais entram (nome e palavras editáveis);
 *   - compartilhar documentos: os vínculos da lei na unidade (licenças, medições, treinamentos, infraestrutura, controles,
 *     fornecedores) são copiados para as outras leis do grupo na mesma unidade.
 * Dados: principal/api/legislacao_grupos.php. Modais Bootstrap da própria tela.
 */
(function ($) {
    'use strict';
    var API = 'api/legislacao_grupos.php';
    function T(k, p) { return typeof window.greenT === 'function' ? window.greenT('legislacao_grupos.js.' + k, p) : p; }
    function esc(v) { return $('<div>').text(v == null ? '' : String(v)).html(); }
    function modal(id, titulo, corpo, rodape, tam) {
        $('#' + id).remove();
        $('body').append('<div class="modal fade" id="' + id + '" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable ' + (tam || 'modal-lg') + '"><div class="modal-content">'
            + '<div class="modal-header py-2"><h5 class="modal-title">' + titulo + '</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>'
            + '<div class="modal-body">' + corpo + '</div><div class="modal-footer py-2">' + (rodape || '') + '<button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">' + esc(T('fechar', 'Fechar')) + '</button></div></div></div></div>');
        var el = document.getElementById(id); bootstrap.Modal.getOrCreateInstance(el).show(); return $(el);
    }
    function aviso($m, txt, tipo) { $m.find('[data-lg-msg]').remove(); $m.find('.modal-body').prepend('<div data-lg-msg class="alert alert-' + (tipo || 'danger') + ' py-1 px-2 small">' + esc(txt) + '</div>'); }

    $('.modulo-legal-requisitos-fragment').each(function () {
        var $f = $(this), $t = $f.find('table').first(), csrf = '', podeEditar = false;
        var grupo = $f.find('[data-req-grupo="correlacionamentos"] .req-grupo-botoes');
        var $btn = $('<button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-req-slot="correlacionamentos-09" data-legislacao-grupos disabled><i class="bi bi-diagram-2"></i></button>')
            .attr('title', T('botao', 'Grupos de leis: agrupar leis parecidas (palavras-chave pela IA) e compartilhar documentos entre elas'));
        if (grupo.length) grupo.append($btn); else $f.find('.crud-actions').first().append($btn);
        function sel() { var $r = $t.find('tbody tr.selected'); return $r.length ? { leg: $.trim($r.find('[data-column="CODLEGISLACAO"]').text()), unid: $.trim($r.find('[data-column="CODUNIDADE"]').text()), codigo: $.trim($r.find('[data-column="CODIGO"]').text()), unidade: $.trim($r.find('[data-column="UNIDADE"]').text()) } : null; }
        function atualizar() { $f.find('[data-legislacao-grupos]').prop('disabled', !sel()); }
        $t.on('click', 'tbody tr', function () { window.setTimeout(atualizar, 0); });
        $f.on('gridPageLoaded', atualizar);

        function abrirLista() {
            var s = sel(); if (!s) return;
            $.ajax({ url: API, data: { leg: s.leg, unid: s.unid }, dataType: 'json', global: false }).done(function (r) {
                if (!r || !r.ok) { modal('lgLista', esc(T('titulo', 'Grupos de leis')), '<div class="alert alert-danger mb-0">' + esc((r && r.message) || 'Erro') + '</div>'); return; }
                csrf = r.csrf; podeEditar = !!r.pode_editar;
                var linha = function (g) {
                    return '<tr><td><b>' + esc(g.nome) + '</b>' + (g.origem === 'IA' ? ' <span class="badge text-bg-light border">IA</span>' : '') + '<div class="small text-muted">' + esc(g.palavras) + '</div></td><td class="text-nowrap">' + g.qtd + ' ' + esc(T('leis', 'leis')) + '</td><td class="text-end text-nowrap">'
                        + '<button type="button" class="btn btn-sm btn-outline-primary" data-lg-editar="' + g.id + '"><i class="bi bi-pencil"></i> ' + esc(podeEditar ? T('editar', 'Ver / editar') : T('ver', 'Ver')) + '</button> '
                        + (g.membro && podeEditar ? '<button type="button" class="btn btn-sm btn-outline-success" data-lg-compartilhar="' + g.id + '"><i class="bi bi-share"></i> ' + esc(T('compartilhar', 'Compartilhar documentos')) + '</button>' : '') + '</td></tr>';
                };
                var meus = r.grupos.filter(function (g) { return g.membro; }), outros = r.grupos.filter(function (g) { return !g.membro; });
                var corpo = '<p class="small mb-2">' + esc(T('explica', 'Leis parecidas ficam num grupo; os documentos que comprovam uma lei (licenças, controles, treinamentos...) podem ser compartilhados com as demais leis do grupo.')) + '</p>'
                    + '<h6 class="mb-1">' + esc(T('desta', 'Grupos desta lei')) + ' — ' + esc(s.codigo) + '</h6>'
                    + (meus.length ? '<table class="table table-sm align-middle">' + meus.map(linha).join('') + '</table>' : '<p class="small text-muted">' + esc(T('nenhum', 'Esta lei ainda não está em nenhum grupo.')) + '</p>')
                    + (outros.length ? '<details><summary class="small">' + esc(T('outros', 'Outros grupos da empresa')) + ' (' + outros.length + ')</summary><table class="table table-sm align-middle mt-1">' + outros.map(linha).join('') + '</table></details>' : '');
                var $m = modal('lgLista', '<i class="bi bi-diagram-2 me-1"></i>' + esc(T('titulo', 'Grupos de leis')), corpo,
                    podeEditar ? '<button type="button" class="btn btn-sm btn-success me-auto" data-lg-novo><i class="bi bi-stars me-1"></i>' + esc(T('novo', 'Novo grupo a partir desta lei (IA)')) + '</button>' : '');
                $m.on('click', '[data-lg-novo]', function () { bootstrap.Modal.getInstance($m[0]).hide(); novoComIa(s); });
                $m.on('click', '[data-lg-editar]', function () { var id = $(this).data('lg-editar'); bootstrap.Modal.getInstance($m[0]).hide(); editar(s, id, null); });
                $m.on('click', '[data-lg-compartilhar]', function () { var id = $(this).data('lg-compartilhar'); bootstrap.Modal.getInstance($m[0]).hide(); compartilhar(s, id); });
            });
        }
        function novoComIa(s) {
            var $m = modal('lgEditor', esc(T('novo_titulo', 'Novo grupo de leis')), '<div class="text-center py-4"><span class="spinner-border spinner-border-sm me-2"></span>' + esc(T('ia_lendo', 'A IA está lendo a lei e gerando as palavras-chave...')) + '</div>');
            $.ajax({ url: API, method: 'POST', dataType: 'json', global: false, data: { acao: 'sugerir', leg: s.leg, csrf: csrf } }).done(function (r) {
                bootstrap.Modal.getInstance($m[0]).hide();
                if (!r || !r.ok) { modal('lgErro', esc(T('titulo', 'Grupos de leis')), '<div class="alert alert-danger mb-0">' + esc((r && r.message) || 'Erro') + '</div>'); return; }
                editar(s, 0, { nome: r.nome, palavras: (r.palavras || []).join('; '), origem: 'IA', leis: [{ leg: Number(s.leg), codigo: s.codigo }] }, true);
            }).fail(function (x) { bootstrap.Modal.getInstance($m[0]).hide(); modal('lgErro', esc(T('titulo', 'Grupos de leis')), '<div class="alert alert-danger mb-0">' + esc((x.responseJSON || {}).message || 'Erro') + '</div>'); });
        }
        function editar(s, id, dados, buscarJa) {
            var carregar = id ? $.ajax({ url: API, data: { acao: 'grupo', id: id }, dataType: 'json', global: false }) : $.Deferred().resolve({ ok: true, grupo: { id: 0, nome: dados.nome, palavras: dados.palavras, origem: dados.origem }, leis: dados.leis });
            carregar.done(function (r) {
                if (!r || !r.ok) return;
                var g = r.grupo, marcadas = {}, lista = {};
                r.leis.forEach(function (l) { marcadas[l.leg] = true; lista[l.leg] = { leg: l.leg, codigo: l.codigo, termos: [], padrao: '' }; });
                var dis = podeEditar ? '' : ' disabled';
                var corpo = '<div class="row g-2"><div class="col-md-5"><label class="form-label small mb-0">' + esc(T('nome', 'Nome do grupo')) + '</label><input class="form-control form-control-sm" data-lg-nome maxlength="150" value="' + esc(g.nome) + '"' + dis + '></div>'
                    + '<div class="col-md-7"><label class="form-label small mb-0">' + esc(T('palavras', 'Palavras-chave (separadas por ;)')) + '</label><div class="input-group input-group-sm"><input class="form-control" data-lg-palavras value="' + esc(g.palavras) + '"' + dis + '><button type="button" class="btn btn-outline-primary" data-lg-buscar' + dis + '><i class="bi bi-search"></i> ' + esc(T('buscar', 'Buscar leis')) + '</button></div></div></div>'
                    + '<div class="d-flex justify-content-between align-items-center mt-3 mb-1"><strong class="small">' + esc(T('leis_grupo', 'Leis do grupo')) + ': <span data-lg-qtd></span></strong><small class="text-muted" data-lg-info></small></div>'
                    + '<div style="max-height:45vh;overflow:auto"><table class="table table-sm table-striped mb-0"><thead><tr><th></th><th>' + esc(T('lei', 'Lei')) + '</th><th>' + esc(T('termos', 'Palavras encontradas')) + '</th><th>' + esc(T('requisito', 'Requisito')) + '</th></tr></thead><tbody data-lg-leis></tbody></table></div>';
                var $m = modal('lgEditor', '<i class="bi bi-diagram-2 me-1"></i>' + esc(id ? T('editar_titulo', 'Grupo de leis') : T('novo_titulo', 'Novo grupo de leis')) + (g.origem === 'IA' ? ' <span class="badge text-bg-light border">IA</span>' : ''), corpo,
                    (podeEditar && id ? '<button type="button" class="btn btn-sm btn-outline-danger me-auto" data-lg-excluir><i class="bi bi-trash"></i> ' + esc(T('excluir', 'Excluir grupo')) + '</button>' : '')
                    + (podeEditar ? '<button type="button" class="btn btn-sm btn-success" data-lg-salvar><i class="bi bi-check2"></i> ' + esc(T('salvar', 'Gravar grupo')) + '</button>' : ''), 'modal-xl');
                function desenhar() {
                    var arr = Object.keys(lista).map(function (k) { return lista[k]; }).sort(function (a, b) { return (b.termos.length - a.termos.length) || String(a.codigo).localeCompare(String(b.codigo)); });
                    $m.find('[data-lg-leis]').html(arr.map(function (l) { return '<tr><td><input class="form-check-input" type="checkbox" data-lg-lei="' + l.leg + '"' + (marcadas[l.leg] ? ' checked' : '') + dis + '></td><td class="text-nowrap">' + esc(l.codigo) + '</td><td class="small">' + esc(l.termos.join(', ')) + '</td><td class="small">' + esc(l.padrao) + '</td></tr>'; }).join(''));
                    $m.find('[data-lg-qtd]').text(Object.keys(marcadas).filter(function (k) { return marcadas[k]; }).length);
                }
                function buscar() {
                    var pal = String($m.find('[data-lg-palavras]').val() || '').split(/[;,\n]/).map(function (p) { return $.trim(p); }).filter(function (p) { return p.length >= 3; });
                    if (!pal.length) return;
                    $m.find('[data-lg-info]').html('<span class="spinner-border spinner-border-sm"></span>');
                    $.ajax({ url: API, method: 'POST', dataType: 'json', global: false, data: { acao: 'buscar', palavras: JSON.stringify(pal), csrf: csrf } }).done(function (b) {
                        if (!b || !b.ok) { $m.find('[data-lg-info]').text((b && b.message) || 'Erro'); return; }
                        b.leis.forEach(function (l) { if (lista[l.leg]) { lista[l.leg].termos = l.termos; lista[l.leg].padrao = l.padrao; } else { lista[l.leg] = l; if (l.termos.length >= 2) marcadas[l.leg] = true; } });
                        $m.find('[data-lg-info]').text(T('achadas', '{n} leis encontradas; marcadas as que têm 2 ou mais palavras. Confira antes de gravar.').replace('{n}', b.total));
                        desenhar();
                    });
                }
                desenhar();
                if (buscarJa) buscar();
                $m.on('change', '[data-lg-lei]', function () { marcadas[$(this).data('lg-lei')] = this.checked; desenhar(); });
                $m.on('click', '[data-lg-buscar]', buscar);
                $m.on('click', '[data-lg-salvar]', function () {
                    var $b = $(this).prop('disabled', true), leis = Object.keys(marcadas).filter(function (k) { return marcadas[k]; }).map(Number);
                    $.ajax({ url: API, method: 'POST', dataType: 'json', global: false, data: { acao: 'salvar', id: id || 0, nome: $m.find('[data-lg-nome]').val(), palavras: $m.find('[data-lg-palavras]').val(), origem: g.origem, leis: JSON.stringify(leis), csrf: csrf } })
                        .done(function (x) { if (x && x.ok) { bootstrap.Modal.getInstance($m[0]).hide(); abrirLista(); } else aviso($m, (x && x.message) || 'Erro'); })
                        .fail(function (x) { aviso($m, (x.responseJSON || {}).message || 'Erro'); }).always(function () { $b.prop('disabled', false); });
                });
                $m.on('click', '[data-lg-excluir]', function () {
                    var $b = $(this);
                    if (!$b.data('confirmar')) { $b.data('confirmar', 1).html('<i class="bi bi-exclamation-triangle"></i> ' + esc(T('excluir_confirma', 'Clique de novo para excluir'))); return; }
                    $.ajax({ url: API, method: 'POST', dataType: 'json', global: false, data: { acao: 'excluir', id: id, csrf: csrf } }).done(function (x) { if (x && x.ok) { bootstrap.Modal.getInstance($m[0]).hide(); abrirLista(); } else aviso($m, (x && x.message) || 'Erro'); });
                });
            });
        }
        function compartilhar(s, id) {
            $.ajax({ url: API, method: 'POST', dataType: 'json', global: false, data: { acao: 'vinculos', leg: s.leg, unid: s.unid, csrf: csrf } }).done(function (r) {
                if (!r || !r.ok) return;
                var tem = r.areas.filter(function (a) { return a.qtd > 0; });
                var corpo = '<p class="small">' + esc(T('comp_explica', 'Os documentos vinculados a {lei} na unidade {un} serão vinculados também às demais leis do grupo que são requisito nessa unidade. Nada é apagado; vínculos que já existem são mantidos.').replace('{lei}', s.codigo).replace('{un}', s.unidade)) + '</p>'
                    + (tem.length ? tem.map(function (a) { return '<label class="form-check"><input class="form-check-input" type="checkbox" data-lg-area="' + a.area + '" checked> ' + esc(a.rotulo) + ' (' + a.qtd + ')</label>'; }).join('')
                        : '<div class="alert alert-warning py-1 small mb-0">' + esc(T('comp_nada', 'Esta lei não tem documentos vinculados nesta unidade.')) + '</div>');
                var $m = modal('lgComp', '<i class="bi bi-share me-1"></i>' + esc(T('compartilhar', 'Compartilhar documentos')), corpo,
                    tem.length ? '<button type="button" class="btn btn-sm btn-success" data-lg-aplicar><i class="bi bi-check2"></i> ' + esc(T('aplicar', 'Compartilhar com o grupo')) + '</button>' : '', 'modal-md');
                $m.on('click', '[data-lg-aplicar]', function () {
                    var $b = $(this).prop('disabled', true), areas = $m.find('[data-lg-area]:checked').map(function () { return $(this).data('lg-area'); }).get();
                    $.ajax({ url: API, method: 'POST', dataType: 'json', global: false, traditional: false, data: { acao: 'compartilhar', id: id, leg: s.leg, unid: s.unid, areas: areas, csrf: csrf } })
                        .done(function (x) { aviso($m, (x && x.message) || 'Erro', x && x.ok ? 'success' : 'danger'); if (x && x.ok) $b.remove(); })
                        .fail(function (x) { aviso($m, (x.responseJSON || {}).message || 'Erro'); $b.prop('disabled', false); });
                });
            });
        }
        $f.on('click', '[data-legislacao-grupos]', abrirLista);
        atualizar();
    });
}(jQuery));
