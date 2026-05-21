// ─── C13e — Subnet stats async load (#565) (Phase 2a, #939) ──────────────────
// Lazy-fills per-subnet utilization counts after page load via
// `api.php?resource=subnet_stats` so the table renders fast and the
// counts trickle in. Inner IIFE survives the wrap (closure scope for
// `placeholders` and the fetched data).
(function(){
  document.addEventListener("DOMContentLoaded", function() {
    /* ---- Subnet stats async load (#565) ---- */
    (function() {
      var placeholders = document.querySelectorAll("[data-subnet-counts]");
      if (!placeholders.length) return;
      fetch("api.php?resource=subnet_stats", {credentials: "same-origin"})
        .then(function(r) { return r.json(); })
        .then(function(resp) {
          var data = resp.data || resp;
          document.querySelectorAll("[data-subnet-counts]").forEach(function(el) {
            var id = el.dataset.subnetCounts;
            var d = data.direct[id] || {used:0,reserved:0,free:0,total:0};
            var a = data.agg[id] || d;
            var hasChildren = el.dataset.hasChildren === "1";
            el.textContent = "";
            el.classList.remove("subnet-stats-placeholder");

            function mkSpan(cls, text) {
              var s = document.createElement("span");
              s.className = cls;
              s.textContent = text;
              return s;
            }
            el.appendChild(mkSpan("status-used", d.used + " used"));
            el.appendChild(document.createTextNode(" \u00b7 "));
            el.appendChild(mkSpan("status-reserved", d.reserved + " reserved"));
            el.appendChild(document.createTextNode(" \u00b7 "));
            el.appendChild(mkSpan("status-free", d.free + " free"));
            if (hasChildren && a.total !== d.total) {
              el.appendChild(document.createTextNode(" "));
              el.appendChild(mkSpan("muted", "(subtree: " + a.used + "u / " + a.reserved + "r / " + a.free + "f)"));
            }
          });

          document.querySelectorAll("[data-subnet-util]").forEach(function(el) {
            var id = el.dataset.subnetUtil;
            var hasChildren = el.dataset.hasChildren === "1";
            var u = hasChildren ? (data.utilAgg[id] || null) : (data.util[id] || null);
            el.textContent = "";
            el.classList.remove("subnet-stats-placeholder");
            if (!u || u.assignable_total <= 0) return;
            var pct = Math.round(u.assigned_assignable / u.assignable_total * 100);
            var warnThresh = 80;
            var critThresh = 95;
            var barClass = pct >= critThresh ? "util-bar-fill--crit" : (pct >= warnThresh ? "util-bar-fill--warn" : "");
            var pctClass = pct >= critThresh ? "danger" : (pct >= warnThresh ? "warning" : "");

            var info = document.createElement("span");
            info.className = "muted";
            info.textContent = "Assignable: " + u.assignable_total + " | Assigned: " + u.assigned_assignable + " | Unassigned: ";
            var bold = document.createElement("b");
            bold.textContent = String(u.unassigned_assignable);
            info.appendChild(bold);
            el.appendChild(info);

            if (hasChildren) {
              el.appendChild(document.createTextNode(" "));
              var note = document.createElement("span");
              note.className = "muted";
              note.textContent = "(incl. subnets)";
              el.appendChild(note);
            }

            el.appendChild(document.createTextNode(" "));
            var bar = document.createElement("span");
            bar.className = "util-bar";
            var fill = document.createElement("span");
            fill.className = "util-bar-fill " + barClass;
            fill.dataset.pct = String(pct);
            fill.style.width = Math.min(pct, 100) + "%";
            bar.appendChild(fill);
            el.appendChild(bar);

            el.appendChild(document.createTextNode(" "));
            var pctEl = document.createElement("span");
            if (pctClass) pctEl.className = pctClass;
            pctEl.textContent = pct + "%";
            el.appendChild(pctEl);
          });

          // Also populate map view util bars
          document.querySelectorAll("[data-map-util]").forEach(function(el) {
            var id = el.dataset.mapUtil;
            var u = data.utilAgg[id] || data.util[id] || null;
            var pct = 0;
            if (u && u.assignable_total > 0) {
              pct = Math.round(u.assigned_assignable / u.assignable_total * 100);
            } else {
              var d2 = data.agg[id] || {used:0,reserved:0,total:0};
              pct = d2.total > 0 ? Math.round((d2.used + d2.reserved) / Math.max(1, d2.total) * 100) : 0;
            }
            var cls = pct >= 90 ? "util-bar-fill--crit" : (pct >= 75 ? "util-bar-fill--warn" : "");
            var fill2 = el.querySelector(".util-bar-fill");
            if (fill2) {
              fill2.className = "util-bar-fill " + cls;
              fill2.dataset.pct = String(pct);
              fill2.style.width = pct + "%";
            }
            var pctSpan = el.querySelector(".map-pct");
            if (pctSpan) pctSpan.textContent = pct + "%";
          });
        })
        .catch(function() {
          document.querySelectorAll(".subnet-stats-placeholder").forEach(function(el) {
            el.textContent = "Error loading stats";
            el.classList.remove("subnet-stats-placeholder");
          });
        });
    }());
  });
}());
