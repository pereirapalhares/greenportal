/*
 * Homologação de Fornecedores › Painel de Homologação (principal/homologacaofornecedores_painel.php).
 * Filtros que valem para tudo, números e consultas que abrem a lista, gráficos clicáveis, exportação, PDF (impressão),
 * cobrança dos responsáveis (alertas na Home), resumo semanal e Ficha do fornecedor (janela de 90% da tela).
 * v8 (migração 370): escolher uma consulta abre a sub-aba do resultado (assets/js/subabas.js).
 */
(function () {
  'use strict';
  var C = window.greenPainel || {};
  var raiz = document.querySelector('[data-painel]');
  if (!raiz) return;
  var form = raiz.querySelector('[data-filtros]');
  var estado = { consulta: 'vencidos', pagina: 1, carregando: false, dados: null, colunas: {}, ordem: { col: '', dir: 'asc' }, buscaGrid: '' };
  var CORES = { VERMELHO: 'Vermelho', AMARELO: 'Amarelo', VERDE: 'Verde', NEUTRO: 'Cinza', BRANCO: 'Branco', PRIMARIA: '' };
  var chaveFiltro = 'greenportal.painelHomologacao.filtro.' + (C.usuario || 0);

  function el(tag, cls, texto) { var e = document.createElement(tag); if (cls) e.className = cls; if (texto !== undefined && texto !== null) e.textContent = String(texto); return e; }
  function limpar(n) { while (n.firstChild) n.removeChild(n.firstChild); }
  function status(msg, tipo) { var s = raiz.querySelector('[data-status]'); s.className = 'painel-status' + (tipo ? ' painel-status-' + tipo : ''); s.textContent = msg || ''; }
  function bola(cor) { var b = el('i', 'painel-bola cor-' + cor); b.setAttribute('aria-hidden', 'true'); return b; }
  function filtros() {
    var f = {}; ['busca', 'unidade', 'tipo', 'responsavel', 'vencimento'].forEach(function (k) { var c = form.elements[k]; if (c && c.value) f[k] = c.value; });
    return f;
  }
  function query(extra) {
    var p = new URLSearchParams(); var f = filtros();
    Object.keys(f).forEach(function (k) { p.set(k, f[k]); });
    Object.keys(extra || {}).forEach(function (k) { p.set(k, extra[k]); });
    return p.toString();
  }
  function lerSalvo() { try { return JSON.parse(window.localStorage.getItem(chaveFiltro) || 'null'); } catch (e) { return null; } }
  function aplicarFiltro(f) { ['busca', 'unidade', 'tipo', 'responsavel', 'vencimento'].forEach(function (k) { var c = form.elements[k]; if (c) c.value = (f && f[k]) || ''; }); }

  // ---------- Carregar ----------
  function carregar() {
    estado.carregando = true; status('Carregando...', 'info');
    fetch(C.url + '?acao=dados&' + query({ consulta: estado.consulta, pagina: estado.pagina, colunas: JSON.stringify(estado.colunas), busca_grid: estado.buscaGrid, ordem_col: estado.ordem.col, ordem_dir: estado.ordem.dir }), { credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok || !j.ok) throw new Error(j.message || 'Não foi possível carregar.'); return j; }); })
      .then(function (d) { estado.dados = d; estado.consulta = d.lista.consulta; estado.pagina = d.lista.pagina; desenhar(d); status(''); })
      .catch(function (e) { status(e.message, 'erro'); })
      .then(function () { estado.carregando = false; });
  }
  function escolher(consulta) {
    estado.consulta = consulta; estado.pagina = 1; carregar();
    // Migração 370: o resultado fica na sub-aba "Resultado da consulta"
    var g = raiz.querySelector('[data-subabas="painel-homologacao"]');
    if (g && window.greenPortalSubabas) { window.greenPortalSubabas.abrir(g, 'resultado'); try { sessionStorage.setItem('gp.subaba:' + location.pathname + ':painel-homologacao', 'resultado'); } catch (e) {} }
    var l = raiz.querySelector('.painel-lista'); if (l && l.scrollIntoView) l.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  // ---------- Desenho ----------
  function desenhar(d) {
    raiz.querySelector('[data-atualizado]').textContent = new Date().toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' });
    // Números
    var k = raiz.querySelector('[data-kpis]'); limpar(k);
    d.kpis.forEach(function (x) {
      var b = el('button', 'painel-kpi' + (x.id && x.id === estado.consulta ? ' ativo' : '')); b.type = 'button';
      if (!x.id) b.disabled = true;
      b.setAttribute('aria-pressed', x.id === estado.consulta ? 'true' : 'false');
      var t = el('span', 'painel-kpi-rotulo'); t.appendChild(bola(x.cor)); t.appendChild(document.createTextNode(x.label));
      b.appendChild(t); b.appendChild(el('span', 'painel-kpi-valor', x.valor)); b.appendChild(el('span', 'painel-kpi-sub', x.sub));
      if (x.id) b.addEventListener('click', function () { escolher(x.id); });
      k.appendChild(b);
    });
    // Consultas prontas
    var c = raiz.querySelector('[data-consultas]'); limpar(c);
    d.grupos.forEach(function (g) {
      var bloco = el('div', 'painel-grupo'); bloco.appendChild(el('div', 'painel-grupo-nome', g.nome));
      g.itens.forEach(function (it) {
        var b = el('button', 'painel-consulta' + (it.id === estado.consulta ? ' ativo' : '')); b.type = 'button';
        b.setAttribute('aria-pressed', it.id === estado.consulta ? 'true' : 'false');
        b.appendChild(el('span', '', it.label)); b.appendChild(el('span', 'painel-consulta-n', it.n));
        b.addEventListener('click', function () { escolher(it.id); });
        bloco.appendChild(b);
      });
      c.appendChild(bloco);
    });
    // Lista
    raiz.querySelector('[data-lista-titulo]').textContent = d.lista.titulo;
    raiz.querySelector('[data-lista-descricao]').textContent = d.lista.descricao;
    var corpo = raiz.querySelector('[data-linhas]'); limpar(corpo);
    if (!d.lista.linhas.length) { var tr0 = el('tr'); var td0 = el('td', 'painel-vazio', 'Nada encontrado nesta consulta com os filtros atuais.'); td0.colSpan = 8; tr0.appendChild(td0); corpo.appendChild(tr0); }
    d.lista.linhas.forEach(function (r) {
      // c456: sem a coluna Cor; a linha fica no tom pastel da cor (regra única) e a cor vai na dica.
      var tr = el('tr', 'painel-linha linha-' + r.cor); tr.title = CORES[r.cor] || '';
      function td(col, cls, texto) { var x = el('td', cls, texto); x.setAttribute('data-column', col); tr.appendChild(x); return x; }
      td('unidade', '', r.unidade);
      var tdf = td('fornecedor', 'painel-forte'); tdf.appendChild(el('span', 'visually-hidden', (CORES[r.cor] || r.cor) + ': ')); tdf.appendChild(document.createTextNode(r.fornecedor));
      var tda = el('td', 'grid-row-actions'); var a = el('button', 'btn btn-sm btn-outline-primary grid-crud-button'); a.type = 'button';
      a.title = 'Abrir ficha'; a.setAttribute('aria-label', 'Abrir ficha: ' + r.fornecedor); var ic = el('i', 'bi bi-building'); ic.setAttribute('aria-hidden', 'true'); a.appendChild(ic);
      a.addEventListener('click', function () { abrirFicha(r.codfornecedor, r.fornecedor); }); tda.appendChild(a); tr.appendChild(tda);
      var tdi = td('item', ''); tdi.appendChild(el('div', '', r.item)); tdi.appendChild(el('div', 'painel-mudo painel-pequeno', r.categoria));
      td('situacao', '', r.situacao);
      td('validade', 'painel-nowrap', r.validade);
      td('dias', 'painel-forte painel-nowrap' + (r.dias !== '' && Number(r.dias) < 0 ? ' painel-negativo' : ''), r.dias);
      td('responsavel', '', r.responsavel);
      corpo.appendChild(tr);
    });
    raiz.querySelector('[data-rodape]').textContent = d.lista.total + ' item(ns)' + (C.externo ? ' · somente os seus fornecedores' : '');
    var ps = raiz.querySelector('[data-pagina]'); ps.textContent = d.lista.pagina + '/' + d.lista.paginas; ps.title = 'Página ' + d.lista.pagina + ' de ' + d.lista.paginas + ' · ' + d.lista.total + ' registros';
    marcarOrdem();
    raiz.querySelector('[data-pag="-1"]').disabled = d.lista.pagina <= 1;
    raiz.querySelector('[data-pag="1"]').disabled = d.lista.pagina >= d.lista.paginas;
    // Situações
    var gs = raiz.querySelector('[data-g-situacao]'); limpar(gs);
    if (!d.situacoes.length) gs.appendChild(el('div', 'painel-mudo', 'Sem documentos no filtro.'));
    d.situacoes.forEach(function (x) {
      var l = el('div', 'painel-barra-linha'); l.appendChild(el('span', '', x.label));
      var trilho = el('span', 'painel-trilho'); var barra = el('span', 'painel-barra cor-' + x.cor); barra.style.width = x.pct + '%'; trilho.appendChild(barra); l.appendChild(trilho);
      l.appendChild(el('span', 'painel-forte painel-direita', x.n)); gs.appendChild(l);
    });
    // Semanas
    var gw = raiz.querySelector('[data-g-semanas]'); limpar(gw);
    d.semanas.forEach(function (w) {
      var b = el('button', 'painel-semana' + (w.id === estado.consulta ? ' ativo' : '')); b.type = 'button';
      b.setAttribute('aria-label', 'Semana de ' + w.label + ': ' + w.n + ' documento(s)');
      b.appendChild(el('span', 'painel-semana-n', w.n));
      var bar = el('span', 'painel-semana-barra ' + (w.amarelo ? 'cor-AMARELO' : 'painel-semana-normal')); bar.style.height = w.h + '%'; b.appendChild(bar);
      b.appendChild(el('span', 'painel-semana-rotulo', w.label));
      b.addEventListener('click', function () { escolher(w.id); });
      gw.appendChild(b);
    });
    // Top fornecedores
    var gt = raiz.querySelector('[data-g-top]'); limpar(gt);
    if (!d.top.length) gt.appendChild(el('div', 'painel-mudo', 'Nenhum fornecedor com documentos vermelhos ou amarelos.'));
    d.top.forEach(function (f) {
      var l = el('div', 'painel-top-linha');
      var a = el('button', 'btn painel-link painel-top-nome', f.nome); a.type = 'button'; a.title = f.nome; a.addEventListener('click', function () { abrirFicha(f.codfornecedor, f.nome); }); l.appendChild(a);
      var trilho = el('span', 'painel-trilho painel-trilho-duplo');
      var v = el('span', 'painel-barra cor-VERMELHO'); v.style.width = f.pv + '%'; trilho.appendChild(v);
      var am = el('span', 'painel-barra cor-AMARELO'); am.style.width = f.pa + '%'; trilho.appendChild(am);
      trilho.title = f.vermelho + ' vermelho(s), ' + f.amarelo + ' amarelo(s)'; l.appendChild(trilho);
      l.appendChild(el('span', 'painel-forte painel-direita', f.n)); gt.appendChild(l);
    });
    // Matriz unidade × situação
    var gm = raiz.querySelector('[data-g-matriz]'); limpar(gm);
    var cols = [['vencido', 'Vencido', 'VERMELHO'], ['pendforn', 'Pend. fornecedor', 'VERMELHO'], ['pendemp', 'Pend. empresa', 'AMARELO'], ['avencer', 'A vencer', 'AMARELO'], ['emdia', 'Em dia', 'VERDE']];
    gm.appendChild(el('span', ''));
    cols.forEach(function (c2) { gm.appendChild(el('span', 'painel-matriz-cab', c2[1])); });
    var maxCol = {}; cols.forEach(function (c2) { maxCol[c2[0]] = Math.max.apply(null, [1].concat(d.matriz.map(function (m) { return m[c2[0]]; }))); });
    if (!d.matriz.length) { var v0 = el('span', 'painel-mudo', 'Sem documentos no filtro.'); v0.style.gridColumn = '1 / -1'; gm.appendChild(v0); }
    d.matriz.forEach(function (m) {
      var un = el('span', 'painel-matriz-unidade', m.unidade); un.title = m.unidade; gm.appendChild(un);
      cols.forEach(function (c2) {
        var n = m[c2[0]], id = 'mat:' + m.id + ':' + c2[0];
        var b = el('button', 'painel-celula cor-' + c2[2] + (n / maxCol[c2[0]] >= 0.5 ? ' forte' : ' fraca') + (n === 0 ? ' zero' : '') + (id === estado.consulta ? ' ativo' : ''), n); b.type = 'button';
        b.setAttribute('aria-label', m.unidade + ', ' + c2[1] + ': ' + n);
        b.addEventListener('click', function () { escolher(id); });
        gm.appendChild(b);
      });
    });
  }

  // ---------- Ficha do fornecedor ----------
  // c455: a ficha é a página homologacaofornecedores_ficha.php, aberta em popup do portal (iframe, 90% da janela).
  function abrirPopup(url, titulo, amplo) {
    var host = window.top || window;
    var abs = new URL(url, window.location.href).toString();
    try {
      if (host.GreenPortalPopups && host.jQuery) {
        var frame = host.jQuery('<iframe>', { src: abs, title: titulo, style: 'width:100%;height:100%;border:0;display:block;' });
        var op = amplo ? { titulo: titulo, conteudo: frame, amplo: true, redimensionavel: true } : { titulo: titulo, conteudo: frame, compacto: true, redimensionavel: true };
        if (typeof host.GreenPortalPopups.transacaoIndependente === 'function') host.GreenPortalPopups.transacaoIndependente(op); else host.GreenPortalPopups.transacao(op);
        return;
      }
    } catch (e) { }
    window.open(abs, '_blank');
  }
  function abrirFicha(cod, nome) {
    abrirPopup((C.fichaUrl || 'homologacaofornecedores_ficha.php') + '?CODFORNECEDOR=' + encodeURIComponent(cod), (C.fichaTitulo || 'Ficha do fornecedor') + (nome ? ' - ' + nome : ''), true);
  }

  // ---------- Grid padrão (c457): filtro por coluna e ordenação no servidor ----------
  var grid = raiz.querySelector('#painelHomologacaoGrid'), tempoColuna = null;
  if (grid) {
    grid.querySelectorAll('thead th[data-column] .column-heading > span').forEach(function (sp) { var i = el('i', 'bi bi-arrow-down-up column-sort-icon'); i.setAttribute('aria-hidden', 'true'); sp.appendChild(i); });
    grid.addEventListener('input', function (ev) {
      var f = ev.target.closest('[data-coluna-filtro]'); if (!f) return;
      var v = String(f.value || '').trim(); if (v) estado.colunas[f.getAttribute('data-coluna-filtro')] = v; else delete estado.colunas[f.getAttribute('data-coluna-filtro')];
      window.clearTimeout(tempoColuna); tempoColuna = window.setTimeout(function () { estado.pagina = 1; carregar(); }, 450);
    });
    grid.querySelector('thead').addEventListener('click', function (ev) {
      if (ev.target.closest('input,select,.column-filter')) return;
      var th = ev.target.closest('th[data-column]'); if (!th) return;
      var col = th.getAttribute('data-column');
      estado.ordem = estado.ordem.col === col ? { col: col, dir: estado.ordem.dir === 'asc' ? 'desc' : 'asc' } : { col: col, dir: 'asc' };
      estado.pagina = 1; carregar();
    });
  }
  var buscaGrid = raiz.querySelector('[data-busca-grid]');
  if (buscaGrid) buscaGrid.addEventListener('input', function () {
    estado.buscaGrid = String(buscaGrid.value || '').trim();
    window.clearTimeout(tempoColuna); tempoColuna = window.setTimeout(function () { estado.pagina = 1; carregar(); }, 450);
  });
  function marcarOrdem() {
    if (!grid) return;
    grid.querySelectorAll('thead th[data-column]').forEach(function (th) {
      var on = th.getAttribute('data-column') === estado.ordem.col, ic = th.querySelector('.column-sort-icon');
      th.classList.toggle('sort-asc', on && estado.ordem.dir === 'asc'); th.classList.toggle('sort-desc', on && estado.ordem.dir === 'desc');
      if (on) th.setAttribute('aria-sort', estado.ordem.dir === 'asc' ? 'ascending' : 'descending'); else th.removeAttribute('aria-sort');
      if (ic) ic.className = 'bi column-sort-icon ' + (on ? (estado.ordem.dir === 'asc' ? 'bi-arrow-up' : 'bi-arrow-down') : 'bi-arrow-down-up');
    });
  }

  // ---------- Botões ----------
  var tempo = null;
  form.addEventListener('input', function (ev) { if (ev.target.name === 'busca') { window.clearTimeout(tempo); tempo = window.setTimeout(function () { estado.pagina = 1; carregar(); }, 450); } });
  form.addEventListener('change', function (ev) { if (ev.target.name !== 'busca') { estado.pagina = 1; carregar(); } });
  form.addEventListener('submit', function (ev) { ev.preventDefault(); estado.pagina = 1; carregar(); });
  raiz.querySelector('[data-limpar]').addEventListener('click', function () { aplicarFiltro({}); estado.pagina = 1; carregar(); });
  raiz.querySelector('[data-salvar-filtro]').addEventListener('click', function () {
    try { window.localStorage.setItem(chaveFiltro, JSON.stringify({ filtros: filtros(), consulta: estado.consulta })); status('Filtro salvo: a tela abrirá com ele da próxima vez.', 'ok'); }
    catch (e) { status('Não foi possível salvar o filtro neste navegador.', 'erro'); }
  });
  raiz.querySelectorAll('[data-pag]').forEach(function (b) { b.addEventListener('click', function () { estado.pagina += Number(b.getAttribute('data-pag')); carregar(); }); });
  // Excel: só o pedido; a planilha é gerada pelo serviço do Agendador do Windows e chega nos alertas com o link.
  var exp = function (consulta, botao) {
    if (botao) botao.disabled = true;
    var corpoReq = filtros(); corpoReq.consulta = consulta;
    fetch(C.url + '?acao=exportar', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': C.csrf }, body: JSON.stringify(corpoReq) })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok || !j.ok) throw new Error(j.message || 'Não foi possível solicitar a exportação.'); return j; }); })
      .then(function (j) { status(j.message || 'A exportação foi solicitada e será disponibilizada nos alertas.', 'ok'); })
      .catch(function (e) { status(e.message, 'erro'); })
      .then(function () { if (botao) botao.disabled = false; });
  };
  var bTudo = raiz.querySelector('[data-exportar-tudo]'); if (bTudo) bTudo.addEventListener('click', function () { exp('todos', bTudo); });
  var bLista = raiz.querySelector('[data-exportar-lista]'); if (bLista) bLista.addEventListener('click', function () { exp(estado.consulta, bLista); });
  var bImp = raiz.querySelector('[data-imprimir]'); if (bImp) bImp.addEventListener('click', function () { window.print(); });
  var bCobrar = raiz.querySelector('[data-cobrar]'), confirmar = false, tConf = null;
  if (bCobrar) {
    var textoCobrar = bCobrar.innerHTML;
    bCobrar.addEventListener('click', function () {
      if (!confirmar) { confirmar = true; bCobrar.textContent = 'Clique de novo para avisar os responsáveis'; tConf = window.setTimeout(function () { confirmar = false; bCobrar.innerHTML = textoCobrar; }, 5000); return; }
      window.clearTimeout(tConf); confirmar = false; bCobrar.innerHTML = textoCobrar; bCobrar.disabled = true;
      var corpoReq = filtros(); corpoReq.consulta = estado.consulta;
      fetch(C.url + '?acao=cobrar', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': C.csrf }, body: JSON.stringify(corpoReq) })
        .then(function (r) { return r.json().then(function (j) { if (!r.ok || !j.ok) throw new Error(j.message || 'Não foi possível avisar.'); return j; }); })
        .then(function (j) { status(j.usuarios + ' responsável(is) avisado(s) na Home sobre ' + j.itens + ' item(ns)' + (j.sem_responsavel ? '; ' + j.sem_responsavel + ' item(ns) sem responsável' : '') + '.', 'ok'); })
        .catch(function (e) { status(e.message, 'erro'); })
        .then(function () { bCobrar.disabled = false; });
    });
  }
  var bResumo = raiz.querySelector('[data-resumo]'), tResumo = raiz.querySelector('[data-resumo-texto]');
  function textoResumo() { tResumo.textContent = C.receberResumo ? 'Resumo semanal: ligado' : 'Resumo semanal: desligado'; bResumo.setAttribute('aria-pressed', C.receberResumo ? 'true' : 'false'); bResumo.title = 'Toda segunda-feira, um resumo das suas pendências chega em Home › Alertas. Clique para ' + (C.receberResumo ? 'desligar' : 'ligar') + '.'; }
  textoResumo();
  bResumo.addEventListener('click', function () {
    bResumo.disabled = true;
    fetch(C.url + '?acao=resumo', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': C.csrf }, body: JSON.stringify({ receber: !C.receberResumo }) })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok || !j.ok) throw new Error(j.message || 'Não foi possível gravar.'); return j; }); })
      .then(function (j) { C.receberResumo = j.receber; textoResumo(); status(j.receber ? 'Você vai receber o resumo semanal.' : 'Resumo semanal desligado.', 'ok'); })
      .catch(function (e) { status(e.message, 'erro'); })
      .then(function () { bResumo.disabled = false; });
  });

  // ---------- Início: link de alerta > filtro salvo > padrão ----------
  var inicial = C.inicial || {};
  var salvo = lerSalvo();
  if (inicial.consulta || inicial.responsavel) { aplicarFiltro({ responsavel: inicial.responsavel || '' }); if (inicial.consulta) estado.consulta = inicial.consulta; }
  else if (salvo && salvo.filtros) { aplicarFiltro(salvo.filtros); if (salvo.consulta) estado.consulta = salvo.consulta; }
  carregar();
})();
