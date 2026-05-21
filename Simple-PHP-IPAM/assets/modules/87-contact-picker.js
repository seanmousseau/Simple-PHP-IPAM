// ─── C14 — Contact picker (#563) (Phase 2a, #939) ────────────────────────────
// Per-page contact-role grid on subnet/site forms. Rebuilds rows on form
// reset; click delete removes a row; the rows array seeds from
// `data-existing` JSON. Inner per-picker closure survives the wrap.
(function(){
  document.addEventListener("DOMContentLoaded", function() {
    // --- Contact picker (v3.0.0 #563) ---
    document.querySelectorAll(".contact-picker").forEach(function(picker) {
      var contacts = [];
      try { contacts = JSON.parse(picker.getAttribute("data-contacts") || "[]"); } catch(e) {}
      var existing = [];
      try { existing = JSON.parse(picker.getAttribute("data-existing") || "[]"); } catch(e) {}
      var rows = picker.querySelector(".contact-picker-rows");
      var addBtn = picker.querySelector(".contact-picker-add");

      function addRow(contactId, role) {
        var row = document.createElement("div");
        row.className = "contact-picker-row row mt-4";

        var sel = document.createElement("select");
        sel.name = "contact_id[]";
        sel.setAttribute("aria-label", "Contact");
        var empty = document.createElement("option");
        empty.value = "";
        empty.textContent = "\u2014 Select \u2014";
        sel.appendChild(empty);
        contacts.forEach(function(c) {
          var opt = document.createElement("option");
          opt.value = c.id;
          opt.textContent = c.name + (c.email ? " (" + c.email + ")" : "");
          if (c.id === contactId) opt.selected = true;
          sel.appendChild(opt);
        });
        row.appendChild(sel);

        var roleInput = document.createElement("input");
        roleInput.name = "contact_role[]";
        roleInput.value = role || "";
        roleInput.placeholder = "Role (e.g. owner, admin)";
        roleInput.setAttribute("aria-label", "Contact role");
        roleInput.style.width = "160px";
        row.appendChild(document.createTextNode(" "));
        row.appendChild(roleInput);

        var removeBtn = document.createElement("button");
        removeBtn.type = "button";
        removeBtn.className = "button-danger btn-sm contact-picker-remove";
        removeBtn.setAttribute("aria-label", "Remove contact");
        removeBtn.textContent = "\u00d7";
        row.appendChild(document.createTextNode(" "));
        row.appendChild(removeBtn);

        rows.appendChild(row);
      }

      existing.forEach(function(c) { addRow(c.id, c.role); });
      if (addBtn) addBtn.addEventListener("click", function() { addRow(0, ""); });
      picker.addEventListener("click", function(e) {
        if (e.target.classList.contains("contact-picker-remove")) e.target.closest(".contact-picker-row").remove();
      });
      picker.addEventListener("reinit", function() {
        rows.textContent = "";
        var fresh = [];
        try { fresh = JSON.parse(picker.getAttribute("data-existing") || "[]"); } catch(ex) {}
        fresh.forEach(function(c) { addRow(c.id, c.role); });
      });
    });

  });
}());
