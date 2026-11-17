/* Migração 348 — página do funcionário (integracao.php): progresso das respostas e aviso das obrigatórias antes de enviar */
(function () {
    'use strict';
    var form = document.querySelector('[data-ip-form]'); if (!form) return;
    var perguntas = Array.prototype.slice.call(form.querySelectorAll('[data-ip-pergunta]'));
    var prog = form.querySelector('[data-ip-progresso]'), falta = form.querySelector('[data-ip-falta]');
    function respondida(f) {
        var ins = f.querySelectorAll('input,textarea,select');
        for (var i = 0; i < ins.length; i++) { var x = ins[i]; if ((x.type === 'radio' || x.type === 'checkbox') ? x.checked : String(x.value || '').trim() !== '') return true; }
        return !ins.length;
    }
    function atualizar() {
        var total = +(prog && prog.getAttribute('data-total')) || 0, ok = perguntas.filter(function (f) { return f.querySelector('input,textarea,select') && respondida(f); }).length;
        if (prog) prog.textContent = ok + ' / ' + total;
        perguntas.forEach(function (f) { if (respondida(f)) f.classList.remove('falta'); });
    }
    form.addEventListener('change', atualizar); form.addEventListener('input', atualizar);
    form.addEventListener('submit', function (ev) {
        var faltando = perguntas.filter(function (f) { return f.hasAttribute('data-obrigatoria') && !respondida(f); });
        faltando.forEach(function (f) { f.classList.add('falta'); });
        if (faltando.length) { ev.preventDefault(); if (falta) falta.hidden = false; faltando[0].scrollIntoView({ behavior: 'smooth', block: 'center' }); return; }
        var b = form.querySelector('button[type=submit]'); if (b) b.disabled = true;
    });
    atualizar();
}());
