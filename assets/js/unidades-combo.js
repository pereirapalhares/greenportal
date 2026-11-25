/*
 * Unidades como lista de escolha na própria célula (25/11/2026, pedido do usuário).
 * Controle, Meus controles, Treinamentos, Meus treinamentos, Infraestrutura e Minhas infraestruturas:
 * clicar na célula Unidades abre uma lista com as unidades da empresa para marcar (várias), com busca;
 * "Aplicar" marca a linha como alteração pendente e liga o Salvar; ao confirmar o Salvar, as unidades são gravadas pela
 * API de relacionamentos do módulo (a mesma do botão Unidades) antes da gravação da grade.
 * Linha nova: grave o registro antes (a ligação precisa do código).
 */
(function ($) {
    'use strict';
    if (!$ || window.gpUnidadesCombo) return;
    window.gpUnidadesCombo = true;

    var CFG = [
        { sel: '.controle-documentos-grid-fragment', url: 'api/controle_documentos_relacionamentos.php', rel: 'unidades', idParam: 'coddocumento' },
        { sel: '.modulo-legal-treinamentos-grid-fragment', url: 'api/modulo_legal_relacionamentos.php', rel: 'treinamentos_unidades', idParam: 'registro' },
        { sel: '.modulo-legal-infraestrutura-grid-fragment', url: 'api/modulo_legal_relacionamentos.php', rel: 'infraestrutura_unidades', idParam: 'registro' }
    ];
    var lang = String(document.documentElement.lang || 'pt').slice(0, 2).toLowerCase();
    var TX = {
        pt: { buscar: 'Buscar unidade', aplicar: 'Aplicar', cancelar: 'Cancelar', todas: 'Marcar todas', nenhuma: 'Limpar', salvar_antes: 'Grave o registro antes de escolher as unidades.', carregando: 'Carregando...', erro: 'N\u00e3o foi poss\u00edvel gravar as unidades.', sem_permissao: 'Sem permiss\u00e3o para alterar as unidades.', dica: 'Clique para escolher as unidades' },
        en: { buscar: 'Search unit', aplicar: 'Apply', cancelar: 'Cancel', todas: 'Select all', nenhuma: 'Clear', salvar_antes: 'Save the record before choosing units.', carregando: 'Loading...', erro: 'Could not save the units.', sem_permissao: 'You are not allowed to change the units.', dica: 'Click to choose the units' },
        es: { buscar: 'Buscar unidad', aplicar: 'Aplicar', cancelar: 'Cancelar', todas: 'Marcar todas', nenhuma: 'Limpiar', salvar_antes: 'Grabe el registro antes de elegir las unidades.', carregando: 'Cargando...', erro: 'No fue posible grabar las unidades.', sem_permissao: 'Sin permiso para cambiar las unidades.', dica: 'Haga clic para elegir las unidades' }
    }[lang] || null;
    if (!TX) TX = { buscar: 'Buscar unidade', aplicar: 'Aplicar', cancelar: 'Cancelar', todas: 'Marcar todas', nenhuma: 'Limpar', salvar_antes: 'Grave o registro antes de escolher as unidades.', carregando: 'Carregando...', erro: 'N\u00e3o foi poss\u00edvel gravar as unidades.', sem_permissao: 'Sem permiss\u00e3o para alterar as unidades.', dica: 'Clique para escolher as unidades' };

    if (!document.getElementById('gp-unidades-combo-css')) {
        $('<style id="gp-unidades-combo-css">' +
            'td[data-column="UNIDADES"] .gp-uc-campo{cursor:pointer;padding-right:22px;background-image:url("data:image/svg+xml,%3csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 16 16%27%3e%3cpath fill=%27none%27 stroke=%27%23343a40%27 stroke-linecap=%27round%27 stroke-linejoin=%27round%27 stroke-width=%272%27 d=%27m2 5 6 6 6-6%27/%3e%3c/svg%3e");background-repeat:no-repeat;background-position:right 6px center;background-size:12px 10px;text-overflow:ellipsis}' +
            '.gp-uc-painel{position:fixed;z-index:2000;background:#fff;border:1px solid #cfd8d3;border-radius:8px;box-shadow:0 6px 18px rgba(0,0,0,.15);width:320px;max-width:90vw;padding:8px;font-size:.85rem}' +
            '.gp-uc-lista{max-height:260px;overflow:auto;margin:6px 0;border-top:1px solid #eef1ef;border-bottom:1px solid #eef1ef;padding:4px 0}' +
            '.gp-uc-lista label{display:flex;gap:6px;align-items:center;padding:2px 4px;margin:0;cursor:pointer}.gp-uc-lista label:hover{background:#f2f7f4}' +
            '.gp-uc-acoes{display:flex;justify-content:space-between;gap:6px;align-items:center}.gp-uc-msg{color:#b02a37;font-size:.8rem;min-height:1em}' +
            '</style>').appendTo(document.head);
    }

    function cfgDe(el) { for (var i = 0; i < CFG.length; i++) if ($(el).closest(CFG[i].sel).length) return CFG[i]; return null; }
    var painel = null, cache = {}, reposicionar = null;
    // A grade rola dentro de um contêiner: o painel (fixo na tela) acompanha a célula.
    document.addEventListener('scroll', function (e) { if (painel && reposicionar && !(e.target && e.target.closest && e.target.closest('.gp-uc-painel'))) reposicionar(); }, { capture: true, passive: true });
    function fechar() { if (painel) { painel.remove(); painel = null; $(document).off('mousedown.gpuc keydown.gpuc'); } }

    function abrir(input, cfg) {
        fechar();
        var tr = $(input).closest('tr[data-key]'), id = String(tr.attr('data-key') || '');
        var p = $('<div class="gp-uc-painel" role="dialog"></div>').appendTo(document.body);
        painel = p;
        // 25/11/2026: posição fixa na tela (sem rolar a página) e abre para cima quando falta espaço embaixo — antes o foco na busca
        // rolava a tela (parecia um refresh) e, dentro da aba do portal, o botão Aplicar ficava fora da área visível.
        var posicionar = function () {
            if (!painel || painel[0] !== p[0]) return;
            var r = input.getBoundingClientRect(), alt = p.outerHeight(), vh = window.innerHeight, vw = window.innerWidth;
            var top = r.bottom + 2;
            if (top + alt > vh - 4) top = (r.top - alt - 2 >= 4) ? r.top - alt - 2 : Math.max(4, vh - alt - 4);
            p.css({ top: top, left: Math.max(4, Math.min(r.left, vw - p.outerWidth() - 4)) });
        };
        p.css({ visibility: 'hidden' });
        if (!/^\d+$/.test(id)) { p.append($('<div class="gp-uc-msg">').text(TX.salvar_antes)); }
        else {
            if (!cache[cfg.rel + ':' + id]) p.append($('<div class="gp-uc-msg">').text(TX.carregando));
            var dados = { action: 'list', relacionamento: cfg.rel }; dados[cfg.idParam] = id;
            $.ajax({ url: cfg.url, method: 'GET', data: dados, dataType: 'json', global: false /* sem o aviso "Processando..." da grade (parecia um refresh) */ }).done(function (r) {
                if (!painel || painel[0] !== p[0]) return;
                cache[cfg.rel + ':' + id] = r;
                montar(r);
            }).fail(function (x) { if (painel && painel[0] === p[0] && !cache[cfg.rel + ':' + id]) { p.empty().append($('<div class="gp-uc-msg">').text(((x && x.responseJSON) || {}).message || TX.erro)); posicionar(); } });
            function montar(r) {
                var marcadosAntes = p.find('.gp-uc-lista input').length ? p.find('.gp-uc-lista input:checked').map(function () { return this.value; }).get() : null;
                var buscaAntes = p.find('input[type=search]').val() || '';
                p.empty();
                if (!r || !r.ok) { p.append($('<div class="gp-uc-msg">').text((r && r.message) || TX.erro)); return; }
                var pode = !!(r.can_edit || (r.can_add && r.can_delete));
                var marcados = {}, pend = $(input).data('gp-uc-pendente');
                (pend || r.linked || []).forEach(function (v) { marcados[String(v)] = true; });
                if (marcadosAntes) { marcados = {}; marcadosAntes.forEach(function (v) { marcados[v] = true; }); }
                var vhMax = Math.max(120, Math.min(260, window.innerHeight - 170));
                var busca = $('<input type="search" class="form-control form-control-sm">').attr('placeholder', TX.buscar).val(buscaAntes).appendTo(p);
                var lista = $('<div class="gp-uc-lista"></div>').css('max-height', vhMax + 'px').appendTo(p);
                (r.available || []).forEach(function (o) {
                    var cb = $('<input type="checkbox" class="form-check-input mt-0">').val(String(o.id)).prop('checked', !!marcados[String(o.id)]).prop('disabled', !pode);
                    $('<label>').append(cb).append($('<span>').text(o.label)).attr('data-busca', String(o.label || '').toLowerCase()).appendTo(lista);
                });
                busca.on('input', function () { var q = String(this.value || '').toLowerCase().trim(); lista.children('label').each(function () { $(this).toggle(!q || String($(this).attr('data-busca')).indexOf(q) >= 0); }); }).trigger('input');
                try { busca[0].focus({ preventScroll: true }); } catch (e) { /* navegador antigo */ }
                var msg = $('<div class="gp-uc-msg"></div>').text(pode ? '' : TX.sem_permissao).appendTo(p);
                var acoes = $('<div class="gp-uc-acoes"></div>').appendTo(p);
                var esq = $('<div></div>').appendTo(acoes), dir = $('<div></div>').appendTo(acoes);
                if (pode) {
                    $('<button type="button" class="btn btn-link btn-sm p-0 me-2">').text(TX.todas).on('click', function () { lista.find('label:visible input').prop('checked', true); }).appendTo(esq);
                    $('<button type="button" class="btn btn-link btn-sm p-0">').text(TX.nenhuma).on('click', function () { lista.find('input').prop('checked', false); }).appendTo(esq);
                }
                $('<button type="button" class="btn btn-outline-secondary btn-sm me-1">').text(TX.cancelar).on('click', fechar).appendTo(dir);
                if (pode) $('<button type="button" class="btn btn-success btn-sm">').text(TX.aplicar).on('click', function () {
                    // 25/11/2026: igual aos outros campos da grade — "Aplicar" deixa a linha com alteração pendente e liga o Salvar;
                    // as unidades são gravadas quando o usuário confirma o Salvar (ver gravarPendentes).
                    var ids = lista.find('input:checked').map(function () { return Number(this.value); }).get();
                    var nomes = lista.find('input:checked').map(function () { return $(this).next('span').text(); }).get().join('; ');
                    var antes = (r.linked || []).map(String).sort().join(','), agora = ids.map(String).sort().join(',');
                    if (agora === antes) $(input).removeData('gp-uc-pendente'); else $(input).data('gp-uc-pendente', ids).data('gp-uc-cfg', cfg);
                    if (input.value !== nomes) { input.value = nomes; $(input).attr('title', nomes || TX.dica).trigger('input').trigger('change'); }
                    fechar();
                }).appendTo(dir);
                posicionar(); p.css({ visibility: '' });
            }
            // Abre na hora com o que já foi lido (sem o "Carregando..."); a lista é confirmada em seguida pelo servidor.
            if (cache[cfg.rel + ':' + id]) montar(cache[cfg.rel + ':' + id]);
        }
        posicionar(); p.css({ visibility: '' });
        $(window).off('resize.gpuc').on('resize.gpuc', fechar);
        reposicionar = posicionar;
        setTimeout(function () {
            $(document).on('mousedown.gpuc', function (e) { if (painel && !$(e.target).closest('.gp-uc-painel').length && e.target !== input) fechar(); });
            $(document).on('keydown.gpuc', function (e) { if (e.key === 'Escape') fechar(); });
        }, 0);
    }

    // Grava as unidades pendentes antes da gravação da grade (depois que o usuário confirma o Salvar).
    function pendentes() { return $('td[data-column="UNIDADES"] input.gp-uc-campo').filter(function () { return !!$(this).data('gp-uc-pendente'); }); }
    function gravarPendentes(pronto, erro) {
        var lista = pendentes().get(), i = 0;
        (function proximo() {
            if (i >= lista.length) { pronto(); return; }
            var el = lista[i++], cfg = $(el).data('gp-uc-cfg') || cfgDe(el), id = String($(el).closest('tr[data-key]').attr('data-key') || '');
            var env = { action: 'sync', relacionamento: cfg.rel, relation_ids: JSON.stringify($(el).data('gp-uc-pendente')) }; env[cfg.idParam] = id;
            $.ajax({ url: cfg.url, method: 'POST', data: env, dataType: 'json' }).done(function (x) {
                if (!x || !x.ok) { erro((x && x.message) || TX.erro); return; }
                $(el).removeData('gp-uc-pendente').val(x.summary || '').attr('title', x.summary || TX.dica);
                cache[cfg.rel + ':' + id] = x; proximo();
            }).fail(function (x) { erro(((x && x.responseJSON) || {}).message || TX.erro); });
        })();
    }
    document.addEventListener('click', function (e) {
        var b = e.target && e.target.closest ? e.target.closest('#greenPortalCadastroConfirm [data-confirmar]') : null;
        if (!b || !pendentes().length) return;
        e.preventDefault(); e.stopImmediatePropagation();
        b.disabled = true;
        gravarPendentes(function () { b.disabled = false; b.click(); }, function (m) {
            b.disabled = false;
            var corpo = document.querySelector('#greenPortalCadastroConfirm .modal-body');
            if (corpo) $('<div class="text-danger small mt-2">').text(m).appendTo(corpo);
        });
    }, true);

    function preparar(raiz) {
        $(raiz).find('td[data-column="UNIDADES"] input[name$="[UNIDADES]"]').each(function () {
            if (this.getAttribute('data-gp-uc') || !cfgDe(this)) return;
            this.setAttribute('data-gp-uc', '1');
            $(this).addClass('gp-uc-campo').attr('title', this.value || TX.dica);
        });
    }
    $(document).on('click', 'td[data-column="UNIDADES"] input.gp-uc-campo', function (e) {
        var cfg = cfgDe(this); if (!cfg) return;
        e.preventDefault();
        if (painel && painel.data('alvo') === this) { fechar(); return; }
        abrir(this, cfg); if (painel) painel.data('alvo', this);
    });
    $(function () {
        preparar(document);
        if (window.MutationObserver) new MutationObserver(function (m) { for (var i = 0; i < m.length; i++) if (m[i].addedNodes && m[i].addedNodes.length) { preparar(document); break; } }).observe(document.body, { childList: true, subtree: true });
    });
})(window.jQuery);
