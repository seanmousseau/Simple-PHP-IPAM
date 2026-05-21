// ─── C06 — Forms-confirm-bulk (Phase 2a, #939) ───────────────────────────────
// data-confirm dialogs on forms + buttons/inputs + non-form links;
// data-submit-on-change auto-submit; data-stop-propagation guard;
// loading-state class on POST submitters (#507); data-select-addrs
// bulk-select helper; SSO-only toggle on users.php; reCAPTCHA v3
// pre-submit token fetch. Many small CSP-safe replacements for the
// inline-onclick / nested-checkbox patterns retired in v3.27.7.
(function(){
  document.addEventListener("DOMContentLoaded", function() {
    // --- Confirm dialogs on forms (data-confirm on <form>) ---
    document.addEventListener("submit", function(e) {
      var form = e.target;
      var msg = form.dataset.confirm;
      if (msg && !confirm(msg)) {
        e.preventDefault();
      }
    });

    // --- Confirm dialogs on buttons/inputs (data-confirm on submitter) ---
    // v3.27.7: replaces inline `onclick="return confirm(...)"` attributes
    // that the strict CSP (script-src 'self' without script-src-attr) blocked.
    document.addEventListener("click", function(e) {
      var t = e.target.closest("[data-confirm-click]");
      if (!t) return;
      if (!confirm(t.dataset.confirmClick)) {
        e.preventDefault();
        e.stopPropagation();
      }
    });

    // --- Auto-submit a form when a select changes (data-submit-on-change) ---
    // v3.27.7: replaces inline `onchange="this.form.submit()"` on the Restore
    // tab destination picker (and any future similar control). CSP3 blocks
    // inline event handlers under our script-src 'self' policy.
    document.querySelectorAll("[data-submit-on-change]").forEach(function(el) {
      el.addEventListener("change", function() {
        if (el.form) el.form.submit();
      });
    });

    // --- Click-event stop-propagation (data-stop-propagation) ---
    // v3.27.7: replaces inline `onclick="event.stopPropagation()"`. Used on
    // the backup history row's actions cell so that clicking inside the cell
    // does not trigger the row-level click handler.
    //
    // CR review (PR #1148): register in the capture phase + use
    // stopImmediatePropagation so we beat the document-level drawer-opener
    // delegate (around line 2214) that runs in bubble phase. Plain
    // stopPropagation in bubble phase doesn't block sibling document
    // listeners — capture-phase + immediate is the only combo that does.
    document.addEventListener("click", function(e) {
      var stopper = e.target && e.target.closest ? e.target.closest("[data-stop-propagation]") : null;
      if (!stopper) return;
      e.stopPropagation();
      e.stopImmediatePropagation();
    }, true);

    // --- Loading state on POST form submit (#507) ---
    document.addEventListener("submit", function(e) {
      if (e.defaultPrevented) return;
      var form = e.target;
      if (form.method !== "post" || form.hasAttribute("data-no-loading")) return;
      var btn = e.submitter || form.querySelector("button[type=submit], input[type=submit]");
      if (!btn || btn.disabled) return;
      if (btn.name && btn.value) {
        var h = document.createElement("input");
        h.type = "hidden"; h.name = btn.name; h.value = btn.value;
        form.appendChild(h);
      }
      btn.disabled = true;
      btn.setAttribute("aria-busy", "true");
      btn.dataset.originalLabel = btn.textContent;
      var reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
      btn.textContent = reduced ? "Working\u2026" : "";
      if (!reduced) btn.classList.add("button-loading");
    });

    // --- Confirm on buttons/links (data-confirm on non-form elements) ---
    document.addEventListener("click", function(e) {
      var el = e.target.closest("[data-confirm]:not(form)");
      if (!el) return;
      if (!confirm(el.dataset.confirm)) {
        e.preventDefault();
      }
    });

    // --- Bulk select buttons (data-select-addrs) ---
    document.querySelectorAll("[data-select-addrs]").forEach(function(btn) {
      btn.addEventListener("click", function() {
        var mode = btn.dataset.selectAddrs;
        document.querySelectorAll("input.addrbox").forEach(function(cb) {
          if (mode === "all") cb.checked = true;
          else if (mode === "none") cb.checked = false;
          else if (mode === "unconfigured" && cb.dataset.unconf !== undefined) cb.checked = true;
        });
      });
    });

    // --- SSO-only toggle (users.php create form) ---
    var ssoToggle = document.getElementById("sso-only-toggle");
    if (ssoToggle) {
      var pwField = document.getElementById("pw-field");
      var pwInput = document.getElementById("create-pw-input");
      var subField = document.getElementById("sub-field");
      if (pwField && pwInput && subField) {
        function applySsoState() {
          var sso = ssoToggle.checked;
          pwField.classList.toggle("hidden", sso);
          pwInput.required = !sso;
          subField.classList.toggle("hidden", !sso);
        }
        ssoToggle.addEventListener("change", applySsoState);
        applySsoState(); // Apply on load for re-rendered forms (#73)
      }
    }

    // util-bar-fill widths and subnet-node indentation are now handled by CSS attribute
    // selectors in app.css ([data-pct="N"], [data-indent="N"]) — no JS needed.

    // --- reCAPTCHA v3: submit form after obtaining token ---
    // Note: do NOT guard with typeof grecaptcha here — the api.js/enterprise.js script
    // is async and may not have executed yet at DOMContentLoaded. ready() queues
    // callbacks until the full library loads, so the listener must always be attached.
    // For reCAPTCHA Enterprise, enterprise.js exposes grecaptcha.enterprise.execute()
    // instead of grecaptcha.execute().
    var rv3 = document.getElementById("g-recaptcha-response");
    if (rv3 && rv3.dataset.recaptchaV3Key) {
      var rv3Form = rv3.closest("form");
      var rv3IsEnterprise = rv3.dataset.recaptchaEnterprise === "1";
      if (rv3Form) {
        rv3Form.addEventListener("submit", function(e) {
          if (rv3.value) return; // already have token
          e.preventDefault();
          var gr = rv3IsEnterprise ? grecaptcha.enterprise : grecaptcha;
          gr.ready(function() {
            gr.execute(rv3.dataset.recaptchaV3Key, {action: rv3.dataset.recaptchaAction || "login"}).then(function(token) {
              rv3.value = token;
              rv3Form.submit();
            });
          });
        });
      }
    }
  });
}());
