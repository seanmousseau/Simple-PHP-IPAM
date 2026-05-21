// ─── C09 — Fill-IP helper + import/restore spinners (Phase 2a, #939) ─────────
// Three small interaction helpers grouped because they each provide
// progress / fill-in affordances around a single click or submit:
// (1) data-fill-ip clicks copy a next-available IP into a sibling
// input[name=ip] inside a .card or .drawer-body; (2) the import-form
// submit overlays a generic spinner; (3) #1135 restore-apply spinner
// overlays a spinner AND escalates its message at 5 s and 20 s so the
// operator knows the page hasn't frozen during a long synchronous
// restore. All three are page-targeted (specific data-* / id), no
// shared state with any other concern.
(function(){
  document.addEventListener("DOMContentLoaded", function() {
    // --- "Use next IP" fill helper (data-fill-ip on addresses.php) ---
    // The link can appear inside the global drawer body (Add Address /
    // Reserve Infra IPs) or alongside a sibling card on the page.
    document.addEventListener("click", function(e) {
      var el = e.target.closest("[data-fill-ip]");
      if (!el) return;
      e.preventDefault();
      var card = el.closest(".card, .drawer-body");
      if (!card) return;
      var inp = card.querySelector('input[name="ip"]');
      if (inp) { inp.value = el.dataset.fillIp; inp.focus(); }
    });

    // --- Import spinner overlay (data-import-form) ---
    var importForm = document.querySelector("[data-import-form]");
    if (importForm) {
      importForm.addEventListener("submit", function() {
        var overlay = document.createElement("div");
        overlay.className = "spinner-overlay";
        overlay.innerHTML = '<div class="spinner"></div><div>Importing database, please wait\u2026</div>';
        document.body.appendChild(overlay);
      });
    }

    // --- Restore-apply spinner overlay (#1135) ---
    // ipam_restore_apply() is synchronous and can take 30+ seconds on a
    // large dump; pre-fix the page just hung after the user clicked Apply.
    // Mirror the import-form spinner pattern with an escalating message so
    // the operator knows the page hasn't frozen on a long-running restore.
    var restoreApplyForm = document.getElementById("restore-apply-form");
    if (restoreApplyForm) {
      restoreApplyForm.addEventListener("submit", function() {
        var overlay = document.createElement("div");
        overlay.className = "spinner-overlay";
        var spinner = document.createElement("div");
        spinner.className = "spinner";
        var msg = document.createElement("div");
        msg.id = "restore-apply-msg";
        msg.textContent = "Applying backup\u2026 please don\u2019t close this window.";
        overlay.appendChild(spinner);
        overlay.appendChild(msg);
        document.body.appendChild(overlay);
        setTimeout(function() {
          if (msg.parentNode) {
            msg.textContent = "Still working\u2026 large restores can take 30+ seconds.";
          }
        }, 5000);
        setTimeout(function() {
          if (msg.parentNode) {
            msg.textContent = "Almost there\u2026 finalizing rows. The page will redirect when complete.";
          }
        }, 20000);
      });
    }
  });
}());
