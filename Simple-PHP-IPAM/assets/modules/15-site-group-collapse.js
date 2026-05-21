// ─── C03 — Site-group collapse/expand (Phase 2a, #939) ───────────────────────
// Sidebar sub-grouping toggle (one button per group). Open/closed state
// persists per-group in localStorage under "ipam_sg_<key>" so the user's
// nav layout survives across pages. Independent of every other concern.
(function(){
  document.addEventListener("DOMContentLoaded", function() {
    document.querySelectorAll(".site-group-toggle").forEach(function(btn) {
      var sgKey = btn.dataset.sgKey;
      if (sgKey && localStorage.getItem("ipam_sg_" + sgKey) === "closed") {
        btn.setAttribute("aria-expanded", "false");
      }
      btn.addEventListener("click", function() {
        var expanded = btn.getAttribute("aria-expanded") === "true";
        btn.setAttribute("aria-expanded", expanded ? "false" : "true");
        if (sgKey) localStorage.setItem("ipam_sg_" + sgKey, expanded ? "closed" : "open");
      });
    });
  });
}());
