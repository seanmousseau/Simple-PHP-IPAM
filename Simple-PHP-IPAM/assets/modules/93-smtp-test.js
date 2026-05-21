// ─── C18b — SMTP test button on settings.php (Phase 2a, #939) ────────────────
// Single button that POSTs to smtp_test.php with the page CSRF; renders
// status (Sent / Failed / Request failed) in #smtp-test-result. Page-
// targeted; not the dashboard uPlot chart that's also numbered C18 in
// the original plan — that's a separate already-standalone IIFE.
(function(){
  // SMTP test button on settings.php
  var smtpTestBtn = document.getElementById("smtp-test-btn");
  if (smtpTestBtn) {
    smtpTestBtn.addEventListener("click", function() {
      var out = document.getElementById("smtp-test-result");
      var csrfInput = document.querySelector("input[name=csrf]");
      if (!out || !csrfInput) return;
      smtpTestBtn.disabled = true;
      out.textContent = "Sending\u2026";
      out.className = "muted";
      var fd = new FormData();
      fd.append("csrf", csrfInput.value);
      fetch("smtp_test.php", {method: "POST", body: fd})
        .then(function(r) { return r.json(); })
        .then(function(d) {
          out.textContent = d.message || (d.ok ? "Sent." : "Failed.");
          out.className = d.ok ? "success" : "danger";
        })
        .catch(function(e) { out.textContent = "Request failed: " + e; out.className = "danger"; })
        .finally(function() { smtpTestBtn.disabled = false; });
    });
  }
}());
