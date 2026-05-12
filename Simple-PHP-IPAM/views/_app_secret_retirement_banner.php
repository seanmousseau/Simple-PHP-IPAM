<?php
declare(strict_types=1);

/**
 * Persistent retirement notice for `app_secret`-based backup encryption
 * (v3.28.0 #1164). Shown at the top of the Backups → Destinations and
 * Backups → Restore tabs. Not dismissible by design — operators with legacy
 * `app_secret`-encrypted archives need to act before v4.0.0's cold break.
 *
 * No template vars required. `e()` from lib.php must be in scope (it always
 * is on a page that reached a backup_admin view).
 */
?>
<div class="card warning" data-test="app-secret-retirement-banner" style="margin:.25rem 0 .75rem">
  <strong>Heads-up: <code>app_secret</code>-based backup encryption is being retired.</strong>
  <p style="margin:.35rem 0 0">
    As of <strong>v3.28.0</strong> the backup orchestrator no longer encrypts scheduled
    backups with the config-resident <code>app_secret</code> — an encrypted backup now
    requires a <strong>backup vault key</strong> (Stored mode, configured below on the
    Destinations tab) or a one-shot <strong>passphrase</strong> (Transitory mode, from the
    manual export). An install that still relies on <code>app_secret</code> will fail
    preflight with an actionable message.
  </p>
  <p style="margin:.35rem 0 0">
    The in-app Restore reader still decrypts legacy <code>app_secret</code>-encrypted
    archives (IPAMBKP1 / IPAMBKP2) and bare SQLite dumps through the v3.x line.
    <strong>v4.0.0 removes that reader entirely (cold break)</strong> — after the upgrade,
    the only way back from a legacy archive is the standalone
    <code>tools/decrypt-backup.php</code> recovery tool. So: re-encrypt any legacy archive
    you need to keep under the vault key, <em>or</em> keep a copy of <code>app_secret</code>
    alongside the archive and the decrypt tool. See <code>docs/upgrading.md</code> §&nbsp;v3.28.0
    (Migration off <code>app_secret</code> backup encryption).
  </p>
</div>
