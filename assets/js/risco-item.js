(function ($) {
    'use strict';
    // Migração 341: colunas Severidade × Probabilidade = Risco nos cadastros dos pilares (ManutencaoLegalCadastroGrid, 'risk_area').
    //  - mostra o risco calculado na hora em que a severidade ou a probabilidade muda;
    //  - marca as linhas alteradas (rows[chave][__alterada]) para o servidor exigir o risco de quem altera um item ainda sem risco;
    //  - confere antes da confirmação de gravação: linha nova, ou linha alterada sem risco, precisa dos dois campos;
    //  - migração 342: botão "Sugerir risco com IA" da barra, para a linha selecionada.
    function T(chave, padrao) { return typeof window.greenT === 'function' ? window.greenT(chave, padrao) : padrao; }
    var idioma = window.greenPortalIdioma || 'pt';
    var NOMES = {
        pt: { BAIXO: 'Baixo', MODERADO: 'Moderado', ALTO: 'Alto', CRITICO: 'Crítico', NAO_AVALIADO: 'Não avaliado' },
        en: { BAIXO: 'Low', MODERADO: 'Moderate', ALTO: 'High', CRITICO: 'Critical', NAO_AVALIADO: 'Not assessed' },
        es: { BAIXO: 'Bajo', MODERADO: 'Moderado', ALTO: 'Alto', CRITICO: 'Crítico', NAO_AVALIADO: 'No evaluado' }
    };
    var CORES = { BAIXO: 'risco-baixo', MODERADO: 'risco-moderado', ALTO: 'risco-alto', CRITICO: 'risco-critico', NAO_AVALIADO: 'risco-nao-avaliado' };

    function classe(sev, prob) {
        if (!sev || !prob) return 'NAO_AVALIADO';
        var p = sev * prob;
        if (sev === 4 || p >= 12) return 'CRITICO';
        if (p >= 8) return 'ALTO';
        if (p >= 4) return 'MODERADO';
        return 'BAIXO';
    }

    function estilo() {
        if (document.getElementById('risco-item-estilo')) return;
        var css = '.risco-celula{font-weight:600;border-radius:.25rem}' +
            '.risco-baixo{background:#d1e7dd!important;color:#0f5132!important}.risco-moderado{background:#fff3cd!important;color:#664d03!important}' +
            '.risco-alto{background:#ffe5d0!important;color:#8a3c00!important}.risco-critico{background:#f8d7da!important;color:#842029!important}' +
            '.risco-nao-avaliado{background:#e9ecef!important;color:#495057!important;font-style:italic}.grid-column-tooltip{cursor:help}';
        $('<style id="risco-item-estilo">').text(css).appendTo('head');
    }

    function atualizar($row) {
        var sev = Number($row.find('[data-column="RISCO_SEVERIDADE"] select').val() || 0);
        var prob = Number($row.find('[data-column="RISCO_PROBABILIDADE"] select').val() || 0);
        var c = classe(sev, prob), $campo = $row.find('[data-column="RISCO_CLASSE"] input').first();
        if (!$campo.length) return;
        var texto = (NOMES[idioma] || NOMES.pt)[c] + (sev && prob ? ' (' + (sev * prob) + ')' : '');
        $campo.val(texto).removeClass('risco-baixo risco-moderado risco-alto risco-critico risco-nao-avaliado').addClass('risco-celula ' + CORES[c]);
    }

    $('.cadastro-legal-fragment').each(function () {
        var $fragment = $(this), $form = $fragment.find('.menu-grid-form'), $table = $fragment.find('.menu-batch-grid');
        if (!$table.find('thead [data-risk-column]').length) return;
        estilo();
        $table.find('tbody tr[data-row]').each(function () { atualizar($(this)); });
        $table.on('change', '[data-column="RISCO_SEVERIDADE"] select, [data-column="RISCO_PROBABILIDADE"] select', function () { atualizar($(this).closest('tr')); });
        // Linha nova (botões Novo e Duplicar): o grid monta a linha depois do clique
        // Linha nova (botões Novo e Duplicar): o grid monta a linha depois do clique. As opções vêm de um objeto JavaScript, que põe
        // as chaves numéricas antes da vazia: sem ajuste, a linha nova viria com "1" escolhido sem o usuário perceber.
        $fragment.on('click', '[data-new-cadastro],[data-duplicate-cadastro]', function () {
            var duplicar = this.hasAttribute('data-duplicate-cadastro');
            window.setTimeout(function () {
                $table.find('tbody tr[data-key^="novo_"]').each(function () {
                    var $row = $(this);
                    if (!$row.data('risco-pronto')) {
                        $row.find('[data-column="RISCO_SEVERIDADE"] select, [data-column="RISCO_PROBABILIDADE"] select').each(function () {
                            var $s = $(this), valor = String($s.val() || ''), $vazia = $s.find('option[value=""]');
                            if (!$vazia.length) $vazia = $('<option value=""></option>');
                            $vazia.prependTo($s);
                            $s.val(duplicar ? valor : '');
                        });
                        $row.data('risco-pronto', true);
                    }
                    atualizar($row);
                });
            }, 0);
        });
        $table.on('change input', 'tbody tr[data-row]:not([data-key^="novo_"]) input, tbody tr[data-row]:not([data-key^="novo_"]) select, tbody tr[data-row]:not([data-key^="novo_"]) textarea', function () {
            var $row = $(this).closest('tr'), key = String($row.attr('data-key') || '');
            if (!key || $row.find('input[data-risco-alterada]').length) return;
            $('<input>', { type: 'hidden', name: 'rows[' + key + '][__alterada]', value: '1', 'data-risco-alterada': '1' }).appendTo($row.find('td').first());
        });

        function riscoOk() {
            var falta = null;
            $table.find('tbody tr[data-row]').each(function () {
                var $row = $(this), key = String($row.attr('data-key') || ''), nova = key.indexOf('novo_') === 0;
                var $sev = $row.find('[data-column="RISCO_SEVERIDADE"] select'), $prob = $row.find('[data-column="RISCO_PROBABILIDADE"] select');
                if (!$sev.length || !$prob.length || $sev.prop('disabled')) return;
                var sev = String($sev.val() || ''), prob = String($prob.val() || '');
                var semRiscoGravado = String($row.find('[data-column="RISCO_SEVERIDADE"] select option[selected]').val() || '') === '';
                var exigir = nova || sev !== '' || prob !== '' || ($row.find('input[data-risco-alterada]').length && semRiscoGravado);
                if (!exigir || (sev !== '' && prob !== '')) return;
                falta = sev === '' ? $sev : $prob;
                return false;
            });
            if (!falta) return true;
            $form.find('[data-ajax-message][data-risco]').remove();
            $('<div class="alert alert-danger py-2 mb-2" data-ajax-message data-risco></div>')
                .text(T('legal.risco.obrigatorio_tela', 'Informe a Severidade e a Probabilidade (1 a 4): o risco é obrigatório ao incluir e ao alterar um registro que ainda não tem risco.'))
                .insertBefore($form).delay(8000).fadeOut(300, function () { $(this).remove(); });
            falta.addClass('is-invalid').one('change', function () { $(this).removeClass('is-invalid'); }).trigger('focus');
            return false;
        }
        // Migração 342: "Sugerir risco com IA" para a linha selecionada (pedido em segundo plano; a resposta chega nos Alertas)
        var $ia = $fragment.find('[data-risco-ia-sugerir]');
        function linhaIa() { var $r = $table.find('tbody tr.selected').first(), k = String($r.attr('data-key') || ''); return /^\d+$/.test(k) ? k : ''; }
        $table.on('click', 'tbody tr', function () { window.setTimeout(function () { $ia.prop('disabled', !linhaIa()); }, 0); });
        $ia.on('click', function () {
            var key = linhaIa(); if (!key) return;
            var $b = $(this).prop('disabled', true);
            $form.find('[data-ajax-message][data-risco-ia]').remove();
            var aviso = function (texto, tipo) { $('<div class="alert py-2 mb-2" data-ajax-message data-risco-ia></div>').addClass('alert-' + tipo).text(texto).insertBefore($form).delay(9000).fadeOut(300, function () { $(this).remove(); }); };
            fetch('api/risco_sugestoes.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': String($b.attr('data-csrf') || '') },
                body: JSON.stringify({ acao: 'sugerir_item', area: String($b.attr('data-area') || ''), item: Number(key) }) })
                .then(function (r) { return r.json().catch(function () { return { ok: false, erro: 'Resposta inválida do servidor (' + r.status + ').' }; }); })
                .then(function (j) { if (!j || !j.ok) throw new Error((j && j.erro) || 'Não foi possível pedir a sugestão.'); aviso((j.resultado && j.resultado.mensagem) || 'Pedido enviado à IA.', 'success'); })
                .catch(function (e) { aviso(e.message, 'danger'); })
                .finally(function () { $b.prop('disabled', !linhaIa()); });
        });

        var anterior = $form.data('antes-de-gravar');
        $form.data('antes-de-gravar', function () {
            if (!riscoOk()) return false;
            return typeof anterior === 'function' ? anterior() : true;
        });
    });
}(jQuery));
