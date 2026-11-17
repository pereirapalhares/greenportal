/* Troca de idioma pelo cabeçalho: grava na sessão (api/idioma.php) e recarrega o portal já no novo idioma. */
(function () {
    'use strict';
    var menu = document.querySelector('.header-language-menu');
    if (!menu) return;
    menu.addEventListener('click', function (event) {
        var item = event.target.closest('[data-trocar-idioma]');
        if (!item) return;
        var idioma = item.getAttribute('data-trocar-idioma');
        if (item.classList.contains('active')) return;
        Array.prototype.forEach.call(menu.querySelectorAll('[data-trocar-idioma]'), function (b) { b.disabled = true; });
        var corpo = new URLSearchParams();
        corpo.set('idioma', idioma);
        corpo.set('csrf', menu.getAttribute('data-idioma-csrf') || '');
        fetch(menu.getAttribute('data-idioma-api'), { method: 'POST', body: corpo, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
            .then(function (resposta) {
                if (resposta && resposta.ok) { window.location.reload(); return; }
                Array.prototype.forEach.call(menu.querySelectorAll('[data-trocar-idioma]'), function (b) { b.disabled = false; });
                if (resposta && resposta.message) item.setAttribute('title', resposta.message);
            })
            .catch(function () { Array.prototype.forEach.call(menu.querySelectorAll('[data-trocar-idioma]'), function (b) { b.disabled = false; }); });
    });
}());
