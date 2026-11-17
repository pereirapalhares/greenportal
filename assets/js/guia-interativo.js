(function (window, document) {
  'use strict';
  var host = document.querySelector('[data-legal-guide-actions]');
  if (!host) return;
  var steps = [
    { selector: '.menu-grid-toolbar', title: 'Operacoes do requisito', text: 'Aqui ficam as acoes gerais da tela.' },
    { selector: '#requisitosResponsavel', title: 'Filtrar por responsavel', text: 'Use este filtro para encontrar requisitos atribuidos.' },
    { selector: '#requisitosLeiRevogada', title: 'Controlar leis revogadas', text: 'Altere o filtro quando precisar auditar normas revogadas.' },
    { selector: '#requisitosSemEvidencia', title: 'Encontrar requisitos sem evidencia', text: 'Escolha Sim para localizar requisitos sem evidencia ou documento associado.' },
    { selector: '.crud-search input', title: 'Pesquisar no grid', text: 'Digite parte do codigo, norma, assunto ou unidade.' },
    { selector: '#requisitosLegaisBatchGrid tbody tr[data-row]', title: 'Selecionar o requisito', text: 'Clique em uma linha para habilitar as acoes.', click: true },
    { selector: '[data-req-grupo="correlacionamentos"]', title: 'Associacoes do atendimento', text: 'Use os grupos para vincular como a empresa atende a obrigacao.' },
    { selector: '[data-req-grupo="avaliacao"]', title: 'Avaliar o requisito', text: 'Consulte situacao, checklist, questionario, IA e relatorio.' },
    { selector: '[data-open-requisito-popup="situacao"]', title: 'Registrar a situacao', text: 'Informe atendimento, responsavel, datas e observacoes.' },
    { selector: '[data-open-questionnaire]', title: 'Responder o questionario', text: 'Conclua o questionario para encaminhar a avaliacao.' },
    { selector: '[data-request-ia-analysis]', title: 'Solicitar apoio da IA', text: 'Revise e aprove ou rejeite cada sugestao da IA.' },
    { selector: '[data-export-requisitos]', title: 'Exportar a visao atual', text: 'A exportacao respeita os filtros e a visao do grid.' },
    { selector: '[data-columns]', title: 'Escolher colunas', text: 'Ajuste as colunas exibidas no grid.' }
  ];
  function findTarget() {
    try {
      var top = window.top || window, frames = top.document.querySelectorAll('iframe'), found = null;
      Array.prototype.some.call(frames, function (frame) {
        if (frame === window.frameElement || !/modulolegal_requisitoslegais/i.test(String(frame.getAttribute('src') || '')) || !frame.contentWindow) return false;
        try { if (frame.contentWindow.document.querySelector('.modulo-legal-requisitos-fragment')) { found = frame.contentWindow; return true; } } catch (e) {}
        return false;
      });
      return found;
    } catch (e) { return null; }
  }
  function mountTour(target) {
    if (!target || target.closed || !target.document || target.document.querySelector('[data-green-tour]')) return;
    var doc = target.document, style = doc.createElement('style');
    style.setAttribute('data-green-tour-style', '1');
    style.textContent = '[data-green-tour]{position:fixed;right:22px;bottom:22px;z-index:2147483647;width:min(390px,calc(100vw - 44px));padding:16px;border:1px solid var(--gp-border,#dce9e2);border-radius:12px;background:var(--gp-surface,#fff);color:var(--gp-text,#203f35);box-shadow:var(--gp-shadow-md,0 12px 36px rgba(20,60,45,.24));font:14px/1.45 var(--gp-font-body,system-ui,sans-serif)}[data-green-tour].is-minimized{width:245px;padding:10px 12px}[data-green-tour].is-minimized .tour-content{display:none}[data-green-tour] .tour-head{display:flex;justify-content:space-between;align-items:flex-start;gap:10px}[data-green-tour] .tour-head-actions{display:flex;gap:3px}[data-green-tour] .tour-head button{border:0;background:transparent;color:var(--gp-muted,#71808c);font-size:16px;line-height:1;padding:3px 6px;cursor:pointer;border-radius:5px}[data-green-tour] .tour-head button:hover{background:var(--gp-primary-soft,#e8f5ee);color:var(--gp-primary-dark,#185640)}[data-green-tour] h2{margin:0 0 7px;font-size:16px}[data-green-tour] p{margin:0 0 12px}[data-green-tour] .tour-count{color:var(--gp-muted,#71808c);font-size:12px}[data-green-tour] .tour-actions{display:flex;justify-content:space-between;gap:7px}[data-green-tour] button{border:1px solid var(--gp-primary,#247a5b);border-radius:7px;padding:7px 10px;background:var(--gp-surface,#fff);color:var(--gp-primary-dark,#185640);cursor:pointer}[data-green-tour] button.primary{background:var(--gp-primary,#247a5b);color:#fff}[data-green-tour] button.close{border-color:var(--gp-border,#dce9e2);color:var(--gp-muted,#53645b)}';
    doc.head.appendChild(style);
    var panel = doc.createElement('aside');
    panel.setAttribute('data-green-tour', '1');
    panel.setAttribute('role', 'dialog');
    panel.innerHTML = '<div class="tour-head"><div><div class="tour-count"></div><h2></h2></div><div class="tour-head-actions"><button type="button" data-tour-minimize aria-label="Minimizar guia" title="Minimizar guia">−</button><button type="button" data-tour-close aria-label="Fechar guia" title="Fechar guia">×</button></div></div><div class="tour-content"><p></p><div class="tour-actions"><button type="button" class="close" data-tour-dismiss>Encerrar</button><span><button type="button" data-tour-prev>Anterior</button> <button type="button" class="primary" data-tour-next>Proximo</button></span></div></div>';
    doc.body.appendChild(panel);
    var current = -1, previous = null;
    function clearHighlight() { if (!previous || !previous.el || !previous.el.isConnected) return; previous.el.style.outline = previous.outline; previous.el.style.outlineOffset = previous.offset; previous.el.removeAttribute('data-green-tour-target'); previous = null; }
    function render(index) {
      clearHighlight(); current = Math.max(0, Math.min(index, steps.length - 1));
      var step = steps[current], element = doc.querySelector(step.selector);
      panel.querySelector('.tour-count').textContent = 'Passo ' + (current + 1) + ' de ' + steps.length;
      panel.querySelector('h2').textContent = step.title;
      panel.querySelector('p').textContent = element ? step.text : 'Este recurso nao esta disponivel para o seu perfil ou empresa.';
      panel.querySelector('[data-tour-prev]').disabled = current === 0;
      panel.querySelector('[data-tour-next]').textContent = current === steps.length - 1 ? 'Concluir' : 'Proximo';
      if (!element) return;
      try { element.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e) { try { element.scrollIntoView(); } catch (ignore) {} }
      if (step.click && typeof element.click === 'function' && !element.classList.contains('selected')) window.setTimeout(function () { if (element.isConnected) element.click(); }, 250);
      previous = { el: element, outline: element.style.outline, offset: element.style.outlineOffset };
      element.setAttribute('data-green-tour-target', '1');
      element.style.outline = '3px solid var(--gp-info,#477fae)';
      element.style.outlineOffset = '3px';
    }
    function close() { clearHighlight(); panel.remove(); style.remove(); }
    panel.querySelector('[data-tour-minimize]').onclick = function () { var small = panel.classList.toggle('is-minimized'), button = panel.querySelector('[data-tour-minimize]'); button.textContent = small ? '□' : '−'; button.setAttribute('aria-label', small ? 'Restaurar guia' : 'Minimizar guia'); button.title = small ? 'Restaurar guia' : 'Minimizar guia'; };
    panel.querySelector('[data-tour-close]').addEventListener('click', close);
    panel.querySelector('[data-tour-dismiss]').addEventListener('click', close);
    panel.querySelector('[data-tour-prev]').addEventListener('click', function () { render(current - 1); });
    panel.querySelector('[data-tour-next]').addEventListener('click', function () { if (current >= steps.length - 1) close(); else render(current + 1); });
    render(0);
  }
  function showMe() {
    var target = findTarget();
    if (target) { mountTour(target); return; }
    var panel = document.querySelector('[data-panel]:not([hidden])') || document.querySelector('[data-panel]');
    var link = panel && (panel.querySelector('a[data-open-transacao][href*="requisitos"]') || panel.querySelector('a[data-open-transacao]'));
    var hostWindow = null;
    try { hostWindow = window.top && window.top.greenPortalOpenTab ? window.top : null; } catch (e) {}
    if (!link || !hostWindow) return;
    hostWindow.greenPortalOpenTab(link.getAttribute('data-tab-id'), link.href, link.getAttribute('data-tab-title'));
    var attempts = 0;
    var waitForTransaction = function () {
      var opened = findTarget();
      if (opened) { mountTour(opened); return; }
      if (attempts++ < 20) window.setTimeout(waitForTransaction, 300);
    };
    window.setTimeout(waitForTransaction, 350);
  }
  host.querySelector('[data-guide-start]').addEventListener('click', function () { var tab = document.querySelector('[data-tab="legal"]'); if (tab) tab.click(); var section = document.getElementById('legal-fluxo'); if (section) section.scrollIntoView({ behavior: 'smooth', block: 'start' }); });
  host.querySelector('[data-guide-step-by-step]').addEventListener('click', function () { var tab = document.querySelector('[data-tab="legal"]'); if (tab) tab.click(); var section = document.getElementById('legal-tarefas'); if (section) { section.querySelectorAll('details').forEach(function (item) { item.open = true; }); section.scrollIntoView({ behavior: 'smooth', block: 'start' }); } });
  host.querySelector('[data-guide-show-me]').addEventListener('click', showMe);
}(window, document));
