(function () {
    'use strict';
    var root = document.querySelector('[data-questionnaire-execution]');
    if (!root) return;
    var api = 'api/questionario_execucao.php', csrf = root.dataset.csrf, context = root.dataset.context || '';
    var select = document.querySelector('[data-questionnaire-select]'), card = document.querySelector('[data-form-card]'), questionsBox = document.querySelector('[data-questions]'), message = document.querySelector('[data-message]');
    var current = 0;
    // Textos no idioma do usuário (Administração › Textos e idiomas), com o português como padrão.
    var T = function (chave, padrao) { return typeof window.greenT === 'function' ? window.greenT('questionario_execucao.js.' + chave, padrao) : padrao; };
    var idioma = window.greenPortalIdioma || 'pt';
    var esc = function (value) { return String(value == null ? '' : value).replace(/[&<>'"]/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' })[c]; }); };
    function show(text, kind) { message.textContent = text; message.className = 'alert alert-' + (kind || 'info') + ' mt-2 py-2'; }
    async function call(url, body) {
        var response = await fetch(url, { method: body ? 'POST' : 'GET', headers: body ? { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' } : undefined, body: body ? new URLSearchParams(Object.assign({}, body, { csrf: csrf, contexto: context })) : undefined });
        var data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.message || T('erro', 'Não foi possível concluir.'));
        return data;
    }
    function question(item) {
        var name = 'q_' + item.codpergunta, input = '';
        if (['UNICA_ESCOLHA', 'SIM_NAO'].indexOf(item.tipo) >= 0) {
            input = '<select class="form-select" name="' + name + '" data-answer data-code="' + esc(item.codigo) + '"><option value="">' + esc(T('selecione', 'Selecione...')) + '</option>' + item.opcoes.map(function (option) { return '<option value="' + esc(option.codigo) + '">' + esc(option.rotulo) + '</option>'; }).join('') + '</select>';
        } else if (item.tipo === 'MULTIPLA_ESCOLHA') {
            input = '<div>' + item.opcoes.map(function (option) { return '<label class="d-block"><input type="checkbox" name="' + name + '[]" value="' + esc(option.codigo) + '" data-answer data-code="' + esc(item.codigo) + '"> ' + esc(option.rotulo) + '</label>'; }).join('') + '</div>';
        } else if (item.tipo === 'ANEXO') {
            input = '<div class="form-text">' + esc(T('anexos', 'Anexos serão associados na etapa de evidências.')) + '</div>';
        } else {
            var type = item.tipo === 'NUMERO' ? 'number' : item.tipo === 'DATA' ? 'date' : 'text';
            input = '<input class="form-control" type="' + type + '" name="' + name + '" data-answer data-code="' + esc(item.codigo) + '">';
        }
        return '<div class="border rounded p-2 mb-2"><label class="form-label">' + esc(item.texto) + (item.obrigatoria ? ' *' : '') + '</label>' + input + '</div>';
    }
    async function load() {
        try {
            var data = await call(api + '?action=catalog&contexto=' + encodeURIComponent(context));
            select.innerHTML = data.rows.map(function (row) { return '<option value="' + esc(row.codigo) + '">' + esc(row['nome_' + idioma] || row.nome_pt) + ' — v' + esc(row.versao) + '</option>'; }).join('') || '<option value="">' + esc(T('nenhum', 'Nenhum questionário publicado para este contexto')) + '</option>';
        } catch (error) { select.innerHTML = '<option value="">' + esc(T('erro_carregar', 'Erro ao carregar')) + '</option>'; show(error.message, 'danger'); }
    }
    document.querySelector('[data-start]').addEventListener('click', async function () {
        if (!select.value) { show(T('selecione_questionario', 'Selecione um questionário publicado.'), 'warning'); return; }
        try {
            var data = await call(api, { action: 'start', codigo: select.value, codunidade: root.dataset.unit, origem_entidade: root.dataset.originEntity, origem_id: root.dataset.originId, workflow_code: root.dataset.workflow, chave_idempotencia: 'QUESTIONNAIRE_UI:' + (context || 'SUPPLIER') + ':' + select.value + ':' + root.dataset.originEntity + ':' + root.dataset.originId + ':' + root.dataset.unit });
            current = Number(data.execution.codexecucao); document.querySelector('[data-form-title]').textContent = data.execution.codigo_questionario + ' — ' + T('execucao', 'execução') + ' #' + current; questionsBox.innerHTML = data.questions.map(question).join(''); card.classList.remove('d-none'); show(data.execution.status === 'CONCLUIDA' ? T('ja_concluida', 'Esta execução já foi concluída.') : T('iniciada', 'Execução iniciada.'), 'success'); if (data.execution.status !== 'ABERTA') document.querySelector('[data-submit]').disabled = true;
        } catch (error) { show(error.message, 'danger'); }
    });
    document.querySelector('[data-answer-form]').addEventListener('submit', async function (event) {
        event.preventDefault(); var answers = {};
        questionsBox.querySelectorAll('[data-answer]').forEach(function (input) { var key = input.dataset.code; if (input.type === 'checkbox') { if (input.checked) { answers[key] = answers[key] || []; answers[key].push(input.value); } } else answers[key] = input.value; });
        try { var data = await call(api, { action: 'submit', codexecucao: current, answers: JSON.stringify(answers) }); show(T('concluido', 'Questionário concluído. Percentual: {percentual}%.').replace('{percentual}', data.result.percentual), 'success'); document.querySelector('[data-submit]').disabled = true; } catch (error) { show(error.message, 'danger'); }
    });
    load();
}());
