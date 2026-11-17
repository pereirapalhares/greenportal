/* Migração 347 — Homologação › Critérios › Modelo do documento (principal/homologacaofornecedores_modelo_documento.php).
 * Itens editáveis, gravação, envio do arquivo de exemplo, leitura (OCR) e sugestão de itens pela IA (fila de IA, consulta a cada 3 s). */
(function () {
    'use strict';
    var C = window.greenDocModelo || {}, T = C.textos || {};
    var itens = (C.itens || []).map(function (i) { return { texto: i.texto, obrigatorio: !!i.obrigatorio }; });
    var q = function (s) { return document.querySelector(s); };
    var msgBox = q('[data-dmo-msg]');
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
    var fmt = function (s, v) { return String(s || '').replace(/\{(\w+)\}/g, function (_, k) { return v && v[k] != null ? v[k] : ''; }); };
    function msg(texto, tipo) { if (!msgBox) return; msgBox.textContent = texto || ''; msgBox.className = 'dmo-msg small' + (tipo ? ' ' + tipo : ''); }
    function post(dados, arquivo) {
        var fd = new FormData();
        fd.append('csrf', C.csrf); fd.append('escopo', C.escopo); fd.append('criterio', C.criterio);
        Object.keys(dados).forEach(function (k) { fd.append(k, dados[k]); });
        if (arquivo) fd.append('arquivo', arquivo);
        return fetch(window.location.pathname + '?escopo=' + encodeURIComponent(C.escopo) + '&criterio=' + encodeURIComponent(C.criterio), { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { ok: false, message: T.erro }; }); });
    }
    // ---------------------------------------------------------------- itens
    var lista = q('[data-dmo-itens]');
    function desenhar() {
        if (!lista) return;
        if (!itens.length) { lista.innerHTML = '<p class="dmo-nota mb-0">' + esc(T.sem_itens) + '</p>'; return; }
        lista.innerHTML = itens.map(function (it, i) {
            return '<div class="dmo-item" data-i="' + i + '"><span class="dmo-n">' + (i + 1) + '.</span>'
                + '<input type="text" class="form-control form-control-sm" maxlength="300" data-campo="texto" value="' + esc(it.texto) + '" placeholder="' + esc(T.item_ph) + '"' + (C.podeEditar ? '' : ' disabled') + '>'
                + '<div class="form-check"><input class="form-check-input" type="checkbox" id="dmoOb' + i + '" data-campo="obrigatorio"' + (it.obrigatorio ? ' checked' : '') + (C.podeEditar ? '' : ' disabled') + '><label class="form-check-label small" for="dmoOb' + i + '">' + esc(T.obrigatorio) + '</label></div>'
                + (C.podeEditar ? '<button type="button" class="btn btn-sm btn-outline-danger" data-remover title="' + esc(T.remover) + '" aria-label="' + esc(T.remover) + '"><i class="bi bi-trash"></i></button>' : '')
                + '</div>';
        }).join('');
    }
    if (lista) {
        lista.addEventListener('input', function (e) { var row = e.target.closest('[data-i]'); if (!row) return; var it = itens[+row.getAttribute('data-i')]; if (e.target.getAttribute('data-campo') === 'texto') it.texto = e.target.value; });
        lista.addEventListener('change', function (e) { var row = e.target.closest('[data-i]'); if (!row) return; if (e.target.getAttribute('data-campo') === 'obrigatorio') itens[+row.getAttribute('data-i')].obrigatorio = e.target.checked; });
        lista.addEventListener('click', function (e) { var b = e.target.closest('[data-remover]'); if (!b) return; itens.splice(+b.closest('[data-i]').getAttribute('data-i'), 1); desenhar(); });
    }
    var bItem = q('[data-dmo-item]');
    if (bItem) bItem.addEventListener('click', function () { itens.push({ texto: '', obrigatorio: true }); desenhar(); var ins = lista.querySelectorAll('input[type=text]'); if (ins.length) ins[ins.length - 1].focus(); });
    desenhar();
    // ---------------------------------------------------------------- gravar
    function campo(nome) { var el = q('[data-dmo="' + nome + '"]'); if (!el) return ''; return el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value; }
    function salvar() {
        var dados = { action: 'salvar', itens: JSON.stringify(itens.filter(function (i) { return String(i.texto || '').trim() !== ''; })) };
        ['ativo', 'automatico', 'existentes', 'cadastro', 'assinatura', 'validade', 'emissao', 'validade_min', 'orientacao'].forEach(function (n) { dados[n] = campo(n); });
        msg(T.gravando);
        return post(dados).then(function (r) { msg(r.message || (r.ok ? '' : T.erro), r.ok ? 'ok' : 'erro'); if (r.ok && r.codmodelo) C.codmodelo = r.codmodelo; return r; });
    }
    var bSalvar = q('[data-dmo-salvar]');
    if (bSalvar) bSalvar.addEventListener('click', function () { bSalvar.disabled = true; salvar().finally(function () { bSalvar.disabled = !C.podeEditar; }); });
    // ---------------------------------------------------------------- exemplo e leitura
    var inArq = q('[data-dmo-arquivo]'), bEnviar = q('[data-dmo-enviar]'), lLeitura = q('[data-dmo-leitura]'), aVer = q('[data-dmo-ver]'), bSugerir = q('[data-dmo-sugerir]'), boxSug = q('[data-dmo-sugestao]');
    var timerLeitura = null, timerSug = null;
    function estado() {
        if (!C.codmodelo || !lLeitura) return;
        post({ action: 'estado' }).then(function (r) {
            if (!r.ok) return;
            if (aVer) { aVer.hidden = !r.arquivo; aVer.textContent = r.arquivo || ''; }
            var t = !r.arquivo ? T.sem_exemplo : (r.leitura === 'LIDO' ? fmt(T.lido, { n: r.caracteres }) : (r.leitura === 'LENDO' ? T.lendo : (r.leitura === 'ERRO' ? fmt(T.erro_leitura, { e: r.erro }) : '')));
            lLeitura.textContent = t;
            if (bSugerir) bSugerir.disabled = !(C.podeEditar && r.leitura === 'LIDO');
            clearTimeout(timerLeitura);
            if (r.leitura === 'LENDO') timerLeitura = setTimeout(estado, 5000);
        });
    }
    if (bEnviar && inArq) {
        bEnviar.addEventListener('click', function () { inArq.click(); });
        inArq.addEventListener('change', function () {
            var f = inArq.files && inArq.files[0]; if (!f) return;
            var depois = function () { msg(T.enviando); post({ action: 'enviar_exemplo' }, f).then(function (r) { msg(r.message || T.erro, r.ok ? 'ok' : 'erro'); inArq.value = ''; if (r.ok) estado(); }); };
            if (!C.codmodelo) salvar().then(function (r) { if (r.ok) depois(); }); else depois();
        });
    }
    // ---------------------------------------------------------------- sugestão da IA
    function mostrarSugestao(s) {
        if (!boxSug) return;
        var regras = [];
        if (s.exige_validade) regras.push(T.sug_validade);
        if (s.exige_assinatura) regras.push(T.sug_assinatura);
        if (s.emissao_max_dias) regras.push(fmt(T.sug_emissao, { n: s.emissao_max_dias }));
        boxSug.innerHTML = '<div class="dmo-sug"><div class="fw-semibold small mb-1">' + esc(T.sugestao_titulo) + '</div>'
            + (s.itens || []).map(function (it, i) { return '<div class="form-check"><input class="form-check-input" type="checkbox" id="dmoSg' + i + '" data-sg="' + i + '" checked><label class="form-check-label small" for="dmoSg' + i + '">' + esc(it.texto) + (it.obrigatorio ? ' <span class="badge text-bg-light border">' + esc(T.obrigatorio) + '</span>' : '') + '</label></div>'; }).join('')
            + (s.orientacao ? '<div class="small text-muted mt-1">' + esc(s.orientacao) + '</div>' : '')
            + (regras.length ? '<div class="small text-muted mt-1">' + esc(fmt(T.sug_regras, { r: regras.join(', ') })) + '</div>' : '')
            + '<div class="d-flex gap-2 mt-2"><button type="button" class="btn btn-sm btn-success" data-sg-usar="mais">' + esc(T.usar) + '</button><button type="button" class="btn btn-sm btn-outline-secondary" data-sg-usar="trocar">' + esc(T.substituir) + '</button></div></div>';
        boxSug.querySelectorAll('[data-sg-usar]').forEach(function (b) {
            b.addEventListener('click', function () {
                var marcados = (s.itens || []).filter(function (_, i) { var c = boxSug.querySelector('[data-sg="' + i + '"]'); return c && c.checked; });
                if (b.getAttribute('data-sg-usar') === 'trocar') itens = [];
                marcados.forEach(function (it) { if (!itens.some(function (x) { return String(x.texto).trim().toLowerCase() === String(it.texto).trim().toLowerCase(); })) itens.push({ texto: it.texto, obrigatorio: !!it.obrigatorio }); });
                var ori = q('[data-dmo="orientacao"]'); if (ori && !ori.value.trim() && s.orientacao) ori.value = s.orientacao;
                if (s.exige_validade) { var v = q('[data-dmo="validade"]'); if (v) v.checked = true; }
                if (s.exige_assinatura) { var a = q('[data-dmo="assinatura"]'); if (a) a.checked = true; }
                if (s.emissao_max_dias) { var em = q('[data-dmo="emissao"]'); if (em && !em.value) em.value = s.emissao_max_dias; }
                desenhar(); boxSug.innerHTML = ''; msg(T.aplicado, 'ok');
            });
        });
    }
    function acompanhar(job, tentativas) {
        clearTimeout(timerSug);
        post({ action: 'sugestao', job: job }).then(function (r) {
            if (!r.ok) { msg(r.message || T.erro, 'erro'); if (bSugerir) bSugerir.disabled = false; return; }
            if (!r.pronto) { if (tentativas > 0) timerSug = setTimeout(function () { acompanhar(job, tentativas - 1); }, 3000); return; }
            msg(''); if (bSugerir) bSugerir.disabled = false; mostrarSugestao(r.sugestao || {});
        });
    }
    if (bSugerir) bSugerir.addEventListener('click', function () {
        bSugerir.disabled = true; msg(T.pensando);
        post({ action: 'sugerir' }).then(function (r) { if (!r.ok) { msg(r.message || T.erro, 'erro'); bSugerir.disabled = false; return; } acompanhar(r.job, 200); });
    });
    // ---------------------------------------------------------------- abrir o parecer (Gestão de Arquivos) no popup do portal
    document.addEventListener('click', function (e) {
        var a = e.target.closest('[data-dmo-abrir]'); if (!a) return;
        var host = window.top || window;
        if (!host.GreenPortalPopups || !host.jQuery) return;
        e.preventDefault();
        var url = new URL(a.getAttribute('href'), window.location.href).href;
        host.GreenPortalPopups.transacaoIndependente({ titulo: a.textContent, conteudo: host.jQuery('<iframe>', { src: url, style: 'width:100%;height:100%;border:0;display:block;' }), amplo: true, redimensionavel: true });
    });
    estado();
}());
