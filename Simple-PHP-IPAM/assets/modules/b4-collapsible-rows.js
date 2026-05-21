// Collapsible row toggle — sites admin and any future collapsible groups (v3.11.0 #632 #633)
(function () {
  function applyCollapsible(btn, childRows, expanded) {
    btn.setAttribute('aria-expanded', String(expanded));
    childRows.forEach(function (row) {
      row.classList.toggle('collapsible-child--hidden', !expanded);
    });
  }

  document.querySelectorAll('[data-collapsible-toggle]').forEach(function (btn) {
    var groupId = btn.getAttribute('data-collapsible-group-id');
    var storageKey = 'ipam-collapsible-' + groupId;
    var childRows = Array.prototype.slice.call(document.querySelectorAll('[data-collapsible-child="' + groupId + '"]'));
    var saved = sessionStorage.getItem(storageKey);
    var expanded = saved === null ? true : saved === 'true';
    applyCollapsible(btn, childRows, expanded);
    btn.addEventListener('click', function () {
      var isExpanded = btn.getAttribute('aria-expanded') === 'true';
      applyCollapsible(btn, childRows, !isExpanded);
      sessionStorage.setItem(storageKey, String(!isExpanded));
    });
  });

  var collapseAllBtn = document.querySelector('[data-collapsible-collapse-all]');
  var expandAllBtn   = document.querySelector('[data-collapsible-expand-all]');
  function allGroupsAction(expanded) {
    document.querySelectorAll('[data-collapsible-toggle]').forEach(function (btn) {
      var groupId = btn.getAttribute('data-collapsible-group-id');
      var childRows = Array.prototype.slice.call(document.querySelectorAll('[data-collapsible-child="' + groupId + '"]'));
      applyCollapsible(btn, childRows, expanded);
      sessionStorage.setItem('ipam-collapsible-' + groupId, String(expanded));
    });
  }
  if (collapseAllBtn) { collapseAllBtn.addEventListener('click', function () { allGroupsAction(false); }); }
  if (expandAllBtn)   { expandAllBtn.addEventListener('click',   function () { allGroupsAction(true); }); }
}());

