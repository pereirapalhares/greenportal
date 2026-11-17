(function () {
    'use strict';
    // Textos no idioma do usuário (Administração › Textos e idiomas), com o português como padrão.
    function T(chave, padrao) { var v = typeof window.greenT === 'function' ? window.greenT('responsabilidades.' + chave, padrao) : padrao; return String(v).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); }
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || form.id !== 'responsibilityForm' || form.dataset.confirmed === '1') return;
        event.preventDefault();
        var modal = document.createElement('div');
        modal.className = 'modal fade show';
        modal.style.cssText = 'display:block;background:rgba(0,0,0,.45);z-index:2000;';
        modal.innerHTML = '<div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">' + T('confirmacao', 'Confirmação') + '</h5></div><div class="modal-body">' + T('confirmar_alteracoes', 'Confirma as alterações de responsabilidades?') + '</div><div class="modal-footer"><button type="button" class="btn btn-sm btn-outline-secondary" data-cancel>' + T('botao.cancelar', 'Cancelar') + '</button><button type="button" class="btn btn-sm btn-success" data-confirm>' + T('botao.confirmar', 'Confirmar') + '</button></div></div></div>';
        document.body.appendChild(modal);
        modal.querySelector('[data-cancel]').onclick = function () { modal.remove(); };
        modal.querySelector('[data-confirm]').onclick = function () { modal.remove(); form.dataset.confirmed = '1'; form.submit(); };
    }, true);
}());
