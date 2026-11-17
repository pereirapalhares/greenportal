/* Administração › Guia do usuário — editor do conteúdo universal do guia. */
(function () {
  'use strict';
  var root = document.querySelector('[data-guia-editor]');
  if (!root) return;

  var T = JSON.parse(document.getElementById('geTextos').textContent || '{}');
  var API = 'api/administracao_guia_usuario.php';
  var csrf = root.getAttribute('data-csrf') || '';
  var podeEditar = root.getAttribute('data-pode-editar') === '1';
  var LANGS = ['pt', 'en', 'es'];
  var ICONES = ['bi-bank', 'bi-truck', 'bi-clipboard2-pulse', 'bi-shield-check', 'bi-tree', 'bi-people', 'bi-person-gear', 'bi-person-check', 'bi-person-workspace', 'bi-person-badge', 'bi-person-vcard', 'bi-gear', 'bi-graph-up', 'bi-file-earmark-text', 'bi-journal-text', 'bi-ui-checks-grid', 'bi-bell', 'bi-calendar-event', 'bi-building', 'bi-tools', 'bi-mortarboard', 'bi-droplet', 'bi-lightning', 'bi-stars', 'bi-check2-circle', 'bi-list-task', 'bi-bar-chart', 'bi-globe2'];

  var MENUS = null; // catálogo de transações com URL (link direto)
  var state = { doc: null, revisao: 0, meta: null, dirty: false, sel: null, lang: 'pt', expandidos: {} };
  var arvore = root.querySelector('[data-arvore]');
  var form = root.querySelector('[data-formulario]');
  var statusEl = root.querySelector('[data-status]');
  var alerta = root.querySelector('[data-alerta]');
  var btn = function (acao) { return root.querySelector('[data-acao="' + acao + '"]'); };

  // ---------------------------------------------------------------- utilidades
  function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function vazio() { return { pt: '', en: '', es: '' }; }
  function txt(o, lang) { if (!o) return ''; if (typeof o === 'string') return o; return String(o[lang || 'pt'] || o.pt || ''); }
  function clone(o) { return JSON.parse(JSON.stringify(o)); }
  function faltando(o) { if (!o || typeof o !== 'object') return { en: 0, es: 0 }; var pt = String(o.pt || '').trim(); return { en: pt && !String(o.en || '').trim() ? 1 : 0, es: pt && !String(o.es || '').trim() ? 1 : 0 }; }
  function textosDoNo(no) {
    if (!no) return [];
    var lista = [];
    ['nome', 'resumo', 'perfis_nota', 'titulo', 'texto', 'onde', 'caminho', 'termo', 'ver', 'antes', 'depois', 'oque'].forEach(function (k) { if (no[k] && typeof no[k] === 'object') lista.push(no[k]); });
    if (Array.isArray(no.passos) && no.passos.length && no.passos[0] && no.passos[0].pt !== undefined) lista = lista.concat(no.passos);
    return lista;
  }
  function pendenciasTraducao(no) { var r = { en: 0, es: 0 }; textosDoNo(no).forEach(function (o) { var f = faltando(o); r.en += f.en; r.es += f.es; }); return r; }
  // MySQL não preserva a ordem das chaves JSON: perfis são listados por nome, com "sistema" no fim.
  function chavesPerfis() {
    return Object.keys(state.doc.perfis).sort(function (a, b) {
      if (a === 'sistema') return 1; if (b === 'sistema') return -1;
      return txt(state.doc.perfis[a].nome).localeCompare(txt(state.doc.perfis[b].nome));
    });
  }
  function chaveValida(v) { return /^[a-z0-9][a-z0-9_-]{1,39}$/.test(v); }

  function mostrarAlerta(msg, tipo) {
    alerta.className = 'ge-alert is-' + (tipo || 'info');
    alerta.textContent = msg;
    window.clearTimeout(mostrarAlerta.t);
    if (tipo === 'success') mostrarAlerta.t = window.setTimeout(function () { alerta.className = 'ge-alert d-none'; }, 5000);
  }
  function limparAlerta() { alerta.className = 'ge-alert d-none'; }

  function chamar(acao, dados) {
    var opcoes = { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } };
    var url = API + '?action=' + encodeURIComponent(acao);
    if (dados) {
      var corpo = new URLSearchParams();
      corpo.append('action', acao); corpo.append('csrf', csrf);
      Object.keys(dados).forEach(function (k) { corpo.append(k, dados[k]); });
      opcoes.method = 'POST'; opcoes.body = corpo;
    }
    return fetch(url, opcoes).then(function (r) {
      return r.json().catch(function () { return { ok: false, message: T.erro }; }).then(function (d) {
        if (!r.ok || !d.ok) { var e = new Error(d.message || T.erro); e.status = r.status; throw e; }
        return d;
      });
    });
  }

  // ---------------------------------------------------------------- estado
  function aplicarRascunho(r) {
    state.doc = r.conteudo || { perfis: {}, modulos: [] };
    if (!state.doc.perfis || Array.isArray(state.doc.perfis)) state.doc.perfis = {};
    if (!Array.isArray(state.doc.modulos)) state.doc.modulos = [];
    state.revisao = r.revisao;
    state.meta = r;
    state.dirty = false;
    if (state.sel && !obter(state.sel)) state.sel = null;
    if (!Object.keys(state.expandidos).length && state.doc.modulos[0]) state.expandidos[0] = true;
  }
  function sujar() { if (!podeEditar) return; state.dirty = true; renderStatus(); agendarArvore(); }

  function carregar() {
    statusEl.innerHTML = '<span class="ge-pill">' + esc(T.carregando) + '</span>';
    var menus = chamar('menus').then(function (d) { MENUS = d.menus || []; }).catch(function () { MENUS = []; });
    return Promise.all([chamar('carregar'), menus]).then(function (r) { aplicarRascunho(r[0].rascunho); renderTudo(); }).catch(function (e) { mostrarAlerta(e.message, 'error'); });
  }

  function salvar() {
    if (!podeEditar) return Promise.resolve(false);
    if (!state.dirty) return Promise.resolve(true);
    btn('salvar').disabled = true;
    return chamar('salvar', { conteudo: JSON.stringify(state.doc), revisao: state.revisao }).then(function (d) {
      var sel = state.sel; aplicarRascunho(d.rascunho); state.sel = sel && obter(sel) ? sel : null;
      renderTudo(); mostrarAlerta(T.salvo, 'success'); return true;
    }).catch(function (e) { mostrarAlerta(e.message, 'error'); renderStatus(); return false; });
  }

  // ---------------------------------------------------------------- seleção
  function modulo(m) { return state.doc.modulos[m]; }
  // Ajuda interativa (migração 309): guias passo a passo e "Sobre esta tela" de cada aba.
  function garantir(mod) { if (mod) { if (!Array.isArray(mod.guias)) mod.guias = []; if (!Array.isArray(mod.telas)) mod.telas = []; } return mod; }
  var TG = {
    guias: T.guias_interativos || 'Guias passo a passo (ajuda ?)', guia: T.guia_interativo || 'Guia passo a passo', novo_guia: T.novo_guia || 'Novo guia passo a passo',
    passo: T.passo_guia || 'Passo', novo_passo: T.novo_passo_guia || 'Novo passo', telas: T.telas_ajuda || 'Sobre esta tela (ajuda ?)', tela: T.tela_ajuda || 'Tela', nova_tela: T.nova_tela_ajuda || 'Novo texto de tela',
    id: T.f_id_guia || 'Código do guia', id_ajuda: T.f_id_guia_ajuda || 'Letras minúsculas, números e hífen (ex.: ml-avaliar-requisito). Não mude depois de publicado: guias em andamento usam este código.',
    resumo: T.f_resumo || 'Resumo', antes: T.f_antes || 'Antes de começar', depois: T.f_depois || 'O que acontece depois', perfis: T.f_perfis_guia || 'Para quais perfis (nenhum = todos)',
    telas_campo: T.f_telas_guia || 'Telas onde aparece', telas_ajuda: T.f_telas_guia_ajuda || 'Separe por vírgula. Use o código do menu (ex.: REQUISITOS_LEGAIS), a transação do botão ? da tela (ex.: manutencaolegal_licencas_condicionantes) ou arquivo:nome.php.',
    quem: T.f_quem || 'Quem faz este passo', qualquer: T.f_qualquer || '(quem estiver usando)', texto: T.f_texto_passo || 'O que fazer (uma ação)',
    texto_ajuda: T.f_texto_passo_ajuda || 'Escreva o nome do botão ou campo entre **dois asteriscos**, exatamente como aparece na tela. Ex.: No grupo **Avaliação**, clique em **Situação**.',
    ver: T.f_ver || 'Você deve ver', ver_ajuda: T.f_ver_ajuda || 'O resultado esperado, para a pessoa saber que deu certo.',
    alvo: T.f_alvo || 'Mostre-me (onde está o item)', alvo_ajuda: T.f_alvo_ajuda || 'Vazio = procura o último nome em negrito. Ou: menu:CODIGO · acao:new|save|delete|export|reload|columns · texto:Nome do botão · campo:COLUNA · coluna:COLUNA · filtro:COLUNA · aba:Nome · linha · busca · css:seletor',
    tela_passo: T.f_tela_passo || 'Ir para a tela', auto: T.f_auto || 'Avançar sozinho quando a pessoa clicar no item', chaves: T.f_chaves || 'Telas (códigos)',
    oque: T.f_oque || 'O que é esta tela', pode: T.f_pode || 'O que você pode fazer aqui (uma linha por item: texto | onde)', pode_ajuda: T.f_pode_ajuda || 'Ex.: Avaliar a situação de um requisito | texto:Situação',
    duvidas: T.f_duvidas || 'Dúvidas comuns (uma por linha: pergunta | resposta)'
  };
  function linhasDe(lista, campoTexto, campoExtra, lang) { return (lista || []).map(function (it) { var a = txt(it[campoTexto], lang); var b = campoExtra === 'alvo' ? (it.alvo || '') : txt(it[campoExtra], lang); return b ? a + ' | ' + b : a; }).join('\n'); }
  function aplicarLinhas(lista, valor, campoTexto, campoExtra, lang) {
    var novas = String(valor || '').split(/\r?\n/).map(function (l) { return l.trim(); }).filter(Boolean), saida = [];
    novas.forEach(function (l, i) {
      var p = l.split('|'), a = p.shift().trim(), b = p.join('|').trim(), it = lista[i] ? lista[i] : {};
      if (!it[campoTexto] || typeof it[campoTexto] !== 'object') it[campoTexto] = vazio();
      it[campoTexto][lang] = a;
      if (campoExtra === 'alvo') it.alvo = b; else { if (!it[campoExtra] || typeof it[campoExtra] !== 'object') it[campoExtra] = vazio(); it[campoExtra][lang] = b; }
      saida.push(it);
    });
    lista.length = 0; saida.forEach(function (x) { lista.push(x); });
  }
  function obter(s) {
    if (!s || !state.doc) return null;
    try {
      switch (s.tipo) {
        case 'perfis': return state.doc.perfis;
        case 'perfil': return state.doc.perfis[s.chave] || null;
        case 'modulo': return modulo(s.m) || null;
        case 'fluxo': return modulo(s.m) ? modulo(s.m).fases : null;
        case 'fase': return modulo(s.m).fases[s.f] || null;
        case 'etapa': return modulo(s.m).fases[s.f].passos[s.p] || null;
        case 'tarefas': return modulo(s.m) ? modulo(s.m).tarefas : null;
        case 'tarefa': return modulo(s.m).tarefas[s.i] || null;
        case 'menu': return modulo(s.m) ? modulo(s.m).menu : null;
        case 'menuitem': return modulo(s.m).menu[s.i] || null;
        case 'termos': return modulo(s.m) ? modulo(s.m).termos : null;
        case 'termo': return modulo(s.m).termos[s.i] || null;
        case 'guias': return modulo(s.m) ? garantir(modulo(s.m)).guias : null;
        case 'guia': return garantir(modulo(s.m)).guias[s.i] || null;
        case 'guiapasso': return garantir(modulo(s.m)).guias[s.i].passos[s.p] || null;
        case 'telas': return modulo(s.m) ? garantir(modulo(s.m)).telas : null;
        case 'tela': return garantir(modulo(s.m)).telas[s.i] || null;
      }
    } catch (e) { return null; }
    return null;
  }
  // Lista que contém o item selecionado e sua posição (para mover/duplicar/excluir).
  function listaDe(s) {
    switch (s.tipo) {
      case 'modulo': return { lista: state.doc.modulos, i: s.m, idx: 'm' };
      case 'fase': return { lista: modulo(s.m).fases, i: s.f, idx: 'f' };
      case 'etapa': return { lista: modulo(s.m).fases[s.f].passos, i: s.p, idx: 'p' };
      case 'tarefa': return { lista: modulo(s.m).tarefas, i: s.i, idx: 'i' };
      case 'menuitem': return { lista: modulo(s.m).menu, i: s.i, idx: 'i' };
      case 'termo': return { lista: modulo(s.m).termos, i: s.i, idx: 'i' };
      case 'guia': return { lista: garantir(modulo(s.m)).guias, i: s.i, idx: 'i' };
      case 'guiapasso': return { lista: garantir(modulo(s.m)).guias[s.i].passos, i: s.p, idx: 'p' };
      case 'tela': return { lista: garantir(modulo(s.m)).telas, i: s.i, idx: 'i' };
    }
    return null;
  }
  function selecionar(s) { state.sel = s; if (s && s.m !== undefined) state.expandidos[s.m] = true; renderArvore(); renderForm(); }
  function igual(a, b) { return a && b && a.tipo === b.tipo && a.m === b.m && a.f === b.f && a.p === b.p && a.i === b.i && a.chave === b.chave; }

  // ---------------------------------------------------------------- status e botões
  function renderStatus() {
    var r = state.meta || {};
    var partes = [];
    if (r.publicado) partes.push('<span>' + esc(T.publicado) + ': <b>' + esc(T.versao) + ' ' + r.publicado.numero + '</b> · ' + esc(formatarData(r.publicado.publicado_em)) + (r.publicado.publicado_por ? ' ' + esc(T.por) + ' ' + esc(r.publicado.publicado_por) : '') + '</span>');
    else partes.push('<span>' + esc(T.nunca) + '</span>');
    if (!podeEditar) partes.push('<span class="ge-pill"><i class="bi bi-lock"></i>' + esc(T.st_leitura) + '</span>');
    else if (state.dirty) partes.push('<span class="ge-pill is-dirty"><i class="bi bi-pencil"></i>' + esc(T.st_sujo) + '</span>');
    else if (r.pendente) partes.push('<span class="ge-pill is-pending"><i class="bi bi-hourglass-split"></i>' + esc(T.st_pendente) + '</span>');
    else partes.push('<span class="ge-pill is-ok"><i class="bi bi-check2"></i>' + esc(T.st_igual) + '</span>');
    statusEl.innerHTML = partes.join('');
    btn('salvar').disabled = !podeEditar || !state.dirty;
    btn('publicar').disabled = !podeEditar;
    btn('salvar').classList.toggle('d-none', !podeEditar);
    btn('publicar').classList.toggle('d-none', !podeEditar);
  }
  function formatarData(v) {
    if (!v) return '';
    var m = String(v).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
    if (!m) return v;
    var idioma = root.getAttribute('data-idioma');
    return (idioma === 'en' ? m[2] + '/' + m[3] + '/' + m[1] : m[3] + '/' + m[2] + '/' + m[1]) + ' ' + m[4] + ':' + m[5];
  }

  // ---------------------------------------------------------------- árvore
  var arvoreTimer = null;
  function agendarArvore() { window.clearTimeout(arvoreTimer); arvoreTimer = window.setTimeout(renderArvore, 200); }

  function no(s, icone, rotulo, extra) {
    var vaz = !String(rotulo || '').trim();
    var off = s.tipo === 'modulo' && modulo(s.m) && modulo(s.m).ativo === false;
    return '<button type="button" class="gt-node' + (igual(s, state.sel) ? ' is-selected' : '') + (off ? ' is-off' : '') + '" data-sel=\'' + esc(JSON.stringify(s)) + '\'>' +
      (icone ? '<i class="bi ' + esc(icone) + '" aria-hidden="true"></i>' : '') + (extra && extra.antes || '') +
      '<span class="gt-label' + (vaz ? ' is-empty' : '') + '">' + esc(vaz ? T.sem_titulo : rotulo) + '</span>' + (extra && extra.depois || '') + '</button>';
  }
  function adicionar(acao, dados, rotulo) {
    if (!podeEditar) return '';
    return '<button type="button" class="gt-add" data-add="' + acao + '" data-ctx=\'' + esc(JSON.stringify(dados || {})) + '\' title="' + esc(rotulo) + '"><i class="bi bi-plus-lg"></i>' + esc(rotulo) + '</button>';
  }
  function iconeLink(o) { return o && o.transacao ? '<i class="bi bi-link-45deg gt-link" title="' + esc(T.tem_link + ': ' + o.transacao) + '"></i>' : ''; }
  function aviso(o) { var p = pendenciasTraducao(o); return p.en + p.es > 0 ? '<span class="gt-warn" title="' + esc((p.en + p.es) + ' ' + T.faltam) + '"></span>' : ''; }

  function renderArvore() {
    if (!state.doc) return;
    var h = '';
    h += '<div class="gt-group"><span>' + esc(T.perfis) + '</span>' + adicionar('perfil', {}, T.novo_perfil) + '</div>';
    chavesPerfis().forEach(function (k) {
      var p = state.doc.perfis[k];
      h += no({ tipo: 'perfil', chave: k }, p.icone || 'bi-person', txt(p.nome) + '  ·  ' + k, { depois: aviso(p) });
    });
    h += '<div class="gt-group"><span>' + esc(T.abas) + '</span>' + adicionar('modulo', {}, T.nova_aba) + '</div>';
    state.doc.modulos.forEach(function (mod, m) {
      var aberto = !!state.expandidos[m];
      h += '<div class="gt-row"><button type="button" class="gt-toggle" data-toggle="' + m + '" aria-expanded="' + aberto + '" aria-label="' + esc(txt(mod.nome)) + '"><i class="bi bi-chevron-' + (aberto ? 'down' : 'right') + '"></i></button>' +
        no({ tipo: 'modulo', m: m }, mod.icone || 'bi-folder', txt(mod.nome), { depois: aviso(mod) }) + '</div>';
      if (!aberto) return;
      h += '<div class="gt-children">';
      h += '<div class="gt-row">' + no({ tipo: 'fluxo', m: m }, 'bi-signpost-split', T.fluxo, { depois: '<span class="gt-count">' + mod.fases.length + '</span>' }) + adicionar('fase', { m: m }, T.fase) + '</div>';
      h += '<div class="gt-children">';
      mod.fases.forEach(function (fase, f) {
        h += '<div class="gt-row">' + no({ tipo: 'fase', m: m, f: f }, null, (f + 1) + '. ' + txt(fase.nome), { depois: aviso(fase) }) + adicionar('etapa', { m: m, f: f }, T.etapa) + '</div>';
        if (fase.passos.length) {
          h += '<div class="gt-children">';
          fase.passos.forEach(function (passo, p) {
            var a = passo.tipo === 'A';
            h += no({ tipo: 'etapa', m: m, f: f, p: p }, null, txt(passo.titulo), { antes: '<span class="gt-am ' + (a ? 'a' : 'm') + '">' + (a ? 'A' : 'M') + '</span>', depois: iconeLink(passo) + aviso(passo) });
          });
          h += '</div>';
        }
      });
      h += '</div>';
      [['tarefas', 'tarefa', 'bi-list-check', T.tarefas, mod.tarefas, 'titulo'], ['menu', 'menuitem', 'bi-compass', T.menu, mod.menu, 'caminho'], ['termos', 'termo', 'bi-lightbulb', T.termos, mod.termos, 'termo']].forEach(function (g) {
        h += '<div class="gt-row">' + no({ tipo: g[0], m: m }, g[2], g[3], { depois: '<span class="gt-count">' + g[4].length + '</span>' }) + adicionar(g[1], { m: m }, '') + '</div>';
        if (state.sel && state.sel.m === m && (state.sel.tipo === g[0] || state.sel.tipo === g[1])) {
          h += '<div class="gt-children">';
          g[4].forEach(function (item, i) { h += no({ tipo: g[1], m: m, i: i }, null, txt(item[g[5]]), { depois: iconeLink(item) + aviso(item) }); });
          h += '</div>';
        }
      });
      garantir(mod);
      h += '<div class="gt-row">' + no({ tipo: 'guias', m: m }, 'bi-compass', TG.guias, { depois: '<span class="gt-count">' + mod.guias.length + '</span>' }) + adicionar('guia', { m: m }, '') + '</div>';
      if (state.sel && state.sel.m === m && /^(guias|guia|guiapasso)$/.test(state.sel.tipo)) {
        h += '<div class="gt-children">';
        mod.guias.forEach(function (g, i) {
          h += '<div class="gt-row">' + no({ tipo: 'guia', m: m, i: i }, null, txt(g.titulo), { depois: '<span class="gt-count">' + (g.passos || []).length + '</span>' + aviso(g) }) + adicionar('guiapasso', { m: m, i: i }, '') + '</div>';
          if ((state.sel.tipo === 'guia' || state.sel.tipo === 'guiapasso') && state.sel.i === i) {
            h += '<div class="gt-children">';
            (g.passos || []).forEach(function (p, j) { h += no({ tipo: 'guiapasso', m: m, i: i, p: j }, null, (j + 1) + '. ' + txt(p.texto).replace(/\*\*/g, ''), { depois: aviso(p) }); });
            h += '</div>';
          }
        });
        h += '</div>';
      }
      h += '<div class="gt-row">' + no({ tipo: 'telas', m: m }, 'bi-info-circle', TG.telas, { depois: '<span class="gt-count">' + mod.telas.length + '</span>' }) + adicionar('tela', { m: m }, '') + '</div>';
      if (state.sel && state.sel.m === m && /^(telas|tela)$/.test(state.sel.tipo)) {
        h += '<div class="gt-children">';
        mod.telas.forEach(function (tl, i) { h += no({ tipo: 'tela', m: m, i: i }, null, (tl.chaves || []).join(', '), { depois: aviso(tl) }); });
        h += '</div>';
      }
      h += '</div>';
    });
    arvore.innerHTML = h;
  }

  arvore.addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-toggle]');
    if (t) { var m = +t.getAttribute('data-toggle'); state.expandidos[m] = !state.expandidos[m]; renderArvore(); return; }
    var a = ev.target.closest('[data-add]');
    if (a) { criar(a.getAttribute('data-add'), JSON.parse(a.getAttribute('data-ctx') || '{}')); return; }
    var n = ev.target.closest('[data-sel]');
    if (n) selecionar(JSON.parse(n.getAttribute('data-sel')));
  });

  // ---------------------------------------------------------------- criação
  function novaEtapa(mod) { return { tipo: 'M', perfil: (mod.perfis && mod.perfis[0]) || 'sistema', titulo: vazio(), texto: vazio(), onde: vazio() }; }
  function criar(tipo, ctx) {
    if (!podeEditar) return;
    var mod = ctx.m !== undefined ? modulo(ctx.m) : null;
    if (tipo === 'perfil') {
      var k = (window.prompt(T.pergunta_codigo_perfil) || '').trim().toLowerCase();
      if (!k) return;
      if (!chaveValida(k) || state.doc.perfis[k]) { mostrarAlerta(T.codigo_invalido, 'error'); return; }
      state.doc.perfis[k] = { icone: 'bi-person', nome: vazio() };
      sujar(); selecionar({ tipo: 'perfil', chave: k }); return;
    }
    if (tipo === 'modulo') {
      var id = (window.prompt(T.pergunta_codigo_aba) || '').trim().toLowerCase();
      if (!id) return;
      if (!chaveValida(id) || state.doc.modulos.some(function (x) { return x.id === id; })) { mostrarAlerta(T.codigo_invalido, 'error'); return; }
      state.doc.modulos.push({ id: id, icone: 'bi-folder', ativo: false, nome: vazio(), resumo: vazio(), perfis: [], perfis_nota: vazio(), fases: [{ nome: vazio(), passos: [] }], tarefas: [], menu: [], termos: [] });
      sujar(); selecionar({ tipo: 'modulo', m: state.doc.modulos.length - 1 }); return;
    }
    if (tipo === 'fase') { mod.fases.push({ nome: vazio(), passos: [] }); sujar(); selecionar({ tipo: 'fase', m: ctx.m, f: mod.fases.length - 1 }); return; }
    if (tipo === 'etapa') { mod.fases[ctx.f].passos.push(novaEtapa(mod)); sujar(); selecionar({ tipo: 'etapa', m: ctx.m, f: ctx.f, p: mod.fases[ctx.f].passos.length - 1 }); return; }
    if (tipo === 'tarefa') { mod.tarefas.push({ titulo: vazio(), passos: [vazio()] }); sujar(); selecionar({ tipo: 'tarefa', m: ctx.m, i: mod.tarefas.length - 1 }); return; }
    if (tipo === 'menuitem') { mod.menu.push({ caminho: vazio(), texto: vazio() }); sujar(); selecionar({ tipo: 'menuitem', m: ctx.m, i: mod.menu.length - 1 }); return; }
    if (tipo === 'termo') { mod.termos.push({ termo: vazio(), texto: vazio(), dica: false }); sujar(); selecionar({ tipo: 'termo', m: ctx.m, i: mod.termos.length - 1 }); }
    if (tipo === 'guia') {
      garantir(mod); var base = (mod.id + '-guia').slice(0, 33), gid = base + '-' + (mod.guias.length + 1), n = mod.guias.length + 2;
      while (state.doc.modulos.some(function (x) { return (x.guias || []).some(function (g) { return g.id === gid; }); })) gid = base + '-' + n++;
      mod.guias.push({ id: gid, titulo: vazio(), resumo: vazio(), antes: vazio(), depois: vazio(), perfis: [], telas: [], passos: [novoPassoGuia()] });
      sujar(); selecionar({ tipo: 'guia', m: ctx.m, i: mod.guias.length - 1 }); return;
    }
    if (tipo === 'guiapasso') { var gg = garantir(mod).guias[ctx.i]; gg.passos.push(novoPassoGuia()); sujar(); selecionar({ tipo: 'guiapasso', m: ctx.m, i: ctx.i, p: gg.passos.length - 1 }); return; }
    if (tipo === 'tela') { garantir(mod).telas.push({ chaves: [], oque: vazio(), pode: [], antes: vazio(), depois: vazio(), duvidas: [] }); sujar(); selecionar({ tipo: 'tela', m: ctx.m, i: mod.telas.length - 1 }); }
  }
  function novoPassoGuia() { return { quem: '', texto: vazio(), ver: vazio(), alvo: '', tela: '', auto: true }; }

  // ---------------------------------------------------------------- formulário
  function campoTexto(chave, rotulo, obj, opcoes) {
    opcoes = opcoes || {};
    var valor = String((obj || {})[state.lang] || '');
    var id = 'f_' + chave + '_' + Math.random().toString(36).slice(2, 7);
    var controle = opcoes.area
      ? '<textarea class="form-control form-control-sm" id="' + id + '" rows="' + (opcoes.linhas || 3) + '" maxlength="' + (opcoes.max || 600) + '" data-texto="' + chave + '">' + esc(valor) + '</textarea>'
      : '<input type="text" class="form-control form-control-sm" id="' + id + '" maxlength="' + (opcoes.max || 200) + '" data-texto="' + chave + '" value="' + esc(valor) + '">';
    var ref = state.lang !== 'pt' && txt(obj, 'pt') ? '<div class="gf-pt-ref">PT: <b>' + esc(txt(obj, 'pt')) + '</b></div>' : '';
    return '<div class="gf-field"><label for="' + id + '">' + esc(rotulo) + ' <span class="text-muted fw-normal">(' + state.lang.toUpperCase() + ')</span></label>' + controle + ref + (opcoes.ajuda ? '<small>' + esc(opcoes.ajuda) + '</small>' : '') + '</div>';
  }
  function campoIcone(valor) {
    return '<div class="gf-field"><span class="gf-label">' + esc(T.f_icone) + '</span><div class="gf-icon-input"><span class="gf-icon-preview"><i class="bi ' + esc(valor || 'bi-circle') + '" data-icone-preview></i></span>' +
      '<input type="text" class="form-control form-control-sm" maxlength="53" data-campo="icone" value="' + esc(valor || '') + '" placeholder="bi-..." aria-label="' + esc(T.f_icone) + '"></div>' +
      '<div class="gf-icons" aria-label="' + esc(T.icones_sugeridos) + '">' + ICONES.map(function (i) { return '<button type="button" data-icone="' + i + '" title="' + i + '"><i class="bi ' + i + '"></i></button>'; }).join('') + '</div></div>';
  }
  function campoLink(o) {
    var atual = o.transacao || '', lista = MENUS || [], existe = !atual || lista.some(function (m) { return m.codigo === atual; });
    var grupos = {}, ordem = [];
    lista.forEach(function (m) { var g = txt(m.grupo, idiomaUi) || '—'; if (!grupos[g]) { grupos[g] = []; ordem.push(g); } grupos[g].push(m); });
    var h = '<div class="gf-field"><label for="gfLink"><i class="bi bi-link-45deg me-1"></i>' + esc(T.f_link) + '</label><select class="form-select form-select-sm" id="gfLink" data-campo="transacao"><option value="">' + esc(T.f_link_nenhum) + '</option>';
    if (!existe) h += '<option value="' + esc(atual) + '" selected>' + esc(atual) + ' (?)</option>';
    ordem.forEach(function (g) {
      h += '<optgroup label="' + esc(g) + '">' + grupos[g].map(function (m) { return '<option value="' + esc(m.codigo) + '"' + (m.codigo === atual ? ' selected' : '') + '>' + esc(txt(m.nome, idiomaUi)) + '</option>'; }).join('') + '</optgroup>';
    });
    h += '</select><small>' + esc(T.f_link_ajuda) + '</small>';
    if (!existe) h += '<div class="gf-link-warn"><i class="bi bi-exclamation-triangle me-1"></i>' + esc(String(T.f_link_invalido).replace('%s', atual)) + '</div>';
    return h + '</div>';
  }
  var idiomaUi = root.getAttribute('data-idioma') || 'pt';

  function seletorIdioma(noAtual) {
    var p = pendenciasTraducao(noAtual);
    return '<div class="gf-lang"><span class="gf-lang-label">' + esc(T.idioma_edicao) + '</span><div class="btn-group btn-group-sm" role="group">' +
      LANGS.map(function (l) {
        var n = l === 'pt' ? 0 : p[l];
        return '<button type="button" class="btn ' + (state.lang === l ? 'btn-success' : 'btn-outline-secondary') + '" data-lang="' + l + '" aria-pressed="' + (state.lang === l) + '">' + l.toUpperCase() + (n ? '<span class="badge rounded-pill text-bg-warning" title="' + n + ' ' + esc(n === 1 ? T.faltando : T.faltam) + '">' + n + '</span>' : '') + '</button>';
      }).join('') + '</div><span class="gf-hint">' + esc(T.aviso_traducao) + '</span></div>';
  }
  function ferramentas(s) {
    if (!podeEditar) return '';
    var l = listaDe(s), h = '<div class="gf-tools">';
    if (l) {
      h += '<button type="button" class="btn btn-sm btn-outline-secondary" data-op="subir" title="' + esc(T.subir) + '" aria-label="' + esc(T.subir) + '"' + (l.i === 0 ? ' disabled' : '') + '><i class="bi bi-arrow-up"></i></button>';
      h += '<button type="button" class="btn btn-sm btn-outline-secondary" data-op="descer" title="' + esc(T.descer) + '" aria-label="' + esc(T.descer) + '"' + (l.i >= l.lista.length - 1 ? ' disabled' : '') + '><i class="bi bi-arrow-down"></i></button>';
      h += '<button type="button" class="btn btn-sm btn-outline-secondary" data-op="duplicar" title="' + esc(T.duplicar) + '"><i class="bi bi-copy me-1"></i>' + esc(T.duplicar) + '</button>';
    }
    if (l || (s.tipo === 'perfil' && s.chave !== 'sistema')) h += '<button type="button" class="btn btn-sm btn-outline-danger" data-op="excluir" title="' + esc(T.excluir) + '"><i class="bi bi-trash me-1"></i>' + esc(T.excluir) + '</button>';
    return h + '</div>';
  }
  function cabecalho(s, migalha, titulo) {
    return '<div class="gf-head"><div><div class="gf-crumb">' + esc(migalha) + '</div><h2 class="gf-title">' + esc(titulo || T.sem_titulo) + '</h2></div>' + ferramentas(s) + '</div>';
  }
  function resumoLista(itens, tipoItem, m, campo, rotuloNovo, tipoNovo) {
    var h = '<div class="gf-summary">';
    itens.forEach(function (it, i) { h += '<button type="button" data-ir=\'' + esc(JSON.stringify({ tipo: tipoItem, m: m, i: i })) + '\'><b>' + (i + 1) + '</b><span>' + esc(txt(it[campo]) || T.sem_titulo) + '</span></button>'; });
    h += '</div>';
    if (podeEditar) h += '<button type="button" class="btn btn-sm btn-outline-success mt-3" data-novo="' + tipoNovo + '"><i class="bi bi-plus-lg me-1"></i>' + esc(rotuloNovo) + '</button>';
    return h;
  }

  function renderForm() {
    var s = state.sel, o = obter(s);
    if (!s || !o) { form.innerHTML = '<div class="ge-empty"><i class="bi bi-hand-index"></i><p>' + esc(T.escolha) + '</p></div>'; return; }
    var mod = s.m !== undefined ? modulo(s.m) : null;
    var nomeMod = mod ? (txt(mod.nome) || mod.id) : '';
    var h = '';
    switch (s.tipo) {
      case 'perfil':
        h += cabecalho(s, T.perfis, txt(o.nome) || s.chave) + seletorIdioma(o);
        h += '<div class="gf-field"><span class="gf-label">' + esc(T.f_chave) + '</span><input class="form-control form-control-sm" value="' + esc(s.chave) + '" readonly><small>' + esc(s.chave === 'sistema' ? T.f_sistema : T.f_chave_ajuda) + '</small></div>';
        h += campoTexto('nome', T.f_nome, o.nome, { max: 80 }) + campoIcone(o.icone);
        break;
      case 'modulo':
        h += cabecalho(s, T.abas, nomeMod) + seletorIdioma(o);
        h += '<div class="gf-row"><div class="gf-field"><label for="gfId">' + esc(T.f_codigo) + '</label><input class="form-control form-control-sm" id="gfId" maxlength="40" data-campo="id" value="' + esc(o.id) + '"><small>' + esc(T.f_codigo_ajuda) + '</small></div>';
        h += '<div class="gf-field"><span class="gf-label">&nbsp;</span><div class="form-check form-switch mt-1"><input class="form-check-input" type="checkbox" role="switch" id="gfAtivo" data-campo="ativo"' + (o.ativo !== false ? ' checked' : '') + '><label class="form-check-label" for="gfAtivo">' + esc(T.f_ativo) + '</label></div></div></div>';
        h += campoTexto('nome', T.f_nome, o.nome, { max: 80 }) + campoTexto('resumo', T.f_resumo, o.resumo, { area: true, max: 400, linhas: 2 }) + campoIcone(o.icone);
        h += '<div class="gf-field"><span class="gf-label">' + esc(T.f_perfis) + '</span><div class="gf-checks">' + chavesPerfis().filter(function (k) { return k !== 'sistema'; }).map(function (k) {
          return '<label><input type="checkbox" data-perfil-modulo="' + esc(k) + '"' + (o.perfis.indexOf(k) >= 0 ? ' checked' : '') + '><i class="bi ' + esc(state.doc.perfis[k].icone) + '"></i>' + esc(txt(state.doc.perfis[k].nome, state.lang) || k) + '</label>';
        }).join('') + '</div></div>';
        h += campoTexto('perfis_nota', T.f_perfis_nota, o.perfis_nota, { area: true, max: 300, linhas: 2 });
        h += '<div class="gf-summary">' + [['fluxo', T.fluxo, o.fases.reduce(function (n, f) { return n + f.passos.length; }, 0)], ['tarefas', T.tarefas, o.tarefas.length], ['menu', T.menu, o.menu.length], ['termos', T.termos, o.termos.length]].map(function (x) {
          return '<button type="button" data-ir=\'' + esc(JSON.stringify({ tipo: x[0], m: s.m })) + '\'><b>' + x[2] + '</b><span>' + esc(x[1]) + '</span></button>';
        }).join('') + '</div>';
        break;
      case 'fluxo':
        h += cabecalho(s, nomeMod, T.fluxo);
        h += '<div class="gf-summary">' + o.map(function (f, i) { return '<button type="button" data-ir=\'' + esc(JSON.stringify({ tipo: 'fase', m: s.m, f: i })) + '\'><b>' + (i + 1) + '</b><span>' + esc(txt(f.nome) || T.sem_titulo) + ' · ' + f.passos.length + ' ' + esc(T.etapa.toLowerCase()) + '(s)</span></button>'; }).join('') + '</div>';
        if (podeEditar) h += '<button type="button" class="btn btn-sm btn-outline-success mt-3" data-novo="fase"><i class="bi bi-plus-lg me-1"></i>' + esc(T.nova_fase) + '</button>';
        break;
      case 'fase':
        h += cabecalho(s, nomeMod + ' › ' + T.fluxo, (s.f + 1) + '. ' + (txt(o.nome) || T.sem_titulo)) + seletorIdioma(o);
        h += campoTexto('nome', T.f_nome, o.nome, { max: 80 });
        h += '<span class="gf-label">' + esc(T.etapa) + 's</span><div class="gf-summary">' + o.passos.map(function (p, i) {
          return '<button type="button" data-ir=\'' + esc(JSON.stringify({ tipo: 'etapa', m: s.m, f: s.f, p: i })) + '\'><b><span class="gt-am ' + (p.tipo === 'A' ? 'a' : 'm') + '">' + p.tipo + '</span></b><span>' + esc(txt(p.titulo) || T.sem_titulo) + '</span></button>';
        }).join('') + '</div>';
        if (podeEditar) h += '<button type="button" class="btn btn-sm btn-outline-success mt-3" data-novo="etapa"><i class="bi bi-plus-lg me-1"></i>' + esc(T.nova_etapa) + '</button>';
        break;
      case 'etapa':
        h += cabecalho(s, nomeMod + ' › ' + (s.f + 1) + '. ' + txt(mod.fases[s.f].nome), txt(o.titulo)) + seletorIdioma(o);
        h += '<div class="gf-field"><span class="gf-label">' + esc(T.f_tipo) + '</span><div class="gf-tipo"><label><input type="radio" name="gfTipo" value="M" data-campo="tipo"' + (o.tipo !== 'A' ? ' checked' : '') + '><span class="gt-am m">M</span>' + esc(T.f_manual) + '</label><label><input type="radio" name="gfTipo" value="A" data-campo="tipo"' + (o.tipo === 'A' ? ' checked' : '') + '><span class="gt-am a">A</span>' + esc(T.f_auto) + '</label></div></div>';
        var ordem = (mod.perfis || []).concat(['sistema']).concat(chavesPerfis().filter(function (k) { return k !== 'sistema' && (mod.perfis || []).indexOf(k) < 0; }));
        h += '<div class="gf-field"><label for="gfPerfil">' + esc(T.f_perfil) + '</label><select class="form-select form-select-sm" id="gfPerfil" data-campo="perfil">' + ordem.map(function (k) {
          return '<option value="' + esc(k) + '"' + (o.perfil === k ? ' selected' : '') + '>' + esc(txt(state.doc.perfis[k].nome, state.lang) || k) + '</option>';
        }).join('') + '</select></div>';
        h += campoTexto('titulo', T.f_titulo, o.titulo, { max: 120 }) + campoTexto('texto', T.f_texto, o.texto, { area: true, max: 600 }) + campoTexto('onde', T.f_onde, o.onde, { max: 200 }) + campoLink(o);
        break;
      case 'tarefas':
        h += cabecalho(s, nomeMod, T.tarefas) + resumoLista(o, 'tarefa', s.m, 'titulo', T.nova_tarefa, 'tarefa');
        break;
      case 'menu':
        h += cabecalho(s, nomeMod, T.menu) + resumoLista(o, 'menuitem', s.m, 'caminho', T.novo_item, 'menuitem');
        break;
      case 'termos':
        h += cabecalho(s, nomeMod, T.termos) + resumoLista(o, 'termo', s.m, 'termo', T.novo_termo, 'termo');
        break;
      case 'tarefa':
        h += cabecalho(s, nomeMod + ' › ' + T.tarefas, txt(o.titulo)) + seletorIdioma(o);
        h += campoTexto('titulo', T.f_titulo, o.titulo, { max: 150 });
        h += '<span class="gf-label">' + esc(T.f_passos) + ' (' + state.lang.toUpperCase() + ')</span><ol class="gf-steps">' + o.passos.map(function (p, i) {
          var ops = podeEditar ? '<div class="ops"><button type="button" class="btn btn-sm btn-outline-secondary" data-passo-op="subir" data-passo="' + i + '" aria-label="' + esc(T.subir) + '"' + (i === 0 ? ' disabled' : '') + '><i class="bi bi-arrow-up"></i></button><button type="button" class="btn btn-sm btn-outline-secondary" data-passo-op="descer" data-passo="' + i + '" aria-label="' + esc(T.descer) + '"' + (i === o.passos.length - 1 ? ' disabled' : '') + '><i class="bi bi-arrow-down"></i></button><button type="button" class="btn btn-sm btn-outline-danger" data-passo-op="excluir" data-passo="' + i + '" aria-label="' + esc(T.excluir) + '"><i class="bi bi-x-lg"></i></button></div>' : '<span></span>';
          var ref = state.lang !== 'pt' && txt(p, 'pt') ? '<div class="gf-pt-ref">PT: <b>' + esc(txt(p, 'pt')) + '</b></div>' : '';
          return '<li><span class="num">' + (i + 1) + '.</span><div><textarea class="form-control form-control-sm" rows="2" maxlength="300" data-passo-texto="' + i + '" aria-label="' + esc(T.f_passos) + ' ' + (i + 1) + '">' + esc(String(p[state.lang] || '')) + '</textarea>' + ref + '</div>' + ops + '</li>';
        }).join('') + '</ol>';
        if (podeEditar) h += '<button type="button" class="btn btn-sm btn-outline-success" data-novo-passo><i class="bi bi-plus-lg me-1"></i>' + esc(T.f_novo_passo) + '</button>';
        h += '<div class="mt-3">' + campoLink(o) + '</div>';
        break;
      case 'menuitem':
        h += cabecalho(s, nomeMod + ' › ' + T.menu, txt(o.caminho)) + seletorIdioma(o);
        h += campoTexto('caminho', T.f_caminho, o.caminho, { max: 200 }) + campoTexto('texto', T.f_texto, o.texto, { area: true, max: 300, linhas: 2 }) + campoLink(o);
        break;
      case 'termo':
        h += cabecalho(s, nomeMod + ' › ' + T.termos, txt(o.termo)) + seletorIdioma(o);
        h += campoTexto('termo', T.f_termo, o.termo, { max: 80 }) + campoTexto('texto', T.f_texto, o.texto, { area: true, max: 400, linhas: 3 });
        h += '<div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="gfDica" data-campo="dica"' + (o.dica ? ' checked' : '') + '><label class="form-check-label" for="gfDica">' + esc(T.f_dica) + '</label></div>';
        break;
      case 'guias':
        h += cabecalho(s, nomeMod, TG.guias) + '<p class="text-muted small">' + esc(T.guias_explica || 'Aparecem no painel de Ajuda (botão ? ou F1) das telas indicadas e no modo guiado, com "Mostre-me" e "Ir para a tela".') + '</p>' + resumoLista(o, 'guia', s.m, 'titulo', TG.novo_guia, 'guia');
        break;
      case 'telas':
        h += cabecalho(s, nomeMod, TG.telas) + '<div class="gf-summary">' + o.map(function (tl, i) { return '<button type="button" data-ir=\'' + esc(JSON.stringify({ tipo: 'tela', m: s.m, i: i })) + '\'><b>' + (i + 1) + '</b><span>' + esc((tl.chaves || []).join(', ') || T.sem_titulo) + '</span></button>'; }).join('') + '</div>'
          + (podeEditar ? '<button type="button" class="btn btn-sm btn-outline-success mt-3" data-novo="tela"><i class="bi bi-plus-lg me-1"></i>' + esc(TG.nova_tela) + '</button>' : '');
        break;
      case 'guia':
        h += cabecalho(s, nomeMod + ' › ' + TG.guias, txt(o.titulo)) + seletorIdioma(o);
        h += '<div class="gf-field"><label for="gfGid">' + esc(TG.id) + '</label><input class="form-control form-control-sm" id="gfGid" maxlength="40" data-campo="gid" value="' + esc(o.id || '') + '"><small>' + esc(TG.id_ajuda) + '</small></div>';
        h += campoTexto('titulo', T.f_titulo, o.titulo, { max: 150 }) + campoTexto('resumo', TG.resumo, o.resumo, { area: true, max: 300, linhas: 2 }) + campoTexto('antes', TG.antes, o.antes, { area: true, max: 400, linhas: 2 }) + campoTexto('depois', TG.depois, o.depois, { area: true, max: 400, linhas: 2 });
        h += '<div class="gf-field"><label for="gfTelas">' + esc(TG.telas_campo) + '</label><input class="form-control form-control-sm" id="gfTelas" data-campo="telas" value="' + esc((o.telas || []).join(', ')) + '"><small>' + esc(TG.telas_ajuda) + '</small></div>';
        h += '<div class="gf-field"><span class="gf-label">' + esc(TG.perfis) + '</span><div class="gf-checks">' + chavesPerfis().filter(function (k) { return k !== 'sistema'; }).map(function (k) {
          return '<label><input type="checkbox" data-perfil-guia="' + esc(k) + '"' + ((o.perfis || []).indexOf(k) >= 0 ? ' checked' : '') + '><i class="bi ' + esc(state.doc.perfis[k].icone) + '"></i>' + esc(txt(state.doc.perfis[k].nome, state.lang) || k) + '</label>';
        }).join('') + '</div></div>';
        h += '<span class="gf-label">' + esc(TG.passo) + 's</span><div class="gf-summary">' + (o.passos || []).map(function (p, i) { return '<button type="button" data-ir=\'' + esc(JSON.stringify({ tipo: 'guiapasso', m: s.m, i: s.i, p: i })) + '\'><b>' + (i + 1) + '</b><span>' + esc(txt(p.texto).replace(/\*\*/g, '') || T.sem_titulo) + '</span></button>'; }).join('') + '</div>';
        if (podeEditar) h += '<button type="button" class="btn btn-sm btn-outline-success mt-3" data-novo-guiapasso><i class="bi bi-plus-lg me-1"></i>' + esc(TG.novo_passo) + '</button>';
        break;
      case 'guiapasso': {
        var gp = mod.guias[s.i];
        h += cabecalho(s, nomeMod + ' › ' + txt(gp.titulo), TG.passo + ' ' + (s.p + 1) + ' / ' + gp.passos.length) + seletorIdioma(o);
        h += '<div class="gf-field"><label for="gfQuem">' + esc(TG.quem) + '</label><select class="form-select form-select-sm" id="gfQuem" data-campo="quem"><option value="">' + esc(TG.qualquer) + '</option>' + chavesPerfis().filter(function (k) { return k !== 'sistema'; }).map(function (k) { return '<option value="' + esc(k) + '"' + (o.quem === k ? ' selected' : '') + '>' + esc(txt(state.doc.perfis[k].nome, state.lang) || k) + '</option>'; }).join('') + '</select></div>';
        h += campoTexto('texto', TG.texto, o.texto, { area: true, max: 400, linhas: 2, ajuda: TG.texto_ajuda }) + campoTexto('ver', TG.ver, o.ver, { area: true, max: 300, linhas: 2, ajuda: TG.ver_ajuda });
        h += '<div class="gf-field"><label for="gfAlvo">' + esc(TG.alvo) + '</label><input class="form-control form-control-sm" id="gfAlvo" maxlength="200" data-campo="alvo" value="' + esc(o.alvo || '') + '"><small>' + esc(TG.alvo_ajuda) + '</small></div>';
        h += campoLink({ transacao: o.tela || '' }).replace('data-campo="transacao"', 'data-campo="tela"').replace(esc(T.f_link), esc(TG.tela_passo));
        h += '<div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="gfAuto" data-campo="auto"' + (o.auto !== false ? ' checked' : '') + '><label class="form-check-label" for="gfAuto">' + esc(TG.auto) + '</label></div>';
        break;
      }
      case 'tela':
        h += cabecalho(s, nomeMod + ' › ' + TG.telas, (o.chaves || []).join(', ')) + seletorIdioma(o);
        h += '<div class="gf-field"><label for="gfChaves">' + esc(TG.chaves) + '</label><input class="form-control form-control-sm" id="gfChaves" data-campo="chaves" value="' + esc((o.chaves || []).join(', ')) + '"><small>' + esc(TG.telas_ajuda) + '</small></div>';
        h += campoTexto('oque', TG.oque, o.oque, { area: true, max: 400, linhas: 3 });
        h += '<div class="gf-field"><label for="gfPode">' + esc(TG.pode) + ' (' + state.lang.toUpperCase() + ')</label><textarea class="form-control form-control-sm" id="gfPode" rows="4" data-linhas="pode">' + esc(linhasDe(o.pode, 'texto', 'alvo', state.lang)) + '</textarea><small>' + esc(TG.pode_ajuda) + '</small></div>';
        h += campoTexto('antes', TG.antes, o.antes, { area: true, max: 400, linhas: 2 }) + campoTexto('depois', TG.depois, o.depois, { area: true, max: 400, linhas: 2 });
        h += '<div class="gf-field"><label for="gfDuvidas">' + esc(TG.duvidas) + ' (' + state.lang.toUpperCase() + ')</label><textarea class="form-control form-control-sm" id="gfDuvidas" rows="4" data-linhas="duvidas">' + esc(linhasDe(o.duvidas, 'p', 'r', state.lang)) + '</textarea></div>';
        break;
      default:
        h = '<div class="ge-empty"><i class="bi bi-hand-index"></i><p>' + esc(T.escolha) + '</p></div>';
    }
    form.innerHTML = podeEditar ? h : '<fieldset disabled>' + h + '</fieldset>';
  }

  // Edição de campos
  form.addEventListener('input', function (ev) {
    var el = ev.target, o = obter(state.sel);
    if (!o || !podeEditar) return;
    if (el.hasAttribute('data-texto')) {
      var chave = el.getAttribute('data-texto');
      if (!o[chave] || typeof o[chave] !== 'object') o[chave] = vazio();
      o[chave][state.lang] = el.value; sujar(); atualizarTitulo(); return;
    }
    if (el.hasAttribute('data-passo-texto')) {
      var i = +el.getAttribute('data-passo-texto');
      if (!o.passos[i] || typeof o.passos[i] !== 'object') o.passos[i] = vazio();
      o.passos[i][state.lang] = el.value; sujar(); return;
    }
    if (el.getAttribute('data-campo') === 'icone') {
      o.icone = el.value.trim().toLowerCase();
      var pv = form.querySelector('[data-icone-preview]'); if (pv) pv.className = 'bi ' + (/^bi-[a-z0-9-]+$/.test(o.icone) ? o.icone : 'bi-circle');
      sujar();
    }
  });
  form.addEventListener('change', function (ev) {
    var el = ev.target, o = obter(state.sel);
    if (!o || !podeEditar) return;
    var campo = el.getAttribute('data-campo');
    if (campo === 'gid') {
      var gid = el.value.trim().toLowerCase(), dup = state.doc.modulos.some(function (x) { return (x.guias || []).some(function (g) { return g !== o && g.id === gid; }); });
      if (!chaveValida(gid) || dup) { mostrarAlerta(T.codigo_invalido, 'error'); el.value = o.id; return; }
      o.id = gid; limparAlerta(); sujar(); return;
    }
    if (campo === 'telas' || campo === 'chaves') { o[campo] = el.value.split(',').map(function (x) { return x.trim(); }).filter(function (x) { return /^[A-Za-z0-9_.:-]{2,120}$/.test(x); }); el.value = o[campo].join(', '); sujar(); renderArvore(); return; }
    if (campo === 'quem' || campo === 'alvo') { o[campo] = el.value.trim(); sujar(); return; }
    if (campo === 'tela') { o.tela = el.value; sujar(); return; }
    if (campo === 'auto') { o.auto = el.checked; sujar(); return; }
    if (el.hasAttribute('data-perfil-guia')) {
      var pg = el.getAttribute('data-perfil-guia');
      o.perfis = (o.perfis || []).filter(function (x) { return x !== pg; });
      if (el.checked) o.perfis.push(pg);
      sujar(); return;
    }
    if (el.hasAttribute('data-linhas')) {
      var qual = el.getAttribute('data-linhas');
      if (!Array.isArray(o[qual])) o[qual] = [];
      if (qual === 'pode') aplicarLinhas(o.pode, el.value, 'texto', 'alvo', state.lang); else aplicarLinhas(o.duvidas, el.value, 'p', 'r', state.lang);
      sujar(); return;
    }
    if (campo === 'id') {
      var novo = el.value.trim().toLowerCase();
      if (!chaveValida(novo) || state.doc.modulos.some(function (x, i) { return i !== state.sel.m && x.id === novo; })) { mostrarAlerta(T.codigo_invalido, 'error'); el.value = o.id; return; }
      o.id = novo; limparAlerta(); sujar(); return;
    }
    if (campo === 'ativo' || campo === 'dica') { o[campo] = el.checked; sujar(); renderArvore(); return; }
    if (campo === 'tipo') { o.tipo = el.value === 'A' ? 'A' : 'M'; if (o.tipo === 'A' && o.perfil !== 'sistema') { o.perfil = 'sistema'; } sujar(); renderArvore(); renderForm(); return; }
    if (campo === 'perfil') { o.perfil = el.value; sujar(); return; }
    if (campo === 'transacao') { o.transacao = el.value; sujar(); renderForm(); return; }
    if (el.hasAttribute('data-perfil-modulo')) {
      var k = el.getAttribute('data-perfil-modulo');
      o.perfis = o.perfis.filter(function (x) { return x !== k; });
      if (el.checked) o.perfis.push(k);
      sujar();
    }
  });
  function atualizarTitulo() {
    var t = form.querySelector('.gf-title'), o = obter(state.sel);
    if (!t || !o) return;
    var campo = o.titulo ? 'titulo' : (o.caminho ? 'caminho' : (o.termo ? 'termo' : 'nome'));
    if (o[campo] && typeof o[campo] === 'object') t.textContent = txt(o[campo]) || T.sem_titulo;
  }

  form.addEventListener('click', function (ev) {
    var l = ev.target.closest('[data-lang]');
    if (l) { state.lang = l.getAttribute('data-lang'); renderForm(); return; }
    var ir = ev.target.closest('[data-ir]');
    if (ir) { selecionar(JSON.parse(ir.getAttribute('data-ir'))); return; }
    var ic = ev.target.closest('[data-icone]');
    if (ic && podeEditar) { var o = obter(state.sel); o.icone = ic.getAttribute('data-icone'); sujar(); renderForm(); renderArvore(); return; }
    var nv = ev.target.closest('[data-novo]');
    if (nv) { criar(nv.getAttribute('data-novo'), state.sel); return; }
    if (ev.target.closest('[data-novo-guiapasso]')) { criar('guiapasso', state.sel); return; }
    if (ev.target.closest('[data-novo-passo]')) { obter(state.sel).passos.push(vazio()); sujar(); renderForm(); var ult = form.querySelectorAll('[data-passo-texto]'); if (ult.length) ult[ult.length - 1].focus(); return; }
    var po = ev.target.closest('[data-passo-op]');
    if (po) {
      var lista = obter(state.sel).passos, i = +po.getAttribute('data-passo'), op = po.getAttribute('data-passo-op');
      if (op === 'excluir') lista.splice(i, 1);
      if (op === 'subir' && i > 0) lista.splice(i - 1, 0, lista.splice(i, 1)[0]);
      if (op === 'descer' && i < lista.length - 1) lista.splice(i + 1, 0, lista.splice(i, 1)[0]);
      sujar(); renderForm(); return;
    }
    var b = ev.target.closest('[data-op]');
    if (b) operar(b.getAttribute('data-op'));
  });

  function perfilEmUso(k) {
    return state.doc.modulos.some(function (m) { return m.perfis.indexOf(k) >= 0 || m.fases.some(function (f) { return f.passos.some(function (p) { return p.perfil === k; }); }); });
  }
  function operar(op) {
    var s = state.sel;
    if (!podeEditar || !s) return;
    if (s.tipo === 'perfil') {
      if (op !== 'excluir' || s.chave === 'sistema') return;
      if (perfilEmUso(s.chave)) { mostrarAlerta(T.perfil_em_uso, 'error'); return; }
      if (!window.confirm(T.confirma_excluir)) return;
      delete state.doc.perfis[s.chave]; sujar(); selecionar(null); return;
    }
    var l = listaDe(s);
    if (!l) return;
    var novo = Object.assign({}, s);
    if (op === 'subir' && l.i > 0) { l.lista.splice(l.i - 1, 0, l.lista.splice(l.i, 1)[0]); novo[l.idx] = l.i - 1; }
    else if (op === 'descer' && l.i < l.lista.length - 1) { l.lista.splice(l.i + 1, 0, l.lista.splice(l.i, 1)[0]); novo[l.idx] = l.i + 1; }
    else if (op === 'duplicar') {
      var copia = clone(l.lista[l.i]);
      if (s.tipo === 'modulo') { var base = copia.id + '-copia', id = base, n = 2; while (state.doc.modulos.some(function (x) { return x.id === id; })) id = base + n++; copia.id = id.slice(0, 40); copia.ativo = false; (copia.guias || []).forEach(function (g, gi) { g.id = (copia.id + '-g' + (gi + 1)).slice(0, 40); }); }
      if (s.tipo === 'guia') { var gb = String(copia.id || 'guia').slice(0, 33) + '-c', gi2 = gb, gn = 2; while (state.doc.modulos.some(function (x) { return (x.guias || []).some(function (g) { return g.id === gi2; }); })) gi2 = gb + gn++; copia.id = gi2.slice(0, 40); }
      l.lista.splice(l.i + 1, 0, copia); novo[l.idx] = l.i + 1;
    } else if (op === 'excluir') {
      if (!window.confirm(T.confirma_excluir)) return;
      l.lista.splice(l.i, 1);
      if (s.tipo === 'modulo') { state.expandidos = {}; novo = null; }
      else if (s.tipo === 'etapa') novo = { tipo: 'fase', m: s.m, f: s.f };
      else if (s.tipo === 'fase') novo = { tipo: 'fluxo', m: s.m };
      else if (s.tipo === 'guiapasso') novo = { tipo: 'guia', m: s.m, i: s.i };
      else novo = { tipo: { tarefa: 'tarefas', menuitem: 'menu', termo: 'termos', guia: 'guias', tela: 'telas' }[s.tipo], m: s.m };
    } else return;
    if (s.tipo === 'modulo' && novo && op !== 'duplicar') { var e = {}; e[novo.m] = true; state.expandidos = e; }
    sujar(); selecionar(novo);
  }

  // ---------------------------------------------------------------- ações do topo
  function abrirGuia(params) {
    var url = new URL('guia_usuario.php?' + params, window.location.href).toString();
    var host = window.top || window, popup = null;
    try { popup = host.GreenPortalPopups; } catch (e) { popup = null; }
    if (popup && host.jQuery) {
      var frame = host.jQuery('<iframe>', { title: T.preview, style: 'width:100%;height:100%;border:0;display:block;' }).attr('src', url);
      if (typeof popup.transacaoIndependente === 'function') popup.transacaoIndependente({ titulo: T.preview + ' — ' + T.titulo, conteudo: frame });
      else popup.transacao({ titulo: T.preview + ' — ' + T.titulo, conteudo: frame });
      return;
    }
    window.open(url, '_blank', 'noopener');
  }

  // Ajuda: dentro do portal o popup padrão é aberto por ajuda-transacao.js; fora dele, abre em nova janela.
  btn('ajuda').addEventListener('click', function () {
    var host = window.top || window, temPopup = false;
    try { temPopup = !!(host.GreenPortalPopups && host.jQuery); } catch (e) { temPopup = false; }
    if (!temPopup) window.open(new URL('setup_ajuda.php?transacao=administracao_guia_usuario', window.location.href).toString(), '_blank', 'noopener');
  });
  btn('salvar').addEventListener('click', salvar);
  btn('preview').addEventListener('click', function () { salvar().then(function (ok) { if (ok !== false) abrirGuia('preview=rascunho'); }); });
  btn('publicar').addEventListener('click', function () {
    if (!podeEditar) return;
    salvar().then(function (ok) {
      if (ok === false) return;
      if (state.meta && !state.meta.pendente) { mostrarAlerta(T.nada_publicar, 'info'); return; }
      document.getElementById('geObservacao').value = '';
      bootstrap.Modal.getOrCreateInstance(document.getElementById('geModalPublicar')).show();
    });
  });
  document.querySelector('[data-confirmar-publicacao]').addEventListener('click', function () {
    var b = this; b.disabled = true;
    chamar('publicar', { revisao: state.revisao, observacao: document.getElementById('geObservacao').value })
      .then(function (d) { bootstrap.Modal.getInstance(document.getElementById('geModalPublicar')).hide(); var sel = state.sel; aplicarRascunho(d.rascunho); state.sel = sel; renderTudo(); mostrarAlerta(T.publicado_ok, 'success'); })
      .catch(function (e) { bootstrap.Modal.getInstance(document.getElementById('geModalPublicar')).hide(); mostrarAlerta(e.message, 'error'); })
      .then(function () { b.disabled = false; });
  });

  var versoesCache = [];
  btn('versoes').addEventListener('click', function () {
    var corpo = document.querySelector('[data-versoes]');
    corpo.innerHTML = '<tr><td colspan="5">' + esc(T.carregando) + '</td></tr>';
    document.querySelector('[data-descartar-rascunho]').classList.toggle('d-none', !podeEditar);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('geModalVersoes')).show();
    chamar('versoes').then(function (d) {
      versoesCache = d.versoes;
      corpo.innerHTML = d.versoes.map(function (v) {
        return '<tr><td><b>' + v.numero + '</b> ' + (v.vigente ? '<span class="badge text-bg-success">' + esc(T.v_vigente) + '</span>' : '') + (v.origem === 'CARGA_INICIAL' ? ' <span class="badge text-bg-light">' + esc(T.v_carga) + '</span>' : '') + '</td>' +
          '<td>' + esc(formatarData(v.publicado_em)) + '</td><td>' + esc(v.publicado_por) + '</td><td>' + esc(v.observacao) + '</td>' +
          '<td class="text-end text-nowrap"><button type="button" class="btn btn-sm btn-outline-secondary" data-ver-versao="' + v.codversao + '"><i class="bi bi-eye me-1"></i>' + esc(T.v_ver) + '</button> ' +
          (podeEditar ? '<button type="button" class="btn btn-sm btn-outline-success" data-restaurar="' + v.codversao + '"><i class="bi bi-arrow-counterclockwise me-1"></i>' + esc(T.v_restaurar) + '</button>' : '') + '</td></tr>';
      }).join('') || '<tr><td colspan="5">—</td></tr>';
    }).catch(function (e) { corpo.innerHTML = '<tr><td colspan="5" class="text-danger">' + esc(e.message) + '</td></tr>'; });
  });
  function restaurar(codversao) {
    if (state.dirty && !window.confirm(T.sair_sem_salvar)) return;
    if (!window.confirm(T.v_confirma)) return;
    chamar('restaurar', { codversao: codversao, revisao: state.revisao }).then(function (d) {
      bootstrap.Modal.getInstance(document.getElementById('geModalVersoes')).hide();
      aplicarRascunho(d.rascunho); state.sel = null; renderTudo(); mostrarAlerta(T.restaurado, 'success');
    }).catch(function (e) { mostrarAlerta(e.message, 'error'); });
  }
  document.getElementById('geModalVersoes').addEventListener('click', function (ev) {
    var ver = ev.target.closest('[data-ver-versao]');
    if (ver) { abrirGuia('preview=versao&codversao=' + encodeURIComponent(ver.getAttribute('data-ver-versao'))); return; }
    var r = ev.target.closest('[data-restaurar]');
    if (r) { restaurar(+r.getAttribute('data-restaurar')); return; }
    if (ev.target.closest('[data-descartar-rascunho]')) {
      var vigente = versoesCache.filter(function (v) { return v.vigente; })[0];
      if (vigente) restaurar(vigente.codversao);
    }
  });

  document.addEventListener('keydown', function (ev) {
    if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 's') { ev.preventDefault(); salvar(); }
  });
  window.addEventListener('beforeunload', function (ev) { if (state.dirty) { ev.preventDefault(); ev.returnValue = ''; } });

  function renderTudo() { renderStatus(); renderArvore(); renderForm(); }
  carregar();
}());
