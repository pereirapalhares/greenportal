(function () {
    'use strict';
    function repair(fragment) {
        var raw = fragment.getAttribute('data-cadastro-config') || '{}', config;
        try { config = JSON.parse(raw); } catch (_) { return; }
        var primary = String(config.primary || ''), field = (config.fields || {})[primary] || {}, options = field.options || {}, template = fragment.ownerDocument.querySelector('.context-crud-primary-template[data-primary="' + primary + '"]');
        if (!primary || (!field.primary_editable && !config.primary_editable && !config.context_primary_select) || (!Object.keys(options).length && !template)) return;
        fragment.querySelectorAll('tbody tr[data-key^="novo_"]').forEach(function (row) {
            var cell = row.querySelector('[data-column="' + primary + '"]');
            if (!cell || cell.querySelector('select.grid-input')) return;
            var current = cell.querySelector('input,select'), value = current ? String(current.value || '') : '', key = String(row.getAttribute('data-key') || ''), select = document.createElement('select');
            select.className = 'grid-input'; select.name = 'rows[' + key + '][' + primary + ']';
            function preencherDadosSelecionados() {
                var records = field.option_records || {}, record = records[String(select.value || '')];
                if (!record) return;
                Object.keys(config.fields || {}).forEach(function (name) {
                    if (name === primary || !Object.prototype.hasOwnProperty.call(record, name)) return;
                    var target = row.querySelector('[data-column="' + name + '"] input, [data-column="' + name + '"] select, [data-column="' + name + '"] textarea');
                    if (!target) return;
                    var targetConfig = config.fields[name] || {}, displayField = String(targetConfig.display_from || name), value = record[displayField] !== undefined ? record[displayField] : record[name];
                    target.value = value == null ? '' : String(value);
                    if (record[name] !== undefined) target.setAttribute('data-raw-value', String(record[name] == null ? '' : record[name]));
                    target.dispatchEvent(new Event('change', { bubbles: true }));
                });
            }
            if (Object.keys(options).length) Object.keys(options).forEach(function (optionKey) { var label = options[optionKey]; if (label && typeof label === 'object') label = label.pt || label.en || label.es || optionKey; var option = document.createElement('option'); option.value = optionKey; option.textContent = String(label); option.selected = String(optionKey) === value; select.appendChild(option); });
            else Array.from(template.options).forEach(function (source) { var option = source.cloneNode(true); option.selected = String(option.value) === value; select.appendChild(option); });
            cell.innerHTML = ''; cell.appendChild(select); select.addEventListener('change', preencherDadosSelecionados); select.dispatchEvent(new Event('change', { bubbles: true })); preencherDadosSelecionados();
        });
    }
    function attach(fragment) {
        repair(fragment);
        var observer = new MutationObserver(function () { repair(fragment); });
        observer.observe(fragment.querySelector('tbody') || fragment, { childList: true, subtree: true });
        fragment.addEventListener('click', function (event) { if (event.target.closest('[data-new-cadastro],[data-duplicate-cadastro]')) window.setTimeout(function () { repair(fragment); }, 25); });
        fragment.addEventListener('gridPageLoaded', function () { repair(fragment); });
    }
    function start() { document.querySelectorAll('.cadastro-legal-fragment').forEach(function (fragment) { if (!fragment.hasAttribute('data-context-crud-primary-ready')) { fragment.setAttribute('data-context-crud-primary-ready', '1'); attach(fragment); } }); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
    window.addEventListener('load', start);
}());
