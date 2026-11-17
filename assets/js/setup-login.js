(function () {
    'use strict';
    var modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('loginPreviewModal'));
    document.querySelector('[data-preview-login]').addEventListener('click', function () {
        var field = function (id) { return document.getElementById(id).value; };
        document.getElementById('previewBrand').textContent = field('login_marca');
        document.getElementById('previewEyebrow').textContent = field('login_eyebrow');
        document.getElementById('previewTitle').textContent = field('login_titulo');
        document.getElementById('previewSubtitle').textContent = field('login_subtitulo');
        document.getElementById('previewButton').textContent = field('login_botao_entrar');
        document.getElementById('previewMarketingEyebrow').textContent = field('login_marketing_eyebrow');
        document.getElementById('previewMarketingTitle').textContent = field('login_marketing_titulo');
        document.getElementById('previewMarketingText').textContent = field('login_marketing_texto');
        var marketing = document.querySelector('.preview-login-marketing');
        var ensure = function (id, tag, className) {
            var node = document.getElementById(id);
            if (!node) { node = document.createElement(tag); node.id = id; if (className) node.className = className; marketing.appendChild(node); }
            return node;
        };
        ensure('previewMarketingTopNote', 'div', 'top-note').textContent = field('login_marketing_top_note');
        ensure('previewMarketingSecondary', 'p', 'marketing-text marketing-text-secondary').textContent = field('login_marketing_texto_secundario');
        ensure('previewMarketingFeature1', 'p', 'feature-item').textContent = '✓ ' + field('login_marketing_feature_1');
        ensure('previewMarketingFeature2', 'p', 'feature-item').textContent = '✓ ' + field('login_marketing_feature_2');
        ensure('previewMarketingFeature3', 'p', 'feature-item').textContent = '✓ ' + field('login_marketing_feature_3');
        ensure('previewMarketingFooterLeft', 'span', '').textContent = field('login_marketing_rodape_esquerdo');
        ensure('previewMarketingFooterRight', 'span', '').textContent = field('login_marketing_rodape_direito');
        modal.show();
    });

}());
