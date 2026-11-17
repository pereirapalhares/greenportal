(function ($) {
    'use strict';
    $('.ia-prompt-grid-fragment').each(function () {
        var $fragment = $(this), $table = $fragment.find('#iaPromptGrid');
        var host = window.parent && window.parent.GreenPortalPopups ? window.parent : window;
        var popup = host.GreenPortalPopups, csrf = String($fragment.data('csrf') || '');
        function selected() { return $table.find('tbody tr.selected').first(); }
        $table.on('click', 'tbody tr', function () { $table.find('tbody tr').removeClass('selected'); $(this).addClass('selected'); });
        $fragment.on('click', '[data-save-ia-prompt]', function () {
            var $row = selected();
            if (!$row.length) { if (popup) popup.mensagem({ titulo: 'Mensagem', mensagem: 'Selecione um prompt.', icone: 'bi-info-circle' }); return; }
            var send = function () {
                $.post(window.location.href, { action: 'save_prompt', csrf: csrf, codprompt: $row.data('key'), prompt_pt: $row.find('[data-lang=\"pt\"]').val(), prompt_en: $row.find('[data-lang=\"en\"]').val(), prompt_es: $row.find('[data-lang=\"es\"]').val() }, function (result) {
                    if (popup) popup.mensagem({ titulo: result.ok ? 'Sucesso' : 'Erro', mensagem: result.message || 'Operacao concluida.', icone: result.ok ? 'bi-check-circle' : 'bi-exclamation-triangle' });
                    if (result.ok) $row.find('[data-column=\"versao\"]').text(result.versao);
                }, 'json').fail(function (xhr) { if (popup) popup.mensagem({ titulo: 'Erro', mensagem: (xhr.responseJSON && xhr.responseJSON.message) || 'Nao foi possivel salvar o prompt.', icone: 'bi-exclamation-triangle' }); });
            };
            if (popup) popup.mensagem({ titulo: 'Confirmar alteracao', mensagem: 'Confirmar a alteracao do prompt selecionado? O conteudo anterior sera preservado no historico.', icone: 'bi-question-circle', confirmar: send }); else send();
        });
        $fragment.on('click', '[data-view-ia-history]', function () {
            var $row = selected();
            if (!$row.length) { if (popup) popup.mensagem({ titulo: 'Mensagem', mensagem: 'Selecione um prompt.', icone: 'bi-info-circle' }); return; }
            $.post(window.location.href, { action: 'history', csrf: csrf, codprompt: $row.data('key') }, function (result) {
                var html = '<div class=\"table-responsive\"><table class=\"table table-sm\"><thead><tr><th>Historico</th><th>Versao anterior</th><th>Nova versao</th><th>Usuario</th><th>Data</th></tr></thead><tbody>';
                (result.rows || []).forEach(function (item) { html += '<tr><td>' + item.codhistorico + '</td><td>' + item.versao_anterior + '</td><td>' + item.versao_nova + '</td><td>' + item.alterado_por + '</td><td>' + item.alterado_em + '</td></tr>'; });
                html += '</tbody></table></div>';
                if (popup) popup.transacao({ titulo: 'Historico do prompt', conteudo: $(html) });
            }, 'json');
        });
    });
}(jQuery));
