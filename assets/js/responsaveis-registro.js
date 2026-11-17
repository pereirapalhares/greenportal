/* Migração 361 — botão "Responsáveis" das grades dos cinco módulos: ativo com uma linha selecionada; abre os responsáveis
   do registro (principal/responsaveis_regras.php?area=&registro=) na janela de transação do portal. */
(function () {
  function linhaSelecionada(btn) {
    var f = btn.closest('.setup-fragment') || document;
    var tr = f.querySelector('tbody tr[data-row].selected, tbody tr.selected');
    var k = tr ? String(tr.getAttribute('data-key') || '') : '';
    return /^\d+$/.test(k) ? k : '';
  }
  function atualizar() {
    document.querySelectorAll('[data-resp-registro]').forEach(function (b) { b.disabled = !linhaSelecionada(b); });
  }
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-resp-registro]');
    if (!b) { setTimeout(atualizar, 0); return; }
    var id = linhaSelecionada(b); if (!id) return;
    var url = new URL('responsaveis_regras.php', window.location.href);
    url.searchParams.set('area', b.getAttribute('data-area') || '');
    url.searchParams.set('registro', id);
    var titulo = (b.getAttribute('data-titulo') || '') + ' — Responsáveis', host = window.top || window;
    try { if (host.GreenPortalPopups && typeof host.GreenPortalPopups.transacao90 === 'function') { host.GreenPortalPopups.transacao90({ titulo: titulo, url: url.toString() }); return; } } catch (e) {}
    window.open(url.toString(), '_blank');
  }, true);
  document.addEventListener('DOMContentLoaded', atualizar);
  // a grade marca a linha (classe "selected") depois do clique: acompanha a mudança de classe das linhas
  var agendado = false;
  new MutationObserver(function (ms) {
    if (agendado) return;
    for (var i = 0; i < ms.length; i++) if (ms[i].target.tagName === 'TR' || ms[i].type === 'childList') { agendado = true; setTimeout(function () { agendado = false; atualizar(); }, 30); return; }
  }).observe(document.documentElement, { subtree: true, attributes: true, attributeFilter: ['class'], childList: true });
  atualizar();
}());
