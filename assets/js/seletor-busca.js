/*
 * Escolha em janela com busca (26/11/2026) — para combos de lista longa (ex.: licenças), que no Windows abriam cortados.
 * Ligado por campo na configuração da grade: 'picker' => true. Ao clicar no combo (ou Enter/Espaço/Alt+↓), abre uma
 * janela na tela principal do portal com campo de busca e a lista; a escolha volta para o combo da linha e marca a linha
 * como alteração pendente, como se fosse escolhida no próprio combo.
 */
(function ($) {
    'use strict';
    if (!$ || window.gpSeletorBusca) return;
    window.gpSeletorBusca = true;

    var lang = String(window.greenPortalIdioma || document.documentElement.lang || 'pt').slice(0, 2).toLowerCase();
    var TX = ({
        pt: { buscar: 'Buscar…', escolher: 'Escolher', limpar: 'Deixar em branco', cancelar: 'Cancelar', nenhum: 'Nada encontrado.', carregando: 'Carregando…', itens: '{n} item(ns)' },
        en: { buscar: 'Search…', escolher: 'Choose', limpar: 'Leave blank', cancelar: 'Cancel', nenhum: 'Nothing found.', carregando: 'Loading…', itens: '{n} item(s)' },
        es: { buscar: 'Buscar…', escolher: 'Elegir', limpar: 'Dejar en blanco', cancelar: 'Cancelar', nenhum: 'Nada encontrado.', carregando: 'Cargando…', itens: '{n} ítem(s)' }
    })[lang] || null;
    if (!TX) TX = { buscar: 'Buscar…', escolher: 'Escolher', limpar: 'Deixar em branco', cancelar: 'Cancelar', nenhum: 'Nada encontrado.', carregando: 'Carregando…', itens: '{n} item(ns)' };

    function semAcento(v) { return String(v || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); }

    function configDoCampo(sel) {
        var $f = $(sel).closest('.cadastro-legal-fragment'), cfg = $f.data('cadastro-config') || {};
        var col = String($(sel).closest('td').attr('data-column') || '');
        return { col: col, campo: (cfg.fields || {})[col] || null };
    }

    function rotulo(v) { if (v && typeof v === 'object') return v[lang] || v.pt || ''; return String(v || ''); }

    function aguardarOpcoes(sel, pronto) {
        var $s = $(sel);
        if ($s.is('[data-lazy-foreign="1"]') && !$s.data('lazy-loaded')) {
            $s.trigger('focus');
            var n = 0, t = setInterval(function () { if ((!$s.data('lazy-loading') && $s.data('lazy-loaded')) || ++n > 60) { clearInterval(t); pronto(); } }, 120);
            return;
        }
        pronto();
    }

    function abrir(sel) {
        var info = configDoCampo(sel);
        var hostWin = window, $h = $;
        try { if (window.top !== window && window.top.jQuery && window.top.bootstrap) { hostWin = window.top; $h = window.top.jQuery; } } catch (e) { hostWin = window; $h = $; }
        $h('#gpSeletorBusca').remove();
        var titulo = info.campo ? rotulo(info.campo.label) : '';
        var $m = $h('<div class="modal fade" id="gpSeletorBusca" tabindex="-1" role="dialog" aria-modal="true"><div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">' +
            '<div class="modal-header py-2"><h5 class="modal-title fs-6"></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>' +
            '<div class="modal-body pt-2"><input type="search" class="form-control form-control-sm mb-2" autocomplete="off"><div class="small text-muted mb-1" data-gpsb-total></div><div class="list-group list-group-flush" data-gpsb-lista style="max-height:55vh;overflow:auto"></div></div>' +
            '<div class="modal-footer py-2"><button type="button" class="btn btn-sm btn-link me-auto" data-gpsb-limpar></button><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"></button></div></div></div></div>');
        $m.find('.modal-title').text(titulo);
        $m.find('[data-gpsb-limpar]').text(TX.limpar);
        $m.find('.modal-footer [data-bs-dismiss]').text(TX.cancelar);
        var $busca = $m.find('input[type=search]').attr('placeholder', TX.buscar);
        var $lista = $m.find('[data-gpsb-lista]').text(TX.carregando);
        $h(hostWin.document.body).append($m);
        var modal = hostWin.bootstrap.Modal.getOrCreateInstance($m[0]);
        $m.on('shown.bs.modal', function () { $m.css('z-index', 2300); $h(hostWin.document.body).children('.modal-backdrop').last().css('z-index', 2290); try { hostWin.focus(); $busca[0].focus(); setTimeout(function () { try { $busca[0].focus(); } catch (e2) { /* idem */ } }, 60); } catch (e) { /* sem foco */ } })
          .on('hidden.bs.modal', function () { $m.remove(); try { sel.focus(); } catch (e) { /* idem */ } });
        var escolher = function (valor) {
            $(sel).val(valor).trigger('input').trigger('change');
            modal.hide();
        };
        $m.find('[data-gpsb-limpar]').on('click', function () { escolher(''); });
        modal.show();
        aguardarOpcoes(sel, function () {
            var atual = String($(sel).val() || '');
            var itens = $(sel).children('option').map(function () {
                var v = String(this.value || ''); if (v === '' || v === '0') return null;
                return { v: v, t: $.trim($(this).text()), d: String($(this).attr('title') || '') };
            }).get();
            var desenhar = function () {
                var q = semAcento($busca.val()).trim(), partes = q.split(/\s+/).filter(Boolean), n = 0;
                $lista.empty();
                itens.forEach(function (it) {
                    var alvo = semAcento(it.t + ' ' + it.d);
                    if (partes.some(function (p) { return alvo.indexOf(p) < 0; })) return;
                    n++;
                    if (n > 400) return;
                    $h('<button type="button" class="list-group-item list-group-item-action py-1 small text-wrap">').toggleClass('active', it.v === atual)
                        .attr('title', it.d || it.t).text(it.t).on('click', function () { escolher(it.v); }).appendTo($lista);
                });
                if (!n) $lista.append($h('<div class="text-muted small p-2">').text(TX.nenhum));
                $m.find('[data-gpsb-total]').text(TX.itens.replace('{n}', n));
            };
            $busca.on('input', desenhar).on('keydown', function (e) {
                if (e.key === 'Enter') { var b = $lista.children('button').first(); if (b.length) { e.preventDefault(); b.trigger('click'); } }
            });
            desenhar();
            var ativo = $lista.children('.active')[0]; if (ativo) ativo.scrollIntoView({ block: 'center' });
        });
    }

    function deveAbrir(sel) { var info = configDoCampo(sel); return !!(info.campo && info.campo.picker) && !sel.disabled; }

    document.addEventListener('mousedown', function (e) {
        var sel = e.target && e.target.closest ? e.target.closest('.cadastro-legal-fragment select.grid-input') : null;
        if (!sel || e.button !== 0 || !deveAbrir(sel)) return;
        e.preventDefault();
        $(sel).closest('tr').trigger('click');
        abrir(sel);
    }, true);
    document.addEventListener('keydown', function (e) {
        var sel = e.target;
        if (!sel || sel.tagName !== 'SELECT' || !$(sel).is('.cadastro-legal-fragment select.grid-input') || !deveAbrir(sel)) return;
        if (e.key === 'Enter' || e.key === ' ' || (e.altKey && e.key === 'ArrowDown') || e.key === 'F4') { e.preventDefault(); abrir(sel); }
    }, true);
})(window.jQuery);
