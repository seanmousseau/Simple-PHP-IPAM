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
    fetch("set_theme.php", {
      method: "POST",
      headers: {"Content-Type": "application/x-www-form-urlencoded"},
      body: "theme=" + encodeURIComponent(t)
    }).catch(function() {});
  }

  function updateThemeButton() {
    var btn = document.getElementById("theme-toggle");
    if (!btn) return;
    var labels = { auto: "\u{1F5A5} System", light: "\u2600 Light", dark: "\u{1F319} Dark" };
    btn.textContent = labels[currentTheme()] || "\u{1F313} Theme";
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

    // --- Dropdown toggle ---
    document.addEventListener("click", function(e) {
      if (e.target.closest("#theme-toggle")) return;

      var toggle = e.target.closest(".nav-dropdown-toggle");
      if (toggle) {
        var dropdown = toggle.closest(".nav-dropdown");
        var isOpen = dropdown.classList.contains("open");
        document.querySelectorAll(".nav-dropdown.open")
                .forEach(function(d) { d.classList.remove("open"); });
        if (!isOpen) dropdown.classList.add("open");
        return;
      }
      document.querySelectorAll(".nav-dropdown.open")
              .forEach(function(d) { d.classList.remove("open"); });
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
    // Gated by <body data-page="addresses"> so the shortcut only fires on the
    // intended page. Skipped when focus is in a text input/textarea so users
    // typing an address can still type the letter "n".
    document.addEventListener("keydown", function(e) {
      if (!(e.metaKey || e.ctrlKey)) return;
      if (e.key !== "n" && e.key !== "N") return;
      if (document.body.dataset.page !== "addresses") return;
      var ae = document.activeElement;
      if (ae && (ae.tagName === "INPUT" || ae.tagName === "TEXTAREA" || ae.isContentEditable)) return;
      var trigger = document.querySelector('[data-open-drawer="add-address"]');
      if (!trigger) return;
      e.preventDefault();
      trigger.click();
      // Auto-focus the IP field once the drawer is open. The drawer-open
      // logic is synchronous so the field is in the DOM by the time we
      // ask for it.
      var ipField = document.querySelector('#add-address input[name="ip"]');
      if (ipField) ipField.focus();
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
        fetch(revealUrl, { method: "POST", body: body, credentials: "include" })
          .then(function(r) {
            if (!r.ok) throw new Error("HTTP " + r.status);
            return r.json();
          })
          .then(function(data) {
            if (typeof data.value === "string") {
              input.value = data.value;
              input.dataset.pwRevealedFromStored = "1";
            }
            pwToggleApply(btn, input, true);
          })
          .catch(function() {
            btn.setAttribute("aria-label", "Could not reveal stored value");
          })
          .finally(function() {
            btn.disabled = false;
          });
        return;
      }

      pwToggleApply(btn, input, true);
    });
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

    // --- Mobile hamburger nav (#250) ---
    var navToggle = document.getElementById("nav-toggle");
    var navDrawer = document.getElementById("nav-drawer");
    var navOverlay = document.querySelector(".nav-drawer-overlay");
    if (navToggle && navDrawer) {
      function openNav() {
        document.body.classList.add("nav-open");
        navToggle.setAttribute("aria-expanded", "true");
        navDrawer.removeAttribute("aria-hidden");
      }
      function closeNav() {
        document.body.classList.remove("nav-open");
        navToggle.setAttribute("aria-expanded", "false");
        navDrawer.setAttribute("aria-hidden", "true");
      }
      navToggle.addEventListener("click", function() {
        document.body.classList.contains("nav-open") ? closeNav() : openNav();
      });
      if (navOverlay) navOverlay.addEventListener("click", closeNav);
      var drawerClose = navDrawer.querySelector(".drawer-close");
      if (drawerClose) drawerClose.addEventListener("click", closeNav);
      navDrawer.querySelectorAll("a").forEach(function(a) { a.addEventListener("click", closeNav); });
      document.addEventListener("keydown", function(e) { if (e.key === "Escape") closeNav(); });
    }

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
        var formData = new FormData();
        formData.append("csrf", csrf.value);
        formData.append("action", "update_status");
        formData.append("id", addrId);
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
          fd.append("csrf",   csrf.value);
          fd.append("action", "update_cell");
          fd.append("id",     addrId);
          fd.append("field",  field);
          fd.append("value",  newVal);
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

    // --- Global ⌘K / Ctrl+K search overlay (#253) ---
    (function() {
      var overlay = document.getElementById("search-overlay");
      if (!overlay) return;
      var overlayInput  = document.getElementById("search-overlay-input");
      var overlayList   = document.getElementById("search-overlay-list");
      var overlayClose  = document.getElementById("search-overlay-close");
      var searchTimer;
      var activeIdx = -1;

      function openOverlay() {
        overlay.classList.add("visible");
        overlayInput.value = "";
        overlayList.innerHTML = "";
        activeIdx = -1;
        overlayInput.focus();
      }

      function closeOverlay() {
        overlay.classList.remove("visible");
        clearTimeout(searchTimer);
      }

      function setActive(idx) {
        var items = overlayList.querySelectorAll(".so-item");
        if (activeIdx >= 0 && items[activeIdx]) items[activeIdx].classList.remove("active");
        activeIdx = Math.max(-1, Math.min(idx, items.length - 1));
        if (activeIdx >= 0 && items[activeIdx]) {
          items[activeIdx].classList.add("active");
          items[activeIdx].scrollIntoView({block: "nearest", behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth"});
        }
      }

      function runSearch(q) {
        overlayList.innerHTML = '<li class="so-loading">Searching\u2026</li>';
        activeIdx = -1;
        fetch("search.php?format=json&q=" + encodeURIComponent(q), {credentials: "same-origin"})
          .then(function(r) { return r.json(); })
          .then(function(data) {
            overlayList.innerHTML = "";
            if (!data.length) {
              overlayList.innerHTML = '<li class="so-empty">No results.</li>';
              return;
            }
            data.forEach(function(row) {
              var li = document.createElement("li");
              li.className = "so-item";
              li.innerHTML =
                '<span class="so-ip">' + escHtml(row.ip) + '</span>'
                + (row.hostname ? ' <span class="so-host">' + escHtml(row.hostname) + '</span>' : '')
                + ' <span class="so-status status-' + escHtml(row.status) + '">' + escHtml(row.status) + '</span>';
              li.dataset.url = row.url;
              li.addEventListener("mousedown", function(e) {
                e.preventDefault();
                window.location.href = row.url;
              });
              overlayList.appendChild(li);
            });
          })
          .catch(function() {
            overlayList.innerHTML = '<li class="so-empty">Search failed.</li>';
          });
      }

      function escHtml(s) {
        return String(s)
          .replace(/&/g, "&amp;")
          .replace(/</g, "&lt;")
          .replace(/>/g, "&gt;")
          .replace(/"/g, "&quot;");
      }

      overlayInput.addEventListener("input", function() {
        clearTimeout(searchTimer);
        var q = overlayInput.value.trim();
        overlayList.innerHTML = "";
        activeIdx = -1;
        if (q.length < 2) return;
        searchTimer = setTimeout(function() { runSearch(q); }, 300);
      });

      overlayInput.addEventListener("keydown", function(e) {
        var items = overlayList.querySelectorAll(".so-item");
        if (e.key === "ArrowDown") { e.preventDefault(); setActive(activeIdx + 1); }
        else if (e.key === "ArrowUp") { e.preventDefault(); setActive(activeIdx - 1); }
        else if (e.key === "Enter") {
          e.preventDefault();
          if (activeIdx >= 0 && items[activeIdx]) {
            window.location.href = items[activeIdx].dataset.url;
          } else if (overlayInput.value.trim() !== "") {
            window.location.href = "search.php?q=" + encodeURIComponent(overlayInput.value.trim());
          }
        } else if (e.key === "Escape") { closeOverlay(); }
        else if (e.key === "Tab") {
          e.preventDefault();
          if (overlayClose) overlayClose.focus();
        }
      });

      if (overlayClose) {
        overlayClose.addEventListener("click", closeOverlay);
        overlayClose.addEventListener("keydown", function(e) {
          if (e.key === "Tab") { e.preventDefault(); overlayInput.focus(); }
          if (e.key === "Escape") { closeOverlay(); }
        });
      }
      overlay.addEventListener("mousedown", function(e) {
        if (e.target === overlay) closeOverlay();
      });

      document.addEventListener("keydown", function(e) {
        if ((e.metaKey || e.ctrlKey) && e.key === "k") {
          e.preventDefault();
          overlay.classList.contains("visible") ? closeOverlay() : openOverlay();
        }
      });

      // Delegated handler for the Search nav link (opens overlay, falls back to search.php without JS)
      document.addEventListener("click", function(e) {
        var btn = e.target.closest(".nav-search-link");
        if (btn) {
          e.preventDefault();
          overlay.classList.contains("visible") ? closeOverlay() : openOverlay();
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
        .catch(function(err) {
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
