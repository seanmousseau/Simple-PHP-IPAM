// ─── C13c — Audit log expand (#564) (Phase 2a, #939) ─────────────────────────
// Click or Enter/Space on `.audit-details` toggles `--expanded` so a
// truncated row reveals its full payload. Delegated handlers; runs once.
(function(){
  document.addEventListener("DOMContentLoaded", function() {
    /* ---- Audit log: expand truncated details (#564) ---- */
    document.addEventListener("click", function(e) {
      var el = e.target.closest(".audit-details");
      if (!el) return;
      el.classList.toggle("audit-details--expanded");
    });
    document.addEventListener("keydown", function(e) {
      if (e.key !== "Enter" && e.key !== " ") return;
      var el = e.target.closest(".audit-details");
      if (!el) return;
      e.preventDefault();
      el.classList.toggle("audit-details--expanded");
    });
  });
}());
