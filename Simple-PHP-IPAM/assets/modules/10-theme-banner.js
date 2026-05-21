// ─── C02 — Theme + dismissable banners (Phase 2a, #939) ──────────────────────
// Theme toggle (auto/light/dark) + update-banner dismiss + per-banner
// dismissable admin notices. Helpers and DOM wire-up live together so the
// concern is self-contained for Phase 2b move. Persists theme via
// user_preference.php (server) AND localStorage (client seed for next page
// load); the C01 bootstrap reads the localStorage value before paint to
// avoid flash-of-unstyled-content. The "ipam_theme" key is the contract
// between this concern and C01 — inlined as a literal at every site so
// neither concern depends on the other's closure scope.
(function(){
  function currentTheme() {
    return document.documentElement.getAttribute("data-theme") || "auto";
  }

  function applyTheme(t) {
    if (t === "auto") {
      document.documentElement.removeAttribute("data-theme");
      localStorage.removeItem("ipam_theme");
    } else {
      document.documentElement.setAttribute("data-theme", t);
      localStorage.setItem("ipam_theme", t);
    }
    var csrfMeta = document.querySelector("meta[name='ipam-csrf']");
    var csrfTok = csrfMeta ? csrfMeta.getAttribute("content") || "" : "";
    fetch("user_preference.php", {
      method: "POST",
      headers: {"Content-Type": "application/x-www-form-urlencoded"},
      body: "key=theme&value=" + encodeURIComponent(t) + "&csrf=" + encodeURIComponent(csrfTok)
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
  });
}());
