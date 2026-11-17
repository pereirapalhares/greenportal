(function () {
  'use strict';
  var permission = window.mlResponsibilityPermission || {};
  var canInclude = !!permission.include;
  var canEdit = !!permission.edit;
  var canDelete = !!permission.delete;
  var form = document.querySelector('#responsibilityForm');
  var table = document.querySelector('#responsibilityTable');
  if (!form || !table) return;

  function isNew(row) { return String(row.dataset.rowKey || '').indexOf('novo_') === 0; }
  function apply() {
    var add = document.querySelector('#addRow');
    var submit = form.querySelector('button[type="submit"]');
    if (add) add.disabled = !canInclude;
    if (submit) submit.disabled = !(canInclude || canEdit || canDelete);
    table.querySelectorAll('tbody tr').forEach(function (row) {
      var remove = row.querySelector('input[name*="[__delete]"]');
      var isNewRow = isNew(row);
      row.querySelectorAll('input, select, textarea').forEach(function (field) {
        if (field.type === 'hidden') return;
        field.disabled = !isNewRow && !canEdit;
      });
      var deleteButton = document.querySelector('#deleteRow');
      if (deleteButton && row.classList.contains('table-active')) {
        deleteButton.disabled = isNewRow ? !canInclude : !canDelete;
      }
    });
  }
  form.addEventListener('submit', function (event) {
    if (!(canInclude || canEdit || canDelete)) { event.preventDefault(); event.stopImmediatePropagation(); }
  }, true);
  table.addEventListener('click', function () { window.setTimeout(apply, 0); }, true);
  new MutationObserver(apply).observe(table.querySelector('tbody'), { childList: true, subtree: true });
  apply();
}());
