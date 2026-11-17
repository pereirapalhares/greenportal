(function () {
    'use strict';

    /*
     * Aba de um tipo de questionário (Administração › Questionários).
     * Escopo: ADMINISTRADOR altera a versão universal; ADM DA EMPRESA (ou perfil com Editar em Questionários) altera a
     * versão da empresa. Versão publicada é imutável: "Nova versão" cria o rascunho (cópia da versão em uso).
     * Rascunho que veio de um merge com a universal pode ter conflitos: o ADM escolhe manter o da empresa ou usar o universal.
     */
    const root = document.querySelector('[data-questionario-tipo]');
    if (!root) return;
    const moduleCode = root.dataset.modulo;
    const isLegal = moduleCode === 'LEGAL';
    const csrf = root.dataset.csrf;
    const api = 'api/questionarios.php';
    // Confirmação no popup do portal (GreenPortalPopups); fora do portal, a confirmação do navegador (25/10/2026)
    const confirmar = (texto) => new Promise((resolve) => {
        let host = window, P = null;
        try { host = window.top || window; P = host.GreenPortalPopups; } catch (e) { P = null; }
        const el = P && P.mensagem ? host.document.getElementById('greenPortalMensagemModal') : null;
        if (!el) { resolve(window.confirm(texto)); return; }
        let ok = false;
        const html = String(texto).replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c])).replace(/\n/g, '<br>');
        el.addEventListener('hidden.bs.modal', () => { if (!ok) resolve(false); }, {once: true});
        P.mensagem({html: html, icone: 'bi-question-circle', confirmar: () => { ok = true; resolve(true); }});
    });
    const table = root.querySelector('[data-questionnaire-table]');
    const tbody = table.querySelector('tbody');
    const select = root.querySelector('[data-questionnaire-select]');
    const search = root.querySelector('[data-grid-search]');
    const fragment = root.querySelector('[data-questionnaire-grid]');
    const versionBar = root.querySelector('[data-version-bar]');
    const conflictsBox = root.querySelector('[data-conflicts]');
    const modal = bootstrap.Modal.getOrCreateInstance(document.querySelector('[id^="questionModal"]'));
    const versionsModal = bootstrap.Modal.getOrCreateInstance(document.querySelector('[id^="versionsModal"]'));
    const form = document.querySelector('[data-question-form]');
    const newButton = root.querySelector('[data-new-question]');
    const editButton = root.querySelector('[data-edit-question]');
    const deleteButton = root.querySelector('[data-delete-question]');
    const publishButton = root.querySelector('[data-publish-version]');
    const prevButton = root.querySelector('[data-page-prev]');
    const nextButton = root.querySelector('[data-page-next]');
    const pageStatus = root.querySelector('[data-page-status]');
    const blockField = form.querySelector('[data-block-field]');
    const optionsField = form.querySelector('[data-options-field]');
    const optionsBody = form.querySelector('[data-options-body]');
    let questionnaires = [];
    let questions = [];
    let blocks = [];
    let filtered = [];
    let selected = null;
    let scope = {};
    let page = 1;
    const pageSize = 25;
    const STATUS = {RASCUNHO: ['Rascunho', 'warning'], PUBLICADA: ['Publicada', 'success'], ARQUIVADA: ['Arquivada', 'secondary']};
    const ORIGEM = {UNIVERSAL: 'universal', EMPRESA: 'da empresa', MERGE: 'merge com a universal'};
    const MOTIVO = {
        ALTERADO_NOS_DOIS: 'alterado pela empresa e pela universal',
        CRIADO_NOS_DOIS: 'criado com o mesmo código pela empresa e pela universal',
        RETIRADO_DA_UNIVERSAL: 'retirado da universal (saiu também da empresa)',
        RETIRADO_DA_UNIVERSAL_MANTIDO_PELA_EMPRESA: 'retirado da universal, mantido porque a empresa tinha alterado',
        RETIRADO_PELA_EMPRESA_ALTERADO_NA_UNIVERSAL: 'retirado pela empresa e alterado na universal (continua retirado)'
    };
    const TIPO_ITEM = {PERGUNTA: 'Pergunta', BLOCO: 'Bloco', REGRA: 'Regra'};
    const TIPOS = {TEXTO: 'Texto', NUMERO: 'Número', DATA: 'Data', SIM_NAO: 'Sim / Não', UNICA_ESCOLHA: 'Escolha única', MULTIPLA_ESCOLHA: 'Múltipla escolha', ANEXO: 'Anexo'};

    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));

    async function call(url, body) {
        const response = await fetch(url, {
            method: body ? 'POST' : 'GET',
            headers: body ? {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'} : undefined,
            body: body ? new URLSearchParams({...body, csrf}) : undefined
        });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.message || 'Não foi possível concluir a operação.');
        return data;
    }

    function setMessage(text, kind = 'muted') {
        const box = root.querySelector('[data-grid-message]');
        box.textContent = text;
        box.className = 'small mb-2 text-' + kind;
    }

    const current = () => questionnaires.find(q => String(q.codversao) === String(select.value)) || null;
    const editable = () => !!(current() && current().pode_editar);

    function resetSelection() {
        selected = null;
        editButton.disabled = true;
        deleteButton.disabled = true;
    }

    function updatePagination() {
        const total = Math.max(1, Math.ceil(filtered.length / pageSize));
        page = Math.min(page, total);
        pageStatus.textContent = page + ' / ' + total;
        prevButton.disabled = page <= 1;
        nextButton.disabled = page >= total;
    }

    function compareLegalQuestions(left, right) {
        const l = String(left.codigo_hierarquico || '').split('.').filter(Boolean).map(Number);
        const r = String(right.codigo_hierarquico || '').split('.').filter(Boolean).map(Number);
        if (!l.length || !r.length) return l.length ? -1 : (r.length ? 1 : Number(left.ordem) - Number(right.ordem));
        for (let i = 0; i < Math.max(l.length, r.length); i++) { const d = (l[i] || 0) - (r[i] || 0); if (d !== 0) return d; }
        return Number(left.ordem) - Number(right.ordem);
    }

    function render() {
        const term = (search.value || '').trim().toLowerCase();
        const hierarchyTerm = /^\d+(?:\.\d+)*\.?$/.test(term);
        filtered = questions.filter(q => !term || (isLegal && hierarchyTerm ? String(q.codigo_hierarquico || '').startsWith(term) : JSON.stringify(q).toLowerCase().includes(term)));
        if (isLegal) filtered.sort(compareLegalQuestions);
        else {   // pela ordem dos blocos no questionário, depois pela ordem da pergunta
            const pos = Object.fromEntries(blocks.map((b, i) => [String(b.codbloco), i]));
            const p = q => q.codbloco == null ? -1 : (pos[String(q.codbloco)] ?? 999);
            filtered.sort((a, b) => p(a) - p(b) || Number(a.ordem) - Number(b.ordem));
        }
        updatePagination();
        const showBlocks = blocks.length > 0;
        table.querySelectorAll('[data-block-column]').forEach(th => th.classList.toggle('d-none', !showBlocks));
        const start = (page - 1) * pageSize;
        const visible = filtered.slice(start, start + pageSize);
        const cols = (isLegal ? 1 : 0) + 5 + (showBlocks ? 1 : 0);
        tbody.innerHTML = visible.map(q => `<tr data-key="${esc(q.codpergunta)}" data-row>${isLegal ? `<td>${esc(q.codigo_hierarquico || '')}</td>` : ''}<td>${esc(q.texto_pt)}</td>${showBlocks ? `<td>${esc(q.bloco || '')}</td>` : ''}<td>${esc(TIPOS[q.tipo] || q.tipo)}</td><td>${(q.opcoes || []).length || ''}</td><td>${Number(q.obrigatoria) ? 'Sim' : 'Não'}</td><td>${esc(q.ordem)}</td></tr>`).join('')
            || `<tr><td colspan="${cols}">Nenhuma pergunta neste questionário.</td></tr>`;
        if (selected && !visible.some(q => String(q.codpergunta) === String(selected.codpergunta))) resetSelection();
    }

    function selectRow(row) {
        tbody.querySelectorAll('tr[data-row]').forEach(item => item.classList.remove('selected'));
        row.classList.add('selected');
        selected = questions.find(q => String(q.codpergunta) === String(row.dataset.key)) || null;
        editButton.disabled = !selected;
        deleteButton.disabled = !selected || !editable();
        editButton.title = editable() ? 'Editar pergunta' : 'Ver pergunta';
    }

    // ---------------------------------------------------------------- opções
    function optionRow(o = {}) {
        const ro = editable() ? '' : ' disabled';
        return `<tr><td><input type="hidden" data-o="codigo" value="${esc(o.codigo || '')}"><input class="form-control form-control-sm" data-o="rotulo_pt" value="${esc(o.rotulo_pt || '')}"${ro}></td><td><input class="form-control form-control-sm" data-o="pontuacao" value="${o.pontuacao ?? ''}" inputmode="decimal"${ro}></td><td>${editable() ? '<button type="button" class="btn btn-sm btn-outline-danger" data-remove-option title="Retirar"><i class="bi bi-x-lg"></i></button>' : ''}</td></tr>`;
    }
    function toggleOptions() {
        const tipo = form.elements.tipo.value;
        optionsField.classList.toggle('d-none', !['UNICA_ESCOLHA', 'MULTIPLA_ESCOLHA'].includes(tipo));
    }
    const readOptions = () => [...optionsBody.querySelectorAll('tr')].map(tr => Object.fromEntries([...tr.querySelectorAll('[data-o]')].map(i => [i.dataset.o, i.value.trim()]))).filter(o => o.rotulo_pt);

    function formMessage(text, kind = 'muted') {
        const box = form.querySelector('[data-form-message]');
        box.textContent = text;
        box.className = 'small text-' + kind;
    }

    function setQuestionForm(question) {
        const empty = {codpergunta: '', codigo: '', codigo_hierarquico: '', texto_pt: '', tipo: 'TEXTO', obrigatoria: 0, ordem: 0, peso: 1, codbloco: '', opcoes: [], codigo_fixo: false};
        const values = question || empty;
        form.reset();
        Object.entries(values).forEach(([key, value]) => {
            const field = form.elements[key];
            if (!field || field instanceof RadioNodeList) return;
            if (field.type === 'checkbox') field.checked = !!Number(value);
            else field.value = value ?? '';
        });
        form.elements.codversao.value = select.value;
        const blockSelect = form.elements.codbloco;
        blockSelect.innerHTML = '<option value="">(sem bloco)</option>' + blocks.map(b => `<option value="${b.codbloco}">${esc(b.titulo_pt)}</option>`).join('');
        blockSelect.value = values.codbloco || '';
        blockField.classList.toggle('d-none', !blocks.length);
        optionsBody.innerHTML = (values.opcoes || []).map(optionRow).join('');
        toggleOptions();
        const canEdit = editable();
        [...form.elements].forEach(el => { if (el.name && el.type !== 'hidden') el.disabled = !canEdit; });
        form.querySelector('[data-add-option]').classList.toggle('d-none', !canEdit);
        form.querySelector('[data-save-question]').disabled = !canEdit;
        form.querySelector('[data-form-new]').disabled = !canEdit;
        form.querySelector('[data-form-delete]').disabled = !canEdit || !question;
        formMessage('');
        const nome = (current() || {}).nome_pt || 'Questionário';
        document.querySelector('[data-question-modal-title]').textContent = nome + ' — ' + (!canEdit ? 'pergunta (somente consulta)' : (question ? 'editar pergunta' : 'nova pergunta'));
        modal.show();
        setTimeout(() => { const t = form.elements.texto_pt; if (canEdit && t) t.focus(); }, 200);
    }

    // ---------------------------------------------------------------- versão, escopo e conflitos
    function renderVersionBar() {
        const q = current();
        if (!q) { versionBar.innerHTML = ''; conflictsBox.classList.add('d-none'); return; }
        const st = STATUS[q.status] || [q.status, 'secondary'];
        const deQuem = Number(q.codempresa_versao) === 0 ? 'Universal' : (scope.empresa || 'Empresa');
        const emUso = scope.admin ? `Em uso pelas empresas sem versão própria: <strong>universal v${q.versao_universal || '—'}</strong>`
            : (q.em_uso === 'EMPRESA' ? `Em uso na ${esc(scope.empresa || 'empresa')}: <strong>versão própria v${q.versao_em_uso}</strong>` : `Em uso na ${esc(scope.empresa || 'empresa')}: <strong>universal v${q.versao_em_uso || '—'}</strong> (a empresa ainda não tem versão própria)`);
        const base = q.base_universal ? ` · base universal v${q.base_universal}` : '';
        const quem = scope.admin ? '<span class="badge text-bg-primary"><i class="bi bi-globe2 me-1"></i>Você altera a versão universal</span>'
            : (scope.pode_editar ? `<span class="badge text-bg-info"><i class="bi bi-building me-1"></i>Você altera a versão da ${esc(scope.empresa || 'empresa')}</span>` : '<span class="badge text-bg-light text-dark border">Somente consulta</span>');
        const acoes = [];
        if (q.pode_nova_versao) acoes.push(`<button type="button" class="btn btn-sm btn-outline-success" data-new-version><i class="bi bi-files me-1"></i>${scope.admin ? 'Nova versão universal' : 'Criar versão da empresa'}</button>`);
        if (q.pode_editar) acoes.push('<button type="button" class="btn btn-sm btn-outline-danger" data-discard-version><i class="bi bi-x-circle me-1"></i>Descartar rascunho</button>');
        acoes.push('<button type="button" class="btn btn-sm btn-outline-secondary" data-show-versions><i class="bi bi-clock-history me-1"></i>Histórico</button>');
        versionBar.innerHTML = `${quem}<span>Exibindo: <strong>${esc(deQuem)} v${q.versao}</strong> <span class="badge text-bg-${st[1]}">${st[0]}</span> <span class="text-muted">(${esc(ORIGEM[q.origem] || q.origem)}${base})</span></span><span class="text-muted">${emUso}</span>${q.observacao ? `<span class="text-muted fst-italic">${esc(q.observacao)}</span>` : ''}<span class="qv-acoes">${acoes.join('')}</span>`;
        const conf = q.conflitos || [], avisos = q.avisos || [];
        if (!conf.length && !avisos.length) { conflictsBox.classList.add('d-none'); conflictsBox.innerHTML = ''; return; }
        conflictsBox.classList.remove('d-none');
        conflictsBox.innerHTML = (conf.length ? `<strong><i class="bi bi-exclamation-triangle me-1"></i>${conf.length} conflito(s) com a nova versão universal</strong> — escolha o que fica antes de publicar.
            <table class="table table-sm"><thead><tr><th>Item</th><th>Código</th><th>Motivo</th><th>Empresa</th><th>Universal</th><th></th></tr></thead><tbody>${conf.map(c => `<tr><td>${esc(TIPO_ITEM[c.tipo] || c.tipo)}</td><td><code>${esc(c.codigo)}</code></td><td>${esc(MOTIVO[c.motivo] || c.motivo)}</td><td>${esc(c.empresa || '')}</td><td>${esc(c.universal_rotulo || '')}</td><td class="text-nowrap">${q.pode_editar ? `<button type="button" class="btn btn-sm btn-outline-secondary" data-resolve="MANTER" data-tipo="${esc(c.tipo)}" data-codigo="${esc(c.codigo)}">Manter da empresa</button> <button type="button" class="btn btn-sm btn-outline-primary" data-resolve="UNIVERSAL" data-tipo="${esc(c.tipo)}" data-codigo="${esc(c.codigo)}">Usar o universal</button>` : ''}</td></tr>`).join('')}</tbody></table>` : '')
            + (avisos.length ? `<details class="mt-1"><summary>${avisos.length} aviso(s) do merge</summary><ul class="mb-0">${avisos.map(a => `<li>${esc(TIPO_ITEM[a.tipo] || a.tipo)} <code>${esc(a.codigo)}</code>: ${esc(MOTIVO[a.motivo] || a.motivo)}${a.empresa || a.universal_rotulo ? ' — ' + esc(a.empresa || a.universal_rotulo) : ''}</li>`).join('')}</ul></details>` : '');
    }

    async function loadQuestions() {
        const q = current();
        resetSelection();
        renderVersionBar();
        const canEdit = editable();
        publishButton.disabled = !canEdit;
        newButton.disabled = !canEdit;
        if (!q) { questions = []; blocks = []; render(); return; }
        try {
            const builder = (await call(api + '?action=builder&codversao=' + encodeURIComponent(q.codversao))).builder;
            questions = builder.perguntas || [];
            blocks = builder.blocos || [];
            page = 1;
            render();
            setMessage(questions.length + ' pergunta(s).' + (canEdit ? '' : (q.pode_nova_versao ? ' Para alterar, crie uma nova versão.' : '')));
        } catch (error) {
            questions = []; blocks = [];
            render();
            setMessage(error.message, 'danger');
        }
    }

    async function loadCatalog(keepCode) {
        fragment.classList.add('grid-is-loading');
        const before = keepCode || (current() || {}).codquestionario;
        try {
            const data = await call(api + '?action=catalog&modulo=' + encodeURIComponent(moduleCode));
            questionnaires = data.rows || [];
            scope = data.escopo || {};
            select.innerHTML = questionnaires.map(q => `<option value="${esc(q.codversao)}" data-q="${esc(q.codquestionario)}">${esc(q.nome_pt)}</option>`).join('') || '<option value="">Nenhum questionário cadastrado</option>';
            const again = questionnaires.find(q => String(q.codquestionario) === String(before));
            if (again) select.value = String(again.codversao);
            await loadQuestions();
        } catch (error) {
            select.innerHTML = '<option value="">Erro ao carregar</option>';
            setMessage(error.message, 'danger');
        } finally {
            fragment.classList.remove('grid-is-loading');
            fragment.classList.add('grid-ready');
        }
    }

    // ---------------------------------------------------------------- eventos
    select.addEventListener('change', loadQuestions);
    search.placeholder = isLegal ? 'Buscar pergunta ou classificação (ex.: 1.)' : 'Buscar pergunta';
    search.addEventListener('input', () => { page = 1; render(); });
    prevButton.addEventListener('click', () => { if (page > 1) { page--; render(); } });
    nextButton.addEventListener('click', () => { if (page < Math.ceil(filtered.length / pageSize)) { page++; render(); } });
    root.querySelector('[data-reload-grid]').addEventListener('click', () => loadCatalog());
    newButton.addEventListener('click', () => setQuestionForm());
    editButton.addEventListener('click', () => { if (selected) setQuestionForm(selected); });
    tbody.addEventListener('click', event => { const row = event.target.closest('tr[data-row]'); if (row) selectRow(row); });
    tbody.addEventListener('dblclick', event => { const row = event.target.closest('tr[data-row]'); if (row) { selectRow(row); setQuestionForm(selected); } });
    form.elements.tipo.addEventListener('change', toggleOptions);
    form.querySelector('[data-add-option]').addEventListener('click', () => { optionsBody.insertAdjacentHTML('beforeend', optionRow()); optionsBody.querySelector('tr:last-child input').focus(); });
    optionsBody.addEventListener('click', event => { const b = event.target.closest('[data-remove-option]'); if (b) b.closest('tr').remove(); });

    publishButton.addEventListener('click', async () => {
        const q = current();
        if (!q) return;
        const texto = scope.admin
            ? 'Publicar esta versão universal?\n\nEla passa a valer para as empresas sem versão própria. As empresas com versão própria recebem um merge automático (com conflito, fica em rascunho para o ADM da empresa revisar).\nQuem já respondeu continua com a versão que respondeu.'
            : 'Publicar esta versão da empresa?\n\nEla passa a valer só para a ' + (scope.empresa || 'sua empresa') + '. Quem já respondeu continua com a versão que respondeu.';
        if (!(await confirmar(texto))) return;
        try {
            const r = (await call(api, {action: 'publicar', codversao: q.codversao})).publication || {};
            const m = r.merge || {};
            const n = Object.keys(m).length;
            await loadCatalog(q.codquestionario);
            const conf = Object.values(m).filter(x => x && x.status === 'RASCUNHO').length;
            setMessage('Versão publicada.' + (n ? ` Merge em ${n} empresa(s) com versão própria${conf ? `: ${conf} ficou(aram) em rascunho para revisão` : ', todas já publicadas'}.` : ''), 'success');
        } catch (error) { setMessage(error.message, 'danger'); }
    });
    deleteButton.addEventListener('click', async () => {
        if (!selected || !(await confirmar('Excluir a pergunta selecionada deste rascunho?'))) return;
        try { await call(api, {action: 'excluir_pergunta', codpergunta: selected.codpergunta}); await loadQuestions(); }
        catch (error) { setMessage(error.message, 'danger'); }
    });
    versionBar.addEventListener('click', async event => {
        const q = current();
        if (!q) return;
        if (event.target.closest('[data-new-version]')) {
            const texto = scope.admin ? 'Criar um rascunho da próxima versão universal (cópia da versão atual)?' : 'Criar a versão da ' + (scope.empresa || 'empresa') + ' (cópia da versão em uso)? Ela só valerá para a sua empresa depois de publicada.';
            if (!(await confirmar(texto))) return;
            try { await call(api, {action: 'nova_versao', codquestionario: q.codquestionario}); await loadCatalog(q.codquestionario); setMessage('Rascunho criado. Altere as perguntas e publique.', 'success'); }
            catch (error) { setMessage(error.message, 'danger'); }
        }
        if (event.target.closest('[data-discard-version]')) {
            if (!(await confirmar('Descartar este rascunho? Ele fica arquivado no histórico.'))) return;
            try { await call(api, {action: 'descartar', codversao: q.codversao}); await loadCatalog(q.codquestionario); }
            catch (error) { setMessage(error.message, 'danger'); }
        }
        if (event.target.closest('[data-show-versions]')) {
            try {
                const rows = (await call(api + '?action=versoes&codquestionario=' + encodeURIComponent(q.codquestionario))).rows || [];
                const data = v => v ? new Date(String(v).replace(' ', 'T')).toLocaleString('pt-BR') : '';
                document.querySelector('[data-versions-body]').innerHTML = rows.map(v => `<tr><td>v${esc(v.versao)}</td><td>${v.escopo === 'UNIVERSAL' ? 'Universal' : esc(scope.empresa || 'Empresa')}</td><td>${esc((STATUS[v.status] || [v.status])[0])}</td><td>${esc(ORIGEM[v.origem] || v.origem)}</td><td>${esc(data(v.publicado_em))}</td><td class="small text-muted">${esc(v.observacao || '')}</td></tr>`).join('') || '<tr><td colspan="6">Sem versões.</td></tr>';
                versionsModal.show();
            } catch (error) { setMessage(error.message, 'danger'); }
        }
    });
    conflictsBox.addEventListener('click', async event => {
        const b = event.target.closest('[data-resolve]');
        const q = current();
        if (!b || !q) return;
        try { await call(api, {action: 'resolver_conflito', codversao: q.codversao, tipo: b.dataset.tipo, codigo: b.dataset.codigo, acao: b.dataset.resolve}); await loadCatalog(q.codquestionario); }
        catch (error) { setMessage(error.message, 'danger'); }
    });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (!editable()) return;
        const data = Object.fromEntries(new FormData(form));
        data.codversao = select.value;
        data.action = data.codpergunta ? 'editar_pergunta' : 'pergunta';
        if (['UNICA_ESCOLHA', 'MULTIPLA_ESCOLHA'].includes(data.tipo)) data.opcoes = JSON.stringify(readOptions());
        try { await call(api, data); modal.hide(); await loadQuestions(); setMessage('Pergunta salva.', 'success'); }
        catch (error) { formMessage(error.message, 'danger'); }
    });
    form.querySelector('[data-form-new]').addEventListener('click', () => { if (editable()) setQuestionForm(); });
    form.querySelector('[data-form-delete]').addEventListener('click', async () => {
        const id = form.elements.codpergunta.value;
        if (!id || !editable() || !(await confirmar('Excluir esta pergunta do rascunho?'))) return;
        try { await call(api, {action: 'excluir_pergunta', codpergunta: id}); modal.hide(); await loadQuestions(); setMessage('Pergunta excluída.', 'success'); }
        catch (error) { formMessage(error.message, 'danger'); }
    });
    loadCatalog();
}());
