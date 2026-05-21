/* ── Settings rail keyboard nav + mobile select (#749, v3.16.0) ───────── */
(function () {
  // Mobile: changing the <select> navigates to ?tab=<value>. The form has
  // method=get action=settings.php so non-JS users get the same behaviour
  // by submitting normally; this just removes the extra click.
  var mobileSelect = document.querySelector('select[data-settings-mobile-nav]');
  if (mobileSelect) {
    mobileSelect.addEventListener('change', function () {
      var form = mobileSelect.closest('form');
      if (form) form.submit();
    });
  }

  // Desktop rail: ArrowUp/ArrowDown move focus between rail links,
  // Home/End jump to first/last, Enter activates the focused link.
  var rail = document.querySelector('[data-settings-rail]');
  if (!rail) return;
  var links = Array.prototype.slice.call(rail.querySelectorAll('.settings-rail__link'));
  if (links.length === 0) return;

  rail.addEventListener('keydown', function (e) {
    var idx = links.indexOf(document.activeElement);
    if (idx === -1) return;
    var next = null;
    if (e.key === 'ArrowDown') {
      next = links[(idx + 1) % links.length];
    } else if (e.key === 'ArrowUp') {
      next = links[(idx - 1 + links.length) % links.length];
    } else if (e.key === 'Home') {
      next = links[0];
    } else if (e.key === 'End') {
      next = links[links.length - 1];
    } else if (e.key === 'Enter') {
      // Native <a> activates on Enter already; do not preventDefault.
      return;
    } else {
      return;
    }
    e.preventDefault();
    if (next) next.focus();
  });
}());

