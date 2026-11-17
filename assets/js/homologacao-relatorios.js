/*
 * Homologação de Fornecedores › Relatórios de Homologação (principal/homologacaofornecedores_relatorios.php, migração 314).
 * Catálogo com o nº de itens vermelhos, números e barra de distribuição clicáveis, filtros rápidos (as Estatísticas do legado),
 * grid padrão (filtro e ordem por coluna no servidor, busca, paginação, linhas pastel), agrupar com subtotais, evidências do GED,
 * Ficha do fornecedor, Excel pela fila do Agendador, "Meus relatórios" e o Relatório unificado de funcionários (matriz).
 */
(function () {
  'use strict';
  var C = window.greenRelatorios || {};
  var X = C.textos || {};
  var raiz = document.querySelector('[data-relatorios]');
  if (!raiz) return;
  var form = raiz.querySelector('[data-filtros]');
  var CORES = { VERMELHO: 'Vermelho', AMARELO: 'Amarelo', VERDE: 'Verde', NEUTRO: 'Cinza', BRANCO: 'Branco' };
  var estado = { relatorio: 'docfor', chip: 'todos', colunas: {}, buscaGrid: '', agrupar: '', ordem: { col: '', dir: 'asc' }, pagina: 1, visao: null, dados: null,
    uni: { chip: 'todos', tipofunc: '', busca: '', ordem: { col: '', dir: 'asc' }, pagina: 1 } };
  var contagens = {};
  var seq = 0;
  var chaveUltimo = 'greenportal.relatoriosHomologacao.ultimo.' + (C.usuario || 0);

  function el(tag, cls, texto) { var e = document.createElement(tag); if (cls) e.className = cls; if (texto !== undefined && texto !== null) e.textContent = String(texto); return e; }
  function limpar(n) { while (n.firstChild) n.removeChild(n.firstChild); }
  function q(sel) { return raiz.querySelector(sel); }
  function status(msg, tipo) { var s = q('[data-status]'); s.className = 'painel-status' + (tipo ? ' painel-status-' + tipo : ''); s.textContent = msg || ''; }
  function bola(cor) { var b = el('i', 'painel-bola cor-' + cor); b.setAttribute('aria-hidden', 'true'); return b; }
  function icone(nome) { var i = el('i', 'bi ' + nome); i.setAttribute('aria-hidden', 'true'); return i; }
  function filtros() { var f = {}; ['busca', 'unidade', 'tipo'].forEach(function (k) { var c = form.elements[k]; if (c && c.value) f[k] = c.value; }); return f; }
  function aplicarFiltros(f) { ['busca', 'unidade', 'tipo'].forEach(function (k) { var c = form.elements[k]; if (c) c.value = (f && f[k]) || ''; }); }
  function pedido() {
    var p = filtros(); p.relatorio = estado.relatorio;
    if (estado.relatorio === 'unificado') { p.chip = estado.uni.chip; p.busca_grid = estado.uni.busca; p.tipofunc = estado.uni.tipofunc; p.ordem_col = estado.uni.ordem.col; p.ordem_dir = estado.uni.ordem.dir; }
    else { p.chip = estado.chip; p.colunas = estado.colunas; p.busca_grid = estado.buscaGrid; p.agrupar = estado.agrupar; p.ordem_col = estado.ordem.col; p.ordem_dir = estado.ordem.dir; }
    return p;
  }
  function getJson(acao, params) {
    var u = new URLSearchParams(); u.set('acao', acao);
    Object.keys(params || {}).forEach(function (k) { var v = params[k]; if (v === undefined || v === null || v === '') return; u.set(k, typeof v === 'object' ? JSON.stringify(v) : v); });
    return fetch(C.url + '?' + u.toString(), { credentials: 'same-origin' }).then(resposta);
  }
  function postJson(acao, corpo) {
    return fetch(C.url + '?acao=' + acao, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': C.csrf }, body: JSON.stringify(corpo || {}) }).then(resposta);
  }
  function resposta(r) { return r.json().then(function (j) { if (!r.ok || !j.ok) throw new Error(j.message || 'Erro.'); return j; }); }
  function numero(n) { return Number(n || 0).toLocaleString(C.idioma === 'en' ? 'en-US' : (C.idioma === 'es' ? 'es-ES' : 'pt-BR')); }
  function guardarUltimo() { try { window.localStorage.setItem(chaveUltimo, estado.relatorio); } catch (e) { } }
  function lerUltimo() { try { return window.localStorage.getItem(chaveUltimo) || ''; } catch (e) { return ''; } }

  // ---------- Popups do portal (ficha do fornecedor, evidências) ----------
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
  function abrirFicha(cod, nome) { abrirPopup(C.fichaUrl + '?CODFORNECEDOR=' + encodeURIComponent(cod), (X.fichaTitulo || 'Ficha do fornecedor') + (nome ? ' - ' + nome : ''), true); }
  var GED = { DOCUMENTO: ['fornecedorcriterios', 'CODFORNECEDORXCRITERIOS'], AUDITORIA: ['fornecedorauditoria', 'CODFORNECEDORXTIPOFORNECEDORES'], INTEGRACAO: ['funcionarios', 'CODFUNCIONARIO'],
    DOCFUNC: ['funcionariocriterios', 'CODIGO'], TREINAMENTO: ['funcionariotreinamentos', 'CODIGO'], EQUIPAMENTO: ['fornecedorequipamentos', 'CODEQUIPAMENTO'], QUIMICO: ['fornecedorquimicos', 'CODPRODUTO'], PLANO: ['fornecedorplanos', 'CODPLANOACAO'] };
  function abrirArquivos(cat, reg, titulo) {
    var g = GED[cat]; if (!g || !reg) return;
    abrirPopup(C.arquivosUrl + '?tipo=' + g[0] + '&chave=' + g[1] + '&valor=' + encodeURIComponent(reg), (X.evidencias || 'Evidências') + (titulo ? ' - ' + titulo : ''), false);
  }

  // ---------- Catálogo ----------
  function desenharCatalogo() {
    var c = q('[data-catalogo]'); limpar(c);
    var termo = String((q('[data-procurar]') || {}).value || '').trim().toLowerCase();
    (C.catalogo || []).forEach(function (g) {
      var itens = g.itens.filter(function (it) { return !termo || (it.nome + ' ' + it.sub).toLowerCase().indexOf(termo) >= 0; });
      if (!itens.length) return;
      var bloco = el('div', 'painel-grupo'); bloco.appendChild(el('div', 'painel-grupo-nome', g.nome));
      itens.forEach(function (it) {
        var on = it.id === estado.relatorio;
        var b = el('button', 'painel-consulta rel-item' + (on ? ' ativo' : '')); b.type = 'button'; b.setAttribute('aria-pressed', on ? 'true' : 'false');
        var txt = el('span', 'rel-item-texto'); txt.appendChild(el('span', 'rel-item-nome', it.nome)); txt.appendChild(el('span', 'rel-item-sub', it.sub)); b.appendChild(txt);
        var n = contagens[it.id];
        if (it.url) b.appendChild(icone('bi-box-arrow-up-right'));
        else if (n && n.vermelhos) { var badge = el('span', 'rel-badge', numero(n.vermelhos)); badge.title = numero(n.vermelhos) + ' / ' + numero(n.total); b.appendChild(badge); }
        b.addEventListener('click', function () {
          if (it.url) { abrirPopup(it.url, it.nome, true); return; }
          escolherRelatorio(it.id);
        });
        bloco.appendChild(b);
      });
      c.appendChild(bloco);
    });
  }
  function carregarContagens() {
    getJson('contagens', filtros()).then(function (j) { contagens = j.contagens || {}; desenharCatalogo(); }).catch(function () { });
  }
  function escolherRelatorio(id) {
    estado.relatorio = id; estado.chip = 'todos'; estado.colunas = {}; estado.buscaGrid = ''; estado.agrupar = ''; estado.ordem = { col: '', dir: 'asc' }; estado.pagina = 1; estado.visao = null;
    estado.uni = { chip: 'todos', tipofunc: '', busca: '', ordem: { col: '', dir: 'asc' }, pagina: 1 };
    var bg = q('[data-busca-grid]'); if (bg) bg.value = ''; var ub = q('[data-uni-busca]'); if (ub) ub.value = '';
    guardarUltimo(); desenharCatalogo(); carregar();
  }

  // ---------- Carregar ----------
  function carregar() {
    var meu = ++seq;
    var uni = estado.relatorio === 'unificado';
    q('[data-vista-relatorio]').hidden = uni; q('[data-vista-unificado]').hidden = !uni;
    status(X.carregando || 'Carregando...', 'info');
    var p = pedido(); p.pagina = uni ? estado.uni.pagina : estado.pagina;
    getJson('dados', p).then(function (d) {
      if (meu !== seq) return;
      estado.dados = d;
      q('[data-atualizado]').textContent = new Date().toLocaleString(C.idioma === 'en' ? 'en-US' : 'pt-BR', { dateStyle: 'short', timeStyle: 'short' });
      if (d.unificado) { estado.uni.pagina = d.pagina; desenharUnificado(d); } else { estado.pagina = d.lista.pagina; estado.chip = d.lista.chip; estado.agrupar = d.lista.agrupar; desenharRelatorio(d); }
      status('');
    }).catch(function (e) { if (meu === seq) status(e.message, 'erro'); });
  }

  // ---------- Relatório ----------
  function desenharRelatorio(d) {
    var r = d.relatorio;
    q('[data-rel-nome]').textContent = r.nome + (estado.visao ? ' · ' + estado.visao.nome : '');
    q('[data-rel-desc]').textContent = r.desc;
    q('[data-rel-legado]').textContent = r.legado;
    // Números (clicáveis: viram filtro rápido)
    var k = q('[data-kpis]'); limpar(k);
    d.kpis.forEach(function (x) {
      var on = x.id === estado.chip;
      var b = el('button', 'painel-kpi' + (on ? ' ativo' : '')); b.type = 'button'; b.setAttribute('aria-pressed', on ? 'true' : 'false');
      var t = el('span', 'painel-kpi-rotulo'); t.appendChild(bola(x.cor)); t.appendChild(document.createTextNode(x.rotulo));
      b.appendChild(t); b.appendChild(el('span', 'painel-kpi-valor', x.valor)); b.appendChild(el('span', 'painel-kpi-sub', x.sub));
      b.addEventListener('click', function () { escolherChip(x.id); });
      k.appendChild(b);
    });
    // Barra de distribuição
    var bar = q('[data-dist]'); limpar(bar);
    d.dist.forEach(function (x) {
      var s = el('button', 'rel-faixa cor-' + x.cor + (x.id === estado.chip ? ' ativo' : '')); s.type = 'button';
      s.style.flexGrow = String(Math.max(x.pct, 1.5)); s.title = x.rotulo + ': ' + numero(x.n) + ' (' + x.pct + '%)';
      s.setAttribute('aria-label', s.title); if (x.pct >= 6) s.textContent = numero(x.n);
      s.addEventListener('click', function () { escolherChip(x.id); });
      bar.appendChild(s);
    });
    // Filtros rápidos
    var ch = q('[data-chips]'); limpar(ch);
    d.chips.forEach(function (x) {
      var on = x.id === estado.chip;
      var b = el('button', 'rel-chip' + (on ? ' ativo' : '')); b.type = 'button'; b.setAttribute('aria-pressed', on ? 'true' : 'false');
      if (x.id !== 'todos') b.appendChild(bola(x.cor));
      b.appendChild(el('span', '', x.rotulo)); b.appendChild(el('span', 'rel-chip-n', numero(x.n)));
      b.addEventListener('click', function () { escolherChip(x.id); });
      ch.appendChild(b);
    });
    // Agrupar por
    var ag = q('[data-agrupar]'); limpar(ag);
    var o0 = el('option', '', X.semAgrupar || 'Sem agrupar'); o0.value = ''; ag.appendChild(o0);
    r.agrupar.forEach(function (a) { var o = el('option', '', a.rotulo); o.value = a.k; if (a.k === estado.agrupar) o.selected = true; ag.appendChild(o); });
    // Cabeçalho do grid (padrão: rótulo + filtro por coluna; Ficha logo depois do Fornecedor)
    var thead = q('[data-cabecalho]'); limpar(thead);
    var tr = el('tr'); var colunas = r.colunas;
    colunas.forEach(function (c) {
      var th = el('th'); th.setAttribute('data-column', c.k); th.className = 'rel-col-' + c.k;
      var h = el('div', 'column-heading'); var sp = el('span', '', c.rotulo);
      if (c.k !== 'evidencia') {
        var ic = el('i', 'bi column-sort-icon ' + (estado.ordem.col === c.k ? (estado.ordem.dir === 'asc' ? 'bi-arrow-up' : 'bi-arrow-down') : 'bi-arrow-down-up')); ic.setAttribute('aria-hidden', 'true'); sp.appendChild(ic);
        if (estado.ordem.col === c.k) { th.classList.add(estado.ordem.dir === 'asc' ? 'sort-asc' : 'sort-desc'); th.setAttribute('aria-sort', estado.ordem.dir === 'asc' ? 'ascending' : 'descending'); }
      } else th.classList.add('rel-sem-ordem');
      h.appendChild(sp);
      if (c.k !== 'evidencia') {
        var inp = el('input', 'column-filter'); inp.type = 'search'; inp.setAttribute('data-coluna-filtro', c.k); inp.placeholder = 'Buscar'; inp.setAttribute('aria-label', c.rotulo); inp.value = estado.colunas[c.k] || '';
        h.appendChild(inp);
      }
      th.appendChild(h); tr.appendChild(th);
      if (c.k === 'fornecedor') { var tf = el('th', 'grid-row-actions-heading'); var hf = el('div', 'column-heading'); hf.appendChild(el('span', '', 'Ficha')); tf.appendChild(hf); tr.appendChild(tf); }
    });
    thead.appendChild(tr);
    // Linhas (com cabeçalho de grupo e subtotais quando agrupado)
    var corpo = q('[data-linhas]'); limpar(corpo);
    var ncol = colunas.length + 1;
    if (!d.lista.linhas.length) { var t0 = el('tr'); var td0 = el('td', 'painel-vazio', X.vazio || 'Nada encontrado.'); td0.colSpan = ncol; t0.appendChild(td0); corpo.appendChild(t0); }
    var grupoAtual = null;
    d.lista.linhas.forEach(function (l) {
      if (d.lista.agrupar) {
        var gv = l[d.lista.agrupar] || '—';
        if (gv !== grupoAtual) {
          grupoAtual = gv;
          var st = (d.lista.subtotais || {})[gv] || { n: 0, VERMELHO: 0, AMARELO: 0, VERDE: 0 };
          var tg = el('tr', 'rel-grupo'); var tdg = el('td'); tdg.colSpan = ncol;
          tdg.appendChild(el('strong', '', gv));
          tdg.appendChild(el('span', 'rel-grupo-resumo', ' · ' + numero(st.n) + ' ' + (X.itens || 'item(ns)') + ' · '));
          [['VERMELHO', st.VERMELHO], ['AMARELO', st.AMARELO], ['VERDE', st.VERDE]].forEach(function (p) { var s = el('span', 'rel-grupo-cor'); s.appendChild(bola(p[0])); s.appendChild(document.createTextNode(numero(p[1]))); tdg.appendChild(s); });
          tg.appendChild(tdg); corpo.appendChild(tg);
        }
      }
      var tr2 = el('tr', 'painel-linha linha-' + l.cor); tr2.title = CORES[l.cor] || '';
      colunas.forEach(function (c) {
        var td = el('td'); td.setAttribute('data-column', c.k);
        var v = l[c.k] === undefined ? '' : String(l[c.k]);
        if (c.k === 'fornecedor') { td.className = 'painel-forte'; td.appendChild(el('span', 'visually-hidden', (CORES[l.cor] || l.cor) + ': ')); td.appendChild(document.createTextNode(v)); }
        else if (c.k === 'dias') { td.className = 'painel-forte painel-nowrap' + (v !== '' && Number(v) < 0 ? ' painel-negativo' : ''); td.textContent = v; }
        else if (c.k === 'validade' || c.k === 'inicio' || c.k === 'ultima') { td.className = 'painel-nowrap'; td.textContent = v; }
        else if (c.k === 'evidencia') {
          var n = Number(v || 0);
          if (n > 0 && r.ged) { var a = el('button', 'btn painel-link'); a.type = 'button'; a.appendChild(icone('bi-paperclip')); a.appendChild(document.createTextNode(' ' + n + ' ' + (X.arquivo || 'arquivo(s)'))); a.addEventListener('click', function () { abrirArquivos(l.categoria, l.codregistro, l.documento || l.fornecedor); }); td.appendChild(a); }
          else td.appendChild(el('span', 'painel-mudo', '—'));
        } else td.textContent = v;
        tr2.appendChild(td);
        if (c.k === 'fornecedor') {
          var tda = el('td', 'grid-row-actions'); var b = el('button', 'btn btn-sm btn-outline-primary grid-crud-button'); b.type = 'button';
          b.title = X.abrirFicha || 'Abrir ficha'; b.setAttribute('aria-label', (X.abrirFicha || 'Abrir ficha') + ': ' + l.fornecedor); b.appendChild(icone('bi-building'));
          b.addEventListener('click', function () { abrirFicha(l.codfornecedor, l.fornecedor); }); tda.appendChild(b); tr2.appendChild(tda);
        }
      });
      corpo.appendChild(tr2);
    });
    q('[data-rodape]').textContent = numero(d.lista.total) + ' ' + (X.itens || 'item(ns)');
    var ps = q('[data-pagina]'); ps.textContent = d.lista.pagina + '/' + d.lista.paginas; ps.title = (X.pagina || 'Página') + ' ' + d.lista.pagina + ' ' + (X.de || 'de') + ' ' + d.lista.paginas + ' · ' + numero(d.lista.total) + ' ' + (X.registros || 'registros');
    q('[data-pag="-1"]').disabled = d.lista.pagina <= 1; q('[data-pag="1"]').disabled = d.lista.pagina >= d.lista.paginas;
  }
  function escolherChip(id) { estado.chip = id; estado.pagina = 1; carregar(); }

  // ---------- Relatório unificado ----------
  function desenharUnificado(d) {
    var c = d.contagem || {};
    var k = q('[data-uni-kpis]'); limpar(k);
    [['todos', 'PRIMARIA', 'Funcionários ativos', c.todos, numero(d.fornecedores) + ' fornecedor(es)'], ['lib', 'VERDE', 'Liberados para acesso', c.lib, 'tudo em dia'],
      ['vence', 'AMARELO', 'Vencem em breve', c.vence, 'algum item amarelo'], ['bloq', 'VERMELHO', 'Bloqueados', c.bloq, 'algum item vencido, não atende ou não avaliado']].forEach(function (x) {
      var on = estado.uni.chip === x[0];
      var b = el('button', 'painel-kpi' + (on ? ' ativo' : '')); b.type = 'button'; b.setAttribute('aria-pressed', on ? 'true' : 'false');
      var t = el('span', 'painel-kpi-rotulo'); t.appendChild(bola(x[1])); t.appendChild(document.createTextNode(x[2]));
      b.appendChild(t); b.appendChild(el('span', 'painel-kpi-valor', numero(x[3]))); b.appendChild(el('span', 'painel-kpi-sub', x[4]));
      b.addEventListener('click', function () { estado.uni.chip = x[0]; estado.uni.pagina = 1; carregar(); });
      k.appendChild(b);
    });
    var sel = q('[data-uni-tipo]'); limpar(sel);
    var o0 = el('option', '', X.todosTipos || 'Todos os tipos'); o0.value = ''; sel.appendChild(o0);
    (d.tipos || []).forEach(function (tp) { var o = el('option', '', tp); o.value = tp; if (tp === estado.uni.tipofunc) o.selected = true; sel.appendChild(o); });
    var ch = q('[data-uni-chips]'); limpar(ch);
    [['todos', 'Todos', c.todos, ''], ['bloq', 'Bloqueados', c.bloq, 'VERMELHO'], ['vence', 'Vencem em breve', c.vence, 'AMARELO'], ['lib', 'Liberados', c.lib, 'VERDE']].forEach(function (x) {
      var on = estado.uni.chip === x[0];
      var b = el('button', 'rel-chip' + (on ? ' ativo' : '')); b.type = 'button'; b.setAttribute('aria-pressed', on ? 'true' : 'false');
      if (x[3]) b.appendChild(bola(x[3])); b.appendChild(el('span', '', x[1])); b.appendChild(el('span', 'rel-chip-n', numero(x[2])));
      b.addEventListener('click', function () { estado.uni.chip = x[0]; estado.uni.pagina = 1; carregar(); });
      ch.appendChild(b);
    });
    // Cabeçalho: grupo (Integração / Documento / Treinamento) em cima do nome do item
    var thead = q('[data-uni-cabecalho]'); limpar(thead);
    var tr = el('tr');
    [['nome', 'Funcionário'], ['situacao', 'Situação']].forEach(function (h, i) {
      var th = el('th', 'rel-fixa rel-fixa-' + i); th.setAttribute('data-uni-ordem', h[0]);
      var hd = el('div', 'column-heading'); var sp = el('span', '', h[1]);
      var on = estado.uni.ordem.col === h[0];
      var ic = el('i', 'bi column-sort-icon ' + (on ? (estado.uni.ordem.dir === 'asc' ? 'bi-arrow-up' : 'bi-arrow-down') : 'bi-arrow-down-up')); ic.setAttribute('aria-hidden', 'true'); sp.appendChild(ic);
      hd.appendChild(sp); th.appendChild(hd); tr.appendChild(th);
    });
    d.colunas.forEach(function (col) {
      var th = el('th', 'rel-item-col rel-cat-' + col.cat); th.title = col.grupo + ': ' + col.nome;
      if (col.cat !== 'INTEGRACAO') th.appendChild(el('span', 'rel-item-grupo', col.grupo)); th.appendChild(el('span', 'rel-item-titulo', col.nome));
      tr.appendChild(th);
    });
    thead.appendChild(tr);
    var corpo = q('[data-uni-linhas]'); limpar(corpo);
    if (!d.linhas.length) { var t0 = el('tr'); var td0 = el('td', 'painel-vazio', X.vazio || 'Nada encontrado.'); td0.colSpan = d.colunas.length + 2; t0.appendChild(td0); corpo.appendChild(t0); }
    var corSit = { bloq: 'VERMELHO', vence: 'AMARELO', lib: 'VERDE' };
    d.linhas.forEach(function (l) {
      var tr2 = el('tr', 'rel-uni-linha');
      var tdn = el('td', 'rel-fixa rel-fixa-0');
      tdn.appendChild(el('div', 'painel-forte', l.nome));
      var forn = el('div', 'painel-pequeno'); var bf = el('button', 'btn painel-link', l.fornecedor); bf.type = 'button'; bf.title = X.abrirFicha || 'Abrir ficha'; bf.addEventListener('click', function () { abrirFicha(l.codfornecedor, l.fornecedor); });
      forn.appendChild(bf); if (l.tipofunc) forn.appendChild(document.createTextNode(' · ' + l.tipofunc)); tdn.appendChild(forn);
      var det = [l.unidade, l.nrregistro ? 'Nº ' + l.nrregistro : '', l.setor, l.datainicio ? (X.desde || 'desde') + ' ' + l.datainicio : ''].filter(Boolean).join(' · ');
      if (det) tdn.appendChild(el('div', 'painel-mudo painel-pequeno', det));
      tr2.appendChild(tdn);
      var tds = el('td', 'rel-fixa rel-fixa-1');
      var selo = el('span', 'rel-selo rel-selo-' + l.situacao); selo.appendChild(bola(corSit[l.situacao])); selo.appendChild(document.createTextNode(l.situacao_rotulo)); tds.appendChild(selo);
      tds.appendChild(el('div', 'painel-mudo painel-pequeno', l.emdia + ' ' + (X.emDia || 'em dia')));
      if (l.pendencias) tds.title = l.pendencias;
      tr2.appendChild(tds);
      l.celulas.forEach(function (cel, i) {
        var col = d.colunas[i] || {};
        var td = el('td', 'rel-celula s-' + cel.s);
        var dica = l.nome + ' · ' + (col.nome || '') + ': ' + (cel.s === 'X' ? (X.naoExigido || 'não exigido para o tipo') : (cel.d || ''));
        if (cel.reg && GED[cel.cat]) {
          var b = el('button', 'rel-celula-btn', cel.t); b.type = 'button'; b.title = dica; b.setAttribute('aria-label', dica);
          b.addEventListener('click', function () { abrirArquivos(cel.cat, cel.reg, l.nome + ' · ' + (col.nome || '')); });
          td.appendChild(b);
        } else { td.textContent = cel.t; td.title = dica; }
        tr2.appendChild(td);
      });
      corpo.appendChild(tr2);
    });
    q('[data-uni-rodape]').textContent = numero(d.total) + ' ' + (X.funcionarios || 'funcionário(s)') + ' · colunas = documentos e treinamentos exigidos pelos tipos de funcionário do filtro';
    var ps = q('[data-pagina-uni]'); ps.textContent = d.pagina + '/' + d.paginas; ps.title = (X.pagina || 'Página') + ' ' + d.pagina + ' ' + (X.de || 'de') + ' ' + d.paginas + ' · ' + numero(d.total) + ' ' + (X.registros || 'registros');
    q('[data-pag-uni="-1"]').disabled = d.pagina <= 1; q('[data-pag-uni="1"]').disabled = d.pagina >= d.paginas;
  }

  // ---------- Meus relatórios ----------
  function desenharVisoes() {
    var ul = q('[data-visoes]'); limpar(ul);
    if (!(C.visoes || []).length) { ul.appendChild(el('li', 'painel-mudo painel-pequeno', X.semVisoes || '')); return; }
    C.visoes.forEach(function (v) {
      var li = el('li', 'rel-visao' + (estado.visao && estado.visao.cod === v.cod ? ' ativo' : ''));
      var ab = el('button', 'btn painel-link rel-visao-nome', v.nome); ab.type = 'button'; ab.title = v.nome; ab.addEventListener('click', function () { abrirVisao(v); }); li.appendChild(ab);
      var env = el('button', 'btn btn-sm grid-crud-button ' + (v.envio ? 'btn-success' : 'btn-outline-secondary')); env.type = 'button';
      env.title = v.envio ? (X.envioLigado || 'Envio semanal ligado') : (X.envioDesligado || 'Envio semanal desligado'); env.setAttribute('aria-pressed', v.envio ? 'true' : 'false'); env.setAttribute('aria-label', env.title + ': ' + v.nome);
      env.appendChild(icone(v.envio ? 'bi-bell-fill' : 'bi-bell-slash'));
      env.addEventListener('click', function () { env.disabled = true; postJson('visao_envio', { cod: v.cod, envio: !v.envio }).then(function (j) { C.visoes = j.visoes; desenharVisoes(); }).catch(function (e) { status(e.message, 'erro'); env.disabled = false; }); });
      li.appendChild(env);
      var ex = el('button', 'btn btn-sm btn-outline-danger grid-crud-button'); ex.type = 'button'; ex.title = 'Excluir'; ex.setAttribute('aria-label', 'Excluir: ' + v.nome); ex.appendChild(icone('bi-trash'));
      var conf = false, tconf = null;
      ex.addEventListener('click', function () {
        if (!conf) { conf = true; ex.title = X.excluirConfirma || 'Clique de novo para excluir'; ex.classList.add('rel-confirmar'); status(ex.title + ': ' + v.nome, 'info'); tconf = window.setTimeout(function () { conf = false; ex.classList.remove('rel-confirmar'); status(''); }, 5000); return; }
        window.clearTimeout(tconf); ex.disabled = true;
        postJson('visao_excluir', { cod: v.cod }).then(function (j) { C.visoes = j.visoes; if (estado.visao && estado.visao.cod === v.cod) estado.visao = null; desenharVisoes(); status(''); }).catch(function (e) { status(e.message, 'erro'); ex.disabled = false; });
      });
      li.appendChild(ex);
      ul.appendChild(li);
    });
  }
  function abrirVisao(v) {
    var p = v.parametros || {};
    aplicarFiltros(p.filtros || {});
    estado.relatorio = p.relatorio || v.relatorio || 'docfor';
    estado.visao = { cod: v.cod, nome: v.nome };
    if (estado.relatorio === 'unificado') {
      estado.uni = { chip: p.chip || 'todos', tipofunc: p.tipofunc || '', busca: p.busca_grid || '', ordem: { col: p.ordem_col || '', dir: p.ordem_dir || 'asc' }, pagina: 1 };
      var ub = q('[data-uni-busca]'); if (ub) ub.value = estado.uni.busca;
    } else {
      estado.chip = p.chip || 'todos'; estado.colunas = p.colunas && typeof p.colunas === 'object' && !Array.isArray(p.colunas) ? p.colunas : {}; estado.buscaGrid = p.busca_grid || '';
      estado.agrupar = p.agrupar || ''; estado.ordem = { col: p.ordem_col || '', dir: p.ordem_dir || 'asc' }; estado.pagina = 1;
      var bg = q('[data-busca-grid]'); if (bg) bg.value = estado.buscaGrid;
    }
    desenharCatalogo(); desenharVisoes(); carregar(); carregarContagens();
  }
  var dlg = q('[data-dialogo-visao]'), formVisao = q('[data-form-visao]');
  function abrirDialogo() {
    formVisao.elements.nome.value = estado.visao ? estado.visao.nome : '';
    formVisao.elements.envio.checked = false;
    var cx = q('[data-substituir-caixa]'); cx.hidden = !estado.visao; formVisao.elements.substituir.checked = !!estado.visao;
    if (estado.visao) { q('[data-substituir-nome]').textContent = estado.visao.nome; var atual = (C.visoes || []).filter(function (v) { return v.cod === estado.visao.cod; })[0]; if (atual) formVisao.elements.envio.checked = !!atual.envio; }
    if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', '');
    formVisao.elements.nome.focus();
  }
  raiz.querySelectorAll('[data-salvar-visao]').forEach(function (b) { b.addEventListener('click', abrirDialogo); });
  q('[data-cancelar]').addEventListener('click', function () { dlg.close ? dlg.close() : dlg.removeAttribute('open'); });
  formVisao.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var nome = String(formVisao.elements.nome.value || '').trim(); if (!nome) { formVisao.elements.nome.focus(); return; }
    var corpo = { nome: nome, envio: formVisao.elements.envio.checked, pedido: pedido(), cod: estado.visao && formVisao.elements.substituir.checked ? estado.visao.cod : 0 };
    corpo.pedido.filtros = filtros();
    postJson('visao_salvar', corpo).then(function (j) {
      C.visoes = j.visoes; if (j.visao) estado.visao = { cod: Number(j.visao.CODVISAO), nome: j.visao.NOME };
      dlg.close ? dlg.close() : dlg.removeAttribute('open');
      desenharVisoes(); status(X.salvo || 'Salvo.', 'ok');
      if (estado.dados && !estado.dados.unificado) q('[data-rel-nome]').textContent = estado.dados.relatorio.nome + ' · ' + estado.visao.nome;
    }).catch(function (e) { status(e.message, 'erro'); });
  });

  // ---------- Exportação (fila do Agendador; chega nos alertas) ----------
  function exportar(botao, portaria) {
    if (botao) botao.disabled = true;
    postJson('exportar', { pedido: pedido(), portaria: !!portaria }).then(function (j) { status(j.message || X.exportado, 'ok'); }).catch(function (e) { status(e.message, 'erro'); }).then(function () { if (botao) botao.disabled = false; });
  }
  var bExp = q('[data-vista-relatorio] [data-export-cadastro]'); if (bExp) bExp.addEventListener('click', function () { exportar(bExp, false); });
  var bUExp = q('[data-uni-exportar]'); if (bUExp) bUExp.addEventListener('click', function () { exportar(bUExp, false); });
  var bPort = q('[data-uni-portaria]'); if (bPort) bPort.addEventListener('click', function () { exportar(bPort, true); });
  raiz.querySelectorAll('[data-imprimir]').forEach(function (b) { b.addEventListener('click', function () { window.print(); }); });
  var bRec = q('[data-vista-relatorio] [data-reload-grid]'); if (bRec) bRec.addEventListener('click', function () { carregar(); carregarContagens(); });
  var bURec = q('[data-uni-recarregar]'); if (bURec) bURec.addEventListener('click', function () { carregar(); });

  // ---------- Grid: filtro por coluna, ordenação, busca, paginação, agrupar ----------
  var tempo = null;
  function adiar(fn) { window.clearTimeout(tempo); tempo = window.setTimeout(fn, 450); }
  var grid = q('#relatoriosHomologacaoGrid');
  grid.addEventListener('input', function (ev) {
    var f = ev.target.closest('[data-coluna-filtro]'); if (!f) return;
    var v = String(f.value || '').trim(), k = f.getAttribute('data-coluna-filtro');
    if (v) estado.colunas[k] = v; else delete estado.colunas[k];
    adiar(function () { estado.pagina = 1; carregar(); });
  });
  grid.querySelector('thead').addEventListener('click', function (ev) {
    if (ev.target.closest('input,select,.column-filter')) return;
    var th = ev.target.closest('th[data-column]'); if (!th || th.classList.contains('rel-sem-ordem')) return;
    var col = th.getAttribute('data-column');
    estado.ordem = estado.ordem.col === col ? { col: col, dir: estado.ordem.dir === 'asc' ? 'desc' : 'asc' } : { col: col, dir: 'asc' };
    estado.pagina = 1; carregar();
  });
  q('[data-busca-grid]').addEventListener('input', function (ev) { estado.buscaGrid = String(ev.target.value || '').trim(); adiar(function () { estado.pagina = 1; carregar(); }); });
  q('[data-agrupar]').addEventListener('change', function (ev) { estado.agrupar = ev.target.value; estado.pagina = 1; carregar(); });
  raiz.querySelectorAll('[data-pag]').forEach(function (b) { b.addEventListener('click', function () { estado.pagina += Number(b.getAttribute('data-pag')); carregar(); }); });
  raiz.querySelectorAll('[data-pag-uni]').forEach(function (b) { b.addEventListener('click', function () { estado.uni.pagina += Number(b.getAttribute('data-pag-uni')); carregar(); }); });
  q('[data-uni-busca]').addEventListener('input', function (ev) { estado.uni.busca = String(ev.target.value || '').trim(); adiar(function () { estado.uni.pagina = 1; carregar(); }); });
  q('[data-uni-tipo]').addEventListener('change', function (ev) { estado.uni.tipofunc = ev.target.value; estado.uni.pagina = 1; carregar(); });
  q('[data-uni-cabecalho]').addEventListener('click', function (ev) {
    var th = ev.target.closest('th[data-uni-ordem]'); if (!th) return;
    var col = th.getAttribute('data-uni-ordem');
    estado.uni.ordem = estado.uni.ordem.col === col ? { col: col, dir: estado.uni.ordem.dir === 'asc' ? 'desc' : 'asc' } : { col: col, dir: 'asc' };
    estado.uni.pagina = 1; carregar();
  });

  // ---------- Filtros gerais ----------
  form.addEventListener('input', function (ev) { if (ev.target.name === 'busca') adiar(function () { estado.pagina = 1; estado.uni.pagina = 1; carregar(); carregarContagens(); }); });
  form.addEventListener('change', function (ev) { if (ev.target.name !== 'busca') { estado.pagina = 1; estado.uni.pagina = 1; carregar(); carregarContagens(); } });
  form.addEventListener('submit', function (ev) { ev.preventDefault(); });
  q('[data-limpar]').addEventListener('click', function () { aplicarFiltros({}); estado.pagina = 1; estado.uni.pagina = 1; carregar(); carregarContagens(); });
  q('[data-procurar]').addEventListener('input', desenharCatalogo);

  // ---------- Início: visão salva (link do alerta) > ?relatorio= > último aberto neste navegador ----------
  var ini = C.inicial || {};
  desenharVisoes();
  if (ini.visao) { abrirVisao(ini.visao); return; }
  var ultimo = ini.relatorio || lerUltimo();
  var existe = (C.catalogo || []).some(function (g) { return g.itens.some(function (it) { return it.id === ultimo && !it.url; }); });
  estado.relatorio = existe ? ultimo : 'docfor';
  desenharCatalogo(); carregar(); carregarContagens();
})();
