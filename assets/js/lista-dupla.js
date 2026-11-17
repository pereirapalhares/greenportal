/*
 * Lista dupla (dual listbox) do portal — migração 388 (17/11/2026). Ver components/lista_dupla.php.
 * Os dois lados vêm do servidor (ld_listar), com busca, filtros extras (esquerda) e paginação; › e ‹ gravam na hora (ld_mover).
 * A espera de 1,5 s depois da digitação vem do filtro-atraso.js do portal (o campo fica em .crud-search).
 */
(function ($) {
    'use strict';
    function esc(v) { return $('<div>').text(v == null ? '' : String(v)).html(); }
    function host() { try { return window.top && window.top.GreenPortalPopups ? window.top : (window.parent && window.parent.GreenPortalPopups ? window.parent : null); } catch (e) { return null; } }

    function montar(section) {
        var $s = $(section), cfg = $s.data('lista-dupla') || {}, url = cfg.url || (window.location.pathname + window.location.search);
        var estado = { esquerda: { pagina: 1, total: null, porPagina: 25, itens: [], req: 0 }, direita: { pagina: 1, total: null, porPagina: 25, itens: [], req: 0 } };
        var $lado = function (l) { return $s.find('[data-ld-lado="' + l + '"]'); };
        var ocupado = false;

        function aviso(t, k) {
            if (window.LP && typeof window.LP.aviso === 'function') return window.LP.aviso(t, k);
            if (t && k === 'danger') { var h = host(); if (h) h.GreenPortalPopups.mensagem({ titulo: 'Atenção', mensagem: t, icone: 'bi-exclamation-triangle text-warning' }); }
        }
        function filtros(l) {
            var f = {};
            $lado(l).find('[data-ld-filtro]').each(function () { var v = String($(this).val() || '').trim(); if (v !== '' && v !== '0') f[$(this).attr('data-ld-filtro')] = v; });
            return f;
        }
        function post(dados) {
            return $.ajax({ url: url, method: 'POST', dataType: 'json', data: $.extend({ ajax: '1', csrf: cfg.csrf || '' }, dados) });
        }
        function marcados(l) { return $lado(l).find('tbody tr[data-id]').filter(function () { return $(this).find('[data-ld-marca]').is(':checked'); }).map(function () { return String($(this).attr('data-id')); }).get(); }
        function botoes() {
            $s.find('[data-ld-mover="direita"]').prop('disabled', ocupado || !cfg.editavel || !marcados('esquerda').length);
            $s.find('[data-ld-mover="esquerda"]').prop('disabled', ocupado || !cfg.editavel || !marcados('direita').length);
        }
        function render(l) {
            var st = estado[l], $c = $lado(l).find('[data-ld-corpo]'), cols = cfg.colunas || [];
            if (!st.itens.length) $c.html('<tr class="ld-vazio"><td colspan="' + (cols.length + 1) + '">' + esc(cfg.vazio[l]) + '</td></tr>');
            else $c.html(st.itens.map(function (it) {
                var b = it.bloqueado ? String(it.bloqueado) : '';
                return '<tr data-id="' + esc(it.id) + '"' + (b ? ' class="ld-bloqueada" title="' + esc(b) + '"' : '') + '><td class="ld-marca">' + (cfg.editavel && !b ? '<input class="form-check-input" type="checkbox" data-ld-marca>' : (b ? '<i class="bi bi-lock" title="' + esc(b) + '"></i>' : '')) + '</td>'
                    + cols.map(function (k, i) { var v = (it.cells || {})[k], d = i === 0 && it.detalhe ? String(it.detalhe) : ''; return '<td data-column="' + esc(k) + '" title="' + esc(v) + (d ? ' — ' + esc(d) : '') + '">' + esc(v) + (d ? '<small class="ld-detalhe">' + esc(d) + '</small>' : '') + '</td>'; }).join('') + '</tr>';
            }).join(''));
            var paginas = st.total !== null ? Math.max(1, Math.ceil(st.total / st.porPagina)) : null;
            $lado(l).find('[data-ld-total]').text(st.total !== null ? st.total.toLocaleString('pt-BR') : '…');
            $lado(l).find('[data-ld-status]').text(st.pagina + '/' + (paginas || '?')).attr('title', st.total !== null ? st.total + ' registros' : '');
            $lado(l).find('[data-ld-pag="-1"]').prop('disabled', st.pagina <= 1);
            $lado(l).find('[data-ld-pag="1"]').prop('disabled', paginas !== null ? st.pagina >= paginas : st.itens.length < st.porPagina);
            $lado(l).find('[data-ld-marcar-pagina]').prop('checked', false);
            botoes();
        }
        // "Carregando..." no centro da lista (sobre a área das linhas) enquanto o servidor responde
        function carregando(l, sim) {
            var $l = $lado(l), $o = $l.children('.ld-carregando');
            if (!sim) { $o.remove(); return; }
            var sc = $l.find('.menu-grid-scroll').get(0);
            if (!$o.length) $o = $('<div class="ld-carregando" role="status" aria-live="polite">Carregando...</div>').appendTo($l);
            if (sc) $o.css({ top: sc.offsetTop + 'px', height: sc.offsetHeight + 'px' });
        }
        function carregar(l) {
            var st = estado[l], n = ++st.req;
            carregando(l, true);
            post({ action: 'ld_listar', lado: l, busca: String($lado(l).find('[data-ld-busca]').val() || '').trim(), pagina: st.pagina, filtros: JSON.stringify(filtros(l)) })
                .done(function (r) {
                    if (n !== st.req) return;
                    if (!r || !r.ok) { aviso((r && r.message) || 'Não foi possível carregar.', 'danger'); return; }
                    st.itens = r.itens || []; st.total = (r.total === null || r.total === undefined) ? null : Number(r.total); st.porPagina = Number(r.por_pagina || 25);
                    render(l);
                })
                .fail(function (x) { if (n === st.req) aviso((x.responseJSON && x.responseJSON.message) || 'Não foi possível carregar.', 'danger'); })
                .always(function () { if (n === st.req) carregando(l, false); });
        }
        function mover(para, ids) {
            if (!ids.length || ocupado) return;
            ocupado = true; botoes();
            post({ action: 'ld_mover', para: para, ids: JSON.stringify(ids) })
                .done(function (r) {
                    if (!r || !r.ok) { aviso((r && r.message) || 'Não foi possível concluir.', 'danger'); return; }
                    aviso(r.message || '', 'success');
                    try { if (r.resumo && window.parent) window.parent.postMessage(r.resumo, '*'); } catch (e) {}
                    section.dispatchEvent(new CustomEvent('lista-dupla-movida', { bubbles: true, detail: r }));
                    carregar('esquerda'); carregar('direita');
                })
                .fail(function (x) { aviso((x.responseJSON && x.responseJSON.message) || 'Não foi possível concluir.', 'danger'); })
                .always(function () { ocupado = false; botoes(); });
        }

        ['esquerda', 'direita'].forEach(function (l) {
            var $l = $lado(l), timer = null;
            $l.on('input search', '[data-ld-busca]', function () { clearTimeout(timer); timer = setTimeout(function () { estado[l].pagina = 1; carregar(l); }, 250); });
            $l.on('change', '[data-ld-filtro]', function () { estado[l].pagina = 1; carregar(l); });
            $l.on('click', '[data-ld-pag]', function () { estado[l].pagina = Math.max(1, estado[l].pagina + Number($(this).attr('data-ld-pag'))); carregar(l); });
            $l.on('change', '[data-ld-marcar-pagina]', function () { var on = this.checked; $l.find('[data-ld-marca]').prop('checked', on).closest('tr').toggleClass('ld-marcada', on); botoes(); });
            $l.on('change', '[data-ld-marca]', function () { $(this).closest('tr').toggleClass('ld-marcada', this.checked); botoes(); });
            $l.on('click', 'tbody tr[data-id]', function (ev) {
                if ($(ev.target).is('[data-ld-marca]') || !cfg.editavel) return;
                var $c = $(this).find('[data-ld-marca]'); if (!$c.length) return;
                $c.prop('checked', !$c.is(':checked')).trigger('change');
            });
            $l.on('dblclick', 'tbody tr[data-id]', function () { if (cfg.editavel && $(this).find('[data-ld-marca]').length) mover(l === 'esquerda' ? 'direita' : 'esquerda', [String($(this).attr('data-id'))]); });
        });
        $s.on('click', '[data-ld-mover]', function () { var para = $(this).attr('data-ld-mover'); mover(para, marcados(para === 'direita' ? 'esquerda' : 'direita')); });
        $s.on('click', '[data-crud-action="reload"],[data-reload-grid]', function (ev) { ev.preventDefault(); ev.stopImmediatePropagation(); carregar('esquerda'); carregar('direita'); });
        carregar('esquerda'); carregar('direita');
    }
    $(function () { $('[data-lista-dupla]').each(function () { if (!this._ldMontada) { this._ldMontada = true; montar(this); } }); });
}(window.jQuery));
