/* Migração 359 — botão "Histórico" das grades com histórico obrigatório: ativo com uma linha selecionada; abre o histórico
   do registro (principal/historico_registro.php) na janela de transação do portal. */
(function () {
  function linhaSelecionada(btn) {
    var f = btn.closest('.setup-fragment') || document;
    var tr = f.querySelector('tbody tr[data-row].selected, tbody tr.selected');
    var k = tr ? String(tr.getAttribute('data-key') || '') : '';
    return k && k.indexOf('novo_') !== 0 ? k : '';
  }
  function atualizar() {
    document.querySelectorAll('[data-historico-registro]').forEach(function (b) { b.disabled = !linhaSelecionada(b); });
  }
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-historico-registro]');
    if (!b) { setTimeout(atualizar, 0); return; }
    var id = linhaSelecionada(b); if (!id) return;
    var url = new URL('historico_registro.php', window.location.href);
    url.searchParams.set('tabela', b.getAttribute('data-tabela') || '');
    url.searchParams.set('id', id);
    url.searchParams.set('menu', b.getAttribute('data-menu') || '');
    url.searchParams.set('rotulos', b.getAttribute('data-rotulos') || '{}');
    var titulo = (b.getAttribute('data-titulo') || '') + ' — Histórico', host = window.top || window;
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
