(function ($) {
    'use strict';
    var $root = $('[data-account-iframe]');
    if (!$root.length) return;
    function T(chave, padrao) { return typeof window.greenT === 'function' ? window.greenT('portal.conta.js.' + chave, padrao) : padrao; }
    var parentWindow = window.parent && window.parent !== window ? window.parent : window;
    function message(text, type) { $('#accountMessage').removeClass('text-success text-danger').addClass(type === 'danger' ? 'text-danger' : 'text-success').text(text); }
    function post(data) {
        $.post('minha_conta.php', data, function (response) {
            if (response && response.ok) {
                if (response.tema) parentWindow.postMessage({type: 'greenportal-account-theme', theme: response.tema}, '*');
                if (response.transacao) parentWindow.greenPortalDefaultTransaction = response.transacao;
                message(response.message || T('concluida', 'Operação concluída.'), 'success');
            } else message((response || {}).message || T('erro', 'Não foi possível concluir a operação.'), 'danger');
        }, 'json').fail(function (xhr) { message((xhr.responseJSON || {}).message || T('erro', 'Não foi possível concluir a operação.'), 'danger'); });
    }
    $('#accountPasswordForm').on('submit', function (event) {
        event.preventDefault();
        var $form = $(this), password = $form.find('[name="nova_senha"]').val(), confirmation = $form.find('[name="confirmar_senha"]').val();
        if (password !== confirmation) return message(T('senhas_diferentes', 'As senhas novas não conferem.'), 'danger');
        post($form.serializeArray().concat([{name: 'acao', value: 'senha'}]));
        $form[0].reset();
    });
    $('[data-save-account-layout]').on('click', function () { post({acao: 'layout', layout_tema: $('[name="account_layout_tema"]:checked').val() || 'verde'}); });
}(jQuery));
