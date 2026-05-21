// ─── C08 — Sidebar + inline status toggle (Phase 2a, #939) ───────────────────
// Two unrelated concerns grouped because both rebind a visible navigation
// surface: (1) #512 sidebar hamburger toggle (mobile slide-in with overlay,
// keyboard escape, aria-hidden state synced to viewport width); (2) #252
// inline status toggle (click a status-badge to cycle used → reserved →
// free → used with an XHR write-back via addresses.php update_status).
// The sidebar dispatches `ipam:sidebar-toggle` so chart resizers can
// re-measure after the slide animation settles.
(function(){
  document.addEventListener("DOMContentLoaded", function() {
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
  });
}());
