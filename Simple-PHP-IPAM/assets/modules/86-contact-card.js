// ─── C13g — Contact card popover (#561) (Phase 2a, #939) ─────────────────────
// Hover/focus a `.contact-link` → fetches contact details via api.php and
// renders a small popover card. Cached per contact id; hidden on Escape
// or scroll. Inner IIFE survives the wrap.
(function(){
  document.addEventListener("DOMContentLoaded", function() {
    /* ---- Contact card popover (#561) ---- */
    (function() {
      var card = null;
      var cache = {};
      var activeCid = null;

      function ensureCard() {
        if (card) return;
        card = document.createElement("div");
        card.id = "contact-card";
        document.body.appendChild(card);
      }

      function hideCard() {
        if (card) card.classList.remove("visible");
      }

      function positionCard(trigger) {
        var r = trigger.getBoundingClientRect();
        var top = r.bottom + 6;
        var left = r.left;
        if (left + 320 > window.innerWidth) left = window.innerWidth - 328;
        if (left < 8) left = 8;
        if (top + 200 > window.innerHeight) top = r.top - 206;
        card.style.top = top + "px";
        card.style.left = left + "px";
      }

      function clearCard() {
        while (card.firstChild) card.removeChild(card.firstChild);
      }

      function addRow(label, value, isLink) {
        var row = document.createElement("div");
        row.className = "cc-row";
        var lbl = document.createElement("span");
        lbl.className = "cc-label";
        lbl.textContent = label;
        row.appendChild(lbl);
        if (isLink) {
          var a = document.createElement("a");
          a.href = "mailto:" + value;
          a.textContent = value;
          row.appendChild(a);
        } else {
          row.appendChild(document.createTextNode(value));
        }
        card.appendChild(row);
      }

      function renderCard(c) {
        clearCard();
        var closeBtn = document.createElement("button");
        closeBtn.type = "button";
        closeBtn.className = "cc-close";
        closeBtn.setAttribute("aria-label", "Close");
        closeBtn.textContent = "\u00d7";
        closeBtn.addEventListener("click", function(e) { e.stopPropagation(); hideCard(); });
        card.appendChild(closeBtn);
        var name = document.createElement("div");
        name.className = "cc-name";
        name.textContent = c.name;
        card.appendChild(name);
        if (c.email) addRow("Email", c.email, true);
        if (c.phone) addRow("Phone", c.phone, false);
        if (c.org) addRow("Org", c.org, false);
        if (c.note) addRow("Note", c.note, false);
      }

      function showMessage(msg) {
        clearCard();
        var row = document.createElement("div");
        row.className = "cc-row";
        row.textContent = msg;
        card.appendChild(row);
      }

      document.addEventListener("click", function(e) {
        var trigger = e.target.closest(".contact-card-trigger");
        if (!trigger) { hideCard(); return; }
        e.preventDefault();
        ensureCard();
        var cid = trigger.dataset.contactId;
        activeCid = cid;
        if (cache[cid]) {
          renderCard(cache[cid]);
          positionCard(trigger);
          card.classList.add("visible");
          return;
        }
        showMessage("Loading\u2026");
        positionCard(trigger);
        card.classList.add("visible");
        fetch("api.php?resource=contacts&id=" + encodeURIComponent(cid), {credentials: "same-origin"})
          .then(function(r) { return r.json(); })
          .then(function(data) {
            if (activeCid !== cid) return;
            if (data.contact) {
              cache[cid] = data.contact;
              renderCard(data.contact);
            } else {
              showMessage("Contact not found");
            }
          })
          .catch(function() { if (activeCid === cid) showMessage("Error loading contact"); });
      });

      document.addEventListener("keydown", function(e) {
        if (e.key === "Escape") hideCard();
      });
      window.addEventListener("scroll", hideCard, true);
    }());
  });
}());
