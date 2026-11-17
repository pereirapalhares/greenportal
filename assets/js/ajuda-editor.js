(function ($) {
    'use strict';
    $(function () {
        $('[data-help-editor]').each(function () {
            var area = this, $area = $(area), $toolbar = $area.prev('[data-help-toolbar]');
            $toolbar.on('click', '[data-help-command]', function () {
                var command = $(this).data('help-command'), start = area.selectionStart, end = area.selectionEnd, selected = $area.val().slice(start, end), replacement = selected;
                if (command === 'link') { var url = window.prompt('URL https://'); if (!url || !/^https?:\/\//i.test(url)) return; replacement = '<a href="' + url.replace(/"/g, '&quot;') + '" target="_blank" rel="noopener noreferrer">' + (selected || url) + '</a>'; }
                if (command === 'bold') replacement = '<strong>' + selected + '</strong>';
                if (command === 'italic') replacement = '<em>' + selected + '</em>';
                if (command === 'ul') replacement = '<ul><li>' + (selected || 'Item') + '</li></ul>';
                if (command === 'ol') replacement = '<ol><li>' + (selected || 'Item') + '</li></ol>';
                if (replacement !== selected) { area.setRangeText(replacement, start, end, 'select'); area.focus(); }
            });
        });
    });
}(jQuery));
