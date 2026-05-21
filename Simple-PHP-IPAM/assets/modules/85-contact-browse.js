// ─── C13f — Contact browse overlay (#562) (Phase 2a, #939) ───────────────────
// Full-page overlay listing contacts; opens when a `[data-browse-contacts]`
// trigger is clicked, populates via `api.php?resource=contacts`, click a
// row to stamp the id back into the originating hidden input. Escape to
// close. Inner IIFE survives the wrap.
(function(){
  document.addEventListener("DOMContentLoaded", function() {
    /* ---- Contact browse overlay (#562) ---- */
    (function() {
      var overlay = null;
      var list = null;
      var input = null;
      var targetOwnerInput = null;
      var targetContactIdInput = null;
      var allContacts = null;

      function ensureOverlay() {
        if (overlay) return;
        overlay = document.createElement("div");
        overlay.id = "contact-browse-overlay";

        var box = document.createElement("div");
        box.className = "cb-box";

        var close = document.createElement("button");
        close.className = "cb-close";
        close.textContent = "\u00d7";
        close.title = "Close";
        close.addEventListener("click", hideOverlay);
        box.appendChild(close);

        input = document.createElement("input");
        input.id = "contact-browse-input";
        input.type = "text";
        input.placeholder = "Filter contacts\u2026";
        input.autocomplete = "off";
        input.addEventListener("input", filterList);
        box.appendChild(input);

        list = document.createElement("ul");
        list.id = "contact-browse-list";
        box.appendChild(list);

        overlay.appendChild(box);
        document.body.appendChild(overlay);

        overlay.addEventListener("click", function(e) {
          if (e.target === overlay) hideOverlay();
        });
      }

      function hideOverlay() {
        if (overlay) overlay.classList.remove("visible");
      }

      function filterList() {
        if (!allContacts) return;
        var q = input.value.trim().toLowerCase();
        while (list.firstChild) list.removeChild(list.firstChild);
        var filtered = allContacts.filter(function(c) {
          if (!q) return true;
          return (c.name && c.name.toLowerCase().indexOf(q) !== -1)
            || (c.email && c.email.toLowerCase().indexOf(q) !== -1)
            || (c.org && c.org.toLowerCase().indexOf(q) !== -1);
        });
        if (filtered.length === 0) {
          var empty = document.createElement("li");
          empty.className = "cb-empty";
          empty.textContent = q ? "No contacts match" : "No contacts found";
          list.appendChild(empty);
          return;
        }
        filtered.forEach(function(c) {
          var li = document.createElement("li");
          li.className = "cb-item";
          var name = document.createElement("div");
          name.className = "cb-item-name";
          name.textContent = c.name;
          li.appendChild(name);
          if (c.email || c.org) {
            var meta = document.createElement("div");
            meta.className = "cb-item-meta";
            var parts = [];
            if (c.email) parts.push(c.email);
            if (c.org) parts.push(c.org);
            meta.textContent = parts.join(" \u2014 ");
            li.appendChild(meta);
          }
          li.addEventListener("click", function() {
            if (targetOwnerInput) targetOwnerInput.value = c.name;
            if (targetContactIdInput) targetContactIdInput.value = c.id;
            hideOverlay();
          });
          list.appendChild(li);
        });
      }

      document.addEventListener("click", function(e) {
        var btn = e.target.closest(".contact-browse-btn");
        if (!btn) return;
        e.preventDefault();
        ensureOverlay();

        var wrap = btn.closest("label") || btn.closest(".contact-typeahead-wrap") || btn.parentElement;
        targetOwnerInput = wrap.querySelector("input[name=owner]");
        targetContactIdInput = wrap.querySelector("input[name=owner_contact_id]");

        input.value = "";
        while (list.firstChild) list.removeChild(list.firstChild);

        var loading = document.createElement("li");
        loading.className = "cb-empty";
        loading.textContent = "Loading\u2026";
        list.appendChild(loading);

        overlay.classList.add("visible");
        input.focus();

        if (allContacts) {
          filterList();
          return;
        }
        fetch("api.php?resource=contacts&limit=200", {credentials: "same-origin"})
          .then(function(r) { return r.json(); })
          .then(function(data) {
            allContacts = data.contacts || [];
            filterList();
          })
          .catch(function() {
            while (list.firstChild) list.removeChild(list.firstChild);
            var err = document.createElement("li");
            err.className = "cb-empty";
            err.textContent = "Error loading contacts";
            list.appendChild(err);
          });
      });

      document.addEventListener("keydown", function(e) {
        if (e.key === "Escape" && overlay && overlay.classList.contains("visible")) {
          hideOverlay();
        }
      });
    }());
  });
}());
