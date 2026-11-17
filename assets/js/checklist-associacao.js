(function () {
  'use strict';

  const initial = window.checklistInitial || {};
  const permission = window.checklistPermission || { include: false, delete: false };
  const selected = new Set((initial.selected || []).map(String));
  const lawId = new URLSearchParams(window.location.search).get('CODLEGISLACAO') || '';
  const rows = document.querySelector('[data-rows]');
  const status = document.querySelector('[data-status]');
  const message = document.querySelector('[data-message]');
  const previous = document.querySelector('[data-prev]');
  const next = document.querySelector('[data-next]');
  const searchForm = document.querySelector('[data-search-form]');
  const searchInput = document.querySelector('[data-search]');
  const save = document.querySelector('[data-save]');
  let page = Number(initial.page || 1);
  let pages = Number(initial.pages || 1);
  let total = Number(initial.total || 0);
  let associatedTotal = Number(initial.total_associadas || document.querySelectorAll('tr.associated').length || 0);
  let search = '';
  let busy = false;

  // Textos no idioma do usuário (Administração › Textos e idiomas), com o português como padrão.
  function T(chave, padrao) { return typeof window.greenT === 'function' ? window.greenT('manutencaolegalrequisitoslegais_checklist.js.' + chave, padrao) : padrao; }

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>'"]/g, function (character) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' })[character];
    });
  }

  function updateStatus() {
    status.textContent = T('status', '{total} perguntas · {associadas} associadas · Página {pagina}').replace('{total}', total).replace('{associadas}', associatedTotal).replace('{pagina}', page);
    previous.disabled = busy || page <= 1;
    next.disabled = busy || page >= pages;
  }

  function applyPermissionState() {
    rows.querySelectorAll('[data-question-check]').forEach(function (checkbox) {
      const associated = checkbox.checked;
      checkbox.disabled = busy || (associated ? !permission.delete : !permission.include);
    });
  }

  function render(items, meta) {
    page = Number(meta.pagina || 1);
    pages = Number(meta.paginas || 1);
    total = Number(meta.total || 0);
    associatedTotal = Number(meta.total_associadas || 0);
    rows.innerHTML = '';
    if (!items.length) {
      rows.innerHTML = '<tr><td colspan="2" class="text-center text-muted">' + escapeHtml(T('nenhuma', 'Nenhuma pergunta encontrada.')) + '</td></tr>';
    } else {
      rows.innerHTML = items.map(function (item) {
        const id = String(item.CODPERGUNTA);
        const associated = Number(item.associado) === 1;
        return '<tr data-question-id="' + escapeHtml(id) + '" class="' + (associated ? 'associated' : '') + '">' +
          '<td><input type="checkbox" data-question-check ' + (selected.has(id) ? 'checked' : '') + '></td>' +
          '<td class="question">' + escapeHtml(item.DSCPERGUNTA) + '</td></tr>';
      }).join('');
    }
    applyPermissionState();
    updateStatus();
  }

  function setBusy(value) {
    busy = value;
    if (save) save.disabled = value || (!permission.include && !permission.delete);
    if (searchInput) searchInput.disabled = value;
    applyPermissionState();
    updateStatus();
  }

  function load(targetPage) {
    if (busy) return;
    setBusy(true);
    message.textContent = '';
    $.post(window.location.href, {
      action: 'load_checklist',
      CODLEGISLACAO: lawId,
      pagina: targetPage,
      busca: search
    }, null, 'json').done(function (response) {
      if (!response || !response.ok) throw new Error((response && response.message) || T('erro_carregar', 'Não foi possível carregar as perguntas.'));
      render(response.items || [], response);
    }).fail(function (xhr) {
      message.textContent = (xhr.responseJSON && xhr.responseJSON.message) || T('erro_carregar', 'Não foi possível carregar as perguntas.');
    }).always(function () { setBusy(false); });
  }

  function confirmSave(callback) {
    const host = window.parent && window.parent.GreenPortalPopups ? window.parent : window.top;
    if (host && host.GreenPortalPopups && typeof host.GreenPortalPopups.mensagem === 'function') {
      host.GreenPortalPopups.mensagem({
        titulo: T('confirmar_titulo', 'Confirmar alterações'),
        mensagem: T('confirmar_mensagem', 'Confirma a inclusão e/ou exclusão das perguntas selecionadas?'),
        icone: 'bi-question-circle',
        confirmar: callback
      });
      return;
    }
    message.textContent = T('erro_popup', 'Não foi possível abrir o popup de confirmação.');
  }

  function closeChecklistPopup() {
    const host = window.top || window.parent;
    if (!host || !host.document || !host.bootstrap) return;
    const modals = host.document.querySelectorAll('[data-popup-transacao-independent]');
    const modal = modals[modals.length - 1];
    if (modal) host.bootstrap.Modal.getOrCreateInstance(modal).hide();
  }

  rows.addEventListener('change', function (event) {
    const checkbox = event.target.closest('[data-question-check]');
    if (!checkbox) return;
    const row = checkbox.closest('[data-question-id]');
    if (!row) return;
    const id = String(row.dataset.questionId);
    if (checkbox.checked) selected.add(id); else selected.delete(id);
    applyPermissionState();
  });

  searchForm.addEventListener('submit', function (event) {
    event.preventDefault();
    search = searchInput.value.trim();
    load(1);
  });

  previous.addEventListener('click', function () { if (page > 1) load(page - 1); });
  next.addEventListener('click', function () { if (page < pages) load(page + 1); });

  save.addEventListener('click', function () {
    if (busy) return;
    confirmSave(function () {
      setBusy(true);
      message.textContent = '';
      $.post(window.location.href, {
        action: 'save_checklist',
        CODLEGISLACAO: lawId,
        perguntas: JSON.stringify(Array.from(selected, Number))
      }, null, 'json').done(function (response) {
        if (!response || !response.ok) throw new Error((response && response.message) || T('erro_salvar', 'Não foi possível salvar as associações.'));
        if (window.parent && window.parent !== window) window.parent.postMessage({ type: 'legislacao-checklist-saved', CODLEGISLACAO: lawId }, '*');
        closeChecklistPopup();
      }).fail(function (xhr) {
        message.textContent = (xhr.responseJSON && xhr.responseJSON.message) || T('erro_salvar', 'Não foi possível salvar as associações.');
      }).always(function () { setBusy(false); });
    });
  });

  load(1);
}());
