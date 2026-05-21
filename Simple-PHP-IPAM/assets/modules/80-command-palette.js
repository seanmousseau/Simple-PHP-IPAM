// ─── C13 — Command palette ⌘K (Phase 2a, #939) ───────────────────────────────
// The largest single concern in _monolith.js. ⌘K opens a quick-action
// palette with global commands (Add Subnet, Add Address, Search, navigation
// links, theme cycle) plus a per-page item set echoed into a JSON island
// from `ipam_command_palette_items()` in lib.php. Fuzzy filter on input,
// arrow-key navigation, Enter activates. Recent items persist in
// localStorage. Cross-module dependency: opens IpamDrawer via
// `IpamDrawer.open(...)` for Add Subnet / Add Address — IpamDrawer is in
// `20-drawer.js` which loads before this module per the documented order.
// Cmd/Ctrl+N shortcut to open Add Address drawer on addresses.php also
// lives in this block (was orphan-commented in C04, removed there).
(function(){
  document.addEventListener("DOMContentLoaded", function() {
    // --- Command palette ⌘K (#516) ---
    (function() {
      var bg = document.getElementById("cmd-palette-bg");
      if (!bg) return;

      var input = document.getElementById("cmd-input");
      var results = document.getElementById("cmd-results");
      var searchTimer;
      var activeIdx = -1;
      var currentItems = [];

      var IPAM_COMMANDS = [
        { group: "Pages", label: "Dashboard",    href: "dashboard.php" },
        { group: "Pages", label: "Subnets",      href: "subnets.php" },
        { group: "Pages", label: "Addresses",    href: "addresses.php" },
        { group: "Pages", label: "Search",       href: "search.php" },
        { group: "Pages", label: "Audit",        href: "audit.php" },
        { group: "Pages", label: "Unassigned",   href: "unassigned.php" },
        { group: "Actions", label: "New Subnet", action: function() { IpamDrawer.open("Add Subnet", "tpl-add-subnet"); } },
        { group: "Actions", label: "Toggle Theme", action: function() {
            var btn = document.getElementById("theme-toggle");
            if (btn) btn.click();
        }},
      ];

      function escHtml(s) {
        return String(s)
          .replace(/&/g, "&amp;")
          .replace(/</g, "&lt;")
          .replace(/>/g, "&gt;")
          .replace(/"/g, "&quot;")
          .replace(/'/g, "&#x27;");
      }

      function safeUrl(url) {
        return /^(javascript|data|vbscript):/i.test(String(url)) ? "#" : url;
      }

      function openPalette() {
        bg.classList.add("is-open");
        input.value = "";
        renderCommands("");
        activeIdx = -1;
        input.focus();
      }

      function closePalette() {
        bg.classList.remove("is-open");
        clearTimeout(searchTimer);
      }

      function renderCommands(q) {
        var lq = q.toLowerCase();
        var filtered = IPAM_COMMANDS.filter(function(c) {
          return !lq || c.label.toLowerCase().indexOf(lq) !== -1;
        });

        results.innerHTML = "";
        currentItems = [];

        if (!filtered.length) {
          results.innerHTML = '<div class="cmd-empty">No commands match.</div>';
          return;
        }

        var lastGroup = null;
        filtered.forEach(function(cmd) {
          if (cmd.group !== lastGroup) {
            var gl = document.createElement("div");
            gl.className = "cmd-group-label";
            gl.textContent = cmd.group;
            results.appendChild(gl);
            lastGroup = cmd.group;
          }
          var item = document.createElement("div");
          item.className = "cmd-item";
          item.setAttribute("role", "option");
          item.innerHTML = escHtml(cmd.label);
          item.addEventListener("mousedown", function(e) {
            e.preventDefault();
            activateCommand(cmd);
          });
          results.appendChild(item);
          currentItems.push({ el: item, cmd: cmd });
        });
        activeIdx = -1;
      }

      function renderEntities(data) {
        if (!data.length) return;
        var gl = document.createElement("div");
        gl.className = "cmd-group-label";
        gl.textContent = "Addresses";
        results.appendChild(gl);
        data.slice(0, 8).forEach(function(row) {
          var item = document.createElement("div");
          item.className = "cmd-item";
          item.setAttribute("role", "option");
          item.innerHTML = escHtml(row.ip) + (row.hostname ? ' <span style="opacity:.65">' + escHtml(row.hostname) + "</span>" : "");
          item.addEventListener("mousedown", function(e) {
            e.preventDefault();
            closePalette();
            window.location.href = safeUrl(row.url);
          });
          results.appendChild(item);
          currentItems.push({ el: item, cmd: { href: row.url } });
        });
      }

      function activateCommand(cmd) {
        closePalette();
        if (cmd.action) {
          cmd.action();
        } else if (cmd.href) {
          window.location.href = safeUrl(cmd.href);
        }
      }

      function setActive(idx) {
        if (activeIdx >= 0 && currentItems[activeIdx]) currentItems[activeIdx].el.classList.remove("is-active");
        activeIdx = Math.max(-1, Math.min(idx, currentItems.length - 1));
        if (activeIdx >= 0 && currentItems[activeIdx]) {
          currentItems[activeIdx].el.classList.add("is-active");
          currentItems[activeIdx].el.scrollIntoView({ block: "nearest" });
        }
      }

      function runSearch(q) {
        renderCommands(q);
        fetch("search.php?format=json&q=" + encodeURIComponent(q), { credentials: "same-origin" })
          .then(function(r) { return r.json(); })
          .then(function(data) { renderEntities(data); })
          .catch(function() {});
      }

      input.addEventListener("input", function() {
        clearTimeout(searchTimer);
        var q = input.value.trim();
        activeIdx = -1;
        renderCommands(q);
        if (q.length >= 2) {
          searchTimer = setTimeout(function() { runSearch(q); }, 300);
        }
      });

      input.addEventListener("keydown", function(e) {
        if (e.key === "ArrowDown") { e.preventDefault(); setActive(activeIdx + 1); }
        else if (e.key === "ArrowUp") { e.preventDefault(); setActive(Math.max(-1, activeIdx - 1)); }
        else if (e.key === "Enter") {
          e.preventDefault();
          if (activeIdx >= 0 && currentItems[activeIdx]) {
            activateCommand(currentItems[activeIdx].cmd);
          } else if (input.value.trim().length >= 2) {
            closePalette();
            window.location.href = "search.php?q=" + encodeURIComponent(input.value.trim());
          }
        } else if (e.key === "Escape") { closePalette(); }
      });

      bg.addEventListener("mousedown", function(e) {
        if (e.target === bg) closePalette();
      });

      document.addEventListener("keydown", function(e) {
        if ((e.metaKey || e.ctrlKey) && e.key === "k") {
          e.preventDefault();
          bg.classList.contains("is-open") ? closePalette() : openPalette();
        }
      });

      document.addEventListener("click", function(e) {
        var btn = e.target.closest(".nav-search-link");
        if (btn) {
          e.preventDefault();
          bg.classList.contains("is-open") ? closePalette() : openPalette();
        }
      });

      document.addEventListener("keydown", function(e) {
        if ((e.metaKey || e.ctrlKey) && e.key === "n" && document.getElementById("tpl-add-address")) {
          e.preventDefault();
          IpamDrawer.open("Add Address", "tpl-add-address");
          var ipField = document.querySelector("#global-drawer-body input[name=\"ip\"]");
          if (ipField) ipField.focus();
        }
      });
    }());
  });
}());
