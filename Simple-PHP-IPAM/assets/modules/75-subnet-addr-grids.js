// ─── C12 — Subnet list/map view + address inline cell edit (Phase 2a, #939) ──
// Two interactions specific to subnets/addresses listings: (1) #255
// subnet list-vs-map view toggle on subnets.php (state persisted as
// `ipam_subnet_view` in localStorage); (2) #254 inline-cell editing on
// addresses.php row cells (click → become an input, blur or Enter →
// XHR write-back via addresses.php update_cell, Tab → advance to next
// editable cell in same row). Grouped because both target the
// address/subnet grids surface.
(function(){
  document.addEventListener("DOMContentLoaded", function() {
    // --- Subnet list/map view toggle (#255) ---
    (function() {
      var listView = document.getElementById("subnet-list-view");
      var mapView  = document.getElementById("subnet-map-view");
      var btns     = document.querySelectorAll(".subnet-view-btn");
      if (!listView || !mapView) return;
      var storageKey = "ipam_subnet_view";

      function applyView(v) {
        var isMap = v === "map";
        listView.hidden = isMap;
        mapView.hidden  = !isMap;
        btns.forEach(function(b) {
          b.classList.toggle("active", b.dataset.view === v);
        });
        localStorage.setItem(storageKey, v);
      }

      btns.forEach(function(b) {
        b.addEventListener("click", function() { applyView(b.dataset.view); });
      });

      applyView(localStorage.getItem(storageKey) === "map" ? "map" : "list");
    }());

    // --- Inline cell editing on address rows (#254) ---
    document.querySelectorAll("[data-editable][data-addr-id]").forEach(function(cell) {
      cell.title = "Click to edit";
      cell.classList.add("th-sortable");
      cell.addEventListener("click", function(e) {
        if (e.target.closest(".contact-card-trigger")) return;
        if (cell.querySelector("input")) return; // already editing
        var field   = cell.dataset.editable;
        var addrId  = cell.dataset.addrId;
        var origHtml = cell.innerHTML;
        var origText = cell.textContent.trim();

        var input = document.createElement("input");
        input.type = "text";
        input.value = origText;
        input.className = "inline-edit-input";
        cell.innerHTML = "";
        cell.appendChild(input);
        input.focus();
        input.select();

        var csrf = document.querySelector("input[name=csrf]");
        var isSaving = false;

        function save() {
          if (isSaving) return;
          var newVal = input.value;
          if (newVal === origText) { cell.innerHTML = origHtml; return; }
          if (!csrf) { cell.innerHTML = origHtml; return; }
          isSaving = true;
          var fd = new FormData();
          fd.append("csrf",      csrf.value);
          fd.append("action",    "update_cell");
          fd.append("id",        addrId);
          fd.append("subnet_id", new URLSearchParams(window.location.search).get("subnet_id") || "0");
          fd.append("field",     field);
          fd.append("value",     newVal);
          cell.classList.add("cell-saving");
          fetch("addresses.php", {method: "POST", body: fd})
            .then(function(r) { return r.json(); })
            .then(function(data) {
              cell.classList.remove("cell-saving");
              isSaving = false;
              if (data.ok) {
                cell.innerHTML = data.value
                  ? data.value.replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;")
                  : "";
              } else {
                cell.innerHTML = origHtml;
                cell.title = data.error || "Save failed";
              }
            })
            .catch(function() {
              cell.classList.remove("cell-saving");
              isSaving = false;
              cell.innerHTML = origHtml;
            });
        }

        input.addEventListener("keydown", function(e) {
          if (e.key === "Enter")  { e.preventDefault(); save(); }
          if (e.key === "Escape") { e.preventDefault(); cell.innerHTML = origHtml; }
          if (e.key === "Tab") {
            e.preventDefault();
            save();
            // Move to next editable cell in the same row
            var cells = Array.from(cell.closest("tr").querySelectorAll("[data-editable]"));
            var idx = cells.indexOf(cell);
            if (idx >= 0 && cells[idx + 1]) cells[idx + 1].click();
          }
        });
        input.addEventListener("blur", function() {
          setTimeout(function() {
            if (isSaving) return;
            if (cell.querySelector("input") === input) save();
          }, 100);
        });
      });
    });
  });
}());
