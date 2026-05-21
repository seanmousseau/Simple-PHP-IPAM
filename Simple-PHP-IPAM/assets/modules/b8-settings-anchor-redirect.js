/* ── Legacy fragment-only bookmark redirect (#749 follow-up) ──────────── */
// Pre-#749 a user could bookmark `settings.php#group-mfa`. After the tab
// rewrite the MFA form lives under ?tab=authentication, so a bare anchor
// lands on the General tab and silently shows the wrong content. This
// shim reads window.location.hash on page load and, if the hash points at
// a known group on a tab other than the current one, replaces the URL
// with the owning tab + the same anchor. Runs before the rail nav IIFE
// so the redirect happens before any user interaction.
(function () {
  var rail = document.querySelector('[data-settings-rail]');
  if (!rail) return;
  var pageName = (window.location.pathname.split('/').pop() || '');
  if (!/^settings\.php/.test(pageName)) return;
  var m = window.location.hash.match(/^#group-([a-z0-9_]+)$/);
  if (!m) return;
  var map;
  try {
    map = JSON.parse(rail.getAttribute('data-group-tab-map') || '{}');
  } catch (err) {
    return;
  }
  var owningTab = map[m[1]];
  if (!owningTab) return;
  var url = new URL(window.location.href);
  if (url.searchParams.get('tab') === owningTab) return;
  url.searchParams.set('tab', owningTab);
  window.location.replace(url.pathname + url.search + window.location.hash);
}());

