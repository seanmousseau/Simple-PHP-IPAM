// ─── C17 — TOTP verify-page toggle (Phase 2a, #939) ──────────────────────────
// On totp_verify.php, "Use a backup code instead" link flips the form
// between TOTP code and backup code inputs (required/disabled mirroring,
// label swap, focus management). Not the TOTP enrollment QR — that's a
// separate concern that already has its own standalone IIFE.
(function(){
  // TOTP backup code toggle on totp_verify.php
  var toggleBackup = document.getElementById("toggle-backup");
  if (toggleBackup) {
    toggleBackup.addEventListener("click", function(e) {
      e.preventDefault();
      var totpRow   = document.getElementById("totp-code-row");
      var backupRow = document.getElementById("backup-code-row");
      var hidden    = document.getElementById("use-backup-hidden");
      var totpInput = document.getElementById("totp-code-input");
      var backupInput = document.getElementById("backup-code-input");
      if (!totpRow || !backupRow || !hidden) return;
      var isBackup = hidden.value === "1";
      hidden.value = isBackup ? "0" : "1";
      totpRow.classList.toggle("hidden", !isBackup);
      backupRow.classList.toggle("hidden", isBackup);
      if (totpInput) { totpInput.required = isBackup; totpInput.disabled = !isBackup; }
      if (backupInput) { backupInput.required = !isBackup; backupInput.disabled = isBackup; }
      toggleBackup.textContent = isBackup ? "Use a backup code instead" : "Use authenticator app instead";
      if (!isBackup && backupInput) backupInput.focus();
      if (isBackup && totpInput) totpInput.focus();
    });
  }
}());
