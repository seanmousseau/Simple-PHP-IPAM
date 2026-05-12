<?php
declare(strict_types=1);

/**
 * Destinations + schedules render block. Shared by destinations.php (legacy)
 * and backup_admin.php?tab=destinations (unified surface, v3.21.0 Wave 4).
 *
 * @var string                            $err
 * @var string                            $flash
 * @var list<array<string, mixed>>        $destinations
 * @var list<array<string, mixed>>        $schedules
 * @var int                               $flashTestId
 * @var bool                              $flashTestOk
 * @var string                            $flashTestMsg
 * @var int|null                          $flashTestLatency
 * @var array{present:bool, source:string, fingerprint:?string, created_at:?string, has_encrypted_runs:bool, state:'absent'|'present'|'unreadable', error_message:?string} $vaultStatus
 * @var string                            $revealedKey
 */
$_currentUser = function_exists('current_user') ? current_user() : ['role' => ''];
$_isAdmin     = ($_currentUser['role'] ?? '') === 'admin';
?>
  <?php if ($err !== ''): ?>
    <div class="card danger"><?= e($err) ?></div>
  <?php endif; ?>
  <?php if ($flash !== ''): ?>
    <div class="card success"><?= e($flash) ?></div>
  <?php endif; ?>

  <?php /* v3.28.0 #1164 — app_secret backup-encryption retirement notice (Destinations + Restore tabs). */ ?>
  <?php include __DIR__ . '/_app_secret_retirement_banner.php'; ?>

  <?php if ($_isAdmin): ?>
  <!-- v3.26.0 (#1098) — Encryption key (Stored mode) admin panel -->
  <section class="card" data-test="vault-key-panel">
    <h2>Encryption key (Stored mode)</h2>

    <?php if ($vaultStatus['state'] === 'unreadable'): ?>
      <div class="card danger" data-test="vault-unreadable-banner" style="margin:.25rem 0 .75rem">
        <strong>Vault key envelope is unreadable.</strong>
        <p style="margin:.25rem 0 0">
          A <code>backup_vault_key</code> envelope is recorded in the database, but it can't
          be unwrapped with this install's current <code>bootstrap_key</code>. The most
          common cause is a rotated <code>bootstrap_key</code> in <code>config.php</code>
          since the envelope was written. Stored-mode encryption is non-functional until
          this is resolved: new IPAMBKP3 runs will fail their preflight check, and existing
          IPAMBKP3 archives encrypted under this vault key cannot be restored on this host.
        </p>
        <?php if ($vaultStatus['error_message'] !== null): ?>
          <p class="muted" style="margin:.25rem 0 0;font-size:.85em">
            Helper reported: <code><?= e($vaultStatus['error_message']) ?></code>
          </p>
        <?php endif; ?>
        <p style="margin:.5rem 0 0;font-size:.9em">
          Recovery is operator-driven and goes through <code>config.php</code> directly
          — see <a href="docs/upgrading.md#vault-recovery"><code>docs/upgrading.md</code> §vault-recovery</a>.
          This release deliberately does not offer an in-app "Replace vault key" action
          while the envelope is unreadable.
        </p>
      </div>
    <?php endif; ?>

    <?php if ($vaultStatus['present']): ?>
      <div class="vault-status" style="display:flex;flex-wrap:wrap;align-items:center;gap:.75rem;margin:.25rem 0 .75rem">
        <p class="muted" style="margin:0;flex:1 1 auto;min-width:18rem">
          Fingerprint
          <code data-test="vault-fingerprint"><?= e((string)$vaultStatus['fingerprint']) ?></code>
          &middot; Source
          <code><?= e($vaultStatus['source']) ?></code>
          <?php if ($vaultStatus['created_at'] !== null): ?>
            &middot; Updated <code><?= e(ipam_format_datetime($vaultStatus['created_at'])) ?></code>
          <?php endif; ?>
        </p>
        <form method="post" action="backup_admin.php?tab=destinations" style="margin:0">
          <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="vault_reveal">
          <button type="submit" data-test="vault-reveal-submit">
            Reveal vault key
          </button>
        </form>
      </div>
      <p class="muted" style="font-size:.85em;margin:.25rem 0 1rem">
        You will be prompted to re-authenticate before the raw key is revealed.
        The reveal is rate-limited and audit-logged
        (<code>backup.vault_key.revealed</code>).
      </p>
    <?php else: ?>
      <p class="muted">
        No vault key configured yet. Stored-mode encryption requires a vault key;
        you can generate one below or paste an existing 32-byte base64-encoded value.
      </p>
    <?php endif; ?>

    <?php if ($revealedKey !== ''): ?>
      <div class="card warn" data-test="vault-revealed">
        <h3 style="margin-top:0">Copy this key offline now &mdash; it will not be shown again</h3>
        <pre style="user-select:all;word-break:break-all;padding:.75rem;background:var(--bg);border-radius:var(--radius-sm)"><code data-test="vault-revealed-key"><?= e($revealedKey) ?></code></pre>
        <p class="muted" style="font-size:.85em">
          Store this in a password manager or offline secure location.
          Without it, archives encrypted in Stored mode cannot be restored if this server is lost.
        </p>
      </div>
    <?php endif; ?>

    <?php
      // v3.28.0: when encrypted backup runs exist, setting/replacing the
      // vault key strands them (the new key can't decrypt archives made
      // under the old one). Rather than a hard block — which left a
      // production install that lost its vault key with no realistic way
      // forward — the form now renders an explicit "I understand …"
      // acknowledgement checkbox; the handler refuses unless it's ticked
      // (or the operator is pasting a known-good key, which recovers
      // rather than orphans). The ack POSTs `confirm_orphan_backups=1`.
      $vkOrphanRisk = !empty($vaultStatus['has_encrypted_runs']);
    ?>
    <?php if ($vaultStatus['present']): ?>
      <details style="margin-top:.5rem">
        <summary>Replace vault key</summary>
        <?php if ($vkOrphanRisk): ?>
          <p class="muted" data-test="vault-replace-orphan-warning" style="font-size:.9em">
            <strong>Encrypted backup runs exist.</strong> Replacing the vault key leaves
            those archives <em>unreadable</em> — the new key cannot decrypt what was
            encrypted under the current one. If you still have the current key, paste it
            below to keep things working. To replace anyway and accept that the existing
            encrypted archives become permanently unrecoverable, tick the acknowledgement.
          </p>
        <?php else: ?>
          <p class="muted" style="font-size:.9em">
            Generates or accepts a new 32-byte vault key. The new key replaces the
            existing envelope; archives encrypted under the old key become unreadable.
            Use this only after confirming no encrypted runs remain (or acknowledge the
            orphaning explicitly when prompted).
          </p>
        <?php endif; ?>
        <form method="post" action="backup_admin.php?tab=destinations" style="display:flex;flex-direction:column;gap:.5rem">
          <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="vault_replace">
          <label>
            <input type="radio" name="vault_mode" value="generate" checked>
            Generate a new random 32-byte key
          </label>
          <label>
            <input type="radio" name="vault_mode" value="paste">
            Paste an existing base64-encoded 32-byte key
          </label>
          <input type="text" name="vault_key_b64" placeholder="base64-encoded vault key (44 chars)"
                 autocomplete="off" data-test="vault-paste-replace">
          <?php if ($vkOrphanRisk): ?>
            <label class="danger" data-test="vault-confirm-orphan-replace" style="font-size:.9em">
              <input type="checkbox" name="confirm_orphan_backups" value="1">
              I understand replacing the vault key will permanently strand the existing encrypted backups.
            </label>
          <?php endif; ?>
          <div>
            <button type="submit" class="button-danger" data-test="vault-replace-submit">
              Replace vault key
            </button>
          </div>
        </form>
      </details>
    <?php else: ?>
      <details open>
        <summary>Set vault key</summary>
        <?php if ($vkOrphanRisk): ?>
          <p class="muted" data-test="vault-set-orphan-warning" style="font-size:.9em">
            <strong>Encrypted backup runs already exist.</strong> Generating a fresh key now
            leaves those archives encrypted under whatever key produced them — the new key
            won't decrypt them, so they'll be unreadable. If you have the original key, paste
            it below to recover. To generate a new key anyway and accept that the existing
            encrypted archives become permanently unrecoverable, tick the acknowledgement.
          </p>
        <?php endif; ?>
        <form method="post" action="backup_admin.php?tab=destinations" style="display:flex;flex-direction:column;gap:.5rem">
          <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="vault_set">
          <label>
            <input type="radio" name="vault_mode" value="generate" checked>
            Generate a new random 32-byte key
          </label>
          <label>
            <input type="radio" name="vault_mode" value="paste">
            Paste an existing base64-encoded 32-byte key
          </label>
          <input type="text" name="vault_key_b64" placeholder="base64-encoded vault key (44 chars)"
                 autocomplete="off" data-test="vault-paste-set">
          <?php if ($vkOrphanRisk): ?>
            <label class="danger" data-test="vault-confirm-orphan-set" style="font-size:.9em">
              <input type="checkbox" name="confirm_orphan_backups" value="1">
              I understand setting a new vault key will permanently strand the existing encrypted backups.
            </label>
          <?php endif; ?>
          <div>
            <button type="submit" data-test="vault-set-submit">Set vault key</button>
          </div>
        </form>
      </details>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <p class="muted">Configure where backups are sent. Each destination can have a schedule for automatic runs.</p>

  <!-- Destinations list -->
  <section class="card">
    <h2>Destinations</h2>

    <?php if (count($destinations) === 0): ?>
      <p class="muted">No destinations configured yet.</p>
    <?php else: ?>
      <table class="data-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Type</th>
            <th>Format</th>
            <th>Encryption</th>
            <th>Default</th>
            <th>Active</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($destinations as $d):
              $destId    = to_int($d['id']);
              $destType  = to_str($d['type']);
              $destBType = to_str($d['default_backup_type'] ?? 'logical');
              $destEMode = to_str($d['default_encryption_mode'] ?? 'stored');
              $destIsDefault = to_int($d['is_default'] ?? 0) === 1;
          ?>
            <tr<?= $destIsDefault ? ' class="row-default"' : '' ?>>
              <td>
                <?= e(to_str($d['name'])) ?>
                <?php if ($destIsDefault): ?>
                  <span class="badge badge-default" title="Default destination">★ default</span>
                <?php endif; ?>
                <?php
                // v3.27.8 (#1172, PR 5/5): surface the most-recent failed
                // run's reason on the destination card so operators see
                // ongoing failures without leaving the Destinations tab.
                // Controller sets $d['last_failure'] only when the newest
                // backup_runs row for this destination has status='failed'.
                $lastFailure = is_array($d['last_failure'] ?? null) ? $d['last_failure'] : null;
                if ($lastFailure !== null):
                    $errMsg   = to_str($lastFailure['error_message'] ?? '');
                    $started  = to_str($lastFailure['started_at'] ?? '');
                    $hover    = $errMsg !== ''
                        ? $errMsg . ($started !== '' ? "\n(started " . $started . ')' : '')
                        : ($started !== '' ? 'Failed run started ' . $started : 'Most recent run failed');
                ?>
                  <br>
                  <span class="badge badge-failed" title="<?= e($hover) ?>">⚠ last run failed</span>
                <?php endif; ?>
              </td>
              <td><span class="badge badge-type-<?= e($destType) ?>"><?= e(strtoupper($destType)) ?></span></td>
              <td><span class="badge badge-format-<?= e($destBType) ?>"><?= $destBType === 'logical' ? 'Logical' : 'Database' ?></span></td>
              <td>
                <?php
                  $emodeLabel = match ($destEMode) {
                      'stored'      => 'Stored key',
                      'transitory'  => 'Per-passphrase',
                      'unencrypted' => 'Unencrypted',
                      default       => $destEMode,
                  };
                  $emodeClass = $destEMode === 'unencrypted' ? 'badge-warn' : 'badge-success';
                ?>
                <span class="badge <?= e($emodeClass) ?>"><?= e($emodeLabel) ?></span>
              </td>
              <td>
                <?php if (!$destIsDefault): ?>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="set_default_destination">
                    <input type="hidden" name="id" value="<?= $destId ?>">
                    <button class="action-pill button-secondary" type="submit" title="Make this the default destination">Set default</button>
                  </form>
                <?php else: ?>
                  <span class="muted" aria-label="This destination is the default">—</span>
                <?php endif; ?>
              </td>
              <td><?= to_int($d['is_active']) === 1 ? 'Active' : 'Disabled' ?></td>
              <td class="actions">
                <?php
                if ($flashTestId === $destId && $flashTestMsg !== '') {
                    $latencySuffix = ($flashTestLatency !== null && $flashTestLatency >= 0)
                        ? ' (' . (int) $flashTestLatency . ' ms)'
                        : '';
                    $badgeText  = ($flashTestOk ? '✓ ' : '✗ ') . $flashTestMsg . $latencySuffix;
                    $badgeClass = $flashTestOk ? 'badge-success' : 'badge-failed';
                ?>
                  <span class="badge <?= e($badgeClass) ?>" data-auto-test-result>
                    <?= e($badgeText) ?>
                  </span>
                <?php } ?>
                <button class="action-pill" type="button"
                        data-drawer-url="destination_edit_drawer.php?id=<?= $destId ?>&amp;form=destination"
                        data-drawer-title="Edit destination &mdash; <?= e(to_str($d['name'])) ?>">Edit</button>
                <button class="action-pill" data-test-destination="<?= $destId ?>">Test</button>
                <button class="action-pill" data-run-now="<?= $destId ?>">Run now</button>
                <button class="action-pill" data-verify-all="<?= $destId ?>" data-destination-name="<?= e(to_str($d['name'])) ?>" title="Verify every backup on this destination">Verify all</button>
                <form method="post" style="display:inline" data-confirm-delete="this destination (schedules will be removed)" data-confirm-typename="<?= e(to_str($d['name'])) ?>">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="delete_destination">
                  <input type="hidden" name="id" value="<?= $destId ?>">
                  <button class="action-pill button-danger" type="submit">Delete</button>
                </form>
                <form method="post" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="toggle_active_destination">
                  <input type="hidden" name="id" value="<?= $destId ?>">
                  <button class="action-pill button-secondary" type="submit">
                    <?= to_int($d['is_active']) === 1 ? 'Disable' : 'Enable' ?>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <h3 style="margin-top:1.5rem">Add a destination</h3>
    <form method="post" class="destination-form">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="create_destination">
      <label>Name <input type="text" name="name" required maxlength="100"></label>
      <label>Type
        <select name="type" required data-destination-type-selector>
          <option value="s3">S3-compatible</option>
          <option value="sftp">SFTP</option>
          <option value="local">Local filesystem</option>
        </select>
      </label>

      <fieldset class="destination-fields" data-type="s3">
        <legend>S3 connection</legend>
        <?php $cfg = []; require __DIR__ . '/destination_form_s3.php'; ?>
      </fieldset>
      <fieldset class="destination-fields" data-type="sftp" hidden>
        <legend>SFTP connection</legend>
        <?php $cfg = []; require __DIR__ . '/destination_form_sftp.php'; ?>
      </fieldset>
      <fieldset class="destination-fields" data-type="local" hidden>
        <legend>Local filesystem</legend>
        <?php $cfg = []; require __DIR__ . '/destination_form_local.php'; ?>
      </fieldset>

      <?php
        // v3.25.0 #1076 #851 #846 #848: shared picker fields (backup format,
        // encryption mode, retention windows, set-as-default flag). Defaults
        // for create form: logical, stored, 0/7/4/3, no.
        $picker = ['type' => '']; // unknown until JS unhides the right fieldset
        require __DIR__ . '/_destination_picker_fields.php';
      ?>

      <button type="submit" class="action-pill">Create destination</button>
    </form>
  </section>

  <!-- Schedules list -->
  <section class="card">
    <h2>Schedules</h2>

    <?php if (count($schedules) === 0): ?>
      <p class="muted">No schedules configured.</p>
    <?php else: ?>
      <?php
        $destById = [];
        foreach ($destinations as $d) {
            $destById[to_int($d['id'])] = to_str($d['name']);
        }
      ?>
      <table class="data-table">
        <thead>
          <tr>
            <th>Destination</th>
            <th>Frequency</th>
            <th>Time/Day</th>
            <th>Retention (h/d/w/m)</th>
            <th>Last run</th>
            <th>Next run</th>
            <th>Active</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($schedules as $s):
            $destName = $destById[to_int($s['destination_id'])] ?? 'unknown';
            $freq     = to_str($s['frequency']);
            if ($freq === 'weekly') {
                $when = 'DOW ' . to_int($s['day_of_week']) . ' @ ' . to_str($s['time_of_day']);
            } elseif ($freq === 'monthly') {
                $when = 'Day ' . to_int($s['day_of_month']) . ' @ ' . to_str($s['time_of_day']);
            } elseif ($freq === 'daily') {
                $when = '@ ' . to_str($s['time_of_day']);
            } else {
                $when = '—';
            }
          ?>
            <?php $schedId = to_int($s['id']); ?>
            <tr>
              <td><?= e($destName) ?></td>
              <td><?= e($freq) ?></td>
              <td><?= e($when) ?></td>
              <td><?= to_int($s['retention_hourly']) ?>/<?= to_int($s['retention_daily']) ?>/<?= to_int($s['retention_weekly']) ?>/<?= to_int($s['retention_monthly']) ?></td>
              <td><?= e(ipam_format_datetime(to_str($s['last_run_at'] ?? '')) ?: '—') ?></td>
              <td><?= e(ipam_format_datetime(to_str($s['next_run_at'] ?? '')) ?: '—') ?></td>
              <td><?= to_int($s['is_active']) === 1 ? 'Active' : 'Disabled' ?></td>
              <td class="actions">
                <button class="action-pill" type="button"
                        data-drawer-url="destination_edit_drawer.php?id=<?= to_int($s['destination_id']) ?>&amp;form=schedule"
                        data-drawer-title="Edit schedule">Edit</button>
                <button class="action-pill" data-run-now="<?= to_int($s['destination_id']) ?>">Run now</button>
                <form method="post" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="toggle_active_schedule">
                  <input type="hidden" name="id" value="<?= $schedId ?>">
                  <button class="action-pill button-secondary" type="submit">
                    <?= to_int($s['is_active']) === 1 ? 'Disable' : 'Enable' ?>
                  </button>
                </form>
                <form method="post" style="display:inline" data-confirm-delete="this schedule">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="delete_schedule">
                  <input type="hidden" name="id" value="<?= $schedId ?>">
                  <button class="action-pill button-danger" type="submit">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <h3 style="margin-top:1.5rem">Add a schedule</h3>
    <?php require __DIR__ . '/schedule_form.php'; ?>
  </section>
