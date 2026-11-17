(function ($) {
    'use strict';
    $('.cadastro-legal-fragment').each(function () {
        var $fragment = $(this), $table = $fragment.find('.menu-batch-grid'), timer, pickerPage = 1, pickerTotalPages = 1;
        if (!$table.length) return;
        function hideTechnicalColumns() { $table.find('[data-column]:not([data-column="PERGUNTA"])').hide(); }
        function questionPicker($row) {
            if (!$row.length || String($row.data('key') || '').indexOf('novo_') !== 0) return;
            var $cell = $row.find('[data-column="PERGUNTA"]'); if (!$cell.length || $cell.find('.checklist-question-picker').length) return;
            var $stored = $row.find('[data-column="CODPERGUNTA"] input,[data-column="CODPERGUNTA"] select').first();
            var $old = $cell.find('input,textarea,select').first(), $wrap = $('<div class="checklist-question-picker"></div>'), $input = $('<input>', { type: 'search', class: 'form-control form-control-sm', placeholder: 'Digite código ou pergunta', autocomplete: 'off' }), $results = $('<div class="checklist-question-results" hidden></div>');
            $wrap.append($input, $results); $cell.empty().append($wrap);
            function search(page) {
                var term = String($input.val() || '').trim(), filters = $fragment.data('context-filters') || {}, law = String(filters.CODLEGISLACAO || '');
                if (term.length < 2 || !law) { $results.empty().prop('hidden', true); return; }
                pickerPage = Math.max(1, page || 1);
                $.post(window.location.href, { action: 'search_checklist_questions', CODLEGISLACAO: law, busca: term, pagina: pickerPage, tamanho: 25 }, function (response) {
                    $results.empty(); (response.items || []).forEach(function (item) { $('<button>', { type: 'button', text: item.CODPERGUNTA + ' - ' + item.DSCPERGUNTA }).data('question', item).appendTo($results); });
                    pickerTotalPages = Number(response.paginas || 1);
                    if (response.total > 25) { var $pager = $('<div class="checklist-question-pager"></div>'); $('<button type="button" class="btn btn-sm btn-light" title="Perguntas anteriores">‹</button>').prop('disabled', pickerPage <= 1).on('click', function () { search(pickerPage - 1); }).appendTo($pager); $('<span></span>').text('Página ' + pickerPage + ' de ' + pickerTotalPages + ' (' + response.total + ')').appendTo($pager); $('<button type="button" class="btn btn-sm btn-light" title="Próximas perguntas">›</button>').prop('disabled', pickerPage >= pickerTotalPages).on('click', function () { search(pickerPage + 1); }).appendTo($pager); $results.append($pager); }
                    $results.prop('hidden', !(response.items || []).length);
                }, 'json');
            }
            $input.on('input', function () { window.clearTimeout(timer); timer = window.setTimeout(function () { search(1); }, 250); });
            $results.on('click', 'button', function () { var item = $(this).data('question'); $stored.val(String(item.CODPERGUNTA)).trigger('change'); $input.val(item.DSCPERGUNTA); $results.prop('hidden', true); });
            $input.on('blur', function () { window.setTimeout(function () { $results.prop('hidden', true); }, 200); });
        }
        function enforce() { hideTechnicalColumns(); $table.find('tbody tr').each(function () { var $row = $(this), isNew = String($row.data('key') || '').indexOf('novo_') === 0; $row.find('[data-column="CODPERGUNTA"] input,[data-column="CODPERGUNTA"] select').prop('disabled', !isNew).prop('readonly', !isNew); if (isNew) questionPicker($row); }); }
        $fragment.on('click', '[data-new-cadastro]', function () { window.setTimeout(enforce, 30); });
        $table.on('click', 'tbody tr', function () { enforce(); });
        enforce();
        new MutationObserver(enforce).observe($table[0], { childList: true, subtree: true });
    });
}(jQuery));
