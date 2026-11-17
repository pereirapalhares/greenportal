(function (window, document) {
    'use strict';
    var editor = document.getElementById('editor'), form = document.getElementById('htmlEdicaoForm');
    if (!editor || !form) return;
    document.querySelectorAll('[data-cmd]').forEach(function (control) {
        control.addEventListener('change', function () { editor.focus(); document.execCommand(this.getAttribute('data-cmd'), false, this.value); });
        if (control.tagName === 'BUTTON') control.addEventListener('click', function () { editor.focus(); document.execCommand(this.getAttribute('data-cmd'), false, null); });
    });
    document.querySelectorAll('[data-insert]').forEach(function (button) {
        button.addEventListener('click', function () {
            var type = this.getAttribute('data-insert'), value = window.prompt(type === 'image' ? 'URL HTTPS da imagem:' : 'URL HTTPS do link:');
            if (!value || !/^https:\/\//i.test(value)) return;
            editor.focus(); document.execCommand(type === 'image' ? 'insertImage' : 'createLink', false, value);
        });
    });
    form.addEventListener('submit', function () { document.getElementById('texto').value = editor.innerHTML; });
}(window, document));
