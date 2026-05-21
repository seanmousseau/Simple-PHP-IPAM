// \u2500\u2500\u2500 C10 \u2014 Contact typeahead (Phase 2a, #939) \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500
// data-contact-typeahead wires a `<input>` to the contacts API with a 250 ms
// debounce; query renders suggestions in a sibling <ul>, click stamps the
// chosen contact's id into the hidden owner_contact_id field. Escape /
// blur clears suggestions. Self-contained per-input closure.
(function(){
  document.addEventListener("DOMContentLoaded", function() {
    // --- Contact typeahead (data-contact-typeahead) ---
    document.querySelectorAll("[data-contact-typeahead]").forEach(function(input) {
      var hiddenInput = input.parentElement.querySelector("input[name=owner_contact_id]");
      if (!hiddenInput) return;
      var list = document.createElement("ul");
      list.className = "contact-suggestions hidden";
      input.parentElement.classList.add("contact-typeahead-wrap");
      input.parentElement.appendChild(list);
      var timer;

      function clearSuggestions() {
        while (list.firstChild) list.removeChild(list.firstChild);
        list.classList.add("hidden");
      }

      input.addEventListener("input", function() {
        hiddenInput.value = "0";
        clearTimeout(timer);
        var q = input.value.trim();
        if (q.length < 2) { clearSuggestions(); return; }
        timer = setTimeout(function() {
          fetch("api.php?resource=contacts&q=" + encodeURIComponent(q) + "&limit=10", {credentials: "same-origin"})
            .then(function(r) { return r.json(); })
            .then(function(data) {
              clearSuggestions();
              if (!data.contacts || !data.contacts.length) return;
              data.contacts.forEach(function(c) {
                var li = document.createElement("li");
                li.textContent = c.name + (c.email ? " <" + c.email + ">" : "");
                li.dataset.contactId   = c.id;
                li.dataset.contactName = c.name;
                li.addEventListener("mousedown", function(e) {
                  e.preventDefault();
                  input.value = c.name;
                  hiddenInput.value = c.id;
                  clearSuggestions();
                });
                list.appendChild(li);
              });
              list.classList.remove("hidden");
            })
            .catch(function() { clearSuggestions(); });
        }, 250);
      });

      input.addEventListener("blur", function() {
        setTimeout(clearSuggestions, 200);
      });

      input.addEventListener("keydown", function(e) {
        if (e.key === "Escape") { clearSuggestions(); }
      });
    });
  });
}());
