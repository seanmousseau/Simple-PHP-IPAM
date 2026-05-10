(function(){
  // Remove skeleton loader if present (CSP-safe, no inline script)
  var skel = document.getElementById("skeleton-shell");
  if (skel) skel.remove();

  var key = "ipam_theme";
  // Seed localStorage from server-side theme meta tag (CSP-safe, replaces inline script)
  var metaTheme = document.querySelector("meta[name='ipam-server-theme']");
  if (metaTheme) {
    var serverTheme = metaTheme.getAttribute("content");
    if (serverTheme === "light" || serverTheme === "dark") {
      localStorage.setItem(key, serverTheme);
    }
  }
  var saved = localStorage.getItem(key);
  if (saved === "light" || saved === "dark") {
    document.documentElement.setAttribute("data-theme", saved);
  }

  function currentTheme() {
    return document.documentElement.getAttribute("data-theme") || "auto";
  }

  function applyTheme(t) {
    if (t === "auto") {
      document.documentElement.removeAttribute("data-theme");
      localStorage.removeItem(key);
    } else {
      document.documentElement.setAttribute("data-theme", t);
      localStorage.setItem(key, t);
    }
    var csrfMeta = document.querySelector("meta[name='ipam-csrf']");
    var csrfTok = csrfMeta ? csrfMeta.getAttribute("content") || "" : "";
    fetch("set_theme.php", {
      method: "POST",
      headers: {"Content-Type": "application/x-www-form-urlencoded"},
      body: "theme=" + encodeURIComponent(t) + "&csrf=" + encodeURIComponent(csrfTok)
    }).catch(function() {});
  }

  function updateThemeButton() {
    var btn = document.getElementById("theme-toggle");
    if (!btn) return;
    var labels = { auto: "System", light: "Light", dark: "Dark" };
    var labelEl = btn.querySelector(".theme-label");
    if (labelEl) labelEl.textContent = labels[currentTheme()] || "Theme";
  }

  function cycleTheme() {
    var order = ["auto", "light", "dark"];
    var next = order[(order.indexOf(currentTheme()) + 1) % order.length];
    applyTheme(next);
    updateThemeButton();
  }

  function dismissUpdate(version) {
    localStorage.setItem("ipam_dismissed_update", version);
    var banner = document.getElementById("ipam-update-banner");
    if (banner) banner.classList.add("hidden");
  }

  document.addEventListener("DOMContentLoaded", function() {
    // Sticky headers are handled by CSS (thead th { position:sticky; top:0 } within
    // .table-wrap { overflow:auto; max-height:65vh }). No JS offset needed.

    updateThemeButton();

    // --- Theme toggle button ---
    var themeBtn = document.getElementById("theme-toggle");
    if (themeBtn) {
      themeBtn.addEventListener("click", function(e) {
        e.stopPropagation(); // keep dropdown open
        cycleTheme();
      });
    }

    // --- Dismiss update banner ---
    var banner = document.getElementById("ipam-update-banner");
    if (banner) {
      var dismissed = localStorage.getItem("ipam_dismissed_update");
      var bannerVersion = banner.dataset.version;
      if (dismissed && bannerVersion && dismissed === bannerVersion) {
        banner.classList.add("hidden");
      }
    }
    document.querySelectorAll("[data-dismiss-update]").forEach(function(btn) {
      btn.addEventListener("click", function() {
        dismissUpdate(btn.dataset.dismissUpdate);
      });
    });

    // --- Dismiss generic admin banners (e.g. MySQL beta banner) ---
    // Keys are per-app-version so upgrading a banner resurfaces it. State is
    // local to the browser (localStorage) so each operator dismisses once.
    document.querySelectorAll("[data-banner]").forEach(function(notice) {
      var key = "ipam_dismissed_banner_" + notice.dataset.banner;
      if (localStorage.getItem(key)) {
        notice.classList.add("hidden");
      }
    });
    document.querySelectorAll("[data-dismiss-banner]").forEach(function(btn) {
      btn.addEventListener("click", function() {
        var name = btn.dataset.dismissBanner;
        var notice = btn.closest("[data-banner]");
        if (notice) { notice.classList.add("hidden"); }
        localStorage.setItem("ipam_dismissed_banner_" + name, "1");
      });
    });

    // --- Site group collapse/expand ---
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

    // --- #317: Cmd/Ctrl+N opens the Add Address drawer on addresses.php ---

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

    // --- Auto-submit selects (data-auto-submit) ---
    document.querySelectorAll("[data-auto-submit]").forEach(function(el) {
      el.addEventListener("change", function() { el.form.submit(); });
    });

    // --- Password show/hide toggle (eye-icon button, #449) ---
    // <button class="pw-toggle" data-pw-toggle-for="<input-id>"> flips the
    // referenced password input between type=password and type=text.
    // Replaced the v2.6.0 inline-onclick + v2.7.0 nested-checkbox approaches
    // after both regressed in real browsers (containerized Playwright passed
    // but the deployed UI did not flip). Stays on type=password by default
    // so password manager autofill keeps working.
    //
    // #449 follow-up: settings.php sensitive fields render with value=""
    // (the stored secret never appears in HTML source), so an unaided
    // toggle could only reveal what the user had just typed. When the
    // button carries data-pw-reveal-key + data-pw-reveal-csrf, fetch the
    // stored value from settings_reveal.php on the first reveal click and
    // populate the input. On hide, clear the input so the secret does not
    // linger in the DOM and the existing "leave blank to keep current"
    // submit semantics still work.
    function pwToggleApply(btn, input, willShow) {
      input.type = willShow ? "text" : "password";
      btn.setAttribute("aria-pressed", willShow ? "true" : "false");
      btn.setAttribute("aria-label", willShow ? "Hide password" : "Show password");
    }
    document.addEventListener("click", function(e) {
      var btn = e.target && e.target.closest ? e.target.closest("button.pw-toggle[data-pw-toggle-for]") : null;
      if (!btn) return;
      var input = document.getElementById(btn.getAttribute("data-pw-toggle-for"));
      if (!input) return;
      var willShow = input.type === "password";

      // Hide path: if we filled this from the stored value, clear the
      // input so the secret leaves the DOM.
      if (!willShow) {
        if (input.dataset.pwRevealedFromStored === "1") {
          input.value = "";
          delete input.dataset.pwRevealedFromStored;
        }
        pwToggleApply(btn, input, false);
        return;
      }

      // Show path: if the input is empty and the button declares a reveal
      // key, fetch the stored secret first. Otherwise just flip the type
      // (user has typed a value they want to verify).
      var revealKey = btn.getAttribute("data-pw-reveal-key");
      var revealCsrf = btn.getAttribute("data-pw-reveal-csrf");
      if (revealKey && revealCsrf && input.value === "") {
        var body = new URLSearchParams({ csrf: revealCsrf, key: revealKey });
        // Build an absolute URL with any embedded credentials stripped so
        // the fetch() constructor does not throw "Request cannot be
        // constructed from a URL that includes credentials" when the page
        // was loaded with HTTP basic auth credentials in the URL (dev /
        // test scenarios).
        var revealUrl;
        try {
          revealUrl = new URL("settings_reveal.php", window.location.href);
          revealUrl.username = "";
          revealUrl.password = "";
          revealUrl = revealUrl.toString();
        } catch (_) {
          revealUrl = "settings_reveal.php";
        }
        btn.disabled = true;
        // v3.27.0 (#1113): settings_reveal returns 401 + JSON
        // {error:'step_up_required'} when the session has no fresh sudo
        // grant. Navigate to step_up.php with a return-to so the user
        // re-authenticates inline and lands back here. Any other error
        // surfaces in the eye's aria-label as before.
        fetch(revealUrl, { method: "POST", body: body, credentials: "include" })
          .then(function(r) {
            return r.json().then(function(data) { return { ok: r.ok, status: r.status, data: data }; },
                                 function() { return { ok: r.ok, status: r.status, data: null }; });
          })
          .then(function(resp) {
            if (resp.status === 401 && resp.data && resp.data.error === "step_up_required") {
              // Build a return-to that points back at this exact settings tab so
              // the user lands where they started after the proof. Any anchor
              // that pinned them to a specific group is preserved.
              var returnTo;
              try {
                var here = new URL(window.location.href);
                here.username = ""; here.password = "";
                returnTo = here.pathname + here.search + here.hash;
              } catch (_) {
                returnTo = "settings.php";
              }
              // #1140: stash a one-shot XHR replay marker so the user
              // doesn't have to click the eye a second time after the
              // OIDC step-up round-trip. Cleared on click and on
              // successful replay; expires after 120 s. Cross-tab safe
              // (sessionStorage is per-tab) and single-use. CSRF is
              // re-read from the page DOM at replay time (it rotates
              // through the OIDC redirect chain), so we do NOT stash it.
              if (!window.__ipamPwReplayInProgress) {
                try {
                  sessionStorage.setItem("ipam_xhr_replay_v1", JSON.stringify({
                    type: "pw_reveal",
                    inputId: input.id,
                    revealKey: revealKey,
                    ts: Date.now()
                  }));
                } catch (_) { /* storage may be full or blocked */ }
              }
              var stepUpUrl = "step_up.php?return_to=" + encodeURIComponent(returnTo);
              window.location.assign(stepUpUrl);
              return;
            }
            if (!resp.ok) throw new Error("HTTP " + resp.status);
            if (resp.data && typeof resp.data.value === "string") {
              input.value = resp.data.value;
              input.dataset.pwRevealedFromStored = "1";
            }
            pwToggleApply(btn, input, true);
          })
          .catch(function() {
            btn.setAttribute("aria-label", "Could not reveal stored value");
          })
          .finally(function() {
            btn.disabled = false;
            // #1140: clear the replay guard once the fetch resolves (success
            // or fail). Pre-fix the flag stayed true after a successful
            // auto-replay, so a later step-up flow on the same page would
            // skip stashing — the second OIDC round-trip would then drop
            // back without an auto-resume, re-creating the bug for the
            // second action. CR follow-up on PR #1144.
            window.__ipamPwReplayInProgress = false;
          });
        return;
      }

      pwToggleApply(btn, input, true);
    });
    // #1140: consume the one-shot XHR replay marker if present. Set just
    // before navigating to step_up.php from a 401 step_up_required; cleared
    // here on the originating page's next load. Auto-clicks the matching
    // pw-toggle button so the user sees the reveal complete instead of a
    // silent drop. If the replay itself gets another 401 (e.g. user
    // cancelled OIDC), __ipamPwReplayInProgress prevents a re-stash loop.
    //
    // #1146 (v3.27.6): only removeItem when we actually have a matching
    // button to click. Pre-fix removeItem fired unconditionally on every
    // page load that saw the marker — so step_up.php (which is a
    // legitimate intermediate stop on the OIDC reauth journey, has no
    // pw-toggle button, but DOES load app.js) ate the marker before the
    // user got back to the originating settings.php. The 120 s TTL
    // bounds any case where no page ever matches.
    try {
      var replayRaw = sessionStorage.getItem("ipam_xhr_replay_v1");
      if (replayRaw) {
        var replay = JSON.parse(replayRaw);
        if (replay && replay.type === "pw_reveal"
            && typeof replay.inputId === "string"
            && typeof replay.revealKey === "string"
            && (Date.now() - (replay.ts || 0)) < 120000) {
          var replayBtn = document.querySelector(
            'button.pw-toggle[data-pw-toggle-for="' + CSS.escape(replay.inputId) + '"]'
            + '[data-pw-reveal-key="' + CSS.escape(replay.revealKey) + '"]'
          );
          if (replayBtn) {
            sessionStorage.removeItem("ipam_xhr_replay_v1");
            window.__ipamPwReplayInProgress = true;
            // Defer to the next microtask so the rest of DOMContentLoaded
            // wiring (including the click handler attached above) is in
            // place before we synthesise the click.
            setTimeout(function() { replayBtn.click(); }, 0);
          }
        } else {
          // Malformed or expired marker — eat it so it can't fire later.
          sessionStorage.removeItem("ipam_xhr_replay_v1");
        }
      }
    } catch (_) { /* malformed JSON or storage blocked; non-fatal */ }

    // Per-browser opt-out: users whose password manager already provides a
    // visibility toggle can hide the IPAM eye buttons by setting
    // localStorage['ipam-pw-toggle-hidden'] = '1' from devtools.
    try {
      if (window.localStorage && localStorage.getItem("ipam-pw-toggle-hidden") === "1") {
        document.body.classList.add("pw-toggle-hidden");
      }
    } catch (_) { /* localStorage may be blocked; non-fatal */ }

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
    document.addEventListener("click", function(e) {
      if (e.target.closest("[data-stop-propagation]")) {
        e.stopPropagation();
      }
    });

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

    // --- Search page: site → subnet cascade filter ---
    var filterSite = document.getElementById("filter-site");
    var filterSubnet = document.getElementById("filter-subnet");
    if (filterSite && filterSubnet) {
      function filterSubnets() {
        var selectedSite = filterSite.value;
        var opts = filterSubnet.querySelectorAll("option");
        var currentVal = filterSubnet.value;
        var currentStillVisible = false;
        opts.forEach(function(opt) {
          if (opt.value === "0") return;
          var match = selectedSite === "0" || opt.dataset.site === selectedSite;
          opt.hidden = !match;
          if (match && opt.value === currentVal) currentStillVisible = true;
        });
        if (!currentStillVisible) filterSubnet.value = "0";
      }
      filterSite.addEventListener("change", filterSubnets);
      filterSubnets();
    }
    // --- Client-side IP/CIDR validation (data-validate) ---
    var ipv4Re = /^(\d{1,3}\.){3}\d{1,3}$/;
    var ipv6Re = /^[0-9a-fA-F:]+$/;
    var cidrRe = /^(.+)\/(\d{1,3})$/;

    function validateInput(el) {
      var type = el.dataset.validate;
      var val = el.value.trim();
      if (val === "") { el.setCustomValidity(""); return; }

      if (type === "ip") {
        if (!ipv4Re.test(val) && !ipv6Re.test(val)) {
          el.setCustomValidity("Enter a valid IPv4 or IPv6 address.");
        } else { el.setCustomValidity(""); }
      } else if (type === "cidr") {
        var m = val.match(cidrRe);
        if (!m) {
          el.setCustomValidity("Enter a valid CIDR (e.g. 10.0.0.0/24 or 2001:db8::/64).");
        } else if (!ipv4Re.test(m[1]) && !ipv6Re.test(m[1])) {
          el.setCustomValidity("Network address is not a valid IP.");
        } else { el.setCustomValidity(""); }
      }
    }

    document.querySelectorAll("[data-validate]").forEach(function(el) {
      el.addEventListener("blur", function() { validateInput(el); });
      el.addEventListener("input", function() { el.setCustomValidity(""); });
    });

    // --- #1133: stop wheel from hijacking focused number inputs ---
    // HTML5 <input type="number"> hijacks the mouse wheel when focused —
    // every wheel tick increments/decrements the value. Operators typed a
    // value into a settings field, kept it focused, then scrolled the page;
    // the field silently counted down (clamped at min), so the saved value
    // bore no relation to what they typed. We preventDefault on the wheel
    // event before the browser applies its native step, then blur the input
    // so the next wheel tick scrolls the page normally. Spinner buttons and
    // arrow-key adjustment stay intact (they don't go through wheel events).
    // Listener must be non-passive so preventDefault has effect.
    document.addEventListener("wheel", function(e) {
      var t = e.target;
      if (t && t.tagName === "INPUT" && t.type === "number" && document.activeElement === t) {
        e.preventDefault();
        t.blur();
      }
    }, { passive: false });

    // --- Sidebar hamburger toggle (#512) ---
    (function () {
      var sidebar = document.getElementById("sidebar");
      var openBtn = document.getElementById("sidebar-open");
      var closeBtn = document.getElementById("sidebar-close");
      if (!sidebar) return;

      var overlay = document.createElement("div");
      overlay.className = "sidebar-overlay";
      document.body.appendChild(overlay);

      // Matches the CSS sidebar slide-in transition duration so uPlot resizes after layout settles
      var SIDEBAR_TRANSITION_MS = 320;

      function isMobile() {
        return window.innerWidth <= 1023;
      }

      function openSidebar() {
        sidebar.classList.add("is-open");
        overlay.classList.add("is-visible");
        sidebar.setAttribute("aria-hidden", "false");
        if (openBtn) openBtn.setAttribute("aria-expanded", "true");
        if (closeBtn) closeBtn.focus();
        setTimeout(function() { document.dispatchEvent(new CustomEvent('ipam:sidebar-toggle')); }, SIDEBAR_TRANSITION_MS);
      }

      function closeSidebar() {
        sidebar.classList.remove("is-open");
        overlay.classList.remove("is-visible");
        if (isMobile()) sidebar.setAttribute("aria-hidden", "true");
        if (openBtn) {
          openBtn.setAttribute("aria-expanded", "false");
          openBtn.focus();
        }
        setTimeout(function() { document.dispatchEvent(new CustomEvent('ipam:sidebar-toggle')); }, SIDEBAR_TRANSITION_MS);
      }

      // Set initial aria-hidden state
      if (isMobile()) sidebar.setAttribute("aria-hidden", "true");

      // Update on resize
      window.addEventListener("resize", function () {
        if (isMobile()) {
          if (!sidebar.classList.contains("is-open")) {
            sidebar.setAttribute("aria-hidden", "true");
          }
        } else {
          sidebar.removeAttribute("aria-hidden");
        }
      });

      if (openBtn) openBtn.addEventListener("click", openSidebar);
      if (closeBtn) closeBtn.addEventListener("click", closeSidebar);
      overlay.addEventListener("click", closeSidebar);
      document.addEventListener("keydown", function (e) { if (e.key === "Escape") closeSidebar(); });
    }());

    // --- Inline status toggle (#252) ---
    document.querySelectorAll(".status-badge[data-addr-id]").forEach(function(badge) {
      badge.addEventListener("click", function() {
        var addrId = badge.dataset.addrId;
        var cycle = {used: "reserved", reserved: "free", free: "used"};
        var curClass = ["status-used","status-reserved","status-free"].find(function(c){ return badge.classList.contains(c); });
        var curStatus = curClass ? curClass.replace("status-","") : "used";
        var nextStatus = cycle[curStatus] || "used";
        var csrf = document.querySelector("input[name=csrf]");
        if (!csrf) return;
        var subnetParam = new URLSearchParams(window.location.search).get("subnet_id") || "0";
        var formData = new FormData();
        formData.append("csrf", csrf.value);
        formData.append("action", "update_status");
        formData.append("id", addrId);
        formData.append("subnet_id", subnetParam);
        formData.append("status", nextStatus);
        badge.classList.add("status-updating");
        fetch("addresses.php", {method: "POST", body: formData})
          .then(function(r){ return r.json(); })
          .then(function(data) {
            if (data.ok) {
              badge.classList.remove("status-used","status-reserved","status-free");
              badge.classList.add("status-" + data.status);
              badge.textContent = data.status;
            }
            badge.classList.remove("status-updating");
          })
          .catch(function(){ badge.classList.remove("status-updating"); });
      });
    });

    // --- Slide-in form drawer (#247) ---
    var formDrawer = document.getElementById("form-drawer");
    var formDrawerOverlay = document.querySelector(".form-drawer-overlay");
    if (formDrawer) {
      document.body.classList.add("drawer-ready");
      function openFormDrawer(triggerId) {
        var src = document.getElementById(triggerId);
        if (!src) return;
        var body = document.getElementById("form-drawer-body");
        if (!body) return;
        // Clear drawer body safely, then move source content in
        while (body.firstChild) body.removeChild(body.firstChild);
        body.appendChild(src);
        formDrawer.classList.add("drawer--open");
        if (formDrawerOverlay) formDrawerOverlay.classList.add("visible");
      }
      function closeFormDrawer() {
        formDrawer.classList.remove("drawer--open");
        if (formDrawerOverlay) formDrawerOverlay.classList.remove("visible");
      }
      if (formDrawerOverlay) formDrawerOverlay.addEventListener("click", closeFormDrawer);
      var drawerCloseBtn = formDrawer.querySelector(".drawer-close-btn");
      if (drawerCloseBtn) drawerCloseBtn.addEventListener("click", closeFormDrawer);
      document.addEventListener("keydown", function(e) { if (e.key === "Escape") closeFormDrawer(); });
      // Wire up any [data-open-drawer] triggers
      document.querySelectorAll("[data-open-drawer]").forEach(function(trigger) {
        trigger.addEventListener("click", function(e) {
          e.preventDefault();
          var titleEl = formDrawer.querySelector(".drawer-title");
          if (titleEl && trigger.dataset.drawerTitle) titleEl.textContent = trigger.dataset.drawerTitle;
          openFormDrawer(trigger.dataset.openDrawer);
        });
      });
    }

    // --- "Use next IP" fill helper (data-fill-ip on addresses.php) ---
    document.addEventListener("click", function(e) {
      var el = e.target.closest("[data-fill-ip]");
      if (!el) return;
      e.preventDefault();
      var card = el.closest(".drawer-form-card, .card");
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

    // --- Contact typeahead (data-contact-typeahead) ---
    document.querySelectorAll("[data-contact-typeahead]").forEach(function(input) {
      var hiddenInput = input.parentElement.querySelector("input[name=owner_contact_id]");
      if (!hiddenInput) return;
      var list = document.createElement("ul");
      list.className = "contact-suggestions hidden";
      input.parentElement.classList.add("contact-typeahead-wrap");
      input.parentElement.appendChild(list);
      var timer;

      function clearSuggestions() {
        while (list.firstChild) list.removeChild(list.firstChild);
        list.classList.add("hidden");
      }

      input.addEventListener("input", function() {
        hiddenInput.value = "0";
        clearTimeout(timer);
        var q = input.value.trim();
        if (q.length < 2) { clearSuggestions(); return; }
        timer = setTimeout(function() {
          fetch("api.php?resource=contacts&q=" + encodeURIComponent(q) + "&limit=10", {credentials: "same-origin"})
            .then(function(r) { return r.json(); })
            .then(function(data) {
              clearSuggestions();
              if (!data.contacts || !data.contacts.length) return;
              data.contacts.forEach(function(c) {
                var li = document.createElement("li");
                li.textContent = c.name + (c.email ? " <" + c.email + ">" : "");
                li.dataset.contactId   = c.id;
                li.dataset.contactName = c.name;
                li.addEventListener("mousedown", function(e) {
                  e.preventDefault();
                  input.value = c.name;
                  hiddenInput.value = c.id;
                  clearSuggestions();
                });
                list.appendChild(li);
              });
              list.classList.remove("hidden");
            })
            .catch(function() { clearSuggestions(); });
        }, 250);
      });

      input.addEventListener("blur", function() {
        setTimeout(clearSuggestions, 200);
      });

      input.addEventListener("keydown", function(e) {
        if (e.key === "Escape") { clearSuggestions(); }
      });
    });

    // --- Dashboard pinnable widgets + site filter (#257) ---
    (function() {
      var HIDDEN_KEY = "ipam_hidden_widgets";
      var SITE_KEY   = "ipam_dash_site";

      function hiddenList() {
        try { return JSON.parse(localStorage.getItem(HIDDEN_KEY) || "[]"); } catch(e) { return []; }
      }

      function applyWidgetVisibility() {
        var hidden = hiddenList();
        document.querySelectorAll("[data-widget]").forEach(function(el) {
          el.classList.toggle("hidden", hidden.includes(el.dataset.widget));
        });
      }

      applyWidgetVisibility();

      document.querySelectorAll(".widget-hide-btn").forEach(function(btn) {
        btn.addEventListener("click", function() {
          var key    = btn.dataset.widgetKey;
          var hidden = hiddenList();
          if (!hidden.includes(key)) hidden.push(key);
          localStorage.setItem(HIDDEN_KEY, JSON.stringify(hidden));
          applyWidgetVisibility();
        });
      });

      var resetBtn = document.getElementById("dash-reset");
      if (resetBtn) {
        resetBtn.addEventListener("click", function(e) {
          e.preventDefault();
          // Clear all ipam_ localStorage keys
          Object.keys(localStorage).filter(function(k) { return k.startsWith("ipam_"); })
                .forEach(function(k) { localStorage.removeItem(k); });
          location.reload();
        });
      }

      // Site filter on "by-site" table
      var siteFilter = document.getElementById("dash-site-filter");
      if (siteFilter) {
        function applyFilter() {
          var val = siteFilter.value;
          localStorage.setItem(SITE_KEY, val);
          document.querySelectorAll("[data-site-row]").forEach(function(tr) {
            tr.hidden = !(val === "" || tr.dataset.siteRow === val);
          });
        }
        siteFilter.addEventListener("change", applyFilter);
        var saved = localStorage.getItem(SITE_KEY) || "";
        if (saved) { siteFilter.value = saved; }
        applyFilter();
      }
    }());

    // --- Per-user column visibility (#256) ---
    document.querySelectorAll("[data-col-table]").forEach(function(table) {
      var key    = "ipam_cols_" + table.dataset.colTable;
      var stored = localStorage.getItem(key);
      var ths    = Array.from(table.querySelectorAll("thead th[data-col]"));
      // When no saved preference exists, use data-col-default-hidden as the initial state
      var defaultHidden = ths
        .filter(function(th) { return th.dataset.colDefaultHidden === "1"; })
        .map(function(th) { return th.dataset.col; });
      var hidden = stored !== null ? JSON.parse(stored) : defaultHidden;
      if (!ths.length) return;

      function setColVisible(col, visible) {
        var idx = ths.findIndex(function(th) { return th.dataset.col === col; });
        if (idx < 0) return;
        var allCells = [ths[idx]].concat(
          Array.from(table.querySelectorAll("tbody tr")).map(function(tr) {
            return tr.cells[idx];
          }).filter(Boolean)
        );
        allCells.forEach(function(c) { c.classList.toggle("col-hidden", !visible); });
      }

      function saveAndApply(hiddenArr) {
        hidden = hiddenArr;
        localStorage.setItem(key, JSON.stringify(hidden));
        ths.forEach(function(th) { setColVisible(th.dataset.col, !hidden.includes(th.dataset.col)); });
        // keep at least one visible
        var visCount = ths.filter(function(th) { return !hidden.includes(th.dataset.col); }).length;
        if (visCount === 0 && ths[0]) {
          hidden = hidden.filter(function(c) { return c !== ths[0].dataset.col; });
          localStorage.setItem(key, JSON.stringify(hidden));
          setColVisible(ths[0].dataset.col, true);
        }
        syncChecks();
      }

      // Build gear dropdown
      var wrapper = document.createElement("div");
      wrapper.className = "col-vis-wrap";
      var gear = document.createElement("button");
      gear.className = "col-vis-gear action-pill";
      gear.textContent = "\u2699 Columns";
      gear.setAttribute("aria-expanded", "false");
      var drop = document.createElement("div");
      drop.className = "col-vis-drop";
      ths.forEach(function(th) {
        var label = document.createElement("label");
        var cb    = document.createElement("input");
        cb.type   = "checkbox";
        cb.dataset.col = th.dataset.col;
        cb.checked = !hidden.includes(th.dataset.col);
        cb.addEventListener("change", function() {
          var newHidden = ths.map(function(t) { return t.dataset.col; })
            .filter(function(c) {
              var cbEl = drop.querySelector("input[data-col='" + c + "']");
              return cbEl && !cbEl.checked;
            });
          saveAndApply(newHidden);
        });
        label.appendChild(cb);
        label.appendChild(document.createTextNode(" " + th.textContent.trim()));
        drop.appendChild(label);
      });
      wrapper.appendChild(gear);
      wrapper.appendChild(drop);

      function syncChecks() {
        drop.querySelectorAll("input[data-col]").forEach(function(cb) {
          cb.checked = !hidden.includes(cb.dataset.col);
        });
      }

      gear.addEventListener("click", function(e) {
        e.stopPropagation();
        var open = drop.classList.toggle("visible");
        gear.setAttribute("aria-expanded", open ? "true" : "false");
      });
      document.addEventListener("click", function() { drop.classList.remove("visible"); gear.setAttribute("aria-expanded","false"); });

      // Insert gear into a toolbar if present, or before the table-wrap container
      var toolbar = table.closest(".card") && table.closest(".card").querySelector(".toolbar");
      if (toolbar) {
        toolbar.appendChild(wrapper);
      } else {
        var tableWrap = table.closest(".table-wrap");
        var insertTarget = tableWrap || table;
        insertTarget.parentNode.insertBefore(wrapper, insertTarget);
      }

      // Apply persisted hidden columns
      hidden.forEach(function(col) { setColVisible(col, false); });
    });

    // --- Subnet list/map view toggle (#255) ---
    (function() {
      var listView = document.getElementById("subnet-list-view");
      var mapView  = document.getElementById("subnet-map-view");
      var btns     = document.querySelectorAll(".subnet-view-btn");
      if (!listView || !mapView) return;
      var storageKey = "ipam_subnet_view";

      function applyView(v) {
        var isMap = v === "map";
        listView.hidden = isMap;
        mapView.hidden  = !isMap;
        btns.forEach(function(b) {
          b.classList.toggle("active", b.dataset.view === v);
        });
        localStorage.setItem(storageKey, v);
      }

      btns.forEach(function(b) {
        b.addEventListener("click", function() { applyView(b.dataset.view); });
      });

      applyView(localStorage.getItem(storageKey) === "map" ? "map" : "list");
    }());

    // --- Inline cell editing on address rows (#254) ---
    document.querySelectorAll("[data-editable][data-addr-id]").forEach(function(cell) {
      cell.title = "Click to edit";
      cell.classList.add("th-sortable");
      cell.addEventListener("click", function(e) {
        if (e.target.closest(".contact-card-trigger")) return;
        if (cell.querySelector("input")) return; // already editing
        var field   = cell.dataset.editable;
        var addrId  = cell.dataset.addrId;
        var origHtml = cell.innerHTML;
        var origText = cell.textContent.trim();

        var input = document.createElement("input");
        input.type = "text";
        input.value = origText;
        input.className = "inline-edit-input";
        cell.innerHTML = "";
        cell.appendChild(input);
        input.focus();
        input.select();

        var csrf = document.querySelector("input[name=csrf]");
        var isSaving = false;

        function save() {
          if (isSaving) return;
          var newVal = input.value;
          if (newVal === origText) { cell.innerHTML = origHtml; return; }
          if (!csrf) { cell.innerHTML = origHtml; return; }
          isSaving = true;
          var fd = new FormData();
          fd.append("csrf",      csrf.value);
          fd.append("action",    "update_cell");
          fd.append("id",        addrId);
          fd.append("subnet_id", new URLSearchParams(window.location.search).get("subnet_id") || "0");
          fd.append("field",     field);
          fd.append("value",     newVal);
          cell.classList.add("cell-saving");
          fetch("addresses.php", {method: "POST", body: fd})
            .then(function(r) { return r.json(); })
            .then(function(data) {
              cell.classList.remove("cell-saving");
              isSaving = false;
              if (data.ok) {
                cell.innerHTML = data.value
                  ? data.value.replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;")
                  : "";
              } else {
                cell.innerHTML = origHtml;
                cell.title = data.error || "Save failed";
              }
            })
            .catch(function() {
              cell.classList.remove("cell-saving");
              isSaving = false;
              cell.innerHTML = origHtml;
            });
        }

        input.addEventListener("keydown", function(e) {
          if (e.key === "Enter")  { e.preventDefault(); save(); }
          if (e.key === "Escape") { e.preventDefault(); cell.innerHTML = origHtml; }
          if (e.key === "Tab") {
            e.preventDefault();
            save();
            // Move to next editable cell in the same row
            var cells = Array.from(cell.closest("tr").querySelectorAll("[data-editable]"));
            var idx = cells.indexOf(cell);
            if (idx >= 0 && cells[idx + 1]) cells[idx + 1].click();
          }
        });
        input.addEventListener("blur", function() {
          setTimeout(function() {
            if (isSaving) return;
            if (cell.querySelector("input") === input) save();
          }, 100);
        });
      });
    });

    // --- Command palette ⌘K (#516) ---
    (function() {
      var bg = document.getElementById("cmd-palette-bg");
      if (!bg) return;

      var input = document.getElementById("cmd-input");
      var results = document.getElementById("cmd-results");
      var searchTimer;
      var activeIdx = -1;
      var currentItems = [];

      var IPAM_COMMANDS = [
        { group: "Pages", label: "Dashboard",    href: "dashboard.php" },
        { group: "Pages", label: "Subnets",      href: "subnets.php" },
        { group: "Pages", label: "Addresses",    href: "addresses.php" },
        { group: "Pages", label: "Search",       href: "search.php" },
        { group: "Pages", label: "Audit",        href: "audit.php" },
        { group: "Pages", label: "Unassigned",   href: "unassigned.php" },
        { group: "Actions", label: "New Subnet", action: function() { IpamDrawer.open("Add Subnet", "tpl-add-subnet"); } },
        { group: "Actions", label: "Toggle Theme", action: function() {
            var btn = document.getElementById("theme-toggle");
            if (btn) btn.click();
        }},
      ];

      function escHtml(s) {
        return String(s)
          .replace(/&/g, "&amp;")
          .replace(/</g, "&lt;")
          .replace(/>/g, "&gt;")
          .replace(/"/g, "&quot;")
          .replace(/'/g, "&#x27;");
      }

      function safeUrl(url) {
        return /^(javascript|data|vbscript):/i.test(String(url)) ? "#" : url;
      }

      function openPalette() {
        bg.classList.add("is-open");
        input.value = "";
        renderCommands("");
        activeIdx = -1;
        input.focus();
      }

      function closePalette() {
        bg.classList.remove("is-open");
        clearTimeout(searchTimer);
      }

      function renderCommands(q) {
        var lq = q.toLowerCase();
        var filtered = IPAM_COMMANDS.filter(function(c) {
          return !lq || c.label.toLowerCase().indexOf(lq) !== -1;
        });

        results.innerHTML = "";
        currentItems = [];

        if (!filtered.length) {
          results.innerHTML = '<div class="cmd-empty">No commands match.</div>';
          return;
        }

        var lastGroup = null;
        filtered.forEach(function(cmd) {
          if (cmd.group !== lastGroup) {
            var gl = document.createElement("div");
            gl.className = "cmd-group-label";
            gl.textContent = cmd.group;
            results.appendChild(gl);
            lastGroup = cmd.group;
          }
          var item = document.createElement("div");
          item.className = "cmd-item";
          item.setAttribute("role", "option");
          item.innerHTML = escHtml(cmd.label);
          item.addEventListener("mousedown", function(e) {
            e.preventDefault();
            activateCommand(cmd);
          });
          results.appendChild(item);
          currentItems.push({ el: item, cmd: cmd });
        });
        activeIdx = -1;
      }

      function renderEntities(data) {
        if (!data.length) return;
        var gl = document.createElement("div");
        gl.className = "cmd-group-label";
        gl.textContent = "Addresses";
        results.appendChild(gl);
        data.slice(0, 8).forEach(function(row) {
          var item = document.createElement("div");
          item.className = "cmd-item";
          item.setAttribute("role", "option");
          item.innerHTML = escHtml(row.ip) + (row.hostname ? ' <span style="opacity:.65">' + escHtml(row.hostname) + "</span>" : "");
          item.addEventListener("mousedown", function(e) {
            e.preventDefault();
            closePalette();
            window.location.href = safeUrl(row.url);
          });
          results.appendChild(item);
          currentItems.push({ el: item, cmd: { href: row.url } });
        });
      }

      function activateCommand(cmd) {
        closePalette();
        if (cmd.action) {
          cmd.action();
        } else if (cmd.href) {
          window.location.href = safeUrl(cmd.href);
        }
      }

      function setActive(idx) {
        if (activeIdx >= 0 && currentItems[activeIdx]) currentItems[activeIdx].el.classList.remove("is-active");
        activeIdx = Math.max(-1, Math.min(idx, currentItems.length - 1));
        if (activeIdx >= 0 && currentItems[activeIdx]) {
          currentItems[activeIdx].el.classList.add("is-active");
          currentItems[activeIdx].el.scrollIntoView({ block: "nearest" });
        }
      }

      function runSearch(q) {
        renderCommands(q);
        fetch("search.php?format=json&q=" + encodeURIComponent(q), { credentials: "same-origin" })
          .then(function(r) { return r.json(); })
          .then(function(data) { renderEntities(data); })
          .catch(function() {});
      }

      input.addEventListener("input", function() {
        clearTimeout(searchTimer);
        var q = input.value.trim();
        activeIdx = -1;
        renderCommands(q);
        if (q.length >= 2) {
          searchTimer = setTimeout(function() { runSearch(q); }, 300);
        }
      });

      input.addEventListener("keydown", function(e) {
        if (e.key === "ArrowDown") { e.preventDefault(); setActive(activeIdx + 1); }
        else if (e.key === "ArrowUp") { e.preventDefault(); setActive(Math.max(-1, activeIdx - 1)); }
        else if (e.key === "Enter") {
          e.preventDefault();
          if (activeIdx >= 0 && currentItems[activeIdx]) {
            activateCommand(currentItems[activeIdx].cmd);
          } else if (input.value.trim().length >= 2) {
            closePalette();
            window.location.href = "search.php?q=" + encodeURIComponent(input.value.trim());
          }
        } else if (e.key === "Escape") { closePalette(); }
      });

      bg.addEventListener("mousedown", function(e) {
        if (e.target === bg) closePalette();
      });

      document.addEventListener("keydown", function(e) {
        if ((e.metaKey || e.ctrlKey) && e.key === "k") {
          e.preventDefault();
          bg.classList.contains("is-open") ? closePalette() : openPalette();
        }
      });

      document.addEventListener("click", function(e) {
        var btn = e.target.closest(".nav-search-link");
        if (btn) {
          e.preventDefault();
          bg.classList.contains("is-open") ? closePalette() : openPalette();
        }
      });

      document.addEventListener("keydown", function(e) {
        if ((e.metaKey || e.ctrlKey) && e.key === "n" && document.getElementById("tpl-add-address")) {
          e.preventDefault();
          IpamDrawer.open("Add Address", "tpl-add-address");
          var ipField = document.querySelector("#global-drawer-body input[name=\"ip\"]");
          if (ipField) ipField.focus();
        }
      });
    }());

    // ── Tooltips (#354) ─────────────────────────────────────────────────────
    // A single shared #ipam-tooltip div at position:fixed is used so the bubble
    // is never clipped by overflow:hidden/clip on ancestor containers.
    (function() {
      var tipEl = null;
      var MARGIN = 8;
      function getTipEl() {
        if (!tipEl) {
          tipEl = document.createElement("div");
          tipEl.id = "ipam-tooltip";
          tipEl.setAttribute("role", "tooltip");
          document.body.appendChild(tipEl);
        }
        return tipEl;
      }
      function showTip(anchor) {
        var t    = getTipEl();
        t.textContent = anchor.dataset.tooltip || "";
        // Measure while visibility:hidden so layout is correct
        t.classList.remove("visible");
        var rect = anchor.getBoundingClientRect();
        var vw   = window.innerWidth;
        var tw   = t.offsetWidth;
        var th   = t.offsetHeight;
        var top  = rect.top - th - 8;
        var cx   = rect.left + rect.width / 2;
        var left = Math.max(MARGIN, Math.min(cx - tw / 2, vw - tw - MARGIN));
        t.style.top  = top  + "px";
        t.style.left = left + "px";
        t.classList.add("visible");
      }
      function hideTip() {
        if (tipEl) tipEl.classList.remove("visible");
      }
      document.querySelectorAll("[data-tooltip]").forEach(function(el) {
        el.addEventListener("mouseenter", function() { showTip(el); });
        el.addEventListener("focusin",    function() { showTip(el); });
        el.addEventListener("mouseleave", hideTip);
        el.addEventListener("focusout",   hideTip);
      });
    }());

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

    /* ---- Subnet edit drawer (#567) ---- */
    (function() {
      var editDrawer = document.getElementById("subnet-edit-drawer");
      if (!editDrawer || !formDrawer) return;

      var siteWrap = document.getElementById("subnet-edit-site-wrap");
      var siteLocked = document.getElementById("subnet-edit-site-locked");
      var siteSelect = document.getElementById("subnet-edit-site");
      var siteHidden = document.getElementById("subnet-edit-site-hidden");
      var siteBadge = document.getElementById("subnet-edit-site-badge");

      document.addEventListener("click", function(e) {
        var btn = e.target.closest(".subnet-edit-btn");
        if (!btn) return;
        var d = btn.dataset;

        document.getElementById("subnet-edit-id").value = d.sid;
        document.getElementById("subnet-delete-id").value = d.sid;
        document.getElementById("subnet-edit-cidr").value = d.cidr;
        document.getElementById("subnet-edit-description").value = d.description;
        document.getElementById("subnet-edit-notes").value = d.notes;
        var vlanSel = document.getElementById("subnet-edit-vlan");
        if (vlanSel) vlanSel.value = d.vlanFk;
        var vrfSel = document.getElementById("subnet-edit-vrf");
        if (vrfSel) vrfSel.value = d.vrfId;

        if (parseInt(d.depth, 10) > 0 && siteLocked && siteWrap) {
          siteWrap.hidden = true;
          if (siteSelect) siteSelect.disabled = true;
          siteLocked.hidden = false;
          if (siteHidden) { siteHidden.value = d.siteId; siteHidden.disabled = false; }
          if (siteBadge) {
            var opt = siteSelect && siteSelect.querySelector("option[value='" + d.siteId + "']");
            siteBadge.textContent = (opt ? opt.textContent : "(none)") + " \u2191";
            siteBadge.title = "Inherited from parent subnet";
          }
        } else if (siteWrap && siteLocked) {
          siteWrap.hidden = false;
          if (siteSelect) { siteSelect.disabled = false; siteSelect.value = d.siteId; }
          siteLocked.hidden = true;
          if (siteHidden) siteHidden.disabled = true;
        }

        var alertsCb = document.getElementById("subnet-edit-alerts");
        if (alertsCb) alertsCb.checked = (d.alertsEnabled !== "0");

        var dhcpFields = [
          ["dhcp-routers", "dhcpRouters"], ["dhcp-dns-servers", "dhcpDnsServers"],
          ["dhcp-domain-name", "dhcpDomainName"], ["dhcp-lease-default", "dhcpLeaseDefault"],
          ["dhcp-lease-max", "dhcpLeaseMax"], ["dhcp-next-server", "dhcpNextServer"],
          ["dhcp-boot-filename", "dhcpBootFilename"]
        ];
        dhcpFields.forEach(function(pair) {
          var el = document.getElementById("subnet-edit-" + pair[0]);
          if (el) el.value = d[pair[1]] || "";
        });
        var dhcpDetails = document.querySelector(".dhcp-options-group");
        if (dhcpDetails) {
          var anySet = dhcpFields.some(function(pair) { return !!(d[pair[1]]); });
          dhcpDetails.open = anySet;
        }

        var contactPicker = document.getElementById("subnet-edit-contacts");
        if (contactPicker) {
          var existingContacts = [];
          try { existingContacts = JSON.parse(d.contacts || "[]"); } catch(ex) {}
          var rowsDiv = contactPicker.querySelector(".contact-picker-rows");
          if (rowsDiv) rowsDiv.textContent = "";
          contactPicker.setAttribute("data-existing", JSON.stringify(existingContacts));
          contactPicker.dispatchEvent(new CustomEvent("reinit"));
        }

        // #1138: pre-select tags on edit-drawer open from data-tag-ids JSON.
        var tagSelect = document.getElementById("subnet-edit-tag-ids");
        if (tagSelect) {
          var selectedTagIds = [];
          try { selectedTagIds = JSON.parse(d.tagIds || "[]"); } catch (ex) {}
          var selectedSet = {};
          for (var ti = 0; ti < selectedTagIds.length; ti++) selectedSet[String(selectedTagIds[ti])] = true;
          Array.prototype.forEach.call(tagSelect.options, function(opt) {
            opt.selected = !!selectedSet[opt.value];
          });
        }

        // Fill custom field inputs from data-custom-fields JSON
        var cfContainer = document.getElementById("subnet-edit-cf-inputs");
        if (cfContainer) {
          var cfValues = {};
          try { cfValues = JSON.parse(d.customFields || "{}"); } catch(ex) {}
          cfContainer.querySelectorAll("[data-cf-key]").forEach(function(inp) {
            var cfKey = inp.getAttribute("data-cf-key");
            var val = cfValues[cfKey];
            if (inp.type === "checkbox") {
              inp.checked = val === true || val === 1 || val === "1";
            } else {
              inp.value = (val !== null && val !== undefined) ? String(val) : "";
            }
          });
        }

        editDrawer.hidden = false;
        var titleEl = formDrawer.querySelector(".drawer-title");
        if (titleEl) titleEl.textContent = "Edit " + d.cidr;
        var body = document.getElementById("form-drawer-body");
        if (body) {
          while (body.firstChild) body.removeChild(body.firstChild);
          body.appendChild(editDrawer);
        }
        formDrawer.classList.add("drawer--open");
        var overlay = document.querySelector(".form-drawer-overlay");
        if (overlay) overlay.classList.add("visible");
      });
    }());

    /* ---- Subnet stats async load (#565) ---- */
    (function() {
      var placeholders = document.querySelectorAll("[data-subnet-counts]");
      if (!placeholders.length) return;
      fetch("api.php?resource=subnet_stats", {credentials: "same-origin"})
        .then(function(r) { return r.json(); })
        .then(function(resp) {
          var data = resp.data || resp;
          document.querySelectorAll("[data-subnet-counts]").forEach(function(el) {
            var id = el.dataset.subnetCounts;
            var d = data.direct[id] || {used:0,reserved:0,free:0,total:0};
            var a = data.agg[id] || d;
            var hasChildren = el.dataset.hasChildren === "1";
            el.textContent = "";
            el.classList.remove("subnet-stats-placeholder");

            function mkSpan(cls, text) {
              var s = document.createElement("span");
              s.className = cls;
              s.textContent = text;
              return s;
            }
            el.appendChild(mkSpan("status-used", d.used + " used"));
            el.appendChild(document.createTextNode(" \u00b7 "));
            el.appendChild(mkSpan("status-reserved", d.reserved + " reserved"));
            el.appendChild(document.createTextNode(" \u00b7 "));
            el.appendChild(mkSpan("status-free", d.free + " free"));
            if (hasChildren && a.total !== d.total) {
              el.appendChild(document.createTextNode(" "));
              el.appendChild(mkSpan("muted", "(subtree: " + a.used + "u / " + a.reserved + "r / " + a.free + "f)"));
            }
          });

          document.querySelectorAll("[data-subnet-util]").forEach(function(el) {
            var id = el.dataset.subnetUtil;
            var hasChildren = el.dataset.hasChildren === "1";
            var u = hasChildren ? (data.utilAgg[id] || null) : (data.util[id] || null);
            el.textContent = "";
            el.classList.remove("subnet-stats-placeholder");
            if (!u || u.assignable_total <= 0) return;
            var pct = Math.round(u.assigned_assignable / u.assignable_total * 100);
            var warnThresh = 80;
            var critThresh = 95;
            var barClass = pct >= critThresh ? "util-bar-fill--crit" : (pct >= warnThresh ? "util-bar-fill--warn" : "");
            var pctClass = pct >= critThresh ? "danger" : (pct >= warnThresh ? "warning" : "");

            var info = document.createElement("span");
            info.className = "muted";
            info.textContent = "Assignable: " + u.assignable_total + " | Assigned: " + u.assigned_assignable + " | Unassigned: ";
            var bold = document.createElement("b");
            bold.textContent = String(u.unassigned_assignable);
            info.appendChild(bold);
            el.appendChild(info);

            if (hasChildren) {
              el.appendChild(document.createTextNode(" "));
              var note = document.createElement("span");
              note.className = "muted";
              note.textContent = "(incl. subnets)";
              el.appendChild(note);
            }

            el.appendChild(document.createTextNode(" "));
            var bar = document.createElement("span");
            bar.className = "util-bar";
            var fill = document.createElement("span");
            fill.className = "util-bar-fill " + barClass;
            fill.dataset.pct = String(pct);
            fill.style.width = Math.min(pct, 100) + "%";
            bar.appendChild(fill);
            el.appendChild(bar);

            el.appendChild(document.createTextNode(" "));
            var pctEl = document.createElement("span");
            if (pctClass) pctEl.className = pctClass;
            pctEl.textContent = pct + "%";
            el.appendChild(pctEl);
          });

          // Also populate map view util bars
          document.querySelectorAll("[data-map-util]").forEach(function(el) {
            var id = el.dataset.mapUtil;
            var u = data.utilAgg[id] || data.util[id] || null;
            var pct = 0;
            if (u && u.assignable_total > 0) {
              pct = Math.round(u.assigned_assignable / u.assignable_total * 100);
            } else {
              var d2 = data.agg[id] || {used:0,reserved:0,total:0};
              pct = d2.total > 0 ? Math.round((d2.used + d2.reserved) / Math.max(1, d2.total) * 100) : 0;
            }
            var cls = pct >= 90 ? "util-bar-fill--crit" : (pct >= 75 ? "util-bar-fill--warn" : "");
            var fill2 = el.querySelector(".util-bar-fill");
            if (fill2) {
              fill2.className = "util-bar-fill " + cls;
              fill2.dataset.pct = String(pct);
              fill2.style.width = pct + "%";
            }
            var pctSpan = el.querySelector(".map-pct");
            if (pctSpan) pctSpan.textContent = pct + "%";
          });
        })
        .catch(function() {
          document.querySelectorAll(".subnet-stats-placeholder").forEach(function(el) {
            el.textContent = "Error loading stats";
            el.classList.remove("subnet-stats-placeholder");
          });
        });
    }());

    /* ---- Contact browse overlay (#562) ---- */
    (function() {
      var overlay = null;
      var list = null;
      var input = null;
      var targetOwnerInput = null;
      var targetContactIdInput = null;
      var allContacts = null;

      function ensureOverlay() {
        if (overlay) return;
        overlay = document.createElement("div");
        overlay.id = "contact-browse-overlay";

        var box = document.createElement("div");
        box.className = "cb-box";

        var close = document.createElement("button");
        close.className = "cb-close";
        close.textContent = "\u00d7";
        close.title = "Close";
        close.addEventListener("click", hideOverlay);
        box.appendChild(close);

        input = document.createElement("input");
        input.id = "contact-browse-input";
        input.type = "text";
        input.placeholder = "Filter contacts\u2026";
        input.autocomplete = "off";
        input.addEventListener("input", filterList);
        box.appendChild(input);

        list = document.createElement("ul");
        list.id = "contact-browse-list";
        box.appendChild(list);

        overlay.appendChild(box);
        document.body.appendChild(overlay);

        overlay.addEventListener("click", function(e) {
          if (e.target === overlay) hideOverlay();
        });
      }

      function hideOverlay() {
        if (overlay) overlay.classList.remove("visible");
      }

      function filterList() {
        if (!allContacts) return;
        var q = input.value.trim().toLowerCase();
        while (list.firstChild) list.removeChild(list.firstChild);
        var filtered = allContacts.filter(function(c) {
          if (!q) return true;
          return (c.name && c.name.toLowerCase().indexOf(q) !== -1)
            || (c.email && c.email.toLowerCase().indexOf(q) !== -1)
            || (c.org && c.org.toLowerCase().indexOf(q) !== -1);
        });
        if (filtered.length === 0) {
          var empty = document.createElement("li");
          empty.className = "cb-empty";
          empty.textContent = q ? "No contacts match" : "No contacts found";
          list.appendChild(empty);
          return;
        }
        filtered.forEach(function(c) {
          var li = document.createElement("li");
          li.className = "cb-item";
          var name = document.createElement("div");
          name.className = "cb-item-name";
          name.textContent = c.name;
          li.appendChild(name);
          if (c.email || c.org) {
            var meta = document.createElement("div");
            meta.className = "cb-item-meta";
            var parts = [];
            if (c.email) parts.push(c.email);
            if (c.org) parts.push(c.org);
            meta.textContent = parts.join(" \u2014 ");
            li.appendChild(meta);
          }
          li.addEventListener("click", function() {
            if (targetOwnerInput) targetOwnerInput.value = c.name;
            if (targetContactIdInput) targetContactIdInput.value = c.id;
            hideOverlay();
          });
          list.appendChild(li);
        });
      }

      document.addEventListener("click", function(e) {
        var btn = e.target.closest(".contact-browse-btn");
        if (!btn) return;
        e.preventDefault();
        ensureOverlay();

        var wrap = btn.closest("label") || btn.closest(".contact-typeahead-wrap") || btn.parentElement;
        targetOwnerInput = wrap.querySelector("input[name=owner]");
        targetContactIdInput = wrap.querySelector("input[name=owner_contact_id]");

        input.value = "";
        while (list.firstChild) list.removeChild(list.firstChild);

        var loading = document.createElement("li");
        loading.className = "cb-empty";
        loading.textContent = "Loading\u2026";
        list.appendChild(loading);

        overlay.classList.add("visible");
        input.focus();

        if (allContacts) {
          filterList();
          return;
        }
        fetch("api.php?resource=contacts&limit=200", {credentials: "same-origin"})
          .then(function(r) { return r.json(); })
          .then(function(data) {
            allContacts = data.contacts || [];
            filterList();
          })
          .catch(function() {
            while (list.firstChild) list.removeChild(list.firstChild);
            var err = document.createElement("li");
            err.className = "cb-empty";
            err.textContent = "Error loading contacts";
            list.appendChild(err);
          });
      });

      document.addEventListener("keydown", function(e) {
        if (e.key === "Escape" && overlay && overlay.classList.contains("visible")) {
          hideOverlay();
        }
      });
    }());

    /* ---- Contact card popover (#561) ---- */
    (function() {
      var card = null;
      var cache = {};
      var activeCid = null;

      function ensureCard() {
        if (card) return;
        card = document.createElement("div");
        card.id = "contact-card";
        document.body.appendChild(card);
      }

      function hideCard() {
        if (card) card.classList.remove("visible");
      }

      function positionCard(trigger) {
        var r = trigger.getBoundingClientRect();
        var top = r.bottom + 6;
        var left = r.left;
        if (left + 320 > window.innerWidth) left = window.innerWidth - 328;
        if (left < 8) left = 8;
        if (top + 200 > window.innerHeight) top = r.top - 206;
        card.style.top = top + "px";
        card.style.left = left + "px";
      }

      function clearCard() {
        while (card.firstChild) card.removeChild(card.firstChild);
      }

      function addRow(label, value, isLink) {
        var row = document.createElement("div");
        row.className = "cc-row";
        var lbl = document.createElement("span");
        lbl.className = "cc-label";
        lbl.textContent = label;
        row.appendChild(lbl);
        if (isLink) {
          var a = document.createElement("a");
          a.href = "mailto:" + value;
          a.textContent = value;
          row.appendChild(a);
        } else {
          row.appendChild(document.createTextNode(value));
        }
        card.appendChild(row);
      }

      function renderCard(c) {
        clearCard();
        var closeBtn = document.createElement("button");
        closeBtn.type = "button";
        closeBtn.className = "cc-close";
        closeBtn.setAttribute("aria-label", "Close");
        closeBtn.textContent = "\u00d7";
        closeBtn.addEventListener("click", function(e) { e.stopPropagation(); hideCard(); });
        card.appendChild(closeBtn);
        var name = document.createElement("div");
        name.className = "cc-name";
        name.textContent = c.name;
        card.appendChild(name);
        if (c.email) addRow("Email", c.email, true);
        if (c.phone) addRow("Phone", c.phone, false);
        if (c.org) addRow("Org", c.org, false);
        if (c.note) addRow("Note", c.note, false);
      }

      function showMessage(msg) {
        clearCard();
        var row = document.createElement("div");
        row.className = "cc-row";
        row.textContent = msg;
        card.appendChild(row);
      }

      document.addEventListener("click", function(e) {
        var trigger = e.target.closest(".contact-card-trigger");
        if (!trigger) { hideCard(); return; }
        e.preventDefault();
        ensureCard();
        var cid = trigger.dataset.contactId;
        activeCid = cid;
        if (cache[cid]) {
          renderCard(cache[cid]);
          positionCard(trigger);
          card.classList.add("visible");
          return;
        }
        showMessage("Loading\u2026");
        positionCard(trigger);
        card.classList.add("visible");
        fetch("api.php?resource=contacts&id=" + encodeURIComponent(cid), {credentials: "same-origin"})
          .then(function(r) { return r.json(); })
          .then(function(data) {
            if (activeCid !== cid) return;
            if (data.contact) {
              cache[cid] = data.contact;
              renderCard(data.contact);
            } else {
              showMessage("Contact not found");
            }
          })
          .catch(function() { if (activeCid === cid) showMessage("Error loading contact"); });
      });

      document.addEventListener("keydown", function(e) {
        if (e.key === "Escape") hideCard();
      });
      window.addEventListener("scroll", hideCard, true);
    }());

    // --- Contact picker (v3.0.0 #563) ---
    document.querySelectorAll(".contact-picker").forEach(function(picker) {
      var contacts = [];
      try { contacts = JSON.parse(picker.getAttribute("data-contacts") || "[]"); } catch(e) {}
      var existing = [];
      try { existing = JSON.parse(picker.getAttribute("data-existing") || "[]"); } catch(e) {}
      var rows = picker.querySelector(".contact-picker-rows");
      var addBtn = picker.querySelector(".contact-picker-add");

      function addRow(contactId, role) {
        var row = document.createElement("div");
        row.className = "contact-picker-row row mt-4";

        var sel = document.createElement("select");
        sel.name = "contact_id[]";
        sel.setAttribute("aria-label", "Contact");
        var empty = document.createElement("option");
        empty.value = "";
        empty.textContent = "\u2014 Select \u2014";
        sel.appendChild(empty);
        contacts.forEach(function(c) {
          var opt = document.createElement("option");
          opt.value = c.id;
          opt.textContent = c.name + (c.email ? " (" + c.email + ")" : "");
          if (c.id === contactId) opt.selected = true;
          sel.appendChild(opt);
        });
        row.appendChild(sel);

        var roleInput = document.createElement("input");
        roleInput.name = "contact_role[]";
        roleInput.value = role || "";
        roleInput.placeholder = "Role (e.g. owner, admin)";
        roleInput.setAttribute("aria-label", "Contact role");
        roleInput.style.width = "160px";
        row.appendChild(document.createTextNode(" "));
        row.appendChild(roleInput);

        var removeBtn = document.createElement("button");
        removeBtn.type = "button";
        removeBtn.className = "button-danger btn-sm contact-picker-remove";
        removeBtn.setAttribute("aria-label", "Remove contact");
        removeBtn.textContent = "\u00d7";
        row.appendChild(document.createTextNode(" "));
        row.appendChild(removeBtn);

        rows.appendChild(row);
      }

      existing.forEach(function(c) { addRow(c.id, c.role); });
      if (addBtn) addBtn.addEventListener("click", function() { addRow(0, ""); });
      picker.addEventListener("click", function(e) {
        if (e.target.classList.contains("contact-picker-remove")) e.target.closest(".contact-picker-row").remove();
      });
      picker.addEventListener("reinit", function() {
        rows.textContent = "";
        var fresh = [];
        try { fresh = JSON.parse(picker.getAttribute("data-existing") || "[]"); } catch(ex) {}
        fresh.forEach(function(c) { addRow(c.id, c.role); });
      });
    });

  });

  // DHCP export card on dhcp_pool.php
  var dhcpChecklist = document.getElementById("dhcp-export-checklist");
  if (dhcpChecklist) {
    var dhcpTotal = parseInt(dhcpChecklist.dataset.total || "0", 10);
    function dhcpSelectedIds() {
      return Array.from(document.querySelectorAll(".dhcp-export-subnet-cb:checked")).map(function(cb) { return cb.value; });
    }
    function dhcpBuildUrl(format, preview) {
      var ids = dhcpSelectedIds();
      if (ids.length === 0) return null;
      var url = "export_dhcp.php?format=" + format;
      if (ids.length < dhcpTotal) {
        url += "&subnets=" + ids.join(",");
      }
      if (preview) url += "&preview=1";
      return url;
    }
    function dhcpUpdateCount() {
      var total = document.querySelectorAll(".dhcp-export-subnet-cb").length;
      var checked = dhcpSelectedIds().length;
      var el = document.getElementById("dhcp-export-count");
      if (el) el.textContent = checked === total ? "(all " + total + ")" : "(" + checked + " of " + total + ")";
    }
    document.querySelectorAll(".dhcp-export-subnet-cb").forEach(function(cb) {
      cb.addEventListener("change", dhcpUpdateCount);
    });
    var dhcpExportBtn = document.getElementById("dhcp-export-dhcpd");
    if (dhcpExportBtn) dhcpExportBtn.addEventListener("click", function() {
      var url = dhcpBuildUrl("dhcpd", false);
      if (!url) return;
      window.location.href = url;
    });
    var dhcpKeaBtn = document.getElementById("dhcp-export-kea");
    if (dhcpKeaBtn) dhcpKeaBtn.addEventListener("click", function() {
      var url = dhcpBuildUrl("kea", false);
      if (!url) return;
      window.location.href = url;
    });
    var dhcpPreviewBtn = document.getElementById("dhcp-preview-btn");
    var dhcpPreviewOut = document.getElementById("dhcp-preview-output");
    if (dhcpPreviewBtn && dhcpPreviewOut) {
      dhcpPreviewBtn.addEventListener("click", function() {
        if (dhcpPreviewOut.style.display !== "none") { dhcpPreviewOut.style.display = "none"; dhcpPreviewBtn.textContent = "Preview"; return; }
        var previewUrl = dhcpBuildUrl("dhcpd", true);
        if (!previewUrl) { dhcpPreviewOut.value = "Select at least one subnet."; dhcpPreviewOut.style.display = "block"; return; }
        dhcpPreviewOut.style.display = "block";
        dhcpPreviewOut.value = "Loading\u2026";
        dhcpPreviewBtn.textContent = "Hide Preview";
        fetch(previewUrl, {credentials: "same-origin"})
          .then(function(r) { return r.text(); })
          .then(function(t) { dhcpPreviewOut.value = t; })
          .catch(function() { dhcpPreviewOut.value = "Error loading preview."; });
      });
    }
  }

  // custom_fields.php — type-select → options-row + preview toggle
  var cfTypeSelect = document.getElementById("cf-type-select");
  if (cfTypeSelect) {
    var cfOptionsRow = document.getElementById("cf-options-row");
    var cfPreviews   = ["text", "number", "date", "boolean", "select"];
    function syncCfType() {
      var t = cfTypeSelect.value;
      cfPreviews.forEach(function(p) {
        var el = document.getElementById("cf-preview-" + p);
        if (el) el.hidden = (p !== t);
      });
      if (cfOptionsRow) cfOptionsRow.hidden = (t !== "select");
    }
    cfTypeSelect.addEventListener("change", syncCfType);
    syncCfType();
  }

  // TOTP backup code toggle on totp_verify.php
  var toggleBackup = document.getElementById("toggle-backup");
  if (toggleBackup) {
    toggleBackup.addEventListener("click", function(e) {
      e.preventDefault();
      var totpRow   = document.getElementById("totp-code-row");
      var backupRow = document.getElementById("backup-code-row");
      var hidden    = document.getElementById("use-backup-hidden");
      var totpInput = document.getElementById("totp-code-input");
      var backupInput = document.getElementById("backup-code-input");
      if (!totpRow || !backupRow || !hidden) return;
      var isBackup = hidden.value === "1";
      hidden.value = isBackup ? "0" : "1";
      totpRow.classList.toggle("hidden", !isBackup);
      backupRow.classList.toggle("hidden", isBackup);
      if (totpInput) { totpInput.required = isBackup; totpInput.disabled = !isBackup; }
      if (backupInput) { backupInput.required = !isBackup; backupInput.disabled = isBackup; }
      toggleBackup.textContent = isBackup ? "Use a backup code instead" : "Use authenticator app instead";
      if (!isBackup && backupInput) backupInput.focus();
      if (isBackup && totpInput) totpInput.focus();
    });
  }

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

})();

// backups.php modal handlers — CSP-safe event delegation on data-action attributes
(function () {
  var page = document.getElementById("backups-page");
  if (!page) return;

  function openModal(id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.style.display = "flex";
    el.addEventListener("click", function onBg(e) {
      if (e.target === el) { closeModal(id); el.removeEventListener("click", onBg); }
    });
    var first = el.querySelector("button, [href], [tabindex]");
    if (first) first.focus();
  }

  function closeModal(id) {
    var el = document.getElementById(id);
    if (el) el.style.display = "none";
  }

  document.addEventListener("click", function (e) {
    var btn = e.target.closest("[data-action]");
    if (!btn) return;
    var action = btn.getAttribute("data-action");

    if (action === "restore-info") {
      var phpPath = page.getAttribute("data-restore-script") || "/path/to/Simple-PHP-IPAM/restore.php";
      var rawArg  = btn.getAttribute("data-path") || btn.getAttribute("data-filename") || "<path/to/backup>";
      var fileArg = "'" + rawArg.replace(/'/g, "'\\''") + "'";
      var dry  = document.getElementById("restore-cmd-dry");
      var apply = document.getElementById("restore-cmd-apply");
      if (dry)   dry.textContent   = "php " + phpPath + " --from=" + fileArg + " --dry-run";
      if (apply) apply.textContent = "php " + phpPath + " --from=" + fileArg + " --force";
      openModal("restore-modal");
    } else if (action === "backup-delete") {
      var idEl = document.getElementById("delete-id");
      var bodyEl = document.getElementById("delete-modal-body");
      if (idEl) idEl.value = btn.getAttribute("data-id") || "";
      if (bodyEl) bodyEl.textContent =
        'Delete backup record and file "' + (btn.getAttribute("data-filename") || "") + '"? This cannot be undone.';
      openModal("delete-modal");
    } else if (action === "close-modal") {
      closeModal(btn.getAttribute("data-target") || "");
    }
  });

  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") {
      closeModal("restore-modal");
      closeModal("delete-modal");
    }
  });
})();

// TOTP enrollment QR code — runs after qrcode.min.js is loaded inline in the view
(function () {
  var qrEl = document.getElementById("totp-qr");
  if (!qrEl || typeof QRCode === "undefined") return;
  var uri = qrEl.getAttribute("data-uri");
  if (!uri) return;
  new QRCode(qrEl, {
    text: uri,
    width: 200,
    height: 200,
    colorDark: "#000000",
    colorLight: "#ffffff",
    correctLevel: QRCode.CorrectLevel.M
  });
})();

// uPlot dashboard growth chart
(function () {
  var el = document.getElementById('growth-chart');
  if (!el || typeof uPlot === 'undefined') return;

  var xs, ys;
  try {
    xs = JSON.parse(el.getAttribute('data-uplot-xs') || '[]');
    ys = JSON.parse(el.getAttribute('data-uplot-ys') || '[]');
  } catch (e) { return; }
  if (!xs.length) return;

  var hasData = ys.some(function(v) { return v !== 0; });
  if (!hasData) {
    /* All content below is hardcoded literal HTML — no user data, no XSS risk */
    var wrap  = document.createElement('div');
    wrap.className = 'chart-empty';
    var svgIcon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svgIcon.setAttribute('class', 'icon');
    svgIcon.setAttribute('aria-hidden', 'true');
    var useEl = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    useEl.setAttribute('href', 'assets/icons.svg#icon-reports');
    svgIcon.appendChild(useEl);
    var msg = document.createElement('p');
    msg.className = 'chart-empty__msg';
    msg.textContent = 'No address activity in the past 30 days';
    var cta = document.createElement('a');
    cta.className = 'chart-empty__cta';
    cta.href = 'subnets.php';
    cta.textContent = 'Go to Subnets';
    wrap.appendChild(svgIcon);
    wrap.appendChild(msg);
    wrap.appendChild(cta);
    el.appendChild(wrap);
    return;
  }

  var style  = getComputedStyle(document.documentElement);
  var stroke = (style.getPropertyValue('--link') || '#0077cc').trim();
  var fill   = stroke + '22';
  var muted  = (style.getPropertyValue('--muted') || '#6c757d').trim();

  var opts = {
    width:  640,
    height: 180,
    cursor: { drag: { x: false, y: false } },
    select: { show: false },
    legend: { show: false },
    series: [
      {},
      {
        label: 'New addresses',
        stroke: stroke,
        fill:   fill,
        width:  2
      }
    ],
    axes: [
      { gap: 8, size: 28, stroke: muted, ticks: { stroke: muted } },
      {
        gap: 8, size: 40, stroke: muted, ticks: { stroke: muted },
        values: function (_u, vals) {
          return vals.map(function (v) { return v == null ? '' : String(Math.round(v)); });
        }
      }
    ],
    scales: { x: { time: true } }
  };

  var u = new uPlot(opts, [xs, ys], el);

  // Hide stale cursor lines/points after mouse leaves the chart (#648)
  el.addEventListener('mouseleave', function () {
    u.over.dispatchEvent(new MouseEvent('mousemove', { bubbles: false, clientX: -9999, clientY: -9999 }));
  });

  function resizeChart() {
    var w = el.offsetWidth;
    if (w > 0) u.setSize({ width: w, height: 180 });
  }

  requestAnimationFrame(resizeChart);
  window.addEventListener('resize', resizeChart);
  document.addEventListener('ipam:sidebar-toggle', resizeChart);
}());

/* global IpamDrawer — right-side drawer (#517) */
var IpamDrawer = (function () {
    var drawer, overlay, titleEl, bodyEl, _lastFocus;

    function _getFocusable() {
        return Array.prototype.slice.call(
            drawer.querySelectorAll(
                'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])'
            )
        );
    }

    function _trapFocus(e) {
        if (e.key !== 'Tab') return;
        var focusable = _getFocusable();
        if (!focusable.length) { e.preventDefault(); return; }
        var first = focusable[0];
        var last  = focusable[focusable.length - 1];
        if (e.shiftKey) {
            if (document.activeElement === first) { e.preventDefault(); last.focus(); }
        } else {
            if (document.activeElement === last)  { e.preventDefault(); first.focus(); }
        }
    }

    function _init() {
        if (drawer) return;
        drawer = document.createElement('div');
        drawer.id = 'global-drawer';
        drawer.className = 'drawer';
        drawer.setAttribute('role', 'dialog');
        drawer.setAttribute('aria-modal', 'true');
        drawer.setAttribute('aria-hidden', 'true');
        drawer.setAttribute('aria-labelledby', 'global-drawer-title');
        drawer.setAttribute('tabindex', '-1');
        drawer.innerHTML =
            '<div class="drawer-header">' +
            '<span class="drawer-title" id="global-drawer-title"></span>' +
            '<button class="drawer-close" id="global-drawer-close" aria-label="Close drawer">' +
            '<svg class="icon" aria-hidden="true" focusable="false"><use href="#icon-x"></use></svg>' +
            '</button>' +
            '</div>' +
            '<div class="drawer-body" id="global-drawer-body"></div>';
        document.body.appendChild(drawer);

        overlay = document.createElement('div');
        overlay.className = 'drawer-overlay';
        document.body.appendChild(overlay);

        titleEl = document.getElementById('global-drawer-title');
        bodyEl  = document.getElementById('global-drawer-body');

        document.getElementById('global-drawer-close').addEventListener('click', close);
        overlay.addEventListener('click', close);

        document.addEventListener('keydown', function (e) {
            if (!drawer.classList.contains('is-open')) return;
            if (e.key === 'Escape') { close(); return; }
            _trapFocus(e);
        });
    }

    function open(title, tplId) {
        _init();
        _lastFocus = document.activeElement;
        var tpl = document.getElementById(tplId);
        bodyEl.innerHTML = tpl ? tpl.innerHTML : '';
        titleEl.textContent = title;
        overlay.classList.add('is-visible');
        drawer.classList.add('is-open');
        drawer.setAttribute('aria-hidden', 'false');
        var focusable = _getFocusable();
        if (focusable.length) focusable[0].focus();
        else drawer.focus();
    }

    function close() {
        if (!drawer) return;
        drawer.classList.remove('is-open');
        overlay.classList.remove('is-visible');
        drawer.setAttribute('aria-hidden', 'true');
        if (_lastFocus && _lastFocus.focus) _lastFocus.focus();
    }

    // Event delegation — handle [data-drawer-title] buttons (CSP-safe, no onclick)
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-drawer-title]') : null;
        if (!btn) return;
        // [data-drawer-url] elements have their own delegate below; don't double-handle.
        if (btn.hasAttribute('data-drawer-url')) return;
        e.preventDefault();
        open(
            btn.getAttribute('data-drawer-title') || '',
            btn.getAttribute('data-drawer-tpl')   || ''
        );
    });

    // Event delegation — handle [data-drawer-url] elements (#803).
    // Loads an HTML partial via fetch and injects it into the drawer body.
    //
    // Trust model: the partial is fetched from a same-origin admin-gated
    // endpoint (require_role('admin')) that emits server-rendered HTML with
    // every user-controlled value passed through e()/htmlspecialchars. This
    // matches the trust model of the existing template-id path above
    // (bodyEl.innerHTML = tpl.innerHTML). innerHTML is safe here because
    // (a) the source is our own server, (b) admins are already trusted,
    // (c) <script> tags injected via innerHTML do not execute in modern
    // browsers per the HTML spec.
    document.addEventListener('click', function (e) {
        var trigger = e.target.closest ? e.target.closest('[data-drawer-url]') : null;
        if (!trigger) return;
        if (e.target.closest && e.target.closest('#global-drawer')) return;  // already-open drawer
        e.preventDefault();
        _openFromUrl(trigger);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        var trigger = e.target.closest ? e.target.closest('[data-drawer-url]') : null;
        if (!trigger) return;
        var tag = (e.target.tagName || '').toLowerCase();
        if (tag === 'input' || tag === 'textarea' || tag === 'select' || tag === 'button') return;
        e.preventDefault();
        _openFromUrl(trigger);
    });

    var _drawerRequestSeq = 0;

    function _openFromUrl(trigger) {
        var url   = trigger.getAttribute('data-drawer-url');
        var title = trigger.getAttribute('data-drawer-title') || 'Details';
        if (!url) return;
        // CR feedback PR #1054: same-origin guard before injecting via innerHTML.
        // Comment promises "trusted same-origin partial" — enforce it here so
        // a future trigger with an absolute or user-influenced URL can't break
        // the contract and turn this into a DOM-XSS sink.
        try {
            var resolved = new URL(url, window.location.href);
            if (resolved.origin !== window.location.origin) return;
            url = resolved.toString();
        } catch (_e) {
            return;
        }
        open(title, '');
        var bodyEl = document.getElementById('global-drawer-body');
        if (!bodyEl) return;
        // Loading state — fully DOM-constructed, no untrusted input involved.
        while (bodyEl.firstChild) bodyEl.removeChild(bodyEl.firstChild);
        var loading = document.createElement('p');
        loading.className = 'muted';
        loading.textContent = 'Loading…';
        bodyEl.appendChild(loading);
        // CR feedback PR #1054: ignore stale fetches. Two rapid clicks (run A
        // then run B) could otherwise let A's slower response overwrite B's
        // already-rendered body, leaving the title and Verify/Delete form
        // out of sync. Each call increments the seq; only the latest renders.
        var requestSeq = ++_drawerRequestSeq;
        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text();
            })
            .then(function (html) {
                if (requestSeq !== _drawerRequestSeq) return;
                bodyEl.innerHTML = html;  // trusted same-origin partial (admin-gated, server-rendered); origin-checked above
                // Notify rebindable widgets that fresh DOM was injected so they
                // can re-attach event listeners (e.g. schedule-form freq gating).
                var ev = new CustomEvent('drawer:loaded', { detail: { drawer: drawer, body: bodyEl } });
                document.dispatchEvent(ev);
            })
            .catch(function (err) {
                if (requestSeq !== _drawerRequestSeq) return;
                // Error state — build via DOM, do not interpolate err.message into HTML.
                while (bodyEl.firstChild) bodyEl.removeChild(bodyEl.firstChild);
                var box = document.createElement('div');
                box.className = 'drawer-error';
                box.setAttribute('role', 'alert');
                var p = document.createElement('p');
                p.textContent = 'Could not load details: ' + ((err && err.message) ? err.message : 'unknown error');
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'action-pill';
                btn.id = 'drawer-retry';
                btn.textContent = 'Retry';
                btn.addEventListener('click', function () { _openFromUrl(trigger); });
                box.appendChild(p);
                box.appendChild(btn);
                bodyEl.appendChild(box);
            });
    }

    return { open: open, close: close };
}());

// Backup History drawer action handlers (#803).
// Verify and Delete are exposed inside the drawer body partial as
// <button data-action="verify|delete">. Download is a submit button bound
// via form="backup-run-download" to a sibling POST form (CSRF + dest_id + name).
(function () {
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('#backup-run-actions [data-action]') : null;
        if (!btn || btn.disabled) return;
        var action = btn.getAttribute('data-action');
        if (action !== 'verify' && action !== 'delete') return;
        e.preventDefault();
        var form = btn.closest('#backup-run-actions');
        if (!form) return;
        var runId = form.getAttribute('data-run-id');
        var csrfInput = form.querySelector('input[name=csrf]');
        var csrf = csrfInput ? csrfInput.value : '';
        if (!runId || !csrf) return;
        if (action === 'verify') _backupRunVerify(form, runId, csrf);
        else                     _backupRunDeletePromptThenSubmit(form, runId, csrf);
    });

    function _backupRunVerify(form, runId, csrf) {
        var resultEl = form.querySelector('#drawer-action-result');
        if (!resultEl) return;
        resultEl.hidden = false;
        resultEl.className = 'drawer-action-result';
        resultEl.textContent = 'Verifying…';
        var fd = new FormData();
        fd.append('csrf', csrf);
        fd.append('action', 'verify');
        fd.append('id', runId);
        fetch('backup_admin.php?tab=history', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json().then(function (j) { return [r.status, j]; }); })
            .then(function (pair) {
                var j = pair[1];
                if (j.ok) {
                    resultEl.classList.add('is-ok');
                    resultEl.textContent = 'Verified — sha256 matches (' + (j.actual || '').slice(0, 12) + '…).';
                } else if (j.expected && j.actual) {
                    resultEl.classList.add('is-error');
                    resultEl.textContent = 'Checksum mismatch — recorded ' + j.expected.slice(0, 12) + '… vs destination ' + j.actual.slice(0, 12) + '…';
                } else {
                    resultEl.classList.add('is-error');
                    resultEl.textContent = 'Verify failed: ' + (j.message || j.error || 'unknown');
                }
            })
            .catch(function (err) {
                resultEl.classList.add('is-error');
                resultEl.textContent = 'Verify request failed: ' + (err && err.message ? err.message : 'network error');
            });
    }

    function _backupRunDeletePromptThenSubmit(form, runId, csrf) {
        if (form.querySelector('.drawer-danger')) return;  // already prompting
        var danger = document.createElement('div');
        danger.className = 'drawer-danger';

        var label = document.createElement('label');
        label.textContent = 'Type DELETE to confirm. This removes the file at the destination AND the history row. This cannot be undone.';

        var input = document.createElement('input');
        input.type = 'text';
        input.id = 'drawer-delete-confirm';
        input.autocomplete = 'off';

        var btnRow = document.createElement('div');
        btnRow.style.marginTop = '0.5rem';
        btnRow.style.display = 'flex';
        btnRow.style.gap = '0.5rem';
        btnRow.style.justifyContent = 'flex-end';

        var cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'action-pill button-secondary';
        cancel.id = 'drawer-delete-cancel';
        cancel.textContent = 'Cancel';

        var arm = document.createElement('button');
        arm.type = 'button';
        arm.className = 'action-pill button-danger';
        arm.id = 'drawer-delete-arm';
        arm.textContent = 'Delete';
        arm.disabled = true;

        btnRow.appendChild(cancel);
        btnRow.appendChild(arm);
        danger.appendChild(label);
        danger.appendChild(input);
        danger.appendChild(btnRow);
        form.appendChild(danger);
        input.focus();

        input.addEventListener('input', function () { arm.disabled = (input.value !== 'DELETE'); });
        cancel.addEventListener('click', function () { danger.remove(); });
        arm.addEventListener('click', function () {
            // CR feedback PR #1054: lock destructive controls before fetch.
            // The remote-delete path is non-idempotent (DELETE on the storage
            // backend); a fast double-click could fire two requests, the
            // second of which fails because the artifact is already gone, and
            // the user sees a confusing error after the first delete already
            // succeeded. Disable arm + cancel for the in-flight window; only
            // re-enable on failure paths.
            arm.disabled    = true;
            cancel.disabled = true;
            var resultEl = form.querySelector('#drawer-action-result');
            if (resultEl) {
                resultEl.hidden = false;
                resultEl.className = 'drawer-action-result';
                resultEl.textContent = 'Deleting…';
            }
            var fd = new FormData();
            fd.append('csrf', csrf);
            fd.append('action', 'delete');
            fd.append('id', runId);
            fd.append('confirm', 'DELETE');
            fetch('backup_admin.php?tab=history', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json().then(function (j) { return [r.status, j]; }); })
                .then(function (pair) {
                    var status = pair[0], j = pair[1];
                    if (j.ok) {
                        if (resultEl) {
                            resultEl.classList.add('is-ok');
                            resultEl.textContent = 'Deleted.';
                        }
                        var row = document.querySelector('.history-row[data-run-id="' + runId + '"]');
                        if (row && row.parentNode) row.parentNode.removeChild(row);
                        setTimeout(function () { if (window.IpamDrawer) IpamDrawer.close(); }, 600);
                    } else {
                        if (resultEl) {
                            resultEl.classList.add('is-error');
                            resultEl.textContent = 'Delete failed (' + status + '): ' + (j.message || j.error || 'unknown');
                        }
                        // Unlock for retry once destination is reachable.
                        cancel.disabled = false;
                        arm.disabled    = (input.value !== 'DELETE');
                    }
                })
                .catch(function (err) {
                    if (resultEl) {
                        resultEl.classList.add('is-error');
                        resultEl.textContent = 'Delete request failed: ' + (err && err.message ? err.message : 'network error');
                    }
                    cancel.disabled = false;
                    arm.disabled    = (input.value !== 'DELETE');
                });
        });
    }
}());

// IpamVirtualTable — utility for future viewport-based row rendering
function IpamVirtualTable(containerId, rows, rowHeight, renderRow) {
    var container = document.getElementById(containerId);
    if (!container) return;
    var scroller = container.querySelector(".vt-scroll");
    var tbody = container.querySelector("tbody");
    if (!scroller || !tbody) return;

    var OVERSCAN = 5;
    scroller.style.height = (rows.length * rowHeight) + "px";

    function render() {
        var scrollTop = container.scrollTop;
        var clientHeight = container.clientHeight;
        var start = Math.max(0, Math.floor(scrollTop / rowHeight) - OVERSCAN);
        var end = Math.min(rows.length, Math.ceil((scrollTop + clientHeight) / rowHeight) + OVERSCAN);
        tbody.style.transform = "translateY(" + (start * rowHeight) + "px)";
        while (tbody.firstChild) tbody.removeChild(tbody.firstChild);
        for (var i = start; i < end; i++) {
            tbody.appendChild(renderRow(rows[i], i));
        }
    }

    container.addEventListener("scroll", render, { passive: true });
    render();
}

// ─── Site filter strip (#629) ─────────────────────────────────────────────────
// Pill-based client-side filter for subnets.php.
// Filter state is stored in sessionStorage so a page reload restores the last
// selection, but navigating away or opening a new tab starts fresh.
(function () {
    var strip = document.getElementById("site-filter-strip");
    if (!strip) return;

    var STORAGE_KEY = "ipam_subnet_site_filter";

    // Restore persisted filter
    var saved = "";
    try { saved = sessionStorage.getItem(STORAGE_KEY) || ""; } catch (e) { saved = ""; }

    // Collect all subnet-node elements (top-level only; children inherit via parent hiding)
    // We show/hide the outermost .subnet-node for each root by checking data-site-id.
    // For nested nodes we must also hide when the root is filtered.

    function getActiveSiteIds(filterVal) {
        // Returns a Set of numeric site IDs that should be SHOWN, or null for "all".
        if (!filterVal || filterVal === "all") return null;
        if (filterVal.indexOf("region:") === 0) {
            // All child site IDs of this region are embedded as data-filter-site on the child pills
            var regionId = filterVal.split(":")[1];
            var region = strip.querySelector("[data-region-id='" + regionId + "']");
            if (!region) return null;
            var ids = new Set();
            region.querySelectorAll(".site-filter-pill--child[data-filter-site]").forEach(function (pill) {
                var v = pill.dataset.filterSite;
                if (v && v !== "all" && v.indexOf("region:") < 0) ids.add(parseInt(v, 10));
            });
            // also include the region's own site ID if it has self_used subnets (child pill "(all)" uses plain int)
            // Those are already captured above since they use an integer data-filter-site.
            return ids.size > 0 ? ids : null;
        }
        var n = parseInt(filterVal, 10);
        if (isNaN(n) || n <= 0) return null;
        return new Set([n]);
    }

    function applyFilter(filterVal) {
        var activeSiteIds = getActiveSiteIds(filterVal);
        var isAll = (activeSiteIds === null);

        // Update pill aria-pressed states
        strip.querySelectorAll(".site-filter-pill").forEach(function (pill) {
            var pv = pill.dataset.filterSite || "";
            var active = (isAll && pv === "all") || (!isAll && pv === filterVal);
            pill.setAttribute("aria-pressed", active ? "true" : "false");
            pill.classList.toggle("site-filter-pill--active", active);
        });

        // Show/hide subnet-node elements; operate on ALL .subnet-node in the list view.
        // Child subnets are DOM-nested inside parent subnet-node divs, so we must also
        // keep a parent visible when any of its descendants match the active site filter.
        document.querySelectorAll("#subnet-list-view .subnet-node").forEach(function (node) {
            if (isAll) {
                node.classList.remove("subnet-node--filtered");
                return;
            }
            var siteId = parseInt(node.dataset.siteId || "0", 10);
            var selfMatch = activeSiteIds !== null && activeSiteIds.has(siteId);
            var childMatch = !selfMatch && Array.from(node.querySelectorAll(".subnet-node[data-site-id]")).some(function (desc) {
                return activeSiteIds !== null && activeSiteIds.has(parseInt(desc.dataset.siteId || "0", 10));
            });
            node.classList.toggle("subnet-node--filtered", !selfMatch && !childMatch);
        });

        // Hide site-group containers that now have no visible subnet nodes
        document.querySelectorAll("#subnet-list-view .site-group").forEach(function (sg) {
            if (isAll) {
                sg.classList.remove("site-group--filter-empty");
                return;
            }
            var visible = sg.querySelectorAll(".subnet-node:not(.subnet-node--filtered)").length > 0;
            sg.classList.toggle("site-group--filter-empty", !visible);
        });

        // Persist
        try { sessionStorage.setItem(STORAGE_KEY, filterVal || "all"); } catch (e) {}
    }

    // Handle pill clicks (and keyboard: Enter / Space)
    strip.addEventListener("click", function (e) {
        var pill = e.target.closest(".site-filter-pill");
        if (!pill) return;
        e.preventDefault();

        // Region toggle button: toggle collapsed children; do not change the tree filter
        var regionId = pill.dataset.regionToggle;
        if (regionId !== undefined) {
            var expanded = pill.getAttribute("aria-expanded") !== "false";
            pill.setAttribute("aria-expanded", expanded ? "false" : "true");
            var childrenWrap = strip.querySelector("[data-region-children='" + regionId + "']");
            if (childrenWrap) childrenWrap.classList.toggle("is-collapsed", expanded);
            // Also apply a filter for the whole region when clicking the region header pill
            applyFilter(pill.dataset.filterSite || "all");
            return;
        }

        applyFilter(pill.dataset.filterSite || "all");
    });

    strip.addEventListener("keydown", function (e) {
        if (e.key === "Enter" || e.key === " ") {
            var pill = e.target.closest(".site-filter-pill");
            if (pill) { e.preventDefault(); pill.click(); }
        }
    });

    // Apply saved state on load
    applyFilter(saved || "all");
}());


// Bulk-select bar for addresses table
(function () {
    var bar = document.getElementById("bulk-bar");
    if (!bar) return;
    var countEl = document.getElementById("bulk-bar-count");
    var linkEl = document.getElementById("bulk-bar-link");
    var subnetId = parseInt(bar.getAttribute("data-subnet-id") || "0", 10);

    function updateBar() {
        var checked = document.querySelectorAll(".row-select:checked");
        var n = checked.length;
        bar.classList.toggle("is-visible", n > 0);
        if (countEl) countEl.textContent = n + " selected";
        if (linkEl && subnetId > 0) {
            var ids = [];
            for (var i = 0; i < checked.length; i++) ids.push(checked[i].value);
            linkEl.href = "bulk_update.php?subnet_id=" + encodeURIComponent(subnetId) + "&ids=" + ids.join(",");
        }
    }

    var selectAll = document.getElementById("select-all-addresses");
    if (selectAll) {
        selectAll.addEventListener("change", function () {
            var boxes = document.querySelectorAll(".row-select");
            for (var i = 0; i < boxes.length; i++) boxes[i].checked = selectAll.checked;
            updateBar();
        });
    }

    document.addEventListener("change", function (e) {
        if (e.target && e.target.classList.contains("row-select")) updateBar();
    });
}());

// ── Address page: site → subnet cascading filter ──────────────────────────────
(function () {
    var siteSelect = document.getElementById("addrSiteFilter");
    var subnetSelect = document.querySelector("select[name=\"subnet_id\"]");
    if (!siteSelect || !subnetSelect) return;

    // Snapshot all options before any filtering occurs
    var allOpts = Array.prototype.slice.call(subnetSelect.options);

    function filterBySite(siteId) {
        var prevVal = subnetSelect.value;
        allOpts.forEach(function (opt) {
            // Always show the placeholder "-- Select --" (value 0 or empty)
            if (!opt.value || opt.value === "0") { opt.hidden = false; return; }
            opt.hidden = siteId > 0 && parseInt(opt.getAttribute("data-site-id") || "0", 10) !== siteId;
        });
        // If the currently-selected subnet is now hidden, reset it
        if (prevVal && prevVal !== "0") {
            var sel = subnetSelect.querySelector("option[value=\"" + prevVal + "\"]");
            if (sel && sel.hidden) subnetSelect.value = "0";
        }
    }

    siteSelect.addEventListener("change", function () {
        filterBySite(parseInt(this.value, 10) || 0);
    });

    // Apply filter on page load so a pre-selected site narrows the list immediately
    var initSite = parseInt(siteSelect.value, 10) || 0;
    if (initSite > 0) filterBySite(initSite);
}());

// Webhooks page — drawer + gen_secret + test-fire (#645 CSP fix, was inline IIFE)
(function () {
  var overlay   = document.getElementById('wh-form-overlay');
  if (!overlay) return; // not on webhooks.php

  var drawer    = document.getElementById('wh-form-drawer');
  var title     = document.getElementById('wh-drawer-title');
  var form      = document.getElementById('wh-form');
  var fAction   = document.getElementById('wh-form-action');
  var fId       = document.getElementById('wh-form-id');
  var fName     = document.getElementById('wh-f-name');
  var fUrl      = document.getElementById('wh-f-url');
  var fSecret   = document.getElementById('wh-f-secret');
  var cbs       = document.querySelectorAll('.wh-event-cb');
  var testPanel = document.getElementById('wh-test-panel');
  var testResult = document.getElementById('wh-test-result');

  function openDrawer() {
    overlay.style.display = 'block';
    drawer.style.display  = 'block';
  }
  function closeDrawer() {
    overlay.style.display = 'none';
    drawer.style.display  = 'none';
  }

  document.getElementById('add-wh-btn').addEventListener('click', function () {
    title.textContent = 'Add webhook';
    fAction.value = 'create';
    fId.value = '';
    form.reset();
    testPanel.style.display = 'none';
    openDrawer();
  });

  document.querySelectorAll('.wh-edit-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      title.textContent = 'Edit webhook';
      fAction.value = 'edit';
      fId.value     = btn.dataset.id || '';
      fName.value   = btn.dataset.name || '';
      fUrl.value    = btn.dataset.url  || '';
      var evts = JSON.parse(btn.dataset.events || '[]');
      cbs.forEach(function (cb) { cb.checked = evts.includes(cb.value); });
      testPanel.style.display = 'none';
      openDrawer();
    });
  });

  document.getElementById('wh-drawer-close').addEventListener('click', closeDrawer);
  document.getElementById('wh-drawer-close2').addEventListener('click', closeDrawer);
  overlay.addEventListener('click', closeDrawer);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && overlay.style.display === 'block') closeDrawer(); });

  document.getElementById('wh-gen-secret').addEventListener('click', function () {
    var fd = new FormData();
    fd.append('csrf', document.querySelector('input[name=csrf]').value);
    fd.append('action', 'gen_secret');
    fetch('webhooks.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d.secret) fSecret.value = d.secret; });
  });

  document.querySelectorAll('.wh-testfire-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      title.textContent = 'Test fire — ' + (btn.dataset.name || '');
      fAction.value = 'test_fire';
      fId.value     = btn.dataset.id || '';
      testPanel.style.display = 'block';
      testResult.innerHTML    = '<span class="muted">Sending…</span>';
      openDrawer();

      var fd = new FormData();
      fd.append('csrf', document.querySelector('input[name=csrf]').value);
      fd.append('action', 'test_fire');
      fd.append('id', btn.dataset.id || '');
      fetch('webhooks.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          var colour = d.ok ? '#065f46' : '#991b1b';
          var status = d.status ? 'HTTP ' + d.status : 'No response';
          function escHtml(s) {
            var t = document.createElement('div');
            t.textContent = typeof s === 'string' ? s : String(s);
            return t.innerHTML;
          }
          testResult.innerHTML =
            '<p style="color:' + colour + ';font-weight:600">' +
              (d.ok ? '✓ Delivered' : '✗ Failed') + ' — ' + status + '</p>' +
            (d.error ? '<p class="muted">Error: ' + escHtml(d.error) + '</p>' : '') +
            '<p class="muted" style="font-size:.8rem">Signature: <code>' + escHtml(d.signature || '') + '</code></p>' +
            (d.body ? '<pre style="font-size:.75rem;overflow-x:auto;max-height:120px">' + escHtml(d.body.substring(0, 500)) + '</pre>' : '');
        })
        .catch(function () { testResult.textContent = 'Request failed.'; });
    });
  });
}());

// Addresses page — device → interface dropdown cascade (#645 CSP fix, was inline IIFE)
(function () {
  var el = document.getElementById('iface-data');
  if (!el) return; // not on addresses.php or no devices

  var ifaceMap;
  try { ifaceMap = JSON.parse(el.getAttribute('data-ifaces') || '{}'); }
  catch (e) { ifaceMap = {}; }

  document.addEventListener('change', function (e) {
    var sel = e.target;
    if (!sel || !sel.classList.contains('addr-device-select')) return;
    var targetId = sel.getAttribute('data-iface-target');
    var target = targetId ? document.getElementById(targetId) : null;
    if (!target) return;
    var devId = parseInt(sel.value, 10);
    var ifaces = (devId && ifaceMap[devId]) ? ifaceMap[devId] : [];
    target.innerHTML = '<option value="0">(none)</option>';
    ifaces.forEach(function (iface) {
      var opt = document.createElement('option');
      opt.value = iface.id;
      opt.textContent = iface.name;
      target.appendChild(opt);
    });
  });
}());

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

// ── Passkey verify page (#689) ──────────────────────────────────────────────
(function () {
  var btn = document.getElementById('btn-passkey');
  if (!btn) return;

  var status = document.getElementById('passkey-status');

  function b64url(buf) {
    return btoa(String.fromCharCode.apply(null, Array.prototype.slice.call(new Uint8Array(buf))))
      .replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
  }

  function b64ToBytes(b64) {
    return Uint8Array.from(atob(b64.replace(/-/g, '+').replace(/_/g, '/')), function (c) { return c.charCodeAt(0); });
  }

  function verify() {
    var optsRaw = btn.getAttribute('data-assert-opts');
    if (!optsRaw) { return; }
    var opts;
    try { opts = JSON.parse(optsRaw); } catch (e) { return; }

    btn.disabled = true;
    if (status) status.textContent = 'Waiting for authenticator…';

    opts.challenge = b64ToBytes(opts.challenge);
    if (opts.allowCredentials) {
      opts.allowCredentials = opts.allowCredentials.map(function (c) {
        return Object.assign({}, c, { id: b64ToBytes(c.id) });
      });
    }

    navigator.credentials.get({ publicKey: opts }).then(function (cred) {
      document.getElementById('f-clientDataJSON').value    = b64url(cred.response.clientDataJSON);
      document.getElementById('f-authenticatorData').value = b64url(cred.response.authenticatorData);
      document.getElementById('f-signature').value         = b64url(cred.response.signature);
      document.getElementById('f-credentialId').value      = b64url(cred.rawId);
      document.getElementById('passkey-form').submit();
    }).catch(function () {
      if (status) status.textContent = 'Passkey prompt cancelled or failed. Try again.';
      btn.disabled = false;
    });
  }

  btn.addEventListener('click', verify);
  setTimeout(verify, 300);
}());

// ── Step-up auth prompt (#1107) ────────────────────────────────────────────
// Drives views/_step_up_prompt.php: hides/shows method-specific sections
// when the user changes the method dropdown, and runs the WebAuthn
// navigator.credentials.get() flow when the user clicks "Verify with passkey".
(function () {
  var prompt = document.querySelector('[data-step-up-prompt]');
  if (!prompt) return;

  var methodEl = prompt.querySelector('[data-step-up-method]');
  var sections = prompt.querySelectorAll('[data-step-up-section]');

  function showSection(method) {
    for (var i = 0; i < sections.length; i++) {
      var sec = sections[i];
      if (sec.getAttribute('data-step-up-section') === method) {
        sec.removeAttribute('hidden');
      } else {
        sec.setAttribute('hidden', '');
      }
    }
  }

  if (methodEl && methodEl.tagName === 'SELECT') {
    methodEl.addEventListener('change', function () { showSection(methodEl.value); });
    showSection(methodEl.value);
  }

  var waBtn = document.getElementById('step-up-webauthn-btn');
  if (!waBtn) return;

  var waStatus = document.getElementById('step-up-webauthn-status');

  function b64url(buf) {
    return btoa(String.fromCharCode.apply(null, Array.prototype.slice.call(new Uint8Array(buf))))
      .replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
  }
  function b64ToBytes(b64) {
    return Uint8Array.from(atob(b64.replace(/-/g, '+').replace(/_/g, '/')), function (c) { return c.charCodeAt(0); });
  }

  function setStatus(msg) { if (waStatus) waStatus.textContent = msg; }

  function runWebAuthn() {
    // Defensive guards (CodeRabbit #1116): WebAuthn may be unavailable
    // (older browsers, insecure context); the prompt's hidden form fields
    // may be missing if the partial was misincluded; the dropdown may be
    // absent in single-method renderings. Validate everything before
    // mutating state, and never leave waBtn disabled on an early return.
    if (!window.PublicKeyCredential ||
        !navigator.credentials ||
        typeof navigator.credentials.get !== 'function') {
      setStatus('Passkeys are not supported in this browser. Choose another method.');
      return;
    }

    var cdj = document.getElementById('step-up-cdj');
    var ad  = document.getElementById('step-up-ad');
    var sig = document.getElementById('step-up-sig');
    var cid = document.getElementById('step-up-cid');
    var form = document.getElementById('step-up-form');
    if (!cdj || !ad || !sig || !cid || !form) {
      setStatus('Step-up form is missing required fields. Reload the page.');
      return;
    }

    var optsRaw = waBtn.getAttribute('data-step-up-webauthn-opts');
    if (!optsRaw) { setStatus('No passkey challenge available. Reload the page.'); return; }

    var opts;
    try {
      opts = JSON.parse(optsRaw);
      opts.challenge = b64ToBytes(opts.challenge);
      if (opts.allowCredentials) {
        opts.allowCredentials = opts.allowCredentials.map(function (c) {
          return Object.assign({}, c, { id: b64ToBytes(c.id) });
        });
      }
    } catch (e) {
      setStatus('Bad challenge data. Reload the page.');
      return;
    }

    waBtn.disabled = true;
    setStatus('Waiting for authenticator…');

    try {
      navigator.credentials.get({ publicKey: opts }).then(function (cred) {
        if (!cred || !cred.response || !cred.rawId) {
          setStatus('Passkey response was incomplete. Try again.');
          waBtn.disabled = false;
          return;
        }
        cdj.value = b64url(cred.response.clientDataJSON);
        ad.value  = b64url(cred.response.authenticatorData);
        sig.value = b64url(cred.response.signature);
        cid.value = b64url(cred.rawId);
        // Force the chosen method to webauthn even if the dropdown is on
        // something else.
        if (methodEl) methodEl.value = 'webauthn';
        form.submit();
      }).catch(function () {
        setStatus('Passkey prompt cancelled or failed. Try again.');
        waBtn.disabled = false;
      });
    } catch (e) {
      setStatus('Passkey prompt failed to start. Try again.');
      waBtn.disabled = false;
    }
  }

  waBtn.addEventListener('click', runWebAuthn);
}());

// ── Passkey registration (change_password.php #passkeys) (#688) ─────────────
(function () {
  var btn = document.getElementById('btn-add-passkey');
  if (!btn) return;

  var statusEl = document.getElementById('passkey-add-status');

  function b64url(buf) {
    return btoa(String.fromCharCode.apply(null, Array.prototype.slice.call(new Uint8Array(buf))))
      .replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
  }

  function b64ToBytes(b64) {
    return Uint8Array.from(atob(b64.replace(/-/g, '+').replace(/_/g, '/')), function (c) { return c.charCodeAt(0); });
  }

  function setStatus(msg) {
    if (!statusEl) return;
    statusEl.style.display = 'inline';
    statusEl.textContent = msg;
  }

  function register() {
    btn.disabled = true;
    setStatus('Generating challenge…');

    var csrf = (document.querySelector('[name=csrf]') || {}).value || '';

    fetch('passkey_register.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ action: 'get_challenge', csrf: csrf })
    }).then(function (r) { return r.json(); }).then(function (data) {
      if (!data.ok) throw new Error(data.error || 'Challenge failed');

      var opts = data.options;
      opts.challenge = b64ToBytes(opts.challenge);
      opts.user.id   = b64ToBytes(opts.user.id);
      if (opts.excludeCredentials) {
        opts.excludeCredentials = opts.excludeCredentials.map(function (c) {
          return Object.assign({}, c, { id: b64ToBytes(c.id) });
        });
      }

      setStatus('Waiting for authenticator…');
      return navigator.credentials.create({ publicKey: opts });
    }).then(function (cred) {
      var credName = btn.getAttribute('data-default-name') || 'Passkey';
      return fetch('passkey_register.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
          action:            'complete',
          csrf:              (document.querySelector('[name=csrf]') || {}).value || '',
          clientDataJSON:    b64url(cred.response.clientDataJSON),
          attestationObject: b64url(cred.response.attestationObject),
          name:              credName
        })
      }).then(function (r) { return r.json(); }).then(function (result) {
        if (!result.ok) throw new Error(result.error || 'Registration failed');
        setStatus('Passkey registered!');
        setTimeout(function () { window.location.reload(); }, 800);
      });
    }).catch(function (err) {
      setStatus('Registration failed: ' + err.message);
      btn.disabled = false;
    });
  }

  btn.addEventListener('click', register);
}());

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

/* === v3.17 destinations admin === */
(function () {
    // Selector list must include every trigger this IIFE wires up. Missing
    // [data-run-now-target] here used to short-circuit the entire block on
    // the unified Backup tab (#1040), leaving the Run-now button inert.
    if (!document.querySelector('.destination-form, [data-edit-destination], [data-test-destination], [data-run-now], [data-run-now-target]')) {
        return;
    }

    function getCsrf() {
        var el = document.querySelector('input[name="csrf"]');
        return el ? el.value : '';
    }

    // Edit drawer toggles for destinations and schedules (#778, #780).
    function bindToggle(triggerAttr, cancelAttr) {
        document.querySelectorAll('[' + triggerAttr + ']').forEach(function (btn) {
            btn.addEventListener('click', function (ev) {
                ev.preventDefault();
                var row = document.getElementById(btn.getAttribute('aria-controls'));
                if (!row) return;
                var open = row.hasAttribute('hidden');
                if (open) {
                    row.removeAttribute('hidden');
                    btn.setAttribute('aria-expanded', 'true');
                    var firstInput = row.querySelector('input:not([type=hidden]):not([disabled]), select, textarea');
                    if (firstInput) firstInput.focus();
                } else {
                    row.setAttribute('hidden', '');
                    btn.setAttribute('aria-expanded', 'false');
                }
            });
        });
        document.querySelectorAll('[' + cancelAttr + ']').forEach(function (btn) {
            btn.addEventListener('click', function (ev) {
                ev.preventDefault();
                var id = btn.getAttribute(cancelAttr);
                var row = btn.closest('tr');
                var trigger = document.querySelector('[' + triggerAttr + '="' + id + '"]');
                if (row) row.setAttribute('hidden', '');
                if (trigger) trigger.setAttribute('aria-expanded', 'false');
            });
        });
    }
    bindToggle('data-edit-destination', 'data-edit-destination-cancel');
    bindToggle('data-edit-schedule',    'data-edit-schedule-cancel');

    // #781: hide schedule fields that don't apply to the chosen frequency.
    // Matrix: hourly = none; daily = time_of_day; weekly = time_of_day + day_of_week;
    // monthly = time_of_day + day_of_month. Hidden inputs are also disabled so that
    // the browser drops them from the submitted payload (defence-in-depth: server
    // also normalises non-applicable fields to NULL).
    function applyFreqGating(form) {
        var sel = form.querySelector('select[name="frequency"]');
        if (!sel) return;
        var freq = sel.value;
        var visible = { time_of_day: false, day_of_week: false, day_of_month: false };
        if (freq === 'daily')   { visible.time_of_day = true; }
        if (freq === 'weekly')  { visible.time_of_day = true; visible.day_of_week  = true; }
        if (freq === 'monthly') { visible.time_of_day = true; visible.day_of_month = true; }
        form.querySelectorAll('[data-freq-field]').forEach(function (el) {
            var key = el.getAttribute('data-freq-field');
            var show = !!visible[key];
            el.hidden = !show;
            el.querySelectorAll('input, select, textarea').forEach(function (i) {
                i.disabled = !show;
            });
        });
    }
    function bindScheduleForm(form) {
        if (!form || form.dataset.freqBound === '1') return;
        var sel = form.querySelector('select[name="frequency"]');
        if (!sel) return;
        sel.addEventListener('change', function () { applyFreqGating(form); });
        applyFreqGating(form);
        form.dataset.freqBound = '1';
    }
    document.querySelectorAll('form.schedule-form').forEach(bindScheduleForm);
    // Drawer-injected schedule forms need re-binding (#803).
    document.addEventListener('drawer:loaded', function (e) {
        var body = (e && e.detail && e.detail.body) || document;
        body.querySelectorAll('form.schedule-form').forEach(bindScheduleForm);
    });

    // Type selector swap. Hidden fieldsets must also disable their inputs so that
    // HTML5 validation on `required` inputs in non-active fieldsets does not block
    // form submission (otherwise the browser silently rejects the submit).
    var sel = document.querySelector('[data-destination-type-selector]');
    if (sel) {
        var updateFieldset = function () {
            document.querySelectorAll('.destination-fields').forEach(function (fs) {
                var active = fs.dataset.type === sel.value;
                fs.hidden = !active;
                fs.querySelectorAll('input, textarea, select').forEach(function (el) {
                    el.disabled = !active;
                });
            });
        };
        sel.addEventListener('change', updateFieldset);
        updateFieldset();
    }

    // Test connection
    document.querySelectorAll('[data-test-destination]').forEach(function (btn) {
        btn.addEventListener('click', function (ev) {
            ev.preventDefault();
            var id = btn.dataset.testDestination;
            btn.disabled = true;
            var orig = btn.textContent;
            btn.textContent = 'Testing…';
            var fd = new FormData();
            fd.append('csrf', getCsrf());
            fd.append('id', id);
            fetch('test_destination.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (j.ok) {
                        btn.textContent = '✓ ' + (j.message || 'connected') + (j.latency_ms != null ? ' (' + j.latency_ms + 'ms)' : '');
                        btn.classList.add('button-success');
                    } else {
                        btn.textContent = '✗ ' + (j.message || 'failed');
                        btn.classList.add('button-danger');
                    }
                })
                .catch(function () {
                    btn.textContent = '✗ network error';
                    btn.classList.add('button-danger');
                })
                .finally(function () {
                    setTimeout(function () {
                        btn.disabled = false;
                        btn.textContent = orig;
                        btn.classList.remove('button-success', 'button-danger');
                    }, 5000);
                });
        });
    });

    // Run now
    // v3.21.0 #797 Backup tab — run-now button paired with a destination
    // <select>. Result text is rendered into the configured aria-live span.
    document.querySelectorAll('[data-run-now-target]').forEach(function (btn) {
        btn.addEventListener('click', function (ev) {
            ev.preventDefault();
            var sel = document.getElementById(btn.dataset.runNowTarget);
            var out = document.getElementById(btn.dataset.runNowResult || '');
            if (!sel) return;
            var destId = sel.value;
            if (!destId || destId === '0') return;
            if (!confirm('Run backup now for the selected destination?')) return;
            btn.disabled = true;
            sel.disabled = true;
            var orig = btn.textContent;
            btn.textContent = 'Running…';
            if (out) { out.textContent = ''; out.classList.remove('success', 'danger'); }
            var fd = new FormData();
            fd.append('csrf', getCsrf());
            fd.append('destination_id', destId);
            fetch('run_backup_now.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (out) {
                        if (j.ok) {
                            out.textContent = '✓ ' + j.filename + ' (' + j.size + ' bytes)';
                            out.classList.add('success');
                        } else {
                            out.textContent = '✗ ' + (j.message || 'failed');
                            out.classList.add('danger');
                        }
                    }
                })
                .catch(function () {
                    if (out) {
                        out.textContent = '✗ network error';
                        out.classList.add('danger');
                    }
                })
                .finally(function () {
                    btn.disabled = false;
                    sel.disabled = false;
                    btn.textContent = orig;
                });
        });
    });

    document.querySelectorAll('[data-run-now]').forEach(function (btn) {
        btn.addEventListener('click', function (ev) {
            ev.preventDefault();
            if (!confirm('Run backup now for this destination?')) return;
            var destId = btn.dataset.runNow;
            btn.disabled = true;
            var orig = btn.textContent;
            btn.textContent = 'Running…';
            var fd = new FormData();
            fd.append('csrf', getCsrf());
            fd.append('destination_id', destId);
            fetch('run_backup_now.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (j.ok) {
                        btn.textContent = '✓ ' + j.filename + ' (' + j.size + ' bytes)';
                        btn.classList.add('button-success');
                    } else {
                        btn.textContent = '✗ ' + (j.message || 'failed');
                        btn.classList.add('button-danger');
                    }
                })
                .catch(function () {
                    btn.textContent = '✗ network error';
                    btn.classList.add('button-danger');
                })
                .finally(function () {
                    setTimeout(function () {
                        btn.disabled = false;
                        btn.textContent = orig;
                        btn.classList.remove('button-success', 'button-danger');
                    }, 8000);
                });
        });
    });
}());

/* === v3.17 restore confirm-typing gate === */
(function () {
    var input = document.getElementById('restore-confirm-input');
    var button = document.getElementById('restore-apply-button');
    if (!input || !button) return;
    input.addEventListener('input', function () {
        button.disabled = (input.value !== 'RESTORE');
    });
})();

/* === v3.17 remote_backups Delete-with-confirm (CSP-safe; replaces inline onsubmit) === */
(function () {
    document.querySelectorAll('form[data-confirm-delete]').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            // v3.25.0 #858: when the form also has data-confirm-typename, gate
            // delete behind typing the destination name. Belt-and-suspenders
            // alongside the simpler window.confirm() prompt for any form that
            // doesn't opt into the type-to-confirm pattern.
            var typeName = form.getAttribute('data-confirm-typename');
            if (typeName) {
                var typed = window.prompt(
                    'Type "' + typeName + '" to confirm delete:',
                    ''
                );
                if (typed !== typeName) {
                    ev.preventDefault();
                    if (typed !== null) {
                        window.alert('Name did not match. Delete canceled.');
                    }
                    return;
                }
                // Name matched — fall through (no second confirm needed).
                return;
            }
            var name = form.getAttribute('data-confirm-delete') || 'this file';
            if (!window.confirm('Delete ' + name + ' from the remote destination?')) {
                ev.preventDefault();
            }
        });
    });
})();

/* === v3.25.0 #850 destinations Verify-all bulk action ===
 * Buttons rendered with data-verify-all="<id>" + data-destination-name=
 * trigger a JSON POST to backup_admin.php?tab=destinations with
 * action=verify_all_destination. Result envelope is summarised inline next
 * to the button. */
(function () {
    document.querySelectorAll('button[data-verify-all]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var destId = btn.getAttribute('data-verify-all');
            var name   = btn.getAttribute('data-destination-name') || ('destination ' + destId);
            if (!window.confirm('Verify every backup on ' + name + '? This downloads each artifact and re-hashes it; long-running on large destinations.')) {
                return;
            }
            btn.disabled = true;
            var originalLabel = btn.textContent;
            btn.textContent = 'Verifying…';
            var token = (document.querySelector('input[name="csrf"]') || {}).value || '';
            var body  = new URLSearchParams();
            body.append('csrf', token);
            body.append('action', 'verify_all_destination');
            body.append('id', String(destId));
            fetch('backup_admin.php?tab=destinations', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
                body: body
            }).then(function (r) {
                return r.json().then(function (j) { return { ok: r.ok, body: j }; });
            }).then(function (resp) {
                var j = resp.body || {};
                var msg;
                if (j.ok) {
                    msg = '✓ ' + j.success + '/' + j.total + ' verified';
                } else {
                    msg = '✗ ' + (j.failed || 0) + '/' + (j.total || 0) + ' failed';
                    if (j.failures && j.failures.length) {
                        msg += ' (first: run #' + j.failures[0].run_id + ' — ' + j.failures[0].error + ')';
                    }
                }
                window.alert(msg);
            }).catch(function (e) {
                window.alert('Verify-all error: ' + (e && e.message ? e.message : e));
            }).finally(function () {
                btn.disabled = false;
                btn.textContent = originalLabel;
            });
        });
    });
})();

/* === v3.25.0 #855 skeleton-toggle helper ===
 * Pages opt in by setting `data-skeleton="loading"` on a container that
 * holds skeleton placeholder rows; once the real content is ready the
 * container's `data-skeleton` attribute is set to `ready`. CSS handles
 * the visual swap. This file just exposes a window-level helper for
 * page scripts to call.
 */
(function () {
    if (window.ipamSkeleton) return;
    window.ipamSkeleton = {
        loading: function (selector) {
            document.querySelectorAll(selector).forEach(function (el) {
                el.setAttribute('data-skeleton', 'loading');
            });
        },
        ready: function (selector) {
            document.querySelectorAll(selector).forEach(function (el) {
                el.setAttribute('data-skeleton', 'ready');
            });
        }
    };
})();

// #1131 (v3.27.3) — sudo-replay landing page auto-submits the staged
// POST so the user only briefly sees the "Resuming…" card. CSP-safe:
// no inline <script>, just a marker attribute on the form.
(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var forms = document.querySelectorAll('form[data-auto-submit]');
        for (var i = 0; i < forms.length; i++) {
            forms[i].submit();
        }
    });
})();
