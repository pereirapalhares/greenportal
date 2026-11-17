/*
 * Não Conformidades › Ocorrência — avaliação da eficácia com IA (migração 337, 23/10/2026).
 * Como a análise da causa: o pedido roda em segundo plano (fila de IA do Agendador, prompt NC_EFICACIA de Setup › Prompts de IA),
 * o popup confirma o pedido e a resposta chega nos Alertas; o alerta abre a NC na aba da eficácia (&ia=CODANALISE) com o parecer.
 * O parecer é dividido em abas (Parecer, Ações e evidências, Recorrências, Falta comprovar, Texto da verificação).
 * "Levar para a verificação" grava só o texto da verificação e deixa o resultado (e o motivo, se não eficaz) preenchidos na tela:
 * o gestor confere e aplica o resultado. Uso: GPNcEficaciaIa.iniciar(cfg, { aviso, recarregar(res, codanalise), antes }).
 */
(function () {
  'use strict';
  var C, H, IA, modal, corpo, botao, botoes = {}, codanalise = 0, parecer = null, ocupado = false;
  var CORES = { EFICAZ: 'text-bg-success', NAO_EFICAZ: 'text-bg-danger', INCONCLUSIVO: 'text-bg-warning', ATENDE: 'text-bg-success', PARCIAL: 'text-bg-warning', NAO_ATENDE: 'text-bg-danger', SEM_DADOS: 'text-bg-secondary' };
  var ABAS = [['parecer', 'Parecer', 'bi-clipboard-check'], ['acoes', 'Ações e evidências', 'bi-list-check'], ['recorrencias', 'Recorrências', 'bi-arrow-repeat'],
    ['falta', 'Falta comprovar', 'bi-question-circle'], ['texto', 'Texto da verificação', 'bi-journal-text']];

  function el(tag, attrs, filhos) {
    var e = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') e.textContent = attrs[k];
      else if (k === 'class') e.className = attrs[k];
      else if (k === 'checked' || k === 'disabled') e[k] = !!attrs[k];
      else if (k === 'value') e.value = attrs[k];
      else e.setAttribute(k, attrs[k]);
    });
    (filhos || []).forEach(function (f) { if (f) e.appendChild(typeof f === 'string' ? document.createTextNode(f) : f); });
    return e;
  }
  function selo(codigo, rotulo) { return el('span', { class: 'badge ' + (CORES[codigo] || 'text-bg-secondary'), text: rotulo || codigo }); }
  function post(sub, dados) {
    return fetch(C.url + '?codigo=' + C.codigo + '&acao=iaeficacia', {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': C.csrf, 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ sub: sub, dados: dados || {} })
    }).then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Resposta inválida do servidor.' }; }); })
      .then(function (res) { if (!res.ok) throw new Error(res.message || 'Não foi possível concluir.'); return res; });
  }
  function estado(pronto) { botoes.aplicar.disabled = !pronto || ocupado; botoes.descartar.disabled = !codanalise || ocupado; botoes.nova.disabled = ocupado; }
  function mensagem(texto, tipo, girando) {
    corpo.innerHTML = '';
    var d = el('div', { class: 'alert py-2 small mb-0 alert-' + (tipo || 'secondary') });
    if (girando) d.appendChild(el('span', { class: 'spinner-border spinner-border-sm me-2', role: 'status' }));
    d.appendChild(document.createTextNode(texto)); corpo.appendChild(d);
  }
  function avisoModal(texto) {
    var a = corpo.querySelector('[data-iaef-erro]');
    if (!a) { a = el('div', { class: 'alert alert-danger py-2 small', 'data-iaef-erro': '' }); corpo.insertBefore(a, corpo.firstChild); }
    a.textContent = texto; corpo.scrollTop = 0;
  }
  function popup(titulo, texto, icone, tipo) {
    var host = null; try { host = window.top && window.top.GreenPortalPopups && window.top.document.getElementById('greenPortalMensagemModal') ? window.top : null; } catch (x) { host = null; }
    if (host) host.GreenPortalPopups.mensagem({ titulo: titulo, mensagem: texto, icone: icone }); else H.aviso(texto, tipo);
  }

  function solicitar() {
    if (ocupado) return; ocupado = true; botao.disabled = true;
    post('solicitar', {}).then(function (res) {
      ocupado = false; IA.pronta = 0; IA.pendente = true; botao.disabled = true;
      botao.innerHTML = '<i class="bi bi-hourglass-split"></i>'; botao.title = 'Parecer da IA em processamento'; botao.setAttribute('aria-label', 'Parecer da IA em processamento'); if (botao.parentElement && botao.parentElement.hasAttribute('data-disabled-tooltip-wrapper')) botao.parentElement.title = 'Parecer da IA em processamento';
      if (botao.parentNode) botao.parentNode.title = 'A resposta chega nos Alertas; até lá não é possível pedir outro.';
      popup('Avaliação da eficácia com IA', res.message, 'bi-stars', 'success');
    }).catch(function (e) { ocupado = false; botao.disabled = false; popup('Avaliação da eficácia com IA', e.message, 'bi-exclamation-triangle', 'danger'); });
  }
  function consultar() {
    estado(false); mensagem('Abrindo o parecer da IA...', 'secondary', true);
    post('consultar', { codanalise: codanalise }).then(function (res) {
      if (res.status === 'SUGERIDA') { parecer = res; desenhar(res); estado(true); return; }
      if (res.status === 'ERRO') { mensagem('A IA não conseguiu responder: ' + (res.erro || 'erro') + ' Use "Pedir outro parecer" para tentar de novo.', 'danger'); estado(false); return; }
      mensagem('O pedido ainda está na fila da IA (' + (res.job_status || 'pendente') + '). A resposta chega nos Alertas.', 'warning'); estado(false);
    }).catch(function (e) { mensagem(e.message, 'danger'); codanalise = 0; IA.pronta = 0; estado(false); });
  }

  function desenhar(res) {
    var p = res.parecer || {}, sem = res.semelhantes || []; corpo.innerHTML = '';
    var nav = el('ul', { class: 'nav nav-tabs mb-2', role: 'tablist' }), conteudo = el('div', { class: 'tab-content' }), pane = {};
    var qtd = { acoes: (p.acoes || []).length, recorrencias: sem.length, falta: (p.falta_comprovar || []).length + (p.alertas || []).length };
    ABAS.forEach(function (a, i) {
      var id = 'iaEfAba-' + a[0], extra = null;
      if (qtd[a[0]] !== undefined) extra = el('span', { class: 'badge ms-1 ' + (qtd[a[0]] ? (a[0] === 'acoes' ? 'text-bg-light border' : 'text-bg-warning') : 'text-bg-secondary'), text: String(qtd[a[0]]) });
      if (a[0] === 'parecer') extra = el('span', { class: 'badge ms-1 ' + (CORES[p.parecer] || 'text-bg-secondary'), text: p.parecer_rotulo || '' });
      nav.appendChild(el('li', { class: 'nav-item', role: 'presentation' }, [el('button', { type: 'button', class: 'nav-link py-1 px-3' + (i ? '' : ' active'), 'data-bs-toggle': 'tab',
        'data-bs-target': '#' + id, 'data-iaef-aba': a[0], role: 'tab' }, [el('i', { class: 'bi ' + a[2] + ' me-1' }), a[1], extra])]));
      pane[a[0]] = el('div', { class: 'tab-pane fade' + (i ? '' : ' show active'), id: id, role: 'tabpanel' });
      conteudo.appendChild(pane[a[0]]);
    });
    corpo.appendChild(nav); corpo.appendChild(conteudo);
    var vazio = function (k, texto) { pane[k].appendChild(el('div', { class: 'small text-muted py-2', text: texto })); };
    var tabela = function (cab, larg) { var t = el('table', { class: 'table table-sm align-top nc-tabela mb-2' }), tr = el('tr'); cab.forEach(function (c, i) { tr.appendChild(el('th', { text: c, style: larg[i] ? 'width:' + larg[i] : '' })); }); t.appendChild(el('thead', {}, [tr])); var b = el('tbody'); t.appendChild(b); return [t, b]; };

    // Parecer
    pane.parecer.appendChild(el('div', { class: 'd-flex align-items-center gap-2 mb-2' }, [el('span', { class: 'small text-muted', text: 'Parecer sugerido:' }), selo(p.parecer, p.parecer_rotulo),
      p.confianca ? el('span', { class: 'small text-muted', text: 'confiança ' + p.confianca + '%' }) : null]));
    if (p.resumo) pane.parecer.appendChild(el('div', { class: 'alert alert-light border py-2 small mb-2', text: p.resumo }));
    if ((p.criterios || []).length) {
      var tc = tabela(['Critério', 'Avaliação', 'Comentário'], ['260px', '140px', '']);
      p.criterios.forEach(function (c) { tc[1].appendChild(el('tr', {}, [el('td', { text: c.criterio }), el('td', {}, [selo(c.avaliacao, IA.avaliacoes[c.avaliacao])]), el('td', { text: c.comentario || '' })])); });
      pane.parecer.appendChild(tc[0]);
    }
    pane.parecer.appendChild(el('div', { class: 'small text-muted', text: 'O resultado da avaliação continua sendo decisão do gestor: "Levar para a verificação" só preenche a tela para você conferir e aplicar.' }));

    // Ações e evidências
    if (!(p.acoes || []).length) vazio('acoes', 'A IA não comentou as ações do plano.');
    else {
      var ta = tabela(['#', 'Ação', 'Status', 'Avaliação', 'Comentário da IA'], ['36px', '30%', '70px', '130px', '']);
      p.acoes.forEach(function (a) { ta[1].appendChild(el('tr', {}, [el('td', { text: String(a.numero) }), el('td', { text: a.acao }), el('td', { text: a.status + '%' }), el('td', {}, [selo(a.avaliacao, IA.avaliacoes[a.avaliacao])]), el('td', { text: a.comentario || '' })])); });
      pane.acoes.appendChild(ta[0]);
    }

    // Recorrências
    (p.recorrencias || []).forEach(function (r) { pane.recorrencias.appendChild(el('div', { class: 'alert alert-warning py-2 small mb-2' }, [el('b', { text: 'NC ' + r.nc + ': ' }), r.comentario || ''])); });
    if (!sem.length) vazio('recorrencias', 'Nenhuma NC parecida foi aberta depois desta (entre as que você pode ver).');
    else {
      var tr = tabela(['NC', 'Aberta em', 'Sumário', 'Situação'], ['120px', '170px', '', '220px']);
      sem.forEach(function (n) {
        tr[1].appendChild(el('tr', {}, [el('td', {}, [el('a', { href: C.url + '?codigo=' + n.codigo + '&aba=ocorrencia', 'data-iaef-nc': n.codigo, text: 'NC ' + n.nrano, title: 'Abrir a NC' })]),
          el('td', {}, [(n.data || '').split('-').reverse().join('/'), n.depois ? el('span', { class: 'badge text-bg-danger ms-1', text: 'depois do plano', title: 'Aberta depois do envio desta NC para a avaliação da eficácia' }) : null]),
          el('td', { text: n.sumario || '' }), el('td', { text: (n.etapa || '') + (n.situacao ? ' / ' + n.situacao : '') })]));
      });
      pane.recorrencias.appendChild(el('div', { class: 'small text-muted mb-1', text: 'NCs parecidas abertas depois desta que a IA considerou.' }));
      pane.recorrencias.appendChild(tr[0]);
    }

    // Falta comprovar e pontos de atenção
    if ((p.falta_comprovar || []).length) {
      var ul = el('ul', { class: 'small ps-3 mb-2' });
      p.falta_comprovar.forEach(function (f) { ul.appendChild(el('li', { text: f })); });
      pane.falta.appendChild(el('h6', { class: 'mb-1', text: 'O que ainda falta comprovar' })); pane.falta.appendChild(ul);
    }
    if ((p.alertas || []).length) {
      var ua = el('ul', { class: 'mb-0 ps-3' });
      p.alertas.forEach(function (a) { ua.appendChild(el('li', { text: a })); });
      pane.falta.appendChild(el('div', { class: 'alert alert-warning py-2 small' }, [el('b', { text: 'Pontos de atenção' }), ua]));
    }
    if (!qtd.falta) vazio('falta', 'A IA não apontou pendências de comprovação.');

    // Texto da verificação (e motivo da devolução, se não eficaz)
    var bloco = el('div', { 'data-iaef-texto': '' });
    if (p.verificacao_da_eficacia) {
      var modo = IA.verificacao_atual ? 'acrescentar' : 'substituir', radios = el('div', { class: 'd-flex gap-3 small mb-1 flex-wrap' });
      [['manter', 'Não alterar a verificação'], ['acrescentar', 'Acrescentar ao texto atual'], ['substituir', IA.verificacao_atual ? 'Substituir o texto atual' : 'Usar este texto']].forEach(function (o) {
        if (o[0] === 'acrescentar' && !IA.verificacao_atual) return;
        radios.appendChild(el('label', { class: 'form-check-label' }, [el('input', { type: 'radio', class: 'form-check-input me-1', name: 'iaEfModo', value: o[0], checked: o[0] === modo }), o[1]]));
      });
      bloco.appendChild(el('h6', { class: 'mb-1', text: 'Verificação da eficácia' })); bloco.appendChild(radios);
      bloco.appendChild(el('textarea', { class: 'form-control form-control-sm mb-2', rows: '7', 'data-campo': 'verificacao', value: p.verificacao_da_eficacia }));
    } else bloco.appendChild(el('div', { class: 'small text-muted py-2', text: 'A IA não sugeriu texto para a verificação da eficácia.' }));
    if (p.parecer === 'NAO_EFICAZ') {
      bloco.appendChild(el('label', { class: 'form-check-label small' }, [el('input', { type: 'checkbox', class: 'form-check-input me-1', 'data-campo': 'usar_motivo', checked: !!p.motivo_nao_eficaz }), 'Preencher o motivo da devolução ao responsável']));
      bloco.appendChild(el('textarea', { class: 'form-control form-control-sm mt-1', rows: '4', 'data-campo': 'motivo', value: p.motivo_nao_eficaz || '' }));
    }
    pane.texto.appendChild(bloco);
  }

  function aplicar() {
    var b = corpo.querySelector('[data-iaef-texto]'), r = b ? b.querySelector('input[name="iaEfModo"]:checked') : null, ver = b ? b.querySelector('[data-campo="verificacao"]') : null;
    var usar = b ? b.querySelector('[data-campo="usar_motivo"]') : null, mot = b ? b.querySelector('[data-campo="motivo"]') : null;
    var itens = { parecer: (parecer && parecer.parecer || {}).parecer || 'INCONCLUSIVO', verificacao: { modo: r ? r.value : 'manter', texto: ver ? ver.value : '' }, motivo: { usar: !!(usar && usar.checked), texto: mot ? mot.value : '' } };
    ocupado = true; estado(true);
    H.antes().then(function () { return post('aplicar', { codanalise: codanalise, itens: itens }); }).then(function (res) {
      ocupado = false; IA.pronta = 0; bootstrap.Modal.getOrCreateInstance(modal).hide(); H.recarregar(res, codanalise);
    }).catch(function (e) { ocupado = false; estado(true); avisoModal(e.message); });
  }
  function descartar() {
    if (!codanalise) return; ocupado = true; estado(!!parecer);
    post('descartar', { codanalise: codanalise }).then(function (res) {
      ocupado = false; codanalise = 0; IA.pronta = 0; bootstrap.Modal.getOrCreateInstance(modal).hide(); H.recarregar(res, 0);
    }).catch(function (e) { ocupado = false; estado(!!parecer); avisoModal(e.message); });
  }
  function abrirNc(ev) {
    var a = ev.target.closest('[data-iaef-nc]'); if (!a) return;
    ev.preventDefault();
    var url = new URL(a.getAttribute('href'), window.location.href).toString(), host = null;
    try { host = window.top && window.top.GreenPortalPopups && window.top.jQuery ? window.top : null; } catch (x) { host = null; }
    if (!host) { window.open(url, '_blank'); return; }
    var opcoes = { titulo: a.textContent, conteudo: host.jQuery('<iframe>', { src: url, title: a.textContent, style: 'width:100%;height:100%;border:0;display:block;' }), redimensionavel: true };
    if (typeof host.GreenPortalPopups.transacaoIndependente === 'function') host.GreenPortalPopups.transacaoIndependente(opcoes); else host.GreenPortalPopups.transacao(opcoes);
  }

  window.GPNcEficaciaIa = {
    iniciar: function (cfg, handlers) {
      C = cfg; H = handlers; IA = cfg.iaef; modal = document.getElementById('ncIaEf');
      botao = document.querySelector('[data-nc-iaef]'); if (!modal || !botao) return;
      corpo = modal.querySelector('[data-iaef-corpo]');
      botoes = { aplicar: modal.querySelector('[data-iaef-aplicar]'), descartar: modal.querySelector('[data-iaef-descartar]'), nova: modal.querySelector('[data-iaef-nova]') };
      botoes.aplicar.addEventListener('click', aplicar);
      botoes.descartar.addEventListener('click', descartar);
      botoes.nova.addEventListener('click', function () { bootstrap.Modal.getOrCreateInstance(modal).hide(); parecer = null; codanalise = 0; solicitar(); });
      corpo.addEventListener('click', abrirNc);
      var abrir = function (cod) { codanalise = cod; bootstrap.Modal.getOrCreateInstance(modal).show(); if (!(parecer && parecer.codanalise === cod)) consultar(); };
      botao.addEventListener('click', function () { if (IA.pendente) return; if (IA.pronta) abrir(IA.pronta); else solicitar(); });
      if (IA.abrir) abrir(IA.abrir);
    }
  };
}());
