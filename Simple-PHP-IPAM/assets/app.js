(function(){
  var key = "ipam_theme";
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
          pwField.style.display = sso ? "none" : "";
          pwInput.required = !sso;
          subField.style.display = sso ? "" : "none";
        }
        ssoToggle.addEventListener("change", applySsoState);
        applySsoState(); // Apply on load for re-rendered forms (#73)
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
  });
})();
