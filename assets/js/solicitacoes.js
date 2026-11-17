(function ($) {
    'use strict';
    // Telas de solicitações de melhoria: Minhas solicitações (modo "minhas") e Gestão de solicitações (modo "gestao").
    var cfgEl = document.getElementById('solicitacoesConfig');
    if (!cfgEl) return;
    var cfg = JSON.parse(cfgEl.textContent || '{}'), ui = cfg.ui || {}, modo = cfg.modo || 'minhas';
    var locale = cfg.idioma === 'en' ? 'en-US' : (cfg.idioma === 'es' ? 'es-ES' : 'pt-BR');

    function esc(v) { return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    function data(v, comHora) {
        if (!v) return '';
        var d = new Date(String(v).replace(' ', 'T'));
        if (isNaN(d.getTime())) return esc(v);
        return comHora ? d.toLocaleString(locale, { dateStyle: 'short', timeStyle: 'short' }) : d.toLocaleDateString(locale);
    }
    function post(dados) { return $.ajax({ url: cfg.api, method: 'POST', dataType: 'json', data: $.extend({ csrf: cfg.csrf }, dados) }); }
    function erro(xhr) { return (xhr && xhr.responseJSON && xhr.responseJSON.message) || ui.erro_conexao; }
    function badge(status) { return '<span class="sol-status sol-status-' + esc(String(status).toLowerCase()) + '">' + esc((cfg.status || {})[status] || status) + '</span>'; }
    function prioridade(p) { return esc({ BAIXA: ui.baixa, MEDIA: ui.media, ALTA: ui.alta }[p] || p); }
    function paragrafos(texto) { return esc(texto).replace(/\r?\n/g, '<br>'); }
    function historico(itens) {
        if (!itens || !itens.length) return '';
        return '<div class="sol-historico"><h3>' + esc(ui.historico) + '</h3><ol>' + itens.map(function (h) {
            return '<li><div>' + badge(h.status) + ' <small>' + data(h.quando, true) + (h.por ? ' · ' + esc(h.por) : '') + '</small></div>' + (h.resposta ? '<p>' + paragrafos(h.resposta) + '</p>' : '') + '</li>';
        }).join('') + '</ol></div>';
    }
    function mensagem(texto, tipo) {
        var $m = $('[data-sol-mensagem]').first();
        if (!$m.length) return;
        $m.html('<div class="alert alert-' + (tipo || 'success') + ' py-2">' + esc(texto) + '</div>');
        window.setTimeout(function () { $m.find('.alert').fadeOut(300, function () { $(this).remove(); }); }, 6000);
    }

    // ------------------------------------------------------------------ Minhas solicitações
    if (modo === 'minhas') {
        var $lista = $('[data-sol-lista]'), $form = $('[data-sol-form-nova]');
        if (!$lista.length) return;
        var cartao = function (s) {
            var $c = $('<article class="sol-cartao" data-sol-id></article>').attr('data-sol-id', s.codsolicitacao);
            $c.append('<button type="button" class="sol-cartao-cabeca" data-sol-abrir aria-expanded="false"><span class="sol-num">#' + s.codsolicitacao + '</span><span class="sol-cartao-titulo">' + esc(s.titulo) + '</span>' + badge(s.status) + '<i class="bi bi-chevron-down" aria-hidden="true"></i></button>');
            $c.append('<div class="sol-cartao-meta">' + esc(s.categoria_rotulo) + (s.modulo ? ' · ' + esc(s.modulo) : '') + ' · ' + esc(ui.criada) + ' ' + data(s.criado_em) + ' · ' + esc(s.origem === 'ASSISTENTE' ? ui.origem_assistente : ui.origem_tela) + '</div>');
            if (s.resposta) $c.append('<div class="sol-resposta"><strong>' + esc(ui.resposta_equipe) + ':</strong> ' + paragrafos(s.resposta) + '</div>');
            $c.append('<div class="sol-cartao-corpo" hidden></div>');
            return $c;
        };
        var carregar = function () {
            post({ action: 'minhas' }).done(function (r) {
                $lista.empty();
                if (!r.itens || !r.itens.length) { $lista.append($('<div class="sol-vazio"></div>').text(ui.vazio_minhas)); return; }
                r.itens.forEach(function (s) { $lista.append(cartao(s)); });
                if (cfg.abrir) { var $alvo = $lista.find('[data-sol-id="' + cfg.abrir + '"] [data-sol-abrir]'); if ($alvo.length) { $alvo.trigger('click'); $alvo[0].scrollIntoView({ block: 'center' }); } cfg.abrir = 0; }
            }).fail(function (xhr) { $lista.html('<div class="alert alert-danger">' + esc(erro(xhr)) + '</div>'); });
        };
        $lista.on('click', '[data-sol-abrir]', function () {
            var $b = $(this), $c = $b.closest('[data-sol-id]'), $corpo = $c.find('.sol-cartao-corpo'), aberto = !$corpo.prop('hidden');
            $corpo.prop('hidden', aberto); $b.attr('aria-expanded', aberto ? 'false' : 'true'); $c.toggleClass('aberto', !aberto);
            if (aberto || $corpo.data('carregado')) return;
            $corpo.html('<div class="sol-vazio">' + esc(ui.carregando) + '</div>');
            post({ action: 'detalhe', id: $c.attr('data-sol-id') }).done(function (r) {
                var s = r.item;
                $corpo.data('carregado', true).html('<p>' + paragrafos(s.descricao) + '</p>' + (s.beneficio ? '<p class="sol-beneficio"><strong>' + esc(ui.f_beneficio) + ':</strong> ' + esc(s.beneficio) + '</p>' : '')
                    + '<p class="sol-cartao-meta">' + esc(ui.f_prioridade) + ': ' + prioridade(s.prioridade_usuario) + (s.tela ? ' · ' + esc(ui.tela_origem) + ': ' + esc(s.tela) : '') + (s.codsolicitacao_original ? ' · ' + esc(ui.duplicada_de) + ' #' + s.codsolicitacao_original : '') + '</p>'
                    + historico(s.historico));
            }).fail(function (xhr) { $corpo.html('<div class="alert alert-danger py-2">' + esc(erro(xhr)) + '</div>'); });
        });
        $('[data-sol-nova]').on('click', function () { $form.prop('hidden', false).find('[name="titulo"]').trigger('focus'); });
        $('[data-sol-cancelar]').on('click', function () { $form.prop('hidden', true)[0].reset(); });
        $form.on('submit', function (e) {
            e.preventDefault();
            var dados = { action: 'criar' };
            $form.serializeArray().forEach(function (c) { dados[c.name] = c.value; });
            $form.find('button').prop('disabled', true);
            post(dados).done(function (r) { $form.prop('hidden', true)[0].reset(); mensagem(r.message); cfg.abrir = r.codsolicitacao; carregar(); })
                .fail(function (xhr) { mensagem(erro(xhr), 'danger'); })
                .always(function () { $form.find('button').prop('disabled', false); });
        });
        carregar();
        return;
    }

    // ------------------------------------------------------------------ Gestão de solicitações
    var $tabela = $('[data-sol-tabela]'), $detalhe = $('[data-sol-detalhe]'), $totais = $('[data-sol-totais]');
    if (!$tabela.length) return;
    var selecionada = cfg.abrir || 0, filtroTimer = null;
    var filtros = function () { var f = { action: 'listar' }; $('[data-sol-filtro]').each(function () { f[$(this).attr('data-sol-filtro')] = $(this).val(); }); return f; };
    var listar = function () {
        post(filtros()).done(function (r) {
            $totais.html(Object.keys(r.totais || {}).map(function (st) { return '<button type="button" class="sol-total" data-sol-total="' + esc(st) + '">' + badge(st) + ' <strong>' + r.totais[st] + '</strong></button>'; }).join(''));
            if (!r.itens || !r.itens.length) { $tabela.html('<tr><td colspan="8" class="sol-vazio">' + esc(ui.vazio) + '</td></tr>'); return; }
            $tabela.html(r.itens.map(function (s) {
                return '<tr data-sol-linha="' + s.codsolicitacao + '"' + (s.codsolicitacao === selecionada ? ' class="table-active"' : '') + '><td>' + s.codsolicitacao + '</td><td class="sol-col-titulo">' + esc(s.titulo) + (s.escopo === 'PRODUTO' ? ' <i class="bi bi-globe2" title="' + esc(ui.g_produto) + '"></i>' : '') + '</td><td>' + esc(s.categoria_rotulo) + '</td><td>' + esc(s.modulo) + '</td><td>' + esc(s.usuario) + '</td><td>' + prioridade(s.prioridade_usuario) + '</td><td>' + badge(s.status) + '</td><td>' + data(s.criado_em) + '</td></tr>';
            }).join(''));
        }).fail(function (xhr) { $tabela.html('<tr><td colspan="8"><div class="alert alert-danger py-2 mb-0">' + esc(erro(xhr)) + '</div></td></tr>'); });
    };
    var detalhar = function (id) {
        selecionada = Number(id);
        $tabela.find('tr').removeClass('table-active').filter('[data-sol-linha="' + id + '"]').addClass('table-active');
        $detalhe.html('<div class="sol-vazio">' + esc(ui.carregando) + '</div>');
        post({ action: 'detalhe', id: id, gestao: 1 }).done(function (r) {
            var s = r.item, opcoes = Object.keys(cfg.status).map(function (st) { return '<option value="' + st + '"' + (st === s.status ? ' selected' : '') + '>' + esc(cfg.status[st]) + '</option>'; }).join('');
            var html = '<div class="sol-detalhe-cabeca"><span class="sol-num">#' + s.codsolicitacao + '</span> ' + badge(s.status) + '</div><h2>' + esc(s.titulo) + '</h2>'
                + '<p class="sol-cartao-meta">' + esc(s.usuario) + ' · ' + data(s.criado_em, true) + ' · ' + esc(s.categoria_rotulo) + (s.modulo ? ' · ' + esc(s.modulo) : '') + ' · ' + esc(ui.f_prioridade) + ': ' + prioridade(s.prioridade_usuario) + '</p>'
                + '<p>' + paragrafos(s.descricao) + '</p>' + (s.beneficio ? '<p class="sol-beneficio"><strong>' + esc(ui.f_beneficio) + ':</strong> ' + esc(s.beneficio) + '</p>' : '')
                + (s.tela ? '<p class="sol-cartao-meta"><strong>' + esc(ui.tela_origem) + ':</strong> ' + esc(s.tela) + '</p>' : '')
                + (s.descricao_original ? '<details class="sol-original"><summary>' + esc(ui.pergunta_original) + '</summary><p>' + paragrafos(s.descricao_original) + '</p></details>' : '');
            if (cfg.podeEditar) {
                html += '<form class="sol-form-gestao" data-sol-form-gestao><div class="row g-2">'
                    + '<div class="col-sm-6"><label class="form-label">' + esc(ui.g_situacao) + '</label><select class="form-select form-select-sm" name="status">' + opcoes + '</select></div>'
                    + '<div class="col-sm-6"><label class="form-label">' + esc(ui.g_escopo) + '</label><select class="form-select form-select-sm" name="escopo"><option value="CLIENTE"' + (s.escopo !== 'PRODUTO' ? ' selected' : '') + '>' + esc(ui.g_cliente) + '</option><option value="PRODUTO"' + (s.escopo === 'PRODUTO' ? ' selected' : '') + '>' + esc(ui.g_produto) + '</option></select></div>'
                    + '<div class="col-12" data-sol-original' + (s.status === 'DUPLICADA' ? '' : ' hidden') + '><label class="form-label">' + esc(ui.g_original) + '</label><input type="number" min="1" class="form-control form-control-sm" name="codsolicitacao_original" value="' + (s.codsolicitacao_original || '') + '"></div>'
                    + '<div class="col-12"><label class="form-label">' + esc(ui.g_resposta) + '</label><textarea class="form-control form-control-sm" name="resposta" rows="3" maxlength="2000" placeholder="' + esc(ui.g_resposta_ph) + '"></textarea></div>'
                    + '</div><div class="sol-form-botoes"><button type="submit" class="btn btn-success btn-sm">' + esc(ui.salvar) + '</button></div><div data-sol-mensagem></div></form>';
            } else {
                html += '<p class="sol-explica">' + esc(ui.g_somente_leitura) + '</p>';
            }
            $detalhe.html(html + historico(s.historico));
        }).fail(function (xhr) { $detalhe.html('<div class="alert alert-danger py-2">' + esc(erro(xhr)) + '</div>'); });
    };
    $tabela.on('click', '[data-sol-linha]', function () { detalhar($(this).attr('data-sol-linha')); });
    $totais.on('click', '[data-sol-total]', function () { $('[data-sol-filtro="status"]').val($(this).attr('data-sol-total')); listar(); });
    $('[data-sol-filtro]').on('change', listar).filter('[data-sol-filtro="busca"]').on('input', function () { window.clearTimeout(filtroTimer); filtroTimer = window.setTimeout(listar, 400); });
    $detalhe.on('change', '[name="status"]', function () { $detalhe.find('[data-sol-original]').prop('hidden', $(this).val() !== 'DUPLICADA'); });
    $detalhe.on('submit', '[data-sol-form-gestao]', function (e) {
        e.preventDefault();
        var $f = $(this), dados = { action: 'atualizar', id: selecionada };
        $f.serializeArray().forEach(function (c) { dados[c.name] = c.value; });
        $f.find('button').prop('disabled', true);
        post(dados).done(function (r) { listar(); detalhar(selecionada); window.setTimeout(function () { mensagem(r.message); }, 400); })
            .fail(function (xhr) { $f.find('[data-sol-mensagem]').html('<div class="alert alert-danger py-2 mt-2">' + esc(erro(xhr)) + '</div>'); $f.find('button').prop('disabled', false); });
    });

    // Sinais do assistente
    var $sinais = $('[data-sol-sinais]'), $resumo = $('[data-sol-resumo]'), sinaisCarregados = false;
    var motivo = function (m) { return '<span class="sol-motivo sol-motivo-' + esc(String(m).toLowerCase()) + '">' + esc(ui['m_' + m] || m) + '</span>'; };
    var carregarSinais = function () {
        sinaisCarregados = true;
        post({ action: 'sinais', dias: $('[data-sol-dias]').val() }).done(function (r) {
            var x = r.resumo || {};
            $resumo.html([[ui.s_respostas, x.total], [ui.s_uteis, x.uteis], [ui.s_nao_uteis, x.nao_uteis], [ui.s_usuarios, x.usuarios]].map(function (i) { return '<div class="sol-indicador"><strong>' + (i[1] || 0) + '</strong><span>' + esc(i[0]) + '</span></div>'; }).join(''));
            if (!r.itens || !r.itens.length) { $sinais.html('<tr><td colspan="5" class="sol-vazio">' + esc(ui.s_vazio) + '</td></tr>'); return; }
            $sinais.html(r.itens.map(function (s) {
                return '<tr><td>' + data(s.quando, true) + '</td><td>' + esc(s.usuario) + '</td><td>' + esc(s.tela) + '</td><td>' + motivo(s.motivo) + '</td><td class="sol-col-titulo">' + esc(s.pergunta) + (s.necessidade ? '<div class="sol-cartao-meta">' + esc(s.necessidade) + '</div>' : '') + '</td></tr>';
            }).join(''));
        }).fail(function (xhr) { $sinais.html('<tr><td colspan="5"><div class="alert alert-danger py-2 mb-0">' + esc(erro(xhr)) + '</div></td></tr>'); });
    };
    $('[data-sol-aba-sinais]').on('shown.bs.tab', function () { if (!sinaisCarregados) carregarSinais(); });
    $('[data-sol-dias]').on('change', carregarSinais);

    listar();
    if (selecionada) detalhar(selecionada);
}(jQuery));
