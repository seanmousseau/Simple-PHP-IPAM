// ── Address page: site → subnet cascading filter ──────────────────────────────
(function () {
    var siteSelect = document.getElementById("addrSiteFilter");
    var subnetSelect = document.querySelector("select[name=\"subnet_id\"]");
    if (!siteSelect || !subnetSelect) return;

    // Snapshot all options before any filtering occurs
    var allOpts = Array.prototype.slice.call(subnetSelect.options);

    function filterBySite(siteId) {
        var prevVal = subnetSelect.value;
        allOpts.forEach(function (opt) {
            // Always show the placeholder "-- Select --" (value 0 or empty)
            if (!opt.value || opt.value === "0") { opt.hidden = false; return; }
            opt.hidden = siteId > 0 && parseInt(opt.getAttribute("data-site-id") || "0", 10) !== siteId;
        });
        // If the currently-selected subnet is now hidden, reset it
        if (prevVal && prevVal !== "0") {
            var sel = subnetSelect.querySelector("option[value=\"" + prevVal + "\"]");
            if (sel && sel.hidden) subnetSelect.value = "0";
        }
    }

    siteSelect.addEventListener("change", function () {
        filterBySite(parseInt(this.value, 10) || 0);
    });

    // Apply filter on page load so a pre-selected site narrows the list immediately
    var initSite = parseInt(siteSelect.value, 10) || 0;
    if (initSite > 0) filterBySite(initSite);
}());

