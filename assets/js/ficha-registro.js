/*
 * Ficha do registro em grupos (25/11/2026, pedido do usuário: a grade do Controle tinha ~35 colunas e ficou confusa).
 * Configuração na tela ('ficha' no ManutencaoLegalCadastroGrid): lista de grupos { titulo: {pt,en,es}, campos: [...] }
 * e, opcional, 'condicoes': { CAMPO: 'CAMPO_DE_QUE_DEPENDE' } (o campo só aparece quando o outro está preenchido).
 * A ficha abre pelo botão "Detalhes" da barra (linha selecionada), por duplo clique numa célula somente leitura e
 * sozinha ao incluir um registro. Ela não grava nada: cada campo da ficha espelha o campo da linha na grade — o que se
 * muda aqui muda lá, marca a linha como alteração pendente e liga o Salvar, como se fosse digitado na grade.
 */
(function ($) {
    'use strict';
    if (!$ || window.gpFichaRegistro) return;
    window.gpFichaRegistro = true;

    var lang = String(window.greenPortalIdioma || document.documentElement.lang || 'pt').slice(0, 2).toLowerCase();
    var TX = {
        pt: { detalhes: 'Detalhes', dica: 'Abrir a ficha do registro selecionado (campos em grupos)', titulo: 'Ficha do registro', novo: 'Novo registro', fechar: 'Fechar', salvar: 'Salvar', pendente: 'As alterações ficam na linha da grade e só são gravadas ao clicar em Salvar.', selecione: 'Selecione uma linha para abrir a ficha.', abrir: 'Abrir', vazio: '—', gravarAntes: 'Grave o registro antes.' },
        en: { detalhes: 'Details', dica: 'Open the selected record card (fields in groups)', titulo: 'Record card', novo: 'New record', fechar: 'Close', salvar: 'Save', pendente: 'Changes stay in the grid row and are only stored when you click Save.', selecione: 'Select a row to open the card.', abrir: 'Open', vazio: '—', gravarAntes: 'Save the record first.' },
        es: { detalhes: 'Detalles', dica: 'Abrir la ficha del registro seleccionado (campos en grupos)', titulo: 'Ficha del registro', novo: 'Nuevo registro', fechar: 'Cerrar', salvar: 'Guardar', pendente: 'Los cambios quedan en la fila de la grilla y solo se guardan al hacer clic en Guardar.', selecione: 'Seleccione una fila para abrir la ficha.', abrir: 'Abrir', vazio: '—', gravarAntes: 'Grabe el registro antes.' }
    }[lang] || null;
    if (!TX) TX = { detalhes: 'Detalhes', dica: 'Abrir a ficha do registro selecionado', titulo: 'Ficha do registro', novo: 'Novo registro', fechar: 'Fechar', salvar: 'Salvar', pendente: 'As alterações só são gravadas ao clicar em Salvar.', selecione: 'Selecione uma linha.', abrir: 'Abrir', vazio: '—', gravarAntes: 'Grave o registro antes.' };
    function rot(v) { if (v && typeof v === 'object') return v[lang] || v.pt || ''; return String(v || ''); }

    if (!document.getElementById('gp-ficha-css')) {
        $('<style id="gp-ficha-css">' +
            '.gp-ficha{position:fixed;top:0;right:0;bottom:0;width:480px;max-width:96vw;background:#fff;z-index:1060;box-shadow:-8px 0 24px rgba(0,0,0,.18);display:flex;flex-direction:column;font-size:.86rem;transform:translateX(105%);transition:transform .18s ease}' +
            '.gp-ficha.aberta{transform:none}' +
            '.gp-ficha-cab{padding:12px 14px;border-bottom:1px solid #e3e9e5;display:flex;gap:8px;align-items:flex-start}' +
            '.gp-ficha-cab h5{font-size:1rem;margin:0;font-weight:600;line-height:1.3}.gp-ficha-cab small{color:#6c757d;display:block}' +
            '.gp-ficha-corpo{flex:1;overflow:auto;padding:10px 14px}' +
            '.gp-ficha-grupo{border:1px solid #e3e9e5;border-radius:8px;margin-bottom:10px}' +
            '.gp-ficha-grupo>h6{font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;color:#2e6b4f;background:#f3f8f5;margin:0;padding:6px 10px;border-radius:8px 8px 0 0;font-weight:600}' +
            '.gp-ficha-campos{padding:8px 10px;display:grid;grid-template-columns:1fr 1fr;gap:8px 10px}' +
            '.gp-ficha-campo{display:flex;flex-direction:column;gap:2px;min-width:0}.gp-ficha-campo.largo{grid-column:1 / -1}' +
            '.gp-ficha-campo label{font-size:.74rem;color:#495057;margin:0}' +
            '.gp-ficha-campo .form-control,.gp-ficha-campo .form-select{font-size:.84rem;padding:.25rem .5rem}' +
            '.gp-ficha-campo .gp-ficha-leitura{background:#f6f7f8;border:1px solid #e3e6e8;border-radius:4px;padding:.25rem .5rem;min-height:30px;white-space:pre-wrap;word-break:break-word}' +
            '.gp-ficha-rod{padding:10px 14px;border-top:1px solid #e3e9e5;display:flex;justify-content:space-between;align-items:center;gap:8px}' +
            '.gp-ficha-rod small{color:#6c757d}' +
            '</style>').appendTo(document.head);
    }

    var painel = null, atual = null;

    function fechar() { if (painel) { painel.removeClass('aberta'); var p = painel; setTimeout(function () { p.remove(); }, 200); painel = null; atual = null; } }

    function original(tr, col) {
        var td = tr.find('td[data-column="' + col + '"]');
        if (!td.length) return null;
        var el = td.find('select, textarea, input').not('.calendar-picker').first();
        return { td: td, el: el.length ? el : null };
    }

    function valorTexto(el) {
        if (!el) return '';
        if (el.is('select')) { var o = el[0].options[el[0].selectedIndex]; return o ? $(o).text() : ''; }
        return String(el.val() || '');
    }

    function montarCampo(tr, col, cfgCampo, ficha) {
        var o = original(tr, col);
        if (!o) return null;
        var largo = /^(TITULO|OBSERVACAO|UNIDADES|RESPONSAVEIS|ARQUIVO)$/.test(col) || (cfgCampo.type === 'textarea');
        var box = $('<div class="gp-ficha-campo">').toggleClass('largo', largo).attr('data-ficha-campo', col);
        var id = 'gpf_' + col + '_' + Date.now();
        $('<label>').attr('for', id).text(rot(cfgCampo.label) || col).appendTo(box);
        var el = o.el, proxy;
        var botaoCelula = o.td.find('button').not('.calendar-picker').first();
        if (!el && botaoCelula.length) {
            // Ex.: Arquivos — o botão da célula abre a lista de arquivos do registro.
            proxy = $('<button type="button" class="btn btn-outline-secondary btn-sm align-self-start">').attr('id', id).html(botaoCelula.html() || TX.abrir)
                .on('click', function () { botaoCelula.trigger('click'); });
        } else if (!el) {
            proxy = $('<div class="gp-ficha-leitura">').text(o.td.text().trim() || TX.vazio);
        } else if (el.hasClass('gp-uc-campo')) {
            // Unidades: abre a lista de escolha da própria célula.
            proxy = $('<input type="text" class="form-control" readonly>').attr('id', id).val(el.val()).css('cursor', 'pointer')
                .on('click', function () { el.trigger('click'); });
            ficha.on('gp-ficha-atualizar', function () { proxy.val(el.val()); });
        } else if (el.is('[readonly]') || el.is('[data-somente-leitura="1"]') || el.prop('disabled') && !el.is('[data-lazy-foreign]')) {
            proxy = $('<div class="gp-ficha-leitura">').text(valorTexto(el) || TX.vazio);
            ficha.on('gp-ficha-atualizar', function () { proxy.text(valorTexto(el) || TX.vazio); });
        } else if (el.is('select')) {
            proxy = $('<select class="form-select">').attr('id', id);
            var copiar = function () { proxy.empty().append(el.children('option').clone()); proxy.val(el.val()); };
            copiar();
            if (el.is('[data-lazy-foreign="1"]')) {
                proxy.on('focus mousedown', function () {
                    if (el.data('lazy-loaded')) return;
                    el.trigger('focus');
                    var tentativas = 0, t = setInterval(function () { if (!el.data('lazy-loading') || ++tentativas > 40) { clearInterval(t); copiar(); } }, 150);
                });
            }
            proxy.on('change', function () { el.val(proxy.val()).trigger('input').trigger('change'); ficha.trigger('gp-ficha-mudou'); });
            ficha.on('gp-ficha-atualizar', function () { if (proxy.val() !== el.val()) { if (!proxy.find('option[value="' + String(el.val()).replace(/"/g, '\\"') + '"]').length) copiar(); proxy.val(el.val()); } });
        } else if (el.is('textarea')) {
            proxy = $('<textarea class="form-control" rows="3">').attr('id', id).val(el.val());
            proxy.on('input', function () { el.val(proxy.val()).trigger('input'); ficha.trigger('gp-ficha-mudou'); });
        } else {
            var data = el.is('[data-calendar-input="1"]');
            proxy = $('<input class="form-control">').attr({ id: id, type: el.attr('type') === 'number' ? 'number' : 'text' }).val(el.val());
            if (data) proxy.attr('placeholder', lang === 'en' ? 'mm/dd/yyyy' : 'dd/mm/aaaa').attr('maxlength', 10);
            proxy.on('input', function () { el.val(proxy.val()).trigger('input'); ficha.trigger('gp-ficha-mudou'); })
                .on('change', function () { el.trigger('change'); ficha.trigger('gp-ficha-mudou'); });
            ficha.on('gp-ficha-atualizar', function () { if (document.activeElement !== proxy[0]) proxy.val(el.val()); });
        }
        if (cfgCampo.tooltip) box.attr('title', rot(cfgCampo.tooltip));
        box.append(proxy);
        box.data('gp-ficha-valor', function () { return el ? String(el.val() || '').trim() : ''; });
        return box;
    }

    function abrir($fragment, tr) {
        fechar();
        var cfg = $fragment.data('cadastro-config') || {}, campos = cfg.fields || {}, ficha = cfg.ficha;
        if (!ficha || !tr || !tr.length) return;
        var grupos = $.isArray(ficha) ? ficha : (ficha.grupos || []), condicoes = ficha.condicoes || {};
        var key = String(tr.attr('data-key') || ''), novo = key.indexOf('novo_') === 0;
        var p = $('<aside class="gp-ficha" role="dialog" aria-modal="false">').appendTo(document.body);
        painel = p; atual = { fragment: $fragment, tr: tr };
        var cab = $('<div class="gp-ficha-cab">').appendTo(p);
        var tit = $('<div class="flex-grow-1">').appendTo(cab);
        var h = $('<h5>').appendTo(tit), sub = $('<small>').appendTo(tit);
        var tituloLinha = function () {
            var cod = valorTexto((original(tr, 'CODIGO') || {}).el || null), t = valorTexto((original(tr, 'TITULO') || {}).el || null);
            var dsc = valorTexto((original(tr, 'DSCTREINAMENTO') || {}).el || null) || valorTexto((original(tr, 'DSCINFRAESTRUTURA') || {}).el || null);
            h.text(novo ? TX.novo : ([cod, t || dsc].filter(Boolean).join(' — ') || TX.titulo));
            sub.text(TX.titulo);
        };
        tituloLinha();
        $('<button type="button" class="btn-close" aria-label="' + TX.fechar + '">').on('click', fechar).appendTo(cab);
        var corpo = $('<div class="gp-ficha-corpo">').appendTo(p);
        grupos.forEach(function (g) {
            var lista = $('<div class="gp-ficha-campos">');
            (g.campos || []).forEach(function (col) {
                // Associação (1 ou mais itens, ex.: Responsáveis): botão que abre a mesma janela do botão da barra.
                if (col && typeof col === 'object' && col.botao) {
                    var alvoBarra = $fragment.find(col.botao).first();
                    if (!alvoBarra.length) return;
                    var caixa = $('<div class="gp-ficha-campo largo">');
                    $('<label>').text(rot(col.rotulo) || $.trim(alvoBarra.text()) || alvoBarra.attr('aria-label') || '').appendTo(caixa);
                    var linhaBotao = $('<div class="d-flex align-items-center gap-2">').appendTo(caixa);
                    $('<button type="button" class="btn btn-outline-success btn-sm">').html((alvoBarra.find('i').first().prop('outerHTML') || '') + ' ' + $('<span>').text(rot(col.acao) || TX.abrir).html())
                        .prop('disabled', novo).on('click', function () { if (!tr.hasClass('selected')) tr.trigger('click'); setTimeout(function () { alvoBarra.prop('disabled', false).trigger('click'); }, 30); }).appendTo(linhaBotao);
                    if (col.dica || novo) $('<small class="text-muted">').text(novo ? TX.gravarAntes : rot(col.dica)).appendTo(linhaBotao);
                    lista.append(caixa);
                    return;
                }
                var c = campos[col]; if (!c) return;
                var campo = montarCampo(tr, col, c, p); if (campo) lista.append(campo);
            });
            if (!lista.children().length) return;
            $('<section class="gp-ficha-grupo">').append($('<h6>').text(rot(g.titulo))).append(lista).appendTo(corpo);
        });
        var aplicarCondicoes = function () {
            Object.keys(condicoes).forEach(function (col) {
                // 'CAMPO' = aparece quando CAMPO está preenchido; {campo: 'CAMPO', valor: 'X'} = aparece quando CAMPO vale X.
                var regra = condicoes[col], campoDep = typeof regra === 'object' && regra ? regra.campo : regra;
                var dep = corpo.find('[data-ficha-campo="' + campoDep + '"]'), alvo = corpo.find('[data-ficha-campo="' + col + '"]');
                if (!dep.length || !alvo.length) return;
                var f = dep.data('gp-ficha-valor'), v = f ? f() : '';
                alvo.toggle(typeof regra === 'object' && regra ? String(v) === String(regra.valor) : v !== '');
            });
        };
        aplicarCondicoes();
        p.on('gp-ficha-mudou', function () { setTimeout(function () { p.trigger('gp-ficha-atualizar'); aplicarCondicoes(); tituloLinha(); }, 60); });
        var rod = $('<div class="gp-ficha-rod">').appendTo(p);
        $('<small>').text(TX.pendente).appendTo(rod);
        var acoes = $('<div class="d-flex gap-1 flex-shrink-0">').appendTo(rod);
        $('<button type="button" class="btn btn-outline-secondary btn-sm">').text(TX.fechar).on('click', fechar).appendTo(acoes);
        var salvar = $fragment.find('[data-save-cadastro]').first();
        if (salvar.length) $('<button type="button" class="btn btn-success btn-sm">').text(TX.salvar).on('click', function () { salvar.trigger('click'); }).appendTo(acoes);
        requestAnimationFrame(function () { p.addClass('aberta'); });
        setTimeout(function () { var primeiro = corpo.find('input:not([readonly]), select, textarea').first(); try { primeiro[0] && primeiro[0].focus({ preventScroll: true }); } catch (e) { /* sem foco */ } }, 220);
    }

    function preparar($fragment) {
        var cfg = $fragment.data('cadastro-config') || {};
        if (!cfg.ficha || $fragment.data('gp-ficha')) return;
        $fragment.data('gp-ficha', true);
        var alvoBarra = $fragment.find('[data-delete-cadastro]').first().parent();
        var botao = $('<button type="button" class="btn btn-sm btn-outline-success grid-crud-button grid-icon-button" data-gp-ficha-abrir>')
            .attr({ title: TX.dica, 'aria-label': TX.detalhes }).html('<i class="bi bi-card-list"></i>').prop('disabled', true);
        if (alvoBarra.length) botao.appendTo(alvoBarra); else botao.prependTo($fragment.find('.crud-toolbar').first());
        var selecionada = function () { return $fragment.find('.menu-batch-grid tbody tr.selected').first(); };
        $fragment.on('click', '.menu-batch-grid tbody tr', function () { botao.prop('disabled', false); if (atual && atual.fragment[0] === $fragment[0] && atual.tr[0] !== this) abrir($fragment, $(this)); });
        botao.on('click', function () { var tr = selecionada(); if (tr.length) abrir($fragment, tr); });
        $fragment.on('dblclick', '.menu-batch-grid tbody td', function (e) {
            var alvo = $(e.target);
            if (alvo.is('input:not([readonly]), select, textarea') || alvo.closest('button').length) return;
            abrir($fragment, $(this).closest('tr'));
        });
        // Registro novo: a ficha abre sozinha, com os campos em grupos.
        $fragment.on('click', '[data-new-cadastro]', function () { setTimeout(function () { var tr = $fragment.find('.menu-batch-grid tbody tr[data-key^="novo_"]').first(); if (tr.length) abrir($fragment, tr); }, 80); });
        // Valores trocados na grade enquanto a ficha está aberta (ex.: datas calculadas) aparecem na ficha.
        $fragment.on('input change', '.menu-batch-grid tbody', function () { if (painel) painel.trigger('gp-ficha-atualizar'); });
    }

    $(document).on('keydown', function (e) { if (e.key === 'Escape' && painel && !$('.modal.show').length && !$('.gp-uc-painel').length) fechar(); });
    $(function () { $('.cadastro-legal-fragment').each(function () { preparar($(this)); }); });
})(window.jQuery);
