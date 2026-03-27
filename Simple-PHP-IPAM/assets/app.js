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

  // Keep old names in case anything calls them externally
  window.ipamToggleTheme = window.ipamCycleTheme;
  window.ipamClearTheme = function() { applyTheme("auto"); updateThemeButton(); };

  document.addEventListener("DOMContentLoaded", function() {
    updateThemeButton();

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
