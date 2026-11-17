<?php
declare(strict_types=1);
// Migração 355: janela "Aprovar — escolher o destino" das abas Licenças e Documentos (incluída por ia_potenciais_categoria.php).
$ehLicenca = $tipo === 'LICENCA';
$potenciaisJs = [];
foreach ($rows as $r) $potenciaisJs[(string)$r['codpotencial']] = ['titulo' => (string)$r['titulo'], 'status' => strtoupper((string)$r['status'])];
?>
<style>
#iaDestinoModal .lista{max-height:300px;overflow:auto;border:1px solid var(--bs-border-color);border-radius:.35rem}
#iaDestinoModal .lista table{margin:0;font-size:.78rem}
#iaDestinoModal .lista td,#iaDestinoModal .lista th{vertical-align:middle;padding:.3rem .45rem}
#iaDestinoModal .lista tr[data-id]{cursor:pointer}
#iaDestinoModal .lista thead th{position:sticky;top:0;background:var(--bs-tertiary-bg,#f6f7f8);z-index:1}
#iaDestinoModal .outros{max-height:140px;overflow:auto}
#iaDestinoModal .form-label{font-size:.78rem;margin-bottom:.15rem}
</style>
<div class="modal fade" id="iaDestinoModal" tabindex="-1" aria-labelledby="iaDestinoTitulo" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable"><form class="modal-content" method="post" id="iaDestinoForm">
    <div class="modal-header py-2"><h6 class="modal-title" id="iaDestinoTitulo"><i class="bi bi-check2-circle me-1"></i><?= $ehLicenca ? 'Aprovar potencial de licença' : 'Aprovar potencial de documento de controle' ?></h6><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
    <div class="modal-body small">
      <input type="hidden" name="action" value="aprovar_destino"><input type="hidden" name="codpotencial" data-destino-potencial>
      <div class="mb-2"><span class="text-muted">Potencial sugerido pela IA:</span> <strong data-destino-titulo></strong></div>
      <div class="d-flex gap-3 mb-2">
        <div class="form-check"><input class="form-check-input" type="radio" name="modo" id="iaDestinoExistente" value="EXISTENTE" checked><label class="form-check-label" for="iaDestinoExistente"><?= $ehLicenca ? 'Vincular a licenças já cadastradas' : 'Vincular a um documento já cadastrado' ?></label></div>
        <div class="form-check"><input class="form-check-input" type="radio" name="modo" id="iaDestinoNovo" value="NOVO"><label class="form-check-label" for="iaDestinoNovo"><?= $ehLicenca ? 'Criar nova licença' : 'Criar novo documento' ?></label></div>
      </div>
      <div data-secao="EXISTENTE">
        <div class="text-muted mb-1"><?= $ehLicenca ? 'Um mesmo tipo (ex.: "Licença Ambiental") pode ter várias licenças. Marque todas as que atendem este requisito.' : 'Um mesmo procedimento pode atender vários controles. Escolha o documento que atende este requisito.' ?></div>
        <div class="row g-2 align-items-end mb-2">
          <div class="col"><label class="form-label" for="iaDestinoBusca">Buscar</label><input type="search" class="form-control form-control-sm" id="iaDestinoBusca" data-destino-busca placeholder="<?= $ehLicenca ? 'Nome, número ou tipo da licença' : 'Código ou título do documento' ?>" autocomplete="off"></div>
          <?php if ($ehLicenca): ?>
          <div class="col-md-4"><label class="form-label" for="iaDestinoFiltroTipo">Tipo documental</label><select class="form-select form-select-sm" id="iaDestinoFiltroTipo" data-destino-filtro-tipo><option value="">Todos</option><?php foreach ($iaDestinoTipos as $t): ?><option value="<?= (int)$t['CODTIPODOCUMENTO'] ?>"><?= $esc($t['DSCTIPODOCUMENTO']) ?></option><?php endforeach; ?></select></div>
          <div class="col-auto"><div class="form-check mb-1"><input class="form-check-input" type="checkbox" id="iaDestinoTodas" data-destino-todas><label class="form-check-label" for="iaDestinoTodas">Todas as unidades</label></div></div>
          <?php endif; ?>
        </div>
        <div class="lista"><table class="table table-sm table-hover"><thead><tr><th style="width:32px"></th><?php if ($ehLicenca): ?><th>Licença</th><th>Tipo documental</th><th>Nº</th><th>Validade</th><th>Unidade</th><?php else: ?><th>Código</th><th>Título</th><?php endif; ?><th title="Requisitos legais já atendidos por este registro">Requisitos</th></tr></thead><tbody data-destino-lista><tr><td colspan="7" class="text-muted">Carregando…</td></tr></tbody></table></div>
        <div class="text-muted mt-1" data-destino-contagem></div>
      </div>
      <div data-secao="NOVO" hidden>
        <?php if ($ehLicenca): ?>
        <div class="mb-1">A licença será criada com o título sugerido pela IA e vinculada ao requisito. Os demais dados (número, validade, arquivo) são completados depois no cadastro de Licenças.</div>
        <div class="col-md-6"><label class="form-label" for="iaDestinoTipoNovo">Tipo documental da nova licença</label><select class="form-select form-select-sm" id="iaDestinoTipoNovo" name="codtipodocumento"><option value="">— escolha —</option><?php foreach ($iaDestinoTipos as $t): ?><option value="<?= (int)$t['CODTIPODOCUMENTO'] ?>"><?= $esc($t['DSCTIPODOCUMENTO']) ?></option><?php endforeach; ?></select></div>
        <?php else: ?>
        <div>O documento de controle será criado com o código <strong>IA-<span data-destino-codigo></span></strong> e o título sugerido pela IA, vinculado ao requisito. O arquivo é anexado depois no cadastro de Controles.</div>
        <?php endif; ?>
      </div>
      <?php if (!$ehLicenca): ?>
      <div class="mt-3" data-destino-outros-bloco hidden>
        <div class="fw-semibold mb-1">Aprovar também, apontando para o mesmo documento:</div>
        <div class="outros border rounded p-2" data-destino-outros></div>
      </div>
      <?php endif; ?>
      <div class="alert alert-danger py-1 mt-2 mb-0" data-destino-erro hidden></div>
    </div>
    <div class="modal-footer py-2"><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-sm btn-success" data-destino-ok><i class="bi bi-check2 me-1"></i>Aprovar</button></div>
  </form></div>
</div>
<script>
(function () {
  'use strict';
  var TIPO = <?= json_encode($tipo) ?>, SOLICITACAO = <?= (int)$id ?>, POTENCIAIS = <?= json_encode($potenciaisJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var modalEl = document.getElementById('iaDestinoModal'), form = document.getElementById('iaDestinoForm');
  if (!modalEl || !window.bootstrap) return;
  var modal = new bootstrap.Modal(modalEl), lista = modalEl.querySelector('[data-destino-lista]'), busca = modalEl.querySelector('[data-destino-busca]');
  var filtroTipo = modalEl.querySelector('[data-destino-filtro-tipo]'), todas = modalEl.querySelector('[data-destino-todas]'), erro = modalEl.querySelector('[data-destino-erro]');
  var contagem = modalEl.querySelector('[data-destino-contagem]'), escolhidos = {}, atual = null, timer = null, pedido = 0;
  function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function norm(v) { return String(v || '').toLocaleLowerCase('pt-BR').normalize('NFD').replace(/[̀-ͯ]/g, ''); }
  function data(v) { if (!v) return ''; var m = String(v).match(/^(\d{4})-(\d{2})-(\d{2})/); return m ? m[3] + '/' + m[2] + '/' + m[1] : v; }
  function modo() { return form.querySelector('input[name=modo]:checked').value; }
  function mostrarSecao() {
    modalEl.querySelectorAll('[data-secao]').forEach(function (s) { s.hidden = s.getAttribute('data-secao') !== modo(); });
    erro.hidden = true;
  }
  // Tipo documental cujo nome aparece no título sugerido (o mais longo)
  function tipoDoTitulo(titulo) {
    var t = norm(titulo), melhor = null;
    var sel = filtroTipo || modalEl.querySelector('#iaDestinoTipoNovo'); if (!sel) return '';
    Array.prototype.forEach.call(sel.options, function (o) { var n = norm(o.text); if (o.value && n.length > 3 && t.indexOf(n) >= 0 && (!melhor || n.length > norm(melhor.text).length)) melhor = o; });
    return melhor ? melhor.value : '';
  }
  function carregar() {
    var meu = ++pedido, q = new URLSearchParams({ acao: 'destinos', codsolicitacao: String(SOLICITACAO), busca: busca.value.trim() });
    if (todas && todas.checked) q.set('todas', '1');
    if (filtroTipo && filtroTipo.value) q.set('codtipo', filtroTipo.value);
    lista.innerHTML = '<tr><td colspan="7" class="text-muted">Carregando…</td></tr>';
    fetch(window.location.pathname + '?' + q.toString(), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      if (meu !== pedido) return;
      if (!d.ok) throw new Error(d.message || 'Falha ao carregar.');
      var itens = d.itens || [], html = '';
      itens.forEach(function (it) {
        var marcado = !!escolhidos[it.id], ja = Number(it.JA_VINCULADA) > 0, sel = TIPO === 'LICENCA' ? 'checkbox' : 'radio';
        html += '<tr data-id="' + esc(it.id) + '"' + (marcado ? ' class="table-active"' : '') + '><td><input class="form-check-input" type="' + sel + '" name="ids[]" value="' + esc(it.id) + '"' + (marcado ? ' checked' : '') + ' aria-label="Escolher"></td>';
        if (TIPO === 'LICENCA') html += '<td>' + esc(it.titulo) + (ja ? ' <span class="badge text-bg-secondary">já vinculada</span>' : '') + '</td><td>' + esc(it.DSCTIPODOCUMENTO) + '</td><td>' + esc(it.NUMERO_LICENCA) + '</td><td>' + esc(data(it.DATA_VALIDADE)) + '</td><td>' + esc(it.DSCUNIDADE) + '</td>';
        else html += '<td>' + esc(it.CODIGO) + '</td><td>' + esc(it.titulo) + (ja ? ' <span class="badge text-bg-secondary">já vinculado</span>' : '') + '</td>';
        html += '<td class="text-center">' + esc(it.QTD_REQUISITOS) + '</td></tr>';
      });
      lista.innerHTML = html || '<tr><td colspan="7" class="text-muted">' + (TIPO === 'LICENCA' ? 'Nenhuma licença encontrada' + (todas && !todas.checked ? ' nesta unidade (marque "Todas as unidades" ou use "Criar nova licença").' : '.') : 'Nenhum documento encontrado.') + '</td></tr>';
      contagem.textContent = (itens.length >= 300 ? 'Mostrando os 300 primeiros: refine a busca. ' : '') + Object.keys(escolhidos).length + ' escolhido(s).';
      if (atual && !itens.length && !busca.value && !Object.keys(escolhidos).length && (!filtroTipo || !filtroTipo.value) && (!todas || !todas.checked) && TIPO === 'CONTROLE') { form.querySelector('#iaDestinoNovo').checked = true; mostrarSecao(); }
    }).catch(function (e) { if (meu === pedido) lista.innerHTML = '<tr><td colspan="7" class="text-danger">' + esc(e.message) + '</td></tr>'; });
  }
  lista.addEventListener('click', function (ev) {
    var tr = ev.target.closest('tr[data-id]'); if (!tr) return;
    var inp = tr.querySelector('input'); if (ev.target !== inp) inp.checked = TIPO === 'LICENCA' ? !inp.checked : true;
    if (TIPO !== 'LICENCA') { escolhidos = {}; lista.querySelectorAll('tr[data-id]').forEach(function (x) { x.classList.remove('table-active'); }); }
    if (inp.checked) escolhidos[inp.value] = true; else delete escolhidos[inp.value];
    tr.classList.toggle('table-active', inp.checked);
    contagem.textContent = Object.keys(escolhidos).length + ' escolhido(s).';
  });
  busca.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(carregar, 400); });
  busca.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); clearTimeout(timer); carregar(); } });
  if (filtroTipo) filtroTipo.addEventListener('change', carregar);
  if (todas) todas.addEventListener('change', carregar);
  form.querySelectorAll('input[name=modo]').forEach(function (r) { r.addEventListener('change', mostrarSecao); });
  form.addEventListener('submit', function (ev) {
    erro.hidden = true;
    // Só os escolhidos visíveis ou não (a lista pode ter sido filtrada depois de marcar)
    form.querySelectorAll('input[type=hidden][name="ids[]"]').forEach(function (x) { x.remove(); });
    lista.querySelectorAll('input[name="ids[]"]').forEach(function (x) { x.disabled = true; });
    Object.keys(escolhidos).forEach(function (id) { var h = document.createElement('input'); h.type = 'hidden'; h.name = 'ids[]'; h.value = id; form.appendChild(h); });
    var falta = '';
    if (modo() === 'EXISTENTE' && !Object.keys(escolhidos).length) falta = TIPO === 'LICENCA' ? 'Marque pelo menos uma licença ou escolha "Criar nova licença".' : 'Escolha o documento ou "Criar novo documento".';
    if (falta) { ev.preventDefault(); erro.textContent = falta; erro.hidden = false; lista.querySelectorAll('input[name="ids[]"]').forEach(function (x) { x.disabled = false; }); return; }
    modalEl.querySelector('[data-destino-ok]').disabled = true;
  });
  window.iaDestinoAbrir = function (codpotencial) {
    atual = String(codpotencial); escolhidos = {};
    var p = POTENCIAIS[atual] || { titulo: '' };
    modalEl.querySelector('[data-destino-potencial]').value = atual;
    modalEl.querySelector('[data-destino-titulo]').textContent = p.titulo || '—';
    var cod = modalEl.querySelector('[data-destino-codigo]'); if (cod) cod.textContent = atual;
    form.querySelector('#iaDestinoExistente').checked = true; mostrarSecao();
    busca.value = ''; if (todas) todas.checked = false;
    var tp = tipoDoTitulo(p.titulo);
    if (filtroTipo) filtroTipo.value = tp;
    var tpNovo = modalEl.querySelector('#iaDestinoTipoNovo'); if (tpNovo) tpNovo.value = tp;
    var bloco = modalEl.querySelector('[data-destino-outros-bloco]');
    if (bloco) {
      var html = '';
      Object.keys(POTENCIAIS).forEach(function (k) { if (k !== atual && POTENCIAIS[k].status === 'SUGERIDO') html += '<div class="form-check"><input class="form-check-input" type="checkbox" name="outros[]" value="' + esc(k) + '" id="iaOutro' + esc(k) + '"><label class="form-check-label" for="iaOutro' + esc(k) + '">' + esc(POTENCIAIS[k].titulo) + '</label></div>'; });
      modalEl.querySelector('[data-destino-outros]').innerHTML = html; bloco.hidden = !html;
    }
    modalEl.querySelector('[data-destino-ok]').disabled = false;
    carregar(); modal.show();
  };
}());
</script>
