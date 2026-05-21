// ─── C13b — Tooltips (#354) (Phase 2a, #939) ─────────────────────────────────
// Single shared `#ipam-tooltip` div at position:fixed so the bubble is never
// clipped by overflow:hidden/clip on ancestor containers. Sweeps the page
// for `[data-tooltip]` elements and wires mouseenter/focusin show +
// mouseleave/focusout hide. Original code already had its own inner IIFE.
(function(){
  document.addEventListener("DOMContentLoaded", function() {
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
  });
}());
