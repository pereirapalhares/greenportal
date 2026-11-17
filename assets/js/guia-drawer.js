(function (window, document) {
  'use strict';
  var root = document.querySelector('[data-help-drawer]');
  if (!root) return;
  var data = Array.isArray(window.greenGuideRequirements) ? window.greenGuideRequirements : [];
  var home = root.querySelector('[data-help-home]');
  var view = root.querySelector('[data-help-step-view]');
  var current = null, step = 0;

  function reqFrame() {
    try {
      var top = window.top || window;
      var frames = top.document.querySelectorAll('iframe');
      for (var i = frames.length - 1; i >= 0; i--) {
        var frame = frames[i], src = String(frame.getAttribute('src') || '');
        if (/modulolegal_requisitoslegais/i.test(src) && frame.contentWindow && frame.contentWindow.document.querySelector('.modulo-legal-requisitos-fragment')) return frame.contentWindow;
      }
    } catch (e) {}
    return null;
  }
  function text(item) { return item && item.pt ? String(item.pt) : String(item || ''); }
  function renderPanelStep() {
    var item = data[current], total = item ? item.passos.length : 0, instruction = item ? String(item.passos[step] || '') : '';
    root.querySelector('[data-step-label]').textContent = 'PASSO ' + (step + 1) + ' DE ' + total;
    root.querySelector('[data-step-title]').textContent = text(item && item.titulo);
    root.querySelector('[data-step-text]').textContent = instruction;
    root.querySelector('[data-step-see]').textContent = instruction;
    root.querySelector('[data-step-count]').textContent = (step + 1) + ' / ' + total;
    root.querySelector('[data-step-prev]').disabled = step === 0;
    root.querySelector('[data-step-next]').textContent = step === total - 1 ? 'Concluir →' : 'Próximo →';
  }
  function findTarget(doc, instruction) {
    var target = String(instruction || '').toLowerCase(), selector = '.menu-grid-toolbar';
    if (/filtro|busca|pesquis/.test(target)) selector = '.legal-context-filters, .crud-search';
    else if (/linha|selecione o requisito|selecione uma linha/.test(target)) selector = '#requisitosLegaisBatchGrid tbody tr[data-row]';
    else if (/situação|status/.test(target)) selector = '[data-help="requisitos-situacao"], [data-open-requisito-popup="situacao"]';
    else if (/questionário/.test(target)) selector = '[data-help="requisitos-questionario"], [data-open-questionnaire]';
    else if (/ia|sugest/.test(target)) selector = '[data-help="requisitos-ia"], [data-request-ia-analysis]';
    else if (/excel|export/.test(target)) selector = '[data-help="requisitos-exportar"], [data-export-requisitos]';
    else if (/fornecedor|homologa/.test(target)) selector = '[data-help="requisitos-homologacao"], [data-open-requisito-popup="homologacao"]';
    return doc.querySelector(selector) || doc.querySelector('.menu-grid-toolbar');
  }
  function clearTarget(doc) {
    var old = doc && doc.querySelector('[data-green-tour-target]');
    if (old) { old.removeAttribute('data-green-tour-target'); old.style.outline = ''; old.style.outlineOffset = ''; }
  }
  function highlight(target, instruction) {
    var doc = target.document, element = findTarget(doc, instruction);
    clearTarget(doc);
    if (!element) return;
    element.scrollIntoView({ behavior: 'smooth', block: 'center' });
    element.setAttribute('data-green-tour-target', '1');
    element.style.outline = '3px solid var(--gp-info, #477fae)';
    element.style.outlineOffset = '3px';
  }
  function makeCard(target) {
    var targetDoc = target.document;
    var doc = (window.top || window).document;
    var old = doc.querySelector('[data-green-guide-card]');
    if (old) old.remove();
    var css = doc.getElementById('greenGuideCardCss');
    if (!css) {
      css = doc.createElement('style'); css.id = 'greenGuideCardCss';
      css.textContent = '[data-green-guide-card]{position:fixed;right:22px;bottom:22px;z-index:2147483647;width:min(380px,calc(100vw - 44px));padding:15px;border:1px solid var(--gp-border,#dce9e2);border-radius:11px;background:var(--gp-surface,#fff);color:var(--gp-text,#203f35);box-shadow:var(--gp-shadow-md,0 10px 30px rgba(20,60,45,.22));font:13px/1.45 var(--gp-font-body,system-ui,sans-serif)}[data-green-guide-card].is-minimized{width:240px;padding:10px 12px}[data-green-guide-card].is-minimized .guide-card-content{display:none}[data-green-guide-card] .guide-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}[data-green-guide-card] .guide-card-head-actions{display:flex;gap:3px}[data-green-guide-card] .guide-card-head button{border:0;background:transparent;color:var(--gp-muted,#71808c);font-size:16px;line-height:1;padding:3px 6px;cursor:pointer;border-radius:5px}[data-green-guide-card] .guide-card-head button:hover{background:var(--gp-primary-soft,#e8f5ee);color:var(--gp-primary-dark,#185640)}[data-green-guide-card] h2{margin:4px 0 8px;font-size:16px}[data-green-guide-card] p{margin:0 0 10px}[data-green-guide-card] .guide-card-label{color:var(--gp-muted,#71808c);font-size:11px;text-transform:uppercase;font-weight:700}[data-green-guide-card] .guide-card-see{padding:9px 10px;margin-bottom:10px;border:1px solid var(--gp-primary,#72b99c);border-radius:8px;background:var(--gp-primary-soft,#edfff8);color:var(--gp-primary-dark,#1a765c)}[data-green-guide-card] .guide-card-actions{display:flex;gap:7px;flex-wrap:wrap}[data-green-guide-card] button{border:1px solid var(--gp-primary,#247a5b);border-radius:7px;padding:7px 10px;background:var(--gp-surface,#fff);color:var(--gp-primary-dark,#185640);font-weight:600;cursor:pointer}[data-green-guide-card] button.primary{background:var(--gp-primary,#247a5b);color:#fff}[data-green-guide-card] .guide-card-nav{display:flex;justify-content:space-between;align-items:center;margin-top:12px;padding-top:9px;border-top:1px solid var(--gp-border,#dce9e2)}[data-green-guide-card] .guide-card-nav span{color:var(--gp-muted,#71808c);font-size:11px}';
      doc.head.appendChild(css);
    }
    var card = doc.createElement('aside'); card.setAttribute('data-green-guide-card', '1');
    card.innerHTML = '<div class="guide-card-head"><div><div class="guide-card-label"></div><h2></h2></div><div class="guide-card-head-actions"><button type="button" data-card-minimize aria-label="Minimizar guia" title="Minimizar guia">−</button><button type="button" data-card-close aria-label="Fechar guia" title="Fechar guia">×</button></div></div><div class="guide-card-content"><p class="guide-card-text"></p><div class="guide-card-see"><strong>◉ Você deve ver</strong><br><span></span></div><div class="guide-card-actions"><button type="button" data-card-show>Mostre-me</button><button type="button" class="primary" data-card-screen>Ir para a tela</button></div><div class="guide-card-nav"><span data-card-count></span><span><button type="button" data-card-prev>← Voltar</button> <button type="button" class="primary" data-card-next>Próximo →</button></span></div></div>';
    doc.body.appendChild(card);
    var minimizeButton = card.querySelector('[data-card-minimize]');
    minimizeButton.onclick = function () {
      var minimized = card.classList.toggle('is-minimized');
      minimizeButton.textContent = minimized ? '□' : '−';
      minimizeButton.setAttribute('aria-label', minimized ? 'Restaurar guia' : 'Minimizar guia');
      minimizeButton.title = minimized ? 'Restaurar guia' : 'Minimizar guia';
      return false;
    };
    function render() {
      var item = data[current], instruction = String(item.passos[step] || '');
      card.querySelector('.guide-card-label').textContent = 'Passo ' + (step + 1) + ' de ' + item.passos.length;
      card.querySelector('h2').textContent = text(item.titulo);
      card.querySelector('.guide-card-text').textContent = instruction;
      card.querySelector('.guide-card-see span').textContent = instruction;
      card.querySelector('[data-card-count]').textContent = (step + 1) + ' / ' + item.passos.length;
      card.querySelector('[data-card-prev]').disabled = step === 0;
      card.querySelector('[data-card-next]').textContent = step === item.passos.length - 1 ? 'Concluir →' : 'Próximo →';
      highlight(target, instruction);
    }
    function finish() {
      clearTarget(targetDoc);
      card.remove();
      try { var hiddenDrawer = (window.top || window).document.getElementById('greenPortalGuideDrawer'); if (hiddenDrawer) hiddenDrawer.remove(); } catch (e) {}
    }
    card.querySelector('[data-card-close]').addEventListener('click', function (event) { event.preventDefault(); event.stopPropagation(); finish(); });
    var lastNavigation = 0;
    function once(action) { var now = Date.now(); if (now - lastNavigation < 250) return; lastNavigation = now; action(); }
    var showButton = card.querySelector('[data-card-show]');
    var screenButton = card.querySelector('[data-card-screen]');
    var prevButton = card.querySelector('[data-card-prev]');
    var nextButton = card.querySelector('[data-card-next]');
    showButton.onclick = function () { highlight(target, String(data[current].passos[step] || '')); };
    screenButton.onclick = function () { highlight(target, String(data[current].passos[step] || '')); };
    prevButton.onclick = function () { once(function () { if (step > 0) { step--; render(); } }); };
    nextButton.onclick = function () { once(function () { if (step < data[current].passos.length - 1) { step++; render(); } else finish(); }); };
    prevButton.onpointerup = prevButton.onmouseup = prevButton.onclick;
    nextButton.onpointerup = nextButton.onmouseup = nextButton.onclick;
    render();
  }
  function closeDrawer() { try { var top = window.top || window, drawer = top.document.getElementById('greenPortalGuideDrawer'); if (drawer) { drawer.style.display = 'none'; drawer.setAttribute('aria-hidden', 'true'); } } catch (e) {} }
  function startGuided(index) { var target = reqFrame(); if (!target) { window.alert('A tela de Requisitos Legais não está disponível nesta sessão.'); return; } current = index; step = 0; makeCard(target); closeDrawer(); }
  function openSteps(index) { current = index; step = 0; home.style.display = 'none'; view.classList.add('active'); renderPanelStep(); }
  function backHome() { current = null; view.classList.remove('active'); home.style.display = 'block'; }
  root.addEventListener('click', function (event) {
    var start = event.target.closest('[data-help-start]'), steps = event.target.closest('[data-help-steps]');
    if (start) { startGuided(Number(start.getAttribute('data-help-start'))); return; }
    if (steps) { openSteps(Number(steps.getAttribute('data-help-steps'))); return; }
    if (event.target.closest('[data-help-all]')) { openSteps(0); return; }
    if (event.target.closest('[data-help-close]')) { closeDrawer(); return; }
    if (event.target.closest('[data-help-back]')) { backHome(); return; }
    if (event.target.closest('[data-step-show]') || event.target.closest('[data-step-screen]')) { var target = reqFrame(); if (target) highlight(target, String(data[current].passos[step] || '')); return; }
    if (event.target.closest('[data-step-prev]') && step > 0) { step--; renderPanelStep(); return; }
    if (event.target.closest('[data-step-next]')) { if (current !== null && step < data[current].passos.length - 1) { step++; renderPanelStep(); } else backHome(); }
  });
}(window, document));
