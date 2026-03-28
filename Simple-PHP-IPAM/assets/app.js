(function(){
  const key = "ipam_theme";
  const saved = localStorage.getItem(key);
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
    const btn = document.getElementById("theme-toggle");
    if (!btn) return;
    const labels = { auto: "🖥 System", light: "☀ Light", dark: "🌙 Dark" };
    btn.textContent = labels[currentTheme()] || "🌓 Theme";
  }

  window.ipamCycleTheme = function() {
    const order = ["auto", "light", "dark"];
    const next = order[(order.indexOf(currentTheme()) + 1) % order.length];
    applyTheme(next);
    updateThemeButton();
  };

  // Dismiss the update-available banner for the current version (stored in localStorage)
  window.ipamDismissUpdate = function(version) {
    localStorage.setItem("ipam_dismissed_update", version);
    var banner = document.getElementById("ipam-update-banner");
    if (banner) banner.style.display = "none";
  };

  document.addEventListener("DOMContentLoaded", function() {
    updateThemeButton();

    // Hide update banner if the version was already dismissed
    var banner = document.getElementById("ipam-update-banner");
    if (banner) {
      var dismissed = localStorage.getItem("ipam_dismissed_update");
      var bannerVersion = banner.dataset.version;
      if (dismissed && bannerVersion && dismissed === bannerVersion) {
        banner.style.display = "none";
      }
    }

    // Site group collapse/expand with localStorage persistence
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

    // Dropdown toggle
    document.addEventListener("click", function(e) {
      // Theme toggle inside user dropdown — cycle theme but keep dropdown open
      if (e.target.closest("#theme-toggle")) {
        return;
      }

      const toggle = e.target.closest(".nav-dropdown-toggle");
      if (toggle) {
        const dropdown = toggle.closest(".nav-dropdown");
        const isOpen = dropdown.classList.contains("open");
        // Close all first
        document.querySelectorAll(".nav-dropdown.open")
                .forEach(function(d) { d.classList.remove("open"); });
        if (!isOpen) dropdown.classList.add("open");
        return;
      }
      // Click outside closes all
      document.querySelectorAll(".nav-dropdown.open")
              .forEach(function(d) { d.classList.remove("open"); });
    });
  });
})();
