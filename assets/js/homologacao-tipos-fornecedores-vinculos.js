/*
 * Homologação de Fornecedores › Tipos de Fornecedores › vínculos (Critérios com nível de exigência / Palavras-chave).
 * Duas listas (disponíveis / vinculados). Marque itens e use as setas; a gravação é imediata
 * (homologacaofornecedores_tiposfornecedores_vinculos.php?acao=gravar → sp_fo_tipofornecedor_vinculos_gravar).
 * Critérios: novos entram com o nível escolhido em "Novos critérios entram como"; o nível de cada vinculado muda na
 * própria linha ou em lote (marcados) → sp_fo_tipofornecedor_nivel_gravar.
 */
(function () {
  'use strict';

  var cfg = window.greenVinculos || { itens: [], textos: {}, url: '' };
  var T = cfg.textos || {};
  var raiz = document.querySelector('[data-vinculos]');
  if (!raiz) return;
  var podeEditar = raiz.getAttribute('data-podeeditar') === '1';
  var status = raiz.querySelector('[data-status]');
  var paineis = {
    disponiveis: raiz.querySelector('[data-painel="disponiveis"]'),
    vinculados: raiz.querySelector('[data-painel="vinculados"]')
  };
  var itens = cfg.itens || [];
  var ocupado = false;
  var comNivel = !!cfg.comNivel;
  var rotuloNivel = cfg.niveisRotulo || {};
  var niveis = cfg.niveis || [];
  var filtroNivel = raiz.querySelector('[data-filtro-nivel]');
  var nivelNovos = raiz.querySelector('[data-nivel-novos]');
  var nivelLote = raiz.querySelector('[data-nivel-lote]');
  var nivelAplicar = raiz.querySelector('[data-nivel-aplicar]');

  function normalizar(texto) {
    return String(texto || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  }

  function itensDo(lado) {
    var filtro = normalizar(paineis[lado].querySelector('[data-filtro]').value).trim();
    var nivel = lado === 'vinculados' && filtroNivel ? filtroNivel.value : '*';
    return itens.filter(function (item) {
      return item.vinculado === (lado === 'vinculados') && (!filtro || normalizar(item.descricao).indexOf(filtro) >= 0)
        && (nivel === '*' || (item.nivel || '') === nivel);
    });
  }

  function desenhar() {
    ['disponiveis', 'vinculados'].forEach(function (lado) {
      var painel = paineis[lado], lista = painel.querySelector('[data-lista]');
      var marcados = {};
      lista.querySelectorAll('input:checked').forEach(function (c) { marcados[c.value] = true; });
      var visiveis = itensDo(lado), total = itens.filter(function (i) { return i.vinculado === (lado === 'vinculados'); }).length;
      lista.innerHTML = '';
      if (!visiveis.length) {
        var vazio = document.createElement('li');
        vazio.className = 'text-muted fst-italic';
        vazio.textContent = T.vazio || 'Nenhum item.';
        lista.appendChild(vazio);
      }
      var frag = document.createDocumentFragment();
      visiveis.forEach(function (item) {
        var li = document.createElement('li'), id = lado + '-' + item.codigo;
        var cb = document.createElement('input');
        cb.type = 'checkbox'; cb.className = 'form-check-input'; cb.value = item.codigo; cb.id = id; cb.disabled = !podeEditar;
        cb.checked = !!marcados[item.codigo];
        var label = document.createElement('label');
        label.htmlFor = id; label.textContent = item.descricao; label.className = 'flex-grow-1';
        li.appendChild(cb); li.appendChild(label);
        if (comNivel && lado === 'vinculados') {
          var sel = document.createElement('select');
          sel.className = 'form-select form-select-sm' + (item.nivel ? '' : ' nivel-vazio');
          sel.disabled = !podeEditar || ocupado;
          sel.setAttribute('aria-label', T.nivel_titulo || 'Nível');
          var opcoes = niveis.slice();
          if (!item.nivel) opcoes.unshift('');
          opcoes.forEach(function (n) {
            var o = document.createElement('option');
            o.value = n; o.textContent = rotuloNivel[n] || n; o.selected = (item.nivel || '') === n;
            sel.appendChild(o);
          });
          sel.addEventListener('click', function (ev) { ev.stopPropagation(); });
          sel.addEventListener('dblclick', function (ev) { ev.stopPropagation(); });
          sel.addEventListener('change', function () { if (sel.value) gravarNivel([item.codigo], sel.value); });
          li.appendChild(sel);
        }
        li.addEventListener('dblclick', function () { if (podeEditar) gravar(lado === 'vinculados' ? [] : [item.codigo], lado === 'vinculados' ? [item.codigo] : []); });
        frag.appendChild(li);
      });
      lista.appendChild(frag);
      painel.querySelector('[data-contagem]').textContent = (T.contagem || '{exibidos} de {total}').replace('{exibidos}', visiveis.length).replace('{total}', total);
    });
    raiz.querySelectorAll('[data-mover]').forEach(function (b) { b.disabled = !podeEditar || ocupado; });
    [nivelNovos, nivelLote, nivelAplicar].forEach(function (c) { if (c) c.disabled = !podeEditar || ocupado; });
  }

  function mensagem(texto, tipo) {
    if (!podeEditar) return;
    status.innerHTML = '';
    var span = document.createElement('span');
    span.className = 'badge text-bg-' + (tipo || 'secondary');
    span.style.whiteSpace = 'normal';
    span.textContent = texto;
    status.appendChild(span);
  }

  function gravarNivel(criterios, nivel) {
    if (ocupado || !criterios.length) return;
    enviar({ modo: 'nivel', criterios: criterios, nivel: nivel }, function (res) {
      return (T.nivel_alterado || 'Nível alterado em {n} critério(s).').replace('{n}', res.alterados);
    });
  }

  function gravar(incluir, remover) {
    if (ocupado || (!incluir.length && !remover.length)) return;
    enviar({ incluir: incluir, remover: remover, nivel: nivelNovos ? nivelNovos.value : '' }, function (res) {
      return (T.gravado || 'Gravado: {i} vinculados, {r} desvinculados.').replace('{i}', res.incluidos).replace('{r}', res.removidos);
    });
  }

  function enviar(corpo, textoOk) {
    ocupado = true; desenhar(); mensagem(T.gravando || 'Gravando...', 'secondary');
    fetch(cfg.url + '&acao=gravar', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(corpo)
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, message: T.erro }; });
    }).then(function (res) {
      if (!res || !res.ok) throw new Error((res && res.message) || T.erro);
      itens = res.itens || itens;
      paineis.disponiveis.querySelectorAll('input:checked').forEach(function (c) { c.checked = false; });
      paineis.vinculados.querySelectorAll('input:checked').forEach(function (c) { c.checked = false; });
      mensagem(textoOk(res), 'success');
    }).catch(function (erro) {
      mensagem(erro.message || T.erro, 'danger');
    }).then(function () { ocupado = false; desenhar(); });
  }

  function marcados(lado) {
    return Array.prototype.map.call(paineis[lado].querySelectorAll('[data-lista] input:checked'), function (c) { return c.value; });
  }

  raiz.querySelectorAll('[data-filtro]').forEach(function (f) { f.addEventListener('input', desenhar); });
  if (filtroNivel) filtroNivel.addEventListener('change', desenhar);
  if (nivelAplicar) nivelAplicar.addEventListener('click', function () { gravarNivel(marcados('vinculados'), nivelLote.value); });
  raiz.querySelectorAll('[data-mover]').forEach(function (botao) {
    botao.addEventListener('click', function () {
      var acao = botao.getAttribute('data-mover');
      if (acao === 'vincular') gravar(marcados('disponiveis'), []);
      else if (acao === 'desvincular') gravar([], marcados('vinculados'));
      else if (acao === 'vincular_todos') gravar(itensDo('disponiveis').map(function (i) { return i.codigo; }), []);
      else if (acao === 'desvincular_todos') {
        // Confirmação sem janela do navegador: o segundo clique (em até 6 s) confirma.
        var todos = itensDo('vinculados').map(function (i) { return i.codigo; });
        if (!todos.length) return;
        if (botao.getAttribute('data-armado') === '1') { botao.removeAttribute('data-armado'); gravar([], todos); return; }
        botao.setAttribute('data-armado', '1');
        mensagem((T.confirmar_todos || 'Desvincular {n} itens? Clique de novo para confirmar.').replace('{n}', todos.length), 'warning');
        setTimeout(function () { botao.removeAttribute('data-armado'); }, 6000);
      }
    });
  });

  desenhar();
})();
