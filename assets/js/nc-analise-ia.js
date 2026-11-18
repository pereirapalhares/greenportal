/*
 * Não Conformidades › Ocorrência — análise da causa com IA (migração 336, 23/10/2026).
 * Pede a sugestão (fila de IA do Agendador, prompt NC_ANALISE_CAUSA de Setup › Prompts de IA). O pedido roda em segundo plano
 * (decisão do usuário 23/10/2026): o popup confirma o pedido e a resposta chega nos Alertas; o alerta abre a NC na aba da análise
 * (&ia=CODANALISE) com a revisão aberta. Na revisão o responsável marca e edita causas, ações e o texto da análise; "Aplicar" grava
 * pelas procedures da tela. Enquanto o pedido está na fila, não é possível pedir outro.
 * Uso (naoconformidades_ocorrencia.php): GPNcIa.iniciar(cfg, { aviso, recarregar, antes }).
 */
(function () {
  'use strict';
  function T(k, p) { return typeof window.greenT === 'function' ? window.greenT('naoconformidades.js.' + k, p) : p; }
  var C, H, IA, modal, corpo, botao, botoes = {}, codanalise = 0, sugestao = null, ocupado = false;

  function el(tag, attrs, filhos) {
    var e = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') e.textContent = attrs[k];
      else if (k === 'class') e.className = attrs[k];
      else if (k === 'checked' || k === 'selected' || k === 'disabled') e[k] = !!attrs[k];
      else if (k === 'value') e.value = attrs[k];
      else e.setAttribute(k, attrs[k]);
    });
    (filhos || []).forEach(function (f) { if (f) e.appendChild(typeof f === 'string' ? document.createTextNode(f) : f); });
    return e;
  }
  function select(nome, pares, atual, vazio) {
    var s = el('select', { class: 'form-select form-select-sm', 'data-campo': nome });
    if (vazio !== false) s.appendChild(el('option', { value: '', text: vazio || '' }));
    pares.forEach(function (p) { s.appendChild(el('option', { value: p[0], text: p[1], selected: String(p[0]) === String(atual) })); });
    if (atual !== undefined && atual !== null && String(atual) !== '') s.value = String(atual);
    return s;
  }
  function somaDias(ymd, dias) {
    var d = new Date(ymd + 'T12:00:00'); d.setDate(d.getDate() + (dias || 0));
    return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
  }
  function post(sub, dados) {
    return fetch(C.url + '?codigo=' + C.codigo + '&acao=ia', {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': C.csrf, 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ sub: sub, dados: dados || {} })
    }).then(function (r) { return r.json().catch(function () { return { ok: false, message: T('resposta_invalida', 'Resposta inválida do servidor.') }; }); })
      .then(function (res) { if (!res.ok) throw new Error(res.message || T('nao_concluiu', 'Não foi possível concluir.')); return res; });
  }
  function estado(pronto) {
    botoes.aplicar.disabled = !pronto || ocupado; botoes.descartar.disabled = !codanalise || ocupado; botoes.nova.disabled = ocupado;
  }
  function mensagem(texto, tipo, girando) {
    corpo.innerHTML = '';
    var d = el('div', { class: 'alert py-2 small mb-0 alert-' + (tipo || 'secondary') });
    if (girando) d.appendChild(el('span', { class: 'spinner-border spinner-border-sm me-2', role: 'status' }));
    d.appendChild(document.createTextNode(texto));
    corpo.appendChild(d);
  }
  // Popup padrão do portal (sem o portal, o aviso da tela)
  function popup(titulo, texto, icone, tipo) {
    var host = null; try { host = window.top && window.top.GreenPortalPopups && window.top.document.getElementById('greenPortalMensagemModal') ? window.top : null; } catch (x) { host = null; }
    if (host) host.GreenPortalPopups.mensagem({ titulo: titulo, mensagem: texto, icone: icone });
    else H.aviso(texto, tipo);
  }
  function botaoPendente() {
    botao.disabled = true; botao.innerHTML = '<i class="bi bi-hourglass-split"></i>'; botao.title = T('ia_processando', 'Análise da IA em processamento'); botao.setAttribute('aria-label', T('ia_processando', 'Análise da IA em processamento')); if (botao.parentElement && botao.parentElement.hasAttribute('data-disabled-tooltip-wrapper')) botao.parentElement.title = T('ia_processando', 'Análise da IA em processamento');
    if (botao.parentNode) botao.parentNode.title = T('ia_espere', 'A resposta chega nos Alertas; até lá não é possível pedir outra.');
  }
  // Pedido em segundo plano: confirma no popup e a resposta chega nos Alertas
  function solicitar() {
    if (ocupado) return; ocupado = true; botao.disabled = true;
    post('solicitar', {}).then(function (res) {
      ocupado = false; IA.pronta = 0; IA.pendente = true; botaoPendente();
      popup(T('ia_titulo', 'Análise da IA'), res.message, 'bi-stars', 'success');
    }).catch(function (e) { ocupado = false; botao.disabled = false; popup(T('ia_titulo', 'Análise da IA'), e.message, 'bi-exclamation-triangle', 'danger'); });
  }
  function consultar() {
    estado(false); mensagem(T('ia_abrindo', 'Abrindo a sugestão da IA...'), 'secondary', true);
    post('consultar', { codanalise: codanalise }).then(function (res) {
      if (res.status === 'SUGERIDA') { sugestao = res; desenhar(res); estado(true); return; }
      if (res.status === 'ERRO') { mensagem(T('ia_nao_respondeu', 'A IA não conseguiu responder: ') + (res.erro || 'erro') + T('ia_gerar_outra', ' Use "Gerar outra sugestão" para pedir de novo.'), 'danger'); estado(false); return; }
      mensagem(T('ia_na_fila', 'O pedido ainda está na fila da IA (') + (res.job_status || 'pendente') + T('ia_na_fila2', '). A resposta chega nos Alertas.'), 'warning'); estado(false);
    }).catch(function (e) { mensagem(e.message, 'danger'); codanalise = 0; IA.pronta = 0; estado(false); });
  }

  // Abas da revisão (pedido do usuário 23/10/2026: visão organizada por assunto)
  var ABAS = [['resumo', T('resumo', 'Resumo'), 'bi-card-text'], ['causas', T('causas', 'Causas'), 'bi-diagram-3'], ['acoes', T('acoes', 'Ações'), 'bi-list-check'], ['analise', T('texto_analise', 'Texto da análise'), 'bi-journal-text'], ['semelhantes', T('ncs_semelhantes', 'NCs semelhantes'), 'bi-files']];
  function mostrarAba(chave) {
    var b = corpo.querySelector('[data-ia-aba="' + chave + '"]');
    if (b && window.bootstrap) bootstrap.Tab.getOrCreateInstance(b).show();
  }
  // Contagem "marcadas/total" nas abas Causas e Ações
  function contar() {
    ['causas', 'acoes'].forEach(function (k) {
      var linhas = corpo.querySelectorAll(k === 'causas' ? '[data-ia-causa]' : '[data-ia-acao]'), n = 0, q = corpo.querySelector('[data-ia-qtd="' + k + '"]');
      linhas.forEach(function (tr) { if (tr.querySelector('[data-marcar]').checked) n++; });
      if (q) { q.textContent = linhas.length ? n + '/' + linhas.length : '0'; q.className = 'badge ms-1 ' + (n ? 'text-bg-primary' : 'text-bg-secondary'); }
    });
  }
  function desenhar(res) {
    var s = res.sugestao || {}, sem = res.semelhantes || []; corpo.innerHTML = '';
    var nav = el('ul', { class: 'nav nav-tabs mb-2', role: 'tablist' }), conteudo = el('div', { class: 'tab-content' }), pane = {};
    ABAS.forEach(function (a, i) {
      var id = 'iaAba-' + a[0], extra = null;
      if (a[0] === 'causas' || a[0] === 'acoes') extra = el('span', { class: 'badge ms-1 text-bg-secondary', 'data-ia-qtd': a[0] });
      if (a[0] === 'semelhantes') extra = el('span', { class: 'badge ms-1 text-bg-secondary', text: String(sem.length) });
      if (a[0] === 'resumo' && (s.alertas || []).length) extra = el('i', { class: 'bi bi-exclamation-triangle-fill text-warning ms-1', title: T('ha_pontos', 'Há pontos de atenção') });
      nav.appendChild(el('li', { class: 'nav-item', role: 'presentation' }, [el('button', { type: 'button', class: 'nav-link py-1 px-3' + (i ? '' : ' active'), 'data-bs-toggle': 'tab',
        'data-bs-target': '#' + id, 'data-ia-aba': a[0], role: 'tab' }, [el('i', { class: 'bi ' + a[2] + ' me-1' }), a[1], extra])]));
      pane[a[0]] = el('div', { class: 'tab-pane fade' + (i ? '' : ' show active'), id: id, role: 'tabpanel' });
      conteudo.appendChild(pane[a[0]]);
    });
    corpo.appendChild(nav); corpo.appendChild(conteudo);
    var vazio = function (k, texto) { pane[k].appendChild(el('div', { class: 'small text-muted py-2', text: texto })); };

    // Resumo: problema, 5 porquês e pontos de atenção
    if (s.resumo_problema) pane.resumo.appendChild(el('div', { class: 'alert alert-light border py-2 small mb-2' }, [el('b', { text: T('problema', 'Problema: ') }), s.resumo_problema]));
    if ((s.cinco_porques || []).length) {
      var ol = el('ol', { class: 'small mb-2' });
      s.cinco_porques.forEach(function (p) { ol.appendChild(el('li', { text: p })); });
      pane.resumo.appendChild(el('div', {}, [el('h6', { class: 'mb-1', text: T('cinco_porques', '5 porquês') }), ol]));
    }
    if ((s.alertas || []).length) {
      var ul = el('ul', { class: 'mb-0 ps-3' });
      s.alertas.forEach(function (a) { ul.appendChild(el('li', { text: a })); });
      pane.resumo.appendChild(el('div', { class: 'alert alert-warning py-2 small mt-2 mb-2' }, [el('b', { text: T('pontos_atencao', 'Pontos de atenção') }), ul]));
    }
    pane.resumo.appendChild(el('div', { class: 'small text-muted', text: T('ia_revise', 'Revise as abas Causas, Ações e Texto da análise: só o que estiver marcado será gravado ao aplicar.') }));

    // Causas sugeridas
    if (!(s.causas || []).length) vazio('causas', T('ia_sem_causas', 'A IA não sugeriu causas novas.'));
    else {
      var tc = el('table', { class: 'table table-sm align-top nc-tabela mb-2', 'data-ia-causas': '' });
      tc.appendChild(el('thead', {}, [el('tr', {}, [el('th', { style: 'width:32px' }), el('th', { style: 'width:30px', text: '#' }), el('th', { style: 'width:210px', text: T('tipo_causa', 'Tipo de causa') }), el('th', { text: T('causa', 'Causa') })])]));
      var tbc = el('tbody');
      s.causas.forEach(function (c, i) {
        var info = [];
        if (c.justificativa) info.push(c.justificativa);
        if (c.confianca) info.push(T('confianca', 'confiança ') + c.confianca + '%');
        if ((c.base || []).length) info.push('base: ' + c.base.join(', '));
        tbc.appendChild(el('tr', { 'data-ia-causa': 'n' + i }, [
          el('td', {}, [el('input', { type: 'checkbox', class: 'form-check-input', 'data-marcar': '', checked: true, title: T('incluir_causa', 'Incluir esta causa') })]),
          el('td', { text: String(i + 1) }),
          el('td', {}, [select('CODTIPOCAUSA', IA.tipos, c.codtipocausa), c.tipo_causa && !c.codtipocausa ? el('div', { class: 'small text-warning', text: T('ia_sugeriu', 'IA sugeriu: ') + c.tipo_causa }) : null]),
          el('td', {}, [el('textarea', { class: 'form-control form-control-sm', rows: '2', maxlength: '500', 'data-campo': 'DSCAUSA', value: c.descricao }), info.length ? el('div', { class: 'small text-muted mt-1', text: info.join(' · ') }) : null])
        ]));
      });
      tc.appendChild(tbc); pane.causas.appendChild(tc);
    }

    // Ações sugeridas
    if (!(s.acoes || []).length) vazio('acoes', T('ia_sem_acoes', 'A IA não sugeriu ações.'));
    else {
      var opcoesCausa = (s.causas || []).map(function (c, i) { return ['n' + i, T('causa_sugerida', 'Causa sugerida ') + (i + 1)]; })
        .concat(IA.causas.map(function (c) { return [c[0], T('ja_cadastrada', 'Já cadastrada: ') + c[1]]; }));
      var ta = el('table', { class: 'table table-sm align-top nc-tabela mb-2', 'data-ia-acoes': '' });
      ta.appendChild(el('thead', {}, [el('tr', {}, [el('th', { style: 'width:32px' }), el('th', { style: 'width:170px', text: T('causa', 'Causa') }), el('th', { text: T('acao', 'Ação') }), el('th', { style: 'width:100px', text: T('prioridade', 'Prioridade') }),
        el('th', { style: 'width:170px', text: T('responsavel', 'Responsável') }), el('th', { style: 'width:130px', text: T('inicio', 'Início') }), el('th', { style: 'width:130px', text: T('fim', 'Fim') })])]));
      var tba = el('tbody');
      s.acoes.forEach(function (a) {
        var fim = somaDias(IA.hoje, a.prazo_dias || 30), dicas = [];
        if (a.responsavel && !a.codusuario) dicas.push(T('ia_sugeriu_resp', 'IA sugeriu como responsável: ') + a.responsavel);
        if (IA.prevista && fim > IA.prevista) dicas.push(T('fim_passa', 'O fim sugerido passa da data prevista de conclusão da NC.'));
        if ((a.base || []).length) dicas.push('base: ' + a.base.join(', '));
        tba.appendChild(el('tr', { 'data-ia-acao': '' }, [
          el('td', {}, [el('input', { type: 'checkbox', class: 'form-check-input', 'data-marcar': '', checked: true, title: T('incluir_acao', 'Incluir esta ação') })]),
          el('td', {}, [select('causa', opcoesCausa, a.causa >= 0 ? 'n' + a.causa : '', T('escolha', 'Escolha'))]),
          el('td', {}, [el('textarea', { class: 'form-control form-control-sm', rows: '2', 'data-campo': 'ACAO', value: a.acao }),
            el('input', { class: 'form-control form-control-sm mt-1', 'data-campo': 'RECURSOS', placeholder: T('recursos', 'Recursos'), value: a.recursos || '' }),
            dicas.length ? el('div', { class: 'small text-muted mt-1', text: dicas.join(' · ') }) : null]),
          el('td', {}, [select('PRIORIDADE', IA.prioridades.map(function (p) { return [p, (IA.prioridades_rotulos || {})[p] || p]; }), a.prioridade)]),
          el('td', {}, [select('CODUSUARIO', IA.responsaveis, a.codusuario || IA.responsavel_padrao)]),
          el('td', {}, [el('input', { type: 'date', class: 'form-control form-control-sm', 'data-campo': 'DATAINICIAL', value: IA.hoje })]),
          el('td', {}, [el('input', { type: 'date', class: 'form-control form-control-sm', 'data-campo': 'DATAFINAL', value: fim })])
        ]));
      });
      ta.appendChild(tba); pane.acoes.appendChild(ta);
    }

    // Texto da análise
    if (!s.analise_da_causa) vazio('analise', T('ia_sem_texto', 'A IA não sugeriu texto para a análise da causa.'));
    else {
      var modo = IA.analise_atual ? 'acrescentar' : 'substituir', radios = el('div', { class: 'd-flex gap-3 small mb-1 flex-wrap' });
      [['manter', T('nao_alterar_analise', 'Não alterar a análise')], ['acrescentar', T('acrescentar', 'Acrescentar ao texto atual')], ['substituir', IA.analise_atual ? T('substituir', 'Substituir o texto atual') : T('usar_texto', 'Usar este texto')]].forEach(function (o) {
        if (o[0] === 'acrescentar' && !IA.analise_atual) return;
        radios.appendChild(el('label', { class: 'form-check-label' }, [el('input', { type: 'radio', class: 'form-check-input me-1', name: 'iaModo', value: o[0], checked: o[0] === modo }), o[1]]));
      });
      pane.analise.appendChild(el('div', { 'data-ia-analise': '' }, [radios, el('textarea', { class: 'form-control form-control-sm', rows: '8', 'data-campo': 'texto', value: s.analise_da_causa })]));
    }

    // NCs semelhantes consultadas
    if (!sem.length) vazio('semelhantes', T('sem_semelhantes', 'Nenhuma NC semelhante já tratada foi encontrada; a sugestão usa só esta ocorrência.'));
    else {
      var tsem = el('table', { class: 'table table-sm nc-tabela mb-0' }, [el('thead', {}, [el('tr', {}, [el('th', { style: 'width:120px', text: 'NC' }), el('th', { text: T('sumario', 'Sumário') }), el('th', { style: 'width:240px', text: T('situacao', 'Situação') })])])]), tbs = el('tbody');
      sem.forEach(function (n) {
        tbs.appendChild(el('tr', {}, [el('td', {}, [el('a', { href: C.url + '?codigo=' + n.codigo + '&aba=analise', 'data-ia-nc': n.codigo, text: 'NC ' + n.nrano, title: T('abrir_nc', 'Abrir a NC') })]),
          el('td', { text: n.sumario || '' }), el('td', { text: (n.etapa || '') + (n.situacao ? ' / ' + n.situacao : '') })]));
      });
      tsem.appendChild(tbs); pane.semelhantes.appendChild(tsem);
      pane.semelhantes.appendChild(el('div', { class: 'small text-muted mt-1', text: T('semelhantes_dica', 'NCs já tratadas na empresa que a IA usou como referência (só as que você pode ver).') }));
    }
    corpo.querySelectorAll('[data-marcar]').forEach(function (c) { c.addEventListener('change', contar); });
    contar();
  }

  function coletar() {
    var itens = { causas: [], acoes: [], analise: { modo: 'manter', texto: '' } }, marcadas = {};
    corpo.querySelectorAll('[data-ia-causa]').forEach(function (tr, i) {
      if (!tr.querySelector('[data-marcar]').checked) return;
      var ref = tr.getAttribute('data-ia-causa'); marcadas[ref] = true;
      itens.causas.push({ ref: ref, CODTIPOCAUSA: tr.querySelector('[data-campo="CODTIPOCAUSA"]').value, DSCAUSA: tr.querySelector('[data-campo="DSCAUSA"]').value });
    });
    var erro = '';
    corpo.querySelectorAll('[data-ia-acao]').forEach(function (tr, i) {
      if (!tr.querySelector('[data-marcar]').checked) return;
      var v = function (c) { return tr.querySelector('[data-campo="' + c + '"]').value; }, causa = v('causa');
      if (!causa) erro = erro || (T('escolha_causa', 'Escolha a causa da ação ') + (i + 1) + '.');
      else if (causa.charAt(0) === 'n' && !marcadas[causa]) erro = erro || (T('a_acao', 'A ação ') + (i + 1) + T('acao_causa_desmarcada', ' é de uma causa sugerida que não está marcada: marque a causa ou escolha outra.'));
      itens.acoes.push({ causa: causa, ACAO: v('ACAO'), RECURSOS: v('RECURSOS'), PRIORIDADE: v('PRIORIDADE'), CODUSUARIO: v('CODUSUARIO'), DATAINICIAL: v('DATAINICIAL'), DATAFINAL: v('DATAFINAL') });
    });
    var an = corpo.querySelector('[data-ia-analise]');
    if (an) { var r = an.querySelector('input[name="iaModo"]:checked'); itens.analise = { modo: r ? r.value : 'manter', texto: an.querySelector('[data-campo="texto"]').value }; }
    if (!erro && !itens.causas.length && !itens.acoes.length && itens.analise.modo === 'manter') erro = T('marque_algo', 'Marque pelo menos uma causa, uma ação ou o texto da análise.');
    return { itens: itens, erro: erro };
  }
  function avisoModal(texto) {
    var a = corpo.querySelector('[data-ia-erro]');
    if (!a) { a = el('div', { class: 'alert alert-danger py-2 small', 'data-ia-erro': '' }); corpo.insertBefore(a, corpo.firstChild); }
    a.textContent = texto; corpo.scrollTop = 0;
  }
  function aplicar() {
    var c = coletar(); if (c.erro) { if (/ação|action|acción/i.test(c.erro)) mostrarAba('acoes'); avisoModal(c.erro); return; }
    ocupado = true; estado(true);
    H.antes().then(function () { return post('aplicar', { codanalise: codanalise, itens: c.itens }); }).then(function (res) {
      ocupado = false; IA.pronta = 0; bootstrap.Modal.getOrCreateInstance(modal).hide(); H.recarregar(res);
    }).catch(function (e) { ocupado = false; estado(true); if (/ação|action|acción/i.test(e.message)) mostrarAba('acoes'); else if (/causa|cause/i.test(e.message)) mostrarAba('causas'); avisoModal(e.message); });
  }
  function descartar() {
    if (!codanalise) return;
    ocupado = true; estado(!!sugestao);
    post('descartar', { codanalise: codanalise }).then(function (res) {
      ocupado = false; codanalise = 0; IA.pronta = 0; bootstrap.Modal.getOrCreateInstance(modal).hide(); H.recarregar(res);
    }).catch(function (e) { ocupado = false; estado(!!sugestao); avisoModal(e.message); });
  }
  // NC semelhante no popup padrão do portal (sem o portal, em outra aba)
  function abrirNc(ev) {
    var a = ev.target.closest('[data-ia-nc]'); if (!a) return;
    ev.preventDefault();
    var url = new URL(a.getAttribute('href'), window.location.href).toString(), host = null;
    try { host = window.top && window.top.GreenPortalPopups && window.top.jQuery ? window.top : null; } catch (x) { host = null; }
    if (!host) { window.open(url, '_blank'); return; }
    var opcoes = { titulo: a.textContent, conteudo: host.jQuery('<iframe>', { src: url, title: a.textContent, style: 'width:100%;height:100%;border:0;display:block;' }), redimensionavel: true };
    if (typeof host.GreenPortalPopups.transacaoIndependente === 'function') host.GreenPortalPopups.transacaoIndependente(opcoes); else host.GreenPortalPopups.transacao(opcoes);
  }

  window.GPNcIa = {
    iniciar: function (cfg, handlers) {
      C = cfg; H = handlers; IA = cfg.ia; modal = document.getElementById('ncIa');
      botao = document.querySelector('[data-nc-ia]'); if (!modal || !botao) return;
      corpo = modal.querySelector('[data-ia-corpo]');
      botoes = { aplicar: modal.querySelector('[data-ia-aplicar]'), descartar: modal.querySelector('[data-ia-descartar]'), nova: modal.querySelector('[data-ia-nova]') };
      botoes.aplicar.addEventListener('click', aplicar);
      botoes.descartar.addEventListener('click', descartar);
      // Outra sugestão: a atual fica substituída e o novo pedido segue em segundo plano
      botoes.nova.addEventListener('click', function () { bootstrap.Modal.getOrCreateInstance(modal).hide(); sugestao = null; codanalise = 0; solicitar(); });
      corpo.addEventListener('click', abrirNc);
      var abrir = function (cod) { codanalise = cod; bootstrap.Modal.getOrCreateInstance(modal).show(); if (!(sugestao && sugestao.codanalise === cod)) consultar(); };
      botao.addEventListener('click', function () { if (IA.pendente) return; if (IA.pronta) abrir(IA.pronta); else solicitar(); });
      // Vindo do alerta "a sugestão da IA está pronta": a revisão abre sozinha
      if (IA.abrir) abrir(IA.abrir);
    }
  };
}());
