// ─── C07 — Search + IP validation + wheel-hijack guard (Phase 2a, #939) ──────
// Three small adjacent search/input concerns: (1) search-page site →
// subnet cascade filter (hides subnet <option>s whose data-site doesn't
// match the chosen site); (2) data-validate="ip" / "cidr" client-side
// regex check on form submit; (3) #1133 wheel-hijack guard that
// preventDefault()'s on a wheel event over a focused number input so
// the page scrolls instead of the number silently counting up/down.
(function(){
  document.addEventListener("DOMContentLoaded", function() {
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
  });
}());
