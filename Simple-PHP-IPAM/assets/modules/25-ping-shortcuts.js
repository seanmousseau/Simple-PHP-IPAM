// ─── C04 — Live Ping + alert-recipients clear-all (Phase 2a, #939) ───────────
// Two unrelated delegated-click handlers grouped because each is small:
// (1) the .ping-btn handler on addresses.php that POSTs to ping_host.php
// and renders up/down/latency in the corresponding .ping-result-<addrId>;
// (2) the #443 [data-clear-select] handler that deselects every option in
// a <select multiple> (no native HTML way to do this). Both are event
// delegates rooted at document, so they activate without needing the
// targets to exist at script-execute time. The orphan "#317 Cmd/Ctrl+N"
// section header that previously sat between them was dropped here — the
// actual Cmd/Ctrl+N implementation lives in C13 (command palette).
(function(){
  document.addEventListener("DOMContentLoaded", function() {
    // --- Live Ping buttons (addresses.php) ---
    document.addEventListener("click", function(e) {
      var btn = e.target.closest(".ping-btn");
      if (!btn || btn.disabled) return;

      var addrId = btn.dataset.addressId;
      var csrf   = btn.dataset.csrf;
      var result = document.querySelector(".ping-result-" + addrId);

      btn.disabled = true;
      btn.textContent = "…";
      if (result) { result.textContent = ""; }

      var body = new URLSearchParams({ csrf: csrf, address_id: addrId });
      fetch("ping_host.php", { method: "POST", body: body })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (result) {
            if (data.up) {
              result.textContent = "up — " + data.latency_ms + "ms";
              result.className   = result.className.replace(/\bmuted\b/, "success");
            } else if (data.error) {
              result.textContent = "error: " + data.error;
              result.className   = result.className.replace(/\bsuccess\b/, "muted");
            } else {
              result.textContent = "no response";
              result.className   = result.className.replace(/\bsuccess\b/, "muted");
            }
          }
        })
        .catch(function() {
          if (result) result.textContent = "request failed";
        })
        .finally(function() {
          btn.disabled = false;
          btn.textContent = "Ping";
        });
    });

    // --- #443: clear-all button for the alert recipients multi-select ---
    // <button data-clear-select="<select-id>"> deselects every option in the
    // referenced <select multiple>. Without this, HTML offers no built-in
    // way to deselect ALL options once at least one is selected (clicking an
    // unmodified option re-selects only that one), so the user has no way to
    // disable email alerts entirely from the UI.
    document.addEventListener("click", function(e) {
      var btn = e.target && e.target.closest ? e.target.closest("button[data-clear-select]") : null;
      if (!btn) return;
      e.preventDefault();
      var sel = document.getElementById(btn.getAttribute("data-clear-select"));
      if (!sel) return;
      Array.from(sel.options).forEach(function(o) { o.selected = false; });
      sel.dispatchEvent(new Event("change", { bubbles: true }));
    });
  });
}());
