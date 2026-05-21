// ─── C15 — DHCP export card on dhcp_pool.php (Phase 2a, #939) ────────────────
// Builds an export URL from selected-subnets checkboxes for dhcpd / Kea
// formats; preview button renders the dhcpd config inline via fetch. No
// DOMContentLoaded needed — script defer guarantees the DOM is parsed
// before this module runs.
(function(){
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
}());
