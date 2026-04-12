(function(){
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
    if (banner) banner.style.display = "none";
  }

  document.addEventListener("DOMContentLoaded", function() {
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
        banner.style.display = "none";
      }
    }
    document.querySelectorAll("[data-dismiss-update]").forEach(function(btn) {
      btn.addEventListener("click", function() {
        dismissUpdate(btn.dataset.dismissUpdate);
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

    // --- Auto-submit selects (data-auto-submit) ---
    document.querySelectorAll("[data-auto-submit]").forEach(function(el) {
      el.addEventListener("change", function() { el.form.submit(); });
    });

    // --- Confirm dialogs on forms (data-confirm on <form>) ---
    document.addEventListener("submit", function(e) {
      var form = e.target;
      var msg = form.dataset.confirm;
      if (msg && !confirm(msg)) {
        e.preventDefault();
      }
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

    // --- Utilization bar fill widths from data-pct (replaces inline style width) ---
    document.querySelectorAll(".util-bar-fill[data-pct]").forEach(function(el) {
      el.style.width = el.dataset.pct + "%";
    });

    // --- Subnet hierarchy node indentation from data-indent (replaces inline margin-left) ---
    document.querySelectorAll(".subnet-node[data-indent]").forEach(function(el) {
      el.style.marginLeft = el.dataset.indent + "px";
    });

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
          opt.style.display = match ? "" : "none";
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
        src.style.display = "";
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
  });
})();
