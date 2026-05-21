// backups.php modal handlers — CSP-safe event delegation on data-action attributes
(function () {
  var page = document.getElementById("backups-page");
  if (!page) return;

  function openModal(id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.style.display = "flex";
    el.addEventListener("click", function onBg(e) {
      if (e.target === el) { closeModal(id); el.removeEventListener("click", onBg); }
    });
    var first = el.querySelector("button, [href], [tabindex]");
    if (first) first.focus();
  }

  function closeModal(id) {
    var el = document.getElementById(id);
    if (el) el.style.display = "none";
  }

  document.addEventListener("click", function (e) {
    var btn = e.target.closest("[data-action]");
    if (!btn) return;
    var action = btn.getAttribute("data-action");

    if (action === "restore-info") {
      var phpPath = page.getAttribute("data-restore-script") || "/path/to/Simple-PHP-IPAM/restore.php";
      var rawArg  = btn.getAttribute("data-path") || btn.getAttribute("data-filename") || "<path/to/backup>";
      var fileArg = "'" + rawArg.replace(/'/g, "'\\''") + "'";
      var dry  = document.getElementById("restore-cmd-dry");
      var apply = document.getElementById("restore-cmd-apply");
      if (dry)   dry.textContent   = "php " + phpPath + " --from=" + fileArg + " --dry-run";
      if (apply) apply.textContent = "php " + phpPath + " --from=" + fileArg + " --force";
      openModal("restore-modal");
    } else if (action === "backup-delete") {
      var idEl = document.getElementById("delete-id");
      var bodyEl = document.getElementById("delete-modal-body");
      if (idEl) idEl.value = btn.getAttribute("data-id") || "";
      if (bodyEl) bodyEl.textContent =
        'Delete backup record and file "' + (btn.getAttribute("data-filename") || "") + '"? This cannot be undone.';
      openModal("delete-modal");
    } else if (action === "close-modal") {
      closeModal(btn.getAttribute("data-target") || "");
    }
  });

  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") {
      closeModal("restore-modal");
      closeModal("delete-modal");
    }
  });
})();

