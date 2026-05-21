// ─── C11 — Dashboard prefs + per-user column visibility (Phase 2a, #939) ─────
// Per-user UI preferences shared by the dashboard and any data-col-table:
// (1) #257 dashboard pinnable widgets — hide/show via a gear menu, state
// persisted as `ipam_hidden_widgets` JSON array in localStorage; plus the
// dashboard's "by-site" filter persisted as `ipam_dash_site`. (2) #256
// per-user column visibility for any table that opts in via
// `data-col-table="<key>"` — gear dropdown shows column toggles, state
// persisted as `ipam_cols_<key>` JSON array. Both concerns are
// localStorage-backed (no server round-trip) so dashboard and table
// preferences survive across sessions on the same browser.
(function(){
  document.addEventListener("DOMContentLoaded", function() {
    // --- Dashboard pinnable widgets + site filter (#257) ---
    (function() {
      var HIDDEN_KEY = "ipam_hidden_widgets";
      var SITE_KEY   = "ipam_dash_site";

      function hiddenList() {
        try { return JSON.parse(localStorage.getItem(HIDDEN_KEY) || "[]"); } catch(e) { return []; }
      }

      function applyWidgetVisibility() {
        var hidden = hiddenList();
        document.querySelectorAll("[data-widget]").forEach(function(el) {
          el.classList.toggle("hidden", hidden.includes(el.dataset.widget));
        });
      }

      applyWidgetVisibility();

      document.querySelectorAll(".widget-hide-btn").forEach(function(btn) {
        btn.addEventListener("click", function() {
          var key    = btn.dataset.widgetKey;
          var hidden = hiddenList();
          if (!hidden.includes(key)) hidden.push(key);
          localStorage.setItem(HIDDEN_KEY, JSON.stringify(hidden));
          applyWidgetVisibility();
        });
      });

      var resetBtn = document.getElementById("dash-reset");
      if (resetBtn) {
        resetBtn.addEventListener("click", function(e) {
          e.preventDefault();
          // Clear all ipam_ localStorage keys
          Object.keys(localStorage).filter(function(k) { return k.startsWith("ipam_"); })
                .forEach(function(k) { localStorage.removeItem(k); });
          location.reload();
        });
      }

      // Site filter on "by-site" table
      var siteFilter = document.getElementById("dash-site-filter");
      if (siteFilter) {
        function applyFilter() {
          var val = siteFilter.value;
          localStorage.setItem(SITE_KEY, val);
          document.querySelectorAll("[data-site-row]").forEach(function(tr) {
            tr.hidden = !(val === "" || tr.dataset.siteRow === val);
          });
        }
        siteFilter.addEventListener("change", applyFilter);
        var saved = localStorage.getItem(SITE_KEY) || "";
        if (saved) { siteFilter.value = saved; }
        applyFilter();
      }
    }());

    // --- Per-user column visibility (#256) ---
    document.querySelectorAll("[data-col-table]").forEach(function(table) {
      var key    = "ipam_cols_" + table.dataset.colTable;
      var stored = localStorage.getItem(key);
      var ths    = Array.from(table.querySelectorAll("thead th[data-col]"));
      // When no saved preference exists, use data-col-default-hidden as the initial state
      var defaultHidden = ths
        .filter(function(th) { return th.dataset.colDefaultHidden === "1"; })
        .map(function(th) { return th.dataset.col; });
      var hidden = stored !== null ? JSON.parse(stored) : defaultHidden;
      if (!ths.length) return;

      function setColVisible(col, visible) {
        var idx = ths.findIndex(function(th) { return th.dataset.col === col; });
        if (idx < 0) return;
        var allCells = [ths[idx]].concat(
          Array.from(table.querySelectorAll("tbody tr")).map(function(tr) {
            return tr.cells[idx];
          }).filter(Boolean)
        );
        allCells.forEach(function(c) { c.classList.toggle("col-hidden", !visible); });
      }

      function saveAndApply(hiddenArr) {
        hidden = hiddenArr;
        localStorage.setItem(key, JSON.stringify(hidden));
        ths.forEach(function(th) { setColVisible(th.dataset.col, !hidden.includes(th.dataset.col)); });
        // keep at least one visible
        var visCount = ths.filter(function(th) { return !hidden.includes(th.dataset.col); }).length;
        if (visCount === 0 && ths[0]) {
          hidden = hidden.filter(function(c) { return c !== ths[0].dataset.col; });
          localStorage.setItem(key, JSON.stringify(hidden));
          setColVisible(ths[0].dataset.col, true);
        }
        syncChecks();
      }

      // Build gear dropdown
      var wrapper = document.createElement("div");
      wrapper.className = "col-vis-wrap";
      var gear = document.createElement("button");
      gear.className = "col-vis-gear action-pill";
      gear.textContent = "\u2699 Columns";
      gear.setAttribute("aria-expanded", "false");
      var drop = document.createElement("div");
      drop.className = "col-vis-drop";
      ths.forEach(function(th) {
        var label = document.createElement("label");
        var cb    = document.createElement("input");
        cb.type   = "checkbox";
        cb.dataset.col = th.dataset.col;
        cb.checked = !hidden.includes(th.dataset.col);
        cb.addEventListener("change", function() {
          var newHidden = ths.map(function(t) { return t.dataset.col; })
            .filter(function(c) {
              var cbEl = drop.querySelector("input[data-col='" + c + "']");
              return cbEl && !cbEl.checked;
            });
          saveAndApply(newHidden);
        });
        label.appendChild(cb);
        label.appendChild(document.createTextNode(" " + th.textContent.trim()));
        drop.appendChild(label);
      });
      wrapper.appendChild(gear);
      wrapper.appendChild(drop);

      function syncChecks() {
        drop.querySelectorAll("input[data-col]").forEach(function(cb) {
          cb.checked = !hidden.includes(cb.dataset.col);
        });
      }

      gear.addEventListener("click", function(e) {
        e.stopPropagation();
        var open = drop.classList.toggle("visible");
        gear.setAttribute("aria-expanded", open ? "true" : "false");
      });
      document.addEventListener("click", function() { drop.classList.remove("visible"); gear.setAttribute("aria-expanded","false"); });

      // Insert gear into a toolbar if present, or before the table-wrap container
      var toolbar = table.closest(".card") && table.closest(".card").querySelector(".toolbar");
      if (toolbar) {
        toolbar.appendChild(wrapper);
      } else {
        var tableWrap = table.closest(".table-wrap");
        var insertTarget = tableWrap || table;
        insertTarget.parentNode.insertBefore(wrapper, insertTarget);
      }

      // Apply persisted hidden columns
      hidden.forEach(function(col) { setColVisible(col, false); });
    });
  });
}());
