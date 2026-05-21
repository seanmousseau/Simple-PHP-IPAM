// ─── C01 — Bootstrap (Phase 2a, #939) ────────────────────────────────────────
// Synchronous init that must run BEFORE DOMContentLoaded — applies the saved
// theme to <html data-theme=...> before first paint to avoid flash-of-unstyled.
// Has no DOM-event dependencies, so it lives outside any DOMContentLoaded
// handler. The "ipam_theme" localStorage key is the contract between this
// concern and C02 (theme/banners) — kept as a string literal at every site
// rather than a shared variable, so the concerns are independently extractable
// in Phase 2b.
(function(){
  // Remove skeleton loader if present (CSP-safe, no inline script)
  var skel = document.getElementById("skeleton-shell");
  if (skel) skel.remove();

  // Seed localStorage from server-side theme meta tag (CSP-safe, replaces inline script)
  var metaTheme = document.querySelector("meta[name='ipam-server-theme']");
  if (metaTheme) {
    var serverTheme = metaTheme.getAttribute("content");
    if (serverTheme === "light" || serverTheme === "dark") {
      localStorage.setItem("ipam_theme", serverTheme);
    }
  }
  var saved = localStorage.getItem("ipam_theme");
  if (saved === "light" || saved === "dark") {
    document.documentElement.setAttribute("data-theme", saved);
  }
}());
