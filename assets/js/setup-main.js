(function () {
    'use strict';
    var button = document.querySelector('[data-preview-main]');
    if (!button) return;
    var modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('mainPreviewModal'));
    button.addEventListener('click', function () {
        var value = function (id) { return document.getElementById(id).value; };
        document.getElementById('previewMainBrand').textContent = value('main_marca_texto');
        document.getElementById('previewMainHeader').textContent = value('main_cabecalho_texto');
        document.getElementById('previewMainFooter').textContent = value('main_rodape_texto');
        modal.show();
    });
}());
