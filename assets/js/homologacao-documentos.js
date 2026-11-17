/*
 * Homologação de Fornecedores › Documentos do fornecedor (principal/homologacaofornecedor_fornecedorxcriterios.php).
 * Grid com cores, filtros por coluna, ordenação, seleção (clique), marcação (caixas), formulário de edição (duplo clique),
 * histórico, copiar/colar (campos vazios + evidências), reiniciar (segundo clique confirma), gerar e exportar.
 * Gravação: ?acao=salvar | copiar | reiniciar | gerar (POST JSON, cabeçalho X-CSRF-Token).
 * c486: padrão de grid do portal — barra greenPortalCrudButtons (grupos), busca geral, filtro e ordenação no cabeçalho
 * (th[data-column] .column-heading + .column-filter), células em caixa (.grid-local-campo), linha selecionada (.selected),
 * paginação na barra, botão Colunas (escolha guardada neste navegador).
 */
(function () {
  'use strict';
  var C = window.greenDocs || {}, T = C.textos || {};
  var raiz = document.querySelector('[data-docs]');
  if (!raiz) return;
  var rows = C.rows || [], selecionado = null, copia = null, filtros = {}, corFiltro = '', ordem = { col: '', dir: 1 }, ocupado = false;
  var corpo = raiz.querySelector('[data-corpo]'), cab = raiz.querySelector('[data-cab]'), busca = raiz.querySelector('[data-busca]');
  var paginacao = raiz.querySelector('[data-paginacao]'), pagina = 1, porPagina = 50, ocultas = [];
  var chaveColunas = 'gridColunas:homologacaofornecedor_fornecedorxcriterios';
  // Filtro por lista (Todos / valores) nas colunas de poucos valores; texto nas demais.
  var colunasLista = ['NIVEL_DE_EXIGENCIA', 'SITUACAO', 'EMPROTOCOLO', 'ENVIADOODOCUMENTO', 'RESPONSAVEL', 'RESPONSAVEL_FORNECEDOR', 'INCLUIDOCONTRATO'];
  var larguras = { DOCUMENTO: 300, NIVEL_DE_EXIGENCIA: 110, SITUACAO: 190, DATADEVALIDADE: 100, QTDDIAS: 120, EMPROTOCOLO: 110, ENVIADOODOCUMENTO: 100,
    RESPONSAVEL: 180, RESPONSAVEL_FORNECEDOR: 180, ARQUIVOS: 90, DATADEREACAO: 110, INCLUIDOCONTRATO: 110, OBSERVACAO: 240, OBSERVACAO_DO_FORNECEDOR: 240 };
  try { var salvas = JSON.parse(window.localStorage.getItem(chaveColunas) || '[]'); if (Array.isArray(salvas)) ocultas = salvas; } catch (e) { ocultas = []; }
  var statusEl = raiz.querySelector('[data-status]'), contagemEl = raiz.querySelector('[data-contagem]'), legendaEl = raiz.querySelector('[data-legenda]');
  var ordemCores = ['VERMELHO', 'AMARELO', 'VERDE', 'NEUTRO', 'BRANCO'];
  var colunas = ['DOCUMENTO', 'NIVEL_DE_EXIGENCIA', 'SITUACAO', 'DATADEVALIDADE', 'QTDDIAS', 'EMPROTOCOLO', 'ENVIADOODOCUMENTO', 'RESPONSAVEL', 'RESPONSAVEL_FORNECEDOR', 'ARQUIVOS', 'DATADEREACAO', 'INCLUIDOCONTRATO', 'OBSERVACAO', 'OBSERVACAO_DO_FORNECEDOR'];

  function txt(v) { return v === null || v === undefined ? '' : String(v); }
  function norm(v) { return txt(v).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); }
  function dataBR(v) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(txt(v)); if (!m) return ''; return C.idioma === 'en' ? m[2] + '/' + m[3] + '/' + m[1] : m[3] + '/' + m[2] + '/' + m[1]; }
  function rotulo(mapa, v) { return v && mapa && mapa[v] !== undefined ? mapa[v] : txt(v); }
  function protocolo(v) { return v === 'S' ? (C.simNao.Sim || 'Sim') : (v === 'N' ? (C.simNao['Não'] || 'Não') : txt(v)); }
  function dias(r) {
    if (r.QTDDIAS === null || r.QTDDIAS === undefined || r.QTDDIAS === '') return '';
    var n = Number(r.QTDDIAS);
    return n < 0 ? (T.vencido || 'vencido há {n} dias').replace('{n}', -n) : (T.dias || '{n} dias').replace('{n}', n);
  }
  function valor(r, col) {
    switch (col) {
      case 'DOCUMENTO': return txt(r.DSCTIPO) + ' / ' + txt(r.DSCRITERIO);
      case 'SITUACAO': return rotulo(C.situacoes, r.SITUACAO);
      case 'DATADEVALIDADE': return dataBR(r.DATADEVALIDADE);
      case 'QTDDIAS': return dias(r);
      case 'EMPROTOCOLO': return protocolo(r.EMPROTOCOLO);
      case 'ENVIADOODOCUMENTO': case 'INCLUIDOCONTRATO': return rotulo(C.simNao, r[col]);
      case 'RESPONSAVEL_FORNECEDOR': return Number(r.CODUSUARIO_FORNECEDOR) === 99999 ? (T.sem_resp_forn || '') : txt(r.RESPONSAVEL_FORNECEDOR);
      case 'ARQUIVOS': return '';
      default: return txt(r[col]);
    }
  }
  function chaveOrdem(r, col) {
    if (col === 'QTDDIAS') return r.QTDDIAS === null || r.QTDDIAS === undefined ? 1e9 : Number(r.QTDDIAS);
    if (col === 'DATADEVALIDADE') return txt(r.DATADEVALIDADE) || '9999';
    if (col === 'DATADEREACAO') return Number(r.DATADEREACAO || 0);
    return norm(valor(r, col));
  }
  function mensagem(texto, tipo) {
    statusEl.innerHTML = '';
    if (!texto) return;
    var s = document.createElement('span'); s.className = 'badge text-bg-' + (tipo || 'secondary'); s.textContent = texto; statusEl.appendChild(s);
  }
  function visiveis() {
    var lista = rows.filter(function (r) {
      if (corFiltro && r.COR !== corFiltro) return false;
      for (var c in filtros) {
        if (!filtros[c]) continue;
        var v = norm(valor(r, c)), f = norm(filtros[c]);
        if (colunasLista.indexOf(c) >= 0 ? v !== f : v.indexOf(f) < 0) return false;
      }
      var geral = norm(busca ? busca.value : '');
      if (geral && norm(colunas.map(function (c) { return valor(r, c); }).join(' ')).indexOf(geral) < 0) return false;
      return true;
    });
    if (ordem.col) lista.sort(function (a, b) { var x = chaveOrdem(a, ordem.col), y = chaveOrdem(b, ordem.col); return (x < y ? -1 : x > y ? 1 : 0) * ordem.dir; });
    return lista;
  }

  function montarCabecalho() {
    var th = document.createElement('th'); th.className = 'docs-col-marca text-center';
    var todos = document.createElement('input'); todos.type = 'checkbox'; todos.className = 'form-check-input'; todos.title = T.marcar_todos || T.colar || '';
    todos.addEventListener('change', function () { corpo.querySelectorAll('input[data-marca]').forEach(function (c) { c.checked = todos.checked; }); });
    th.appendChild(todos); cab.appendChild(th);
    var thc = document.createElement('th'); thc.className = 'docs-col-cor'; cab.appendChild(thc);
    colunas.forEach(function (col) {
      var h = document.createElement('th'), rot = (C.colunas || {})[col] || col;
      h.dataset.column = col; h.style.width = (larguras[col] || 140) + 'px';
      var cabeca = document.createElement('div'); cabeca.className = 'column-heading';
      var span = document.createElement('span'); span.textContent = rot;
      var icone = document.createElement('i'); icone.className = 'bi bi-arrow-down-up column-sort-icon'; icone.setAttribute('aria-hidden', 'true');
      span.appendChild(icone); cabeca.appendChild(span);
      if (col !== 'ARQUIVOS') {
        var filtro;
        if (colunasLista.indexOf(col) >= 0) {
          filtro = document.createElement('select'); filtro.className = 'column-filter form-select form-select-sm';
          var op = document.createElement('option'); op.value = ''; op.textContent = T.todos || 'Todos'; filtro.appendChild(op);
          var vistos = {};
          rows.forEach(function (r) { var v = valor(r, col); if (v !== '') vistos[v] = true; });
          Object.keys(vistos).sort(function (a, b) { return a.localeCompare(b, 'pt-BR', { numeric: true, sensitivity: 'base' }); })
            .forEach(function (v) { var o = document.createElement('option'); o.value = v; o.textContent = v; filtro.appendChild(o); });
          filtro.addEventListener('change', function () { filtros[col] = filtro.value; pagina = 1; desenhar(); });
        } else {
          filtro = document.createElement('input'); filtro.type = 'search'; filtro.className = 'column-filter'; filtro.placeholder = T.buscar || T.filtrar || '';
          filtro.addEventListener('input', function () { filtros[col] = filtro.value; pagina = 1; desenhar(); });
        }
        filtro.setAttribute('data-column-filter', col); filtro.setAttribute('aria-label', rot);
        filtro.addEventListener('click', function (ev) { ev.stopPropagation(); });
        cabeca.appendChild(filtro);
      }
      h.appendChild(cabeca);
      h.addEventListener('click', function (ev) {
        if (ev.target.closest('.column-filter')) return;
        ordem.dir = ordem.col === col ? -ordem.dir : 1; ordem.col = col; desenhar();
      });
      cab.appendChild(h);
    });
  }

  function legenda() {
    var cont = {}; rows.forEach(function (r) { cont[r.COR] = (cont[r.COR] || 0) + 1; });
    legendaEl.innerHTML = '';
    var todos = document.createElement('button'); todos.type = 'button'; todos.textContent = (T.todas_cores || 'Todos') + ' (' + rows.length + ')';
    if (!corFiltro) todos.className = 'ativo';
    todos.addEventListener('click', function () { corFiltro = ''; desenhar(); });
    legendaEl.appendChild(todos);
    ordemCores.forEach(function (c) {
      if (!cont[c]) return;
      var b = document.createElement('button'); b.type = 'button'; b.title = (C.cores || {})[c] || c;
      if (corFiltro === c) b.className = 'ativo';
      var bola = document.createElement('span'); bola.className = 'bola cor-' + c; b.appendChild(bola);
      b.appendChild(document.createTextNode(((C.cores || {})[c] || c) + ' (' + cont[c] + ')'));
      b.addEventListener('click', function () { corFiltro = corFiltro === c ? '' : c; desenhar(); });
      legendaEl.appendChild(b);
    });
  }

  function campo(texto, extraClasse) {
    var d = document.createElement('div'); d.className = 'grid-local-campo' + (extraClasse ? ' ' + extraClasse : ''); d.textContent = texto; d.title = texto; return d;
  }
  function desenhar() {
    var marcados = {}; corpo.querySelectorAll('input[data-marca]:checked').forEach(function (c) { marcados[c.value] = true; });
    var lista = visiveis(), paginas = Math.max(1, Math.ceil(lista.length / porPagina));
    if (pagina > paginas) pagina = paginas;
    var pag = lista.slice((pagina - 1) * porPagina, pagina * porPagina);
    corpo.innerHTML = '';
    if (!pag.length) {
      var tr0 = document.createElement('tr'), td0 = document.createElement('td'); tr0.className = 'grid-local-vazio'; td0.colSpan = colunas.length + 2;
      td0.textContent = rows.length ? (T.sem_filtro || T.vazio || '') : (T.vazio || '');
      tr0.appendChild(td0); corpo.appendChild(tr0);
    }
    var frag = document.createDocumentFragment();
    pag.forEach(function (r) {
      var tr = document.createElement('tr'), id = String(r.CODFORNECEDORXCRITERIOS);
      tr.dataset.id = id; tr.setAttribute('data-row', ''); tr.className = 'linha-' + r.COR + (selecionado === id ? ' selected' : '');
      tr.title = (C.cores || {})[r.COR] || '';
      var tdm = document.createElement('td'); tdm.className = 'docs-col-marca text-center';
      var cb = document.createElement('input'); cb.type = 'checkbox'; cb.className = 'form-check-input'; cb.value = id; cb.setAttribute('data-marca', ''); cb.checked = !!marcados[id];
      cb.addEventListener('click', function (ev) { ev.stopPropagation(); });
      tdm.appendChild(cb); tr.appendChild(tdm);
      var tdc = document.createElement('td'); tdc.className = 'cor cor-' + r.COR; tr.appendChild(tdc);
      colunas.forEach(function (col) {
        var td = document.createElement('td'); td.dataset.column = col;
        if (col === 'DOCUMENTO') {
          var d = campo(txt(r.DSCRITERIO), 'docs-campo-doc'); d.title = txt(r.DSCTIPO) + ' / ' + txt(r.DSCRITERIO);
          var t = document.createElement('span'); t.className = 'tipo'; t.textContent = txt(r.DSCTIPO);
          d.insertBefore(t, d.firstChild);
          td.appendChild(d);
        } else if (col === 'ARQUIVOS') {
          var b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-sm btn-outline-secondary grid-crud-button docs-evidencias'; b.title = (T.evidencias || '') + ' - ' + txt(r.DSCRITERIO);
          b.innerHTML = '<i class="bi bi-files"></i> <span data-qtd-arquivos>' + (contagens[id] !== undefined ? contagens[id] : '') + '</span>';
          b.addEventListener('click', function (ev) { ev.stopPropagation(); abrirEvidencias(r); });
          td.appendChild(b);
        } else {
          td.appendChild(campo(valor(r, col)));
        }
        if (ocultas.indexOf(col) >= 0) td.hidden = true;
        tr.appendChild(td);
      });
      tr.addEventListener('click', function () { selecionar(id); });
      tr.addEventListener('dblclick', function () { selecionar(id); editar(); });
      frag.appendChild(tr);
    });
    corpo.appendChild(frag);
    cab.querySelectorAll('th[data-column]').forEach(function (h) {
      var col = h.dataset.column, ativo = col === ordem.col, ic = h.querySelector('.column-sort-icon');
      h.hidden = ocultas.indexOf(col) >= 0;
      h.classList.toggle('sort-asc', ativo && ordem.dir === 1); h.classList.toggle('sort-desc', ativo && ordem.dir === -1);
      if (ic) ic.className = 'bi column-sort-icon ' + (ativo ? (ordem.dir === 1 ? 'bi-arrow-up' : 'bi-arrow-down') : 'bi-arrow-down-up');
    });
    // Largura fixa = soma das colunas visíveis (as caixas não esticam a coluna; o texto completo fica na dica)
    var tabela = raiz.querySelector('[data-tabela]'), soma = 40;
    colunas.forEach(function (c) { if (ocultas.indexOf(c) < 0) soma += larguras[c] || 140; });
    if (tabela) tabela.style.setProperty('width', soma + 'px', 'important');
    if (paginacao) {
      var st = paginacao.querySelector('.grid-page-status');
      st.textContent = pagina + '/' + paginas;
      st.title = (T.contagem || '{exibidos} de {total}').replace('{exibidos}', lista.length).replace('{total}', rows.length) + ' · ' + (T.pagina || 'Página') + ' ' + pagina + '/' + paginas;
      paginacao.querySelector('.grid-page-prev').disabled = pagina <= 1;
      paginacao.querySelector('.grid-page-next').disabled = pagina >= paginas;
    }
    legenda();
    botoes();
  }
  function selecionar(id) {
    selecionado = id;
    corpo.querySelectorAll('tr.selected').forEach(function (t) { t.classList.remove('selected'); });
    var tr = corpo.querySelector('tr[data-id="' + id + '"]'); if (tr) tr.classList.add('selected');
    botoes();
  }
  function linha(id) { for (var i = 0; i < rows.length; i++) if (String(rows[i].CODFORNECEDORXCRITERIOS) === String(id)) return rows[i]; return null; }
  function botoes() {
    var tem = !!linha(selecionado);
    var mapa = { editar: tem, historico: tem, copiar: tem && C.podeEditar, colar: !!copia && C.podeEditar, reiniciar: tem && C.podeReiniciar, gerar: C.podeGerar !== undefined ? !!C.podeGerar : C.podeEditar, exportar: C.podeExportar, recarregar: true };
    raiz.querySelectorAll('[data-acao]').forEach(function (b) { var a = b.getAttribute('data-acao'); if (mapa[a] !== undefined) b.disabled = !mapa[a] || ocupado; });
    var exp = raiz.querySelector('[data-crud-action="export"]'); if (exp && !exp.hasAttribute('data-permission-locked')) exp.disabled = !C.podeExportar || ocupado;
    var rec = raiz.querySelector('[data-crud-action="reload"]'); if (rec) rec.disabled = ocupado;
  }

  // Quantidade de evidências por documento (api/ged_contagens.php)
  var contagens = {};
  function contarArquivos() {
    var ids = rows.map(function (r) { return Number(r.CODFORNECEDORXCRITERIOS); });
    if (!ids.length) return;
    fetch('api/ged_contagens.php?tipo=fornecedorcriterios&ids=' + encodeURIComponent(JSON.stringify(ids)), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); }).then(function (d) {
        if (!d || !d.ok) return;
        contagens = d.counts || {};
        corpo.querySelectorAll('tr[data-id]').forEach(function (tr) { var s = tr.querySelector('[data-qtd-arquivos]'); if (s) s.textContent = String(contagens[tr.dataset.id] || 0); });
      }).catch(function () {});
  }
  function abrirEvidencias(r) {
    var host = window.top || window, url = new URL('gestao_arquivos.php?tipo=fornecedorcriterios&chave=CODFORNECEDORXCRITERIOS&valor=' + encodeURIComponent(r.CODFORNECEDORXCRITERIOS), window.location.href).toString();
    var titulo = (T.evidencias || 'Evidências') + ' - ' + txt(r.DSCRITERIO);
    if (host.GreenPortalPopups && host.jQuery) {
      var frame = host.jQuery('<iframe>', { src: url, style: 'width:100%;height:100%;border:0;display:block;' });
      frame.on('load', function () { contarArquivos(); });
      var opcoes = { titulo: titulo, conteudo: frame, compacto: true, redimensionavel: true };
      if (typeof host.GreenPortalPopups.transacaoIndependente === 'function') host.GreenPortalPopups.transacaoIndependente(opcoes); else host.GreenPortalPopups.transacao(opcoes);
    } else window.open(url, '_blank');
  }

  function enviar(acao, corpoReq) {
    ocupado = true; botoes(); mensagem(T.gravando || 'Gravando...', 'secondary');
    return fetch(C.url + '&acao=' + acao, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': C.csrf },
      body: JSON.stringify(corpoReq || {})
    }).then(function (r) { return r.json().catch(function () { return { ok: false, message: T.erro }; }); })
      .then(function (res) { if (!res || !res.ok) throw new Error((res && res.message) || T.erro); return res; })
      .finally(function () { ocupado = false; botoes(); });
  }
  function substituir(row) { for (var i = 0; i < rows.length; i++) if (String(rows[i].CODFORNECEDORXCRITERIOS) === String(row.CODFORNECEDORXCRITERIOS)) rows[i] = row; }

  // Formulário de edição
  var modalForm = document.getElementById('docForm'), form = modalForm.querySelector('[data-form]'), formStatus = form.querySelector('[data-form-status]');
  var bsForm = window.bootstrap ? bootstrap.Modal.getOrCreateInstance(modalForm) : null;
  function opcoes(sel, lista, atual, extra) {
    sel.innerHTML = '';
    var vazio = document.createElement('option'); vazio.value = ''; sel.appendChild(vazio);
    (extra || []).concat(lista).forEach(function (o) { var op = document.createElement('option'); op.value = o.v; op.textContent = o.t; sel.appendChild(op); });
    if (atual && !sel.querySelector('option[value="' + atual + '"]')) { var op2 = document.createElement('option'); op2.value = atual; op2.textContent = '#' + atual; sel.appendChild(op2); }
    sel.value = atual || '';
  }
  function editar() {
    var r = linha(selecionado); if (!r) { mensagem(T.sem_selecao, 'warning'); return; }
    form.dataset.id = r.CODFORNECEDORXCRITERIOS;
    form.querySelector('[data-form-titulo]').textContent = txt(r.DSCRITERIO);
    form.querySelector('[data-form-documento]').textContent = txt(r.DSCTIPO) + ' / ' + txt(r.DSCRITERIO) + (r.NIVEL_DE_EXIGENCIA ? ' (' + r.NIVEL_DE_EXIGENCIA + ')' : '');
    form.querySelector('[data-form-cobranca]').textContent = dataBR(r.DATACOBRANCA_EMPROTOCOLO) || '—';
    form.SITUACAO.value = txt(r.SITUACAO);
    form.DATADEVALIDADE.value = txt(r.DATADEVALIDADE).substring(0, 10);
    form.DATADEREACAO.value = txt(r.DATADEREACAO);
    form.ENVIADOODOCUMENTO.value = txt(r.ENVIADOODOCUMENTO);
    form.EMPROTOCOLO.value = txt(r.EMPROTOCOLO);
    form.INCLUIDOCONTRATO.value = txt(r.INCLUIDOCONTRATO);
    form.OBSERVACAO.value = txt(r.OBSERVACAO);
    form.OBSERVACAO_DO_FORNECEDOR.value = txt(r.OBSERVACAO_DO_FORNECEDOR);
    opcoes(form.CODUSUARIO, C.usuariosEmpresa || [], txt(r.CODUSUARIO));
    opcoes(form.CODUSUARIO_FORNECEDOR, C.usuariosFornecedor || [], txt(r.CODUSUARIO_FORNECEDOR), [{ v: '99999', t: T.sem_resp_forn || '' }]);
    var soLeitura = !C.podeEditar, escopoFornecedor = C.escopo === 'FORNECEDOR', camposFornecedor = C.camposFornecedor || [];
    // Perfil FORNECEDOR: só os campos do fornecedor ficam editáveis (a gravação também é restrita no servidor).
    form.querySelectorAll('input, select, textarea').forEach(function (c) { c.disabled = soLeitura || (escopoFornecedor && camposFornecedor.indexOf(c.name) < 0); });
    form.querySelector('[data-form-salvar]').disabled = soLeitura;
    formStatus.textContent = '';
    if (bsForm) bsForm.show();
  }
  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (!C.podeEditar) return;
    var soFornecedor = C.escopo === 'FORNECEDOR';
    if (!soFornecedor && form.SITUACAO.value === 'Reprovado' && !form.OBSERVACAO.value.trim()) { formStatus.className = 'small ms-2 text-danger'; formStatus.textContent = T.reprovar_obs; form.OBSERVACAO.focus(); return; }
    var dados = {};
    ['SITUACAO', 'DATADEVALIDADE', 'DATADEREACAO', 'ENVIADOODOCUMENTO', 'EMPROTOCOLO', 'INCLUIDOCONTRATO', 'OBSERVACAO', 'OBSERVACAO_DO_FORNECEDOR', 'CODUSUARIO', 'CODUSUARIO_FORNECEDOR']
      .forEach(function (k) { if (!soFornecedor || (C.camposFornecedor || []).indexOf(k) >= 0) dados[k] = form[k].value; });
    formStatus.className = 'small ms-2 text-muted'; formStatus.textContent = T.gravando;
    enviar('salvar', { codigo: Number(form.dataset.id), dados: dados }).then(function (res) {
      substituir(res.row); desenhar();
      var aviso = res.row && res.row.SITUACAO === 'Aprovado' && !res.row.DATADEVALIDADE ? ' ' + (T.validade_vazia || '') : '';
      formStatus.className = 'small ms-2 ' + (aviso ? 'text-warning-emphasis' : 'text-success'); formStatus.textContent = (T.gravado || '') + aviso;
      form.querySelector('[data-form-cobranca]').textContent = dataBR(res.row.DATACOBRANCA_EMPROTOCOLO) || '—';
      form.EMPROTOCOLO.value = txt(res.row.EMPROTOCOLO);
      mensagem(T.gravado, 'success');
    }).catch(function (e) { formStatus.className = 'small ms-2 text-danger'; formStatus.textContent = e.message; });
  });
  form.querySelector('[data-form-evidencias]').addEventListener('click', function () { var r = linha(form.dataset.id); if (r) abrirEvidencias(r); });

  // Histórico
  var modalHist = document.getElementById('docHistorico'), bsHist = window.bootstrap ? bootstrap.Modal.getOrCreateInstance(modalHist) : null;
  function historico() {
    var r = linha(selecionado); if (!r) { mensagem(T.sem_selecao, 'warning'); return; }
    modalHist.querySelector('[data-hist-titulo]').textContent = (T.historico || '') + ' - ' + txt(r.DSCRITERIO);
    var cabH = modalHist.querySelector('[data-hist-cab]'), corpoH = modalHist.querySelector('[data-hist-corpo]');
    var cols = [['DATAALTERACAO', 'Data'], ['ALTERADO_POR', ''], ['SITUACAO', C.colunas.SITUACAO], ['DATADEVALIDADE', C.colunas.DATADEVALIDADE], ['EMPROTOCOLO', C.colunas.EMPROTOCOLO],
      ['ENVIADOODOCUMENTO', C.colunas.ENVIADOODOCUMENTO], ['RESPONSAVEL', C.colunas.RESPONSAVEL], ['RESPONSAVEL_FORNECEDOR', C.colunas.RESPONSAVEL_FORNECEDOR],
      ['INCLUIDOCONTRATO', C.colunas.INCLUIDOCONTRATO], ['OBSERVACAO', C.colunas.OBSERVACAO], ['OBSERVACAO_DO_FORNECEDOR', C.colunas.OBSERVACAO_DO_FORNECEDOR]];
    cols[0][1] = C.idioma === 'en' ? 'Date' : (C.idioma === 'es' ? 'Fecha' : 'Data');
    cols[1][1] = C.idioma === 'en' ? 'Changed by' : (C.idioma === 'es' ? 'Cambiado por' : 'Alterado por');
    cabH.innerHTML = ''; var trh = document.createElement('tr');
    cols.forEach(function (c) { var th = document.createElement('th'); th.textContent = c[1]; trh.appendChild(th); }); cabH.appendChild(trh);
    corpoH.innerHTML = '<tr><td colspan="' + cols.length + '" class="text-muted p-3">…</td></tr>';
    if (bsHist) bsHist.show();
    fetch(C.url + '&acao=historico&codigo=' + encodeURIComponent(r.CODFORNECEDORXCRITERIOS), { credentials: 'same-origin' }).then(function (x) { return x.json(); }).then(function (res) {
      corpoH.innerHTML = '';
      if (!res.ok) throw new Error(res.message || T.erro);
      if (!res.rows.length) { corpoH.innerHTML = '<tr><td colspan="' + cols.length + '" class="text-muted p-3"></td></tr>'; corpoH.querySelector('td').textContent = T.hist_vazio; return; }
      res.rows.forEach(function (h) {
        var tr = document.createElement('tr');
        cols.forEach(function (c) {
          var td = document.createElement('td'), v = h[c[0]];
          if (c[0] === 'DATAALTERACAO') { var m = /^(\d{4})-(\d{2})-(\d{2})\s*(\d{2}:\d{2})?/.exec(txt(v)); v = m ? dataBR(v) + (m[4] ? ' ' + m[4] : '') : txt(v); }
          else if (c[0] === 'DATADEVALIDADE') v = dataBR(v);
          else if (c[0] === 'SITUACAO') v = rotulo(C.situacoes, v);
          else if (c[0] === 'EMPROTOCOLO') v = protocolo(v);
          td.textContent = txt(v); tr.appendChild(td);
        });
        corpoH.appendChild(tr);
      });
    }).catch(function (e) { corpoH.innerHTML = '<tr><td class="text-danger p-3"></td></tr>'; corpoH.querySelector('td').textContent = e.message; });
  }

  // Copiar / colar
  var modalColar = document.getElementById('docColar'), bsColar = window.bootstrap ? bootstrap.Modal.getOrCreateInstance(modalColar) : null;
  function marcados() { return Array.prototype.map.call(corpo.querySelectorAll('input[data-marca]:checked'), function (c) { return Number(c.value); }); }
  function copiar() {
    var r = linha(selecionado); if (!r) { mensagem(T.sem_selecao, 'warning'); return; }
    copia = r; mensagem((T.copiado || '').replace('{doc}', txt(r.DSCRITERIO)), 'info'); botoes();
  }
  function colar() {
    if (!copia) { mensagem(T.sem_copia, 'warning'); return; }
    var destinos = marcados().filter(function (n) { return String(n) !== String(copia.CODFORNECEDORXCRITERIOS); });
    if (!destinos.length) { mensagem(T.sem_marcados, 'warning'); return; }
    modalColar.querySelector('[data-colar-texto]').textContent = (T.confirmar_colar || '').replace('{n}', destinos.length);
    modalColar.dataset.destinos = JSON.stringify(destinos);
    if (bsColar) bsColar.show();
  }
  modalColar.querySelector('[data-colar-confirmar]').addEventListener('click', function () {
    var destinos = JSON.parse(modalColar.dataset.destinos || '[]');
    enviar('copiar', { origem: Number(copia.CODFORNECEDORXCRITERIOS), destinos: destinos, evidencias: document.getElementById('colarEvidencias').checked }).then(function (res) {
      rows = res.rows || rows; if (bsColar) bsColar.hide();
      corpo.querySelectorAll('input[data-marca]').forEach(function (c) { c.checked = false; });
      desenhar(); contarArquivos();
      mensagem((T.colado || '').replace('{n}', res.alterados).replace('{a}', res.arquivos), 'success');
    }).catch(function (e) { mensagem(e.message, 'danger'); });
  });

  // Reiniciar (segundo clique em até 6 s confirma, sem janela do navegador)
  function reiniciar(botao) {
    var r = linha(selecionado); if (!r) { mensagem(T.sem_selecao, 'warning'); return; }
    if (botao.getAttribute('data-armado') !== String(r.CODFORNECEDORXCRITERIOS)) {
      botao.setAttribute('data-armado', String(r.CODFORNECEDORXCRITERIOS));
      mensagem((T.confirmar_reiniciar || '').replace('{doc}', txt(r.DSCRITERIO)), 'warning');
      setTimeout(function () { botao.removeAttribute('data-armado'); }, 6000);
      return;
    }
    botao.removeAttribute('data-armado');
    enviar('reiniciar', { codigo: Number(r.CODFORNECEDORXCRITERIOS) }).then(function (res) { substituir(res.row); desenhar(); mensagem(T.reiniciado, 'success'); })
      .catch(function (e) { mensagem(e.message, 'danger'); });
  }
  function gerar() {
    enviar('gerar', {}).then(function (res) { rows = res.rows || rows; desenhar(); contarArquivos(); mensagem((T.gerados || '').replace('{n}', res.gerados), 'success'); })
      .catch(function (e) { mensagem(e.message, 'danger'); });
  }
  function recarregar() {
    fetch(C.url + '&acao=listar', { credentials: 'same-origin' }).then(function (x) { return x.json(); }).then(function (res) {
      if (!res.ok) throw new Error(res.message || T.erro);
      rows = res.rows; desenhar(); contarArquivos(); mensagem('', '');
    }).catch(function (e) { mensagem(e.message, 'danger'); });
  }
  // Excel: só o pedido (documentos visíveis, na ordem e com os filtros da tela). A planilha é gerada pelo serviço do
  // Agendador do Windows e chega nos alertas com o link.
  function exportar() {
    var cods = visiveis().map(function (r) { return Number(r.CODFORNECEDORXCRITERIOS); });
    var cols = colunas.filter(function (c) { return c !== 'ARQUIVOS' && ocultas.indexOf(c) < 0; }); // c486: só as colunas visíveis
    enviar('exportar', { codigos: cods, colunas: cols, mapas: { colunas: C.colunas || {}, situacoes: C.situacoes || {}, simNao: C.simNao || {}, cores: C.cores || {}, textos: { dias: T.dias || '', vencido: T.vencido || '', sem_resp_forn: T.sem_resp_forn || '' } } })
      .then(function (res) { mensagem(res.message || 'A exportação foi solicitada e será disponibilizada nos alertas.', 'success'); })
      .catch(function (e) { mensagem(e.message, 'danger'); });
  }

  raiz.querySelectorAll('[data-acao]').forEach(function (b) {
    b.addEventListener('click', function () {
      var a = b.getAttribute('data-acao');
      if (a === 'editar') editar(); else if (a === 'historico') historico(); else if (a === 'copiar') copiar(); else if (a === 'colar') colar();
      else if (a === 'reiniciar') reiniciar(b); else if (a === 'gerar') gerar(); else if (a === 'recarregar') recarregar(); else if (a === 'exportar') exportar();
    });
  });

  // Barra padrão: Recarregar, Exportar e Colunas (components/botoes_crud.php)
  var botaoRecarregar = raiz.querySelector('[data-crud-action="reload"]'), botaoExportar = raiz.querySelector('[data-crud-action="export"]'), botaoColunas = raiz.querySelector('[data-crud-action="columns"]');
  if (botaoRecarregar) botaoRecarregar.addEventListener('click', recarregar);
  if (botaoExportar) botaoExportar.addEventListener('click', exportar);
  if (busca) busca.addEventListener('input', function () { pagina = 1; desenhar(); });
  // Paginação na barra de ações, como nos grids padrão
  var acoesBarra = raiz.querySelector('.crud-actions');
  if (acoesBarra && paginacao) { var sep = document.createElement('span'); sep.className = 'crud-pagination-separator'; sep.setAttribute('aria-hidden', 'true'); sep.textContent = '|'; acoesBarra.appendChild(sep); acoesBarra.appendChild(paginacao); }
  if (paginacao) {
    paginacao.querySelector('.grid-page-prev').addEventListener('click', function () { if (pagina > 1) { pagina--; desenhar(); } });
    paginacao.querySelector('.grid-page-next').addEventListener('click', function () { pagina++; desenhar(); });
  }
  if (botaoColunas) botaoColunas.addEventListener('click', function () {
    var antigo = document.getElementById('docsColunasModal'); if (antigo) antigo.remove();
    var m = document.createElement('div'); m.className = 'modal fade'; m.id = 'docsColunasModal'; m.tabIndex = -1;
    m.innerHTML = '<div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header py-2"><h6 class="modal-title"></h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="row g-2"></div></div><div class="modal-footer py-2"><button type="button" class="btn btn-sm btn-success" data-aplicar></button></div></div></div>';
    m.querySelector('.modal-title').textContent = T.colunas_titulo || 'Colunas do grid';
    m.querySelector('[data-aplicar]').textContent = T.aplicar || 'Aplicar';
    var lista = m.querySelector('.row');
    colunas.forEach(function (col) {
      var div = document.createElement('div'); div.className = 'col-md-6';
      var lab = document.createElement('label'); lab.className = 'form-check';
      var cb = document.createElement('input'); cb.type = 'checkbox'; cb.className = 'form-check-input'; cb.value = col; cb.checked = ocultas.indexOf(col) < 0;
      var sp = document.createElement('span'); sp.className = 'form-check-label'; sp.textContent = ' ' + ((C.colunas || {})[col] || col);
      lab.appendChild(cb); lab.appendChild(sp); div.appendChild(lab); lista.appendChild(div);
    });
    m.querySelector('[data-aplicar]').addEventListener('click', function () {
      ocultas = []; m.querySelectorAll('input[type=checkbox]').forEach(function (c) { if (!c.checked) ocultas.push(c.value); });
      try { window.localStorage.setItem(chaveColunas, JSON.stringify(ocultas)); } catch (e) { }
      desenhar(); if (window.bootstrap) bootstrap.Modal.getOrCreateInstance(m).hide();
    });
    document.body.appendChild(m);
    if (window.bootstrap) bootstrap.Modal.getOrCreateInstance(m).show();
  });

  montarCabecalho();
  desenhar();
  raiz.classList.add('grid-ready'); // o padrão (main.css) só mostra o fragmento do grid depois de pronto
  contarArquivos();
})();
