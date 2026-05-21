// ─── Site filter strip (#629) ─────────────────────────────────────────────────
// Pill-based client-side filter for subnets.php.
// Filter state is stored in sessionStorage so a page reload restores the last
// selection, but navigating away or opening a new tab starts fresh.
(function () {
    var strip = document.getElementById("site-filter-strip");
    if (!strip) return;

    var STORAGE_KEY = "ipam_subnet_site_filter";

    // Restore persisted filter
    var saved = "";
    try { saved = sessionStorage.getItem(STORAGE_KEY) || ""; } catch (e) { saved = ""; }

    // Collect all subnet-node elements (top-level only; children inherit via parent hiding)
    // We show/hide the outermost .subnet-node for each root by checking data-site-id.
    // For nested nodes we must also hide when the root is filtered.

    function getActiveSiteIds(filterVal) {
        // Returns a Set of numeric site IDs that should be SHOWN, or null for "all".
        if (!filterVal || filterVal === "all") return null;
        if (filterVal.indexOf("region:") === 0) {
            // All child site IDs of this region are embedded as data-filter-site on the child pills
            var regionId = filterVal.split(":")[1];
            var region = strip.querySelector("[data-region-id='" + regionId + "']");
            if (!region) return null;
            var ids = new Set();
            region.querySelectorAll(".site-filter-pill--child[data-filter-site]").forEach(function (pill) {
                var v = pill.dataset.filterSite;
                if (v && v !== "all" && v.indexOf("region:") < 0) ids.add(parseInt(v, 10));
            });
            // also include the region's own site ID if it has self_used subnets (child pill "(all)" uses plain int)
            // Those are already captured above since they use an integer data-filter-site.
            return ids.size > 0 ? ids : null;
        }
        var n = parseInt(filterVal, 10);
        if (isNaN(n) || n <= 0) return null;
        return new Set([n]);
    }

    function applyFilter(filterVal) {
        var activeSiteIds = getActiveSiteIds(filterVal);
        var isAll = (activeSiteIds === null);

        // Update pill aria-pressed states
        strip.querySelectorAll(".site-filter-pill").forEach(function (pill) {
            var pv = pill.dataset.filterSite || "";
            var active = (isAll && pv === "all") || (!isAll && pv === filterVal);
            pill.setAttribute("aria-pressed", active ? "true" : "false");
            pill.classList.toggle("site-filter-pill--active", active);
        });

        // Show/hide subnet-node elements; operate on ALL .subnet-node in the list view.
        // Child subnets are DOM-nested inside parent subnet-node divs, so we must also
        // keep a parent visible when any of its descendants match the active site filter.
        document.querySelectorAll("#subnet-list-view .subnet-node").forEach(function (node) {
            if (isAll) {
                node.classList.remove("subnet-node--filtered");
                return;
            }
            var siteId = parseInt(node.dataset.siteId || "0", 10);
            var selfMatch = activeSiteIds !== null && activeSiteIds.has(siteId);
            var childMatch = !selfMatch && Array.from(node.querySelectorAll(".subnet-node[data-site-id]")).some(function (desc) {
                return activeSiteIds !== null && activeSiteIds.has(parseInt(desc.dataset.siteId || "0", 10));
            });
            node.classList.toggle("subnet-node--filtered", !selfMatch && !childMatch);
        });

        // Hide site-group containers that now have no visible subnet nodes
        document.querySelectorAll("#subnet-list-view .site-group").forEach(function (sg) {
            if (isAll) {
                sg.classList.remove("site-group--filter-empty");
                return;
            }
            var visible = sg.querySelectorAll(".subnet-node:not(.subnet-node--filtered)").length > 0;
            sg.classList.toggle("site-group--filter-empty", !visible);
        });

        // Persist
        try { sessionStorage.setItem(STORAGE_KEY, filterVal || "all"); } catch (e) {}
    }

    // Handle pill clicks (and keyboard: Enter / Space)
    strip.addEventListener("click", function (e) {
        var pill = e.target.closest(".site-filter-pill");
        if (!pill) return;
        e.preventDefault();

        // Region toggle button: toggle collapsed children; do not change the tree filter
        var regionId = pill.dataset.regionToggle;
        if (regionId !== undefined) {
            var expanded = pill.getAttribute("aria-expanded") !== "false";
            pill.setAttribute("aria-expanded", expanded ? "false" : "true");
            var childrenWrap = strip.querySelector("[data-region-children='" + regionId + "']");
            if (childrenWrap) childrenWrap.classList.toggle("is-collapsed", expanded);
            // Also apply a filter for the whole region when clicking the region header pill
            applyFilter(pill.dataset.filterSite || "all");
            return;
        }

        applyFilter(pill.dataset.filterSite || "all");
    });

    strip.addEventListener("keydown", function (e) {
        if (e.key === "Enter" || e.key === " ") {
            var pill = e.target.closest(".site-filter-pill");
            if (pill) { e.preventDefault(); pill.click(); }
        }
    });

    // Apply saved state on load
    applyFilter(saved || "all");
}());


