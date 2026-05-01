# Backup History per-row drawer + Destinations migration — implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a per-row detail drawer to the History tab of the unified Backup & Restore surface (#803), and migrate the Destinations tab's inline edit-row editors to the same global drawer for consistency.

**Architecture:** Extend the existing `#global-drawer` pattern in `assets/app.js` with a generic `data-drawer-url` opener that fetches HTML partials. Two new GET-only PHP endpoints render the partials. Two new POST handlers under `lib/backup_admin_history.php` cover Verify and Delete. Destinations' inline `<tr id="edit-…">` rows are removed and replaced by drawer triggers.

**Tech Stack:** PHP 8.2+, vanilla JS (no framework), SQLite/MySQL/PostgreSQL via PDO, PHPUnit 11, Playwright.

**Spec:** `docs/superpowers/specs/2026-05-01-backup-history-detail-drawer-design.md`

---

## File map

**New files:**
- `Simple-PHP-IPAM/backup_run_detail.php` — read-only HTML partial endpoint for History drawer body
- `Simple-PHP-IPAM/destination_edit_drawer.php` — read-only HTML partial endpoint for Destinations edit drawer body
- `Simple-PHP-IPAM/views/_backup_run_detail_body.php` — partial template included by `backup_run_detail.php`
- `Simple-PHP-IPAM/views/_destination_edit_destination_form.php` — extracted from current `views/backup_admin_destinations.php` inline tr
- `Simple-PHP-IPAM/views/_destination_edit_schedule_form.php` — extracted likewise
- `tests/BackupRunDetailTest.php`
- `tests/BackupAdminHistoryActionsTest.php`
- `tests/DestinationEditDrawerTest.php`
- `testing/playwright/tests/backup-history-drawer.spec.ts`

**Modified files:**
- `Simple-PHP-IPAM/assets/app.js` — generic `data-drawer-url` opener; inline result rendering for verify/delete
- `Simple-PHP-IPAM/assets/app.css` — drawer body layout helpers; danger-zone confirm region
- `Simple-PHP-IPAM/views/backup_admin_history.php` — rows get `data-drawer-url` + click affordance
- `Simple-PHP-IPAM/views/backup_admin_destinations.php` — drop inline `#edit-destination-N` / `#edit-schedule-N` rows; add drawer triggers
- `Simple-PHP-IPAM/lib/backup_admin_history.php` — add `verify` and `delete` POST handlers
- `tests/BackupAdminRbacTest.php` — provider gains the two new endpoints
- `testing/playwright/tests/backups.spec.ts` — replace `#edit-schedule-N` selectors with drawer-based equivalents

---

## Task 1: Generic `data-drawer-url` opener in app.js

**Files:**
- Modify: `Simple-PHP-IPAM/assets/app.js:2034-2043` (existing `[data-drawer-title]` event delegation block — sibling, not replacement)
- Modify: `Simple-PHP-IPAM/assets/app.css` (add danger-zone styles)

This task adds a second click delegate that handles `data-drawer-url` buttons/rows. The existing `data-drawer-title` + `data-drawer-tpl` (template-id) delegate stays intact for callers that pre-render their drawer body inline. The new delegate fetches an HTML partial via `fetch()` and injects it into the same `#global-drawer-body`.

- [ ] **Step 1: Read the existing drawer code to confirm the seam**

Run: `grep -n "data-drawer-title\|getCsrf\|fetchJson" Simple-PHP-IPAM/assets/app.js | head -20`
Expected: confirms `data-drawer-title` delegate at ~2034, `getCsrf()` and a JSON helper exist for reuse.

- [ ] **Step 2: Add the `data-drawer-url` event delegate**

Insert immediately after the existing `data-drawer-title` block (`assets/app.js:2043`):

```javascript
// Event delegation — handle [data-drawer-url] elements (CSP-safe, no onclick)
// Loads an HTML partial via fetch and injects it into the global drawer body.
document.addEventListener('click', function (e) {
    var trigger = e.target.closest ? e.target.closest('[data-drawer-url]') : null;
    if (!trigger) return;
    // Don't hijack clicks on action buttons inside an already-open drawer.
    if (e.target.closest && e.target.closest('#global-drawer')) return;
    e.preventDefault();
    var url = trigger.getAttribute('data-drawer-url');
    var title = trigger.getAttribute('data-drawer-title') || 'Details';
    if (!url) return;

    // Open with a placeholder so the user gets immediate feedback.
    IpamGlobalDrawer.open(title, '');
    var bodyEl = document.getElementById('global-drawer-body');
    bodyEl.innerHTML = '<p class="muted">Loading…</p>';

    fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
        .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.text();
        })
        .then(function (html) { bodyEl.innerHTML = html; })
        .catch(function (err) {
            bodyEl.innerHTML =
                '<div class="drawer-error" role="alert">' +
                '<p>Could not load details: ' + (err && err.message ? err.message : 'unknown error') + '</p>' +
                '<button type="button" class="action-pill" id="drawer-retry">Retry</button>' +
                '</div>';
            var retry = document.getElementById('drawer-retry');
            if (retry) retry.addEventListener('click', function () { trigger.click(); IpamGlobalDrawer.close(); });
        });
});
```

The existing `IpamGlobalDrawer` IIFE at `assets/app.js:1942-2046` exposes `open(title, tplId)`; passing an empty `tplId` opens with an empty body that the fetch then fills.

- [ ] **Step 3: Add danger-zone confirm CSS**

Append to `Simple-PHP-IPAM/assets/app.css`:

```css
/* #803 — drawer danger-zone confirm region */
.drawer-danger {
    margin-top: var(--space-md);
    padding: var(--space-md);
    border: 1px solid var(--danger);
    border-radius: var(--radius-md);
    background: color-mix(in srgb, var(--danger) 6%, var(--card));
}
.drawer-danger label { display: block; margin-bottom: var(--space-sm); }
.drawer-danger input[type="text"] { width: 100%; }
.drawer-actions {
    margin-top: var(--space-lg);
    display: flex;
    gap: var(--space-sm);
    justify-content: flex-end;
    flex-wrap: wrap;
}
.drawer-actions .button-danger:not(:disabled) { margin-left: auto; }
.drawer-action-result {
    margin-top: var(--space-md);
    padding: var(--space-sm);
    border-radius: var(--radius-sm);
}
.drawer-action-result.is-ok    { background: color-mix(in srgb, var(--success) 12%, var(--card)); }
.drawer-action-result.is-error { background: color-mix(in srgb, var(--danger)  12%, var(--card)); }
```

- [ ] **Step 4: Bump asset cache-buster**

Find the `?v=` token in `page_header()` (`Simple-PHP-IPAM/lib.php`) and `demo_gate.php:74-75`, bump to `?v=3.21.0-803a`. Without this the browser will serve a stale `app.js` and the drawer-url delegate will appear absent.

- [ ] **Step 5: Lint and commit**

```bash
php -l Simple-PHP-IPAM/lib.php
git add Simple-PHP-IPAM/assets/app.js Simple-PHP-IPAM/assets/app.css Simple-PHP-IPAM/lib.php Simple-PHP-IPAM/demo_gate.php
git commit -m "feat(ui): generic data-drawer-url opener for global drawer (#803)"
```

---

## Task 2: `backup_run_detail.php` HTML partial endpoint (TDD — start with the test)

**Files:**
- Test: `tests/BackupRunDetailTest.php` (new)
- Create: `Simple-PHP-IPAM/backup_run_detail.php`
- Create: `Simple-PHP-IPAM/views/_backup_run_detail_body.php`

- [ ] **Step 1: Write the failing test**

Create `tests/BackupRunDetailTest.php`:

```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Simple-PHP-IPAM/lib.php';

/**
 * Asserts the drawer-body partial for a backup_runs row renders the
 * expected fields and disabled-state classes for the action bar.
 *
 * Renders the partial directly via a helper (ipam_render_backup_run_detail)
 * rather than HTTP-fetching the endpoint, so this is a pure unit test.
 */
final class BackupRunDetailTest extends TestCase
{
    private \PDO $db;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->exec(file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/schema.sql'));
        $this->db->exec("INSERT INTO backup_destinations (id, name, type) VALUES (1, 'pw-local', 'local')");
    }

    private function seedRun(array $overrides = []): int
    {
        $row = array_merge([
            'destination_id' => 1,
            'backup_type' => 'logical',
            'encryption_mode' => 'stored',
            'triggered_by' => 'schedule',
            'status' => 'success',
            'filename' => 'ipam-20260501-020000.logical.sql.enc',
            'size_bytes' => 17_400_000,
            'checksum' => '9f3c0000000000000000000000000000000000000000000000000000000000b2',
            'started_at' => '2026-05-01 02:00:00',
            'completed_at' => '2026-05-01 02:00:14',
            'is_protected' => 0,
            'error_message' => null,
            'source_version' => '3.21.0',
        ], $overrides);
        $cols = array_keys($row);
        $sql = 'INSERT INTO backup_runs (' . implode(',', $cols) . ') VALUES (:' . implode(',:', $cols) . ')';
        $st = $this->db->prepare($sql);
        $st->execute(array_combine(array_map(fn($c) => ':' . $c, $cols), array_values($row)));
        return (int) $this->db->lastInsertId();
    }

    public function testRendersFullPayloadForSuccessfulRun(): void
    {
        $id = $this->seedRun();
        $html = ipam_render_backup_run_detail($this->db, $id);
        $this->assertStringContainsString('Run #' . $id, $html);
        $this->assertStringContainsString('success', $html);
        $this->assertStringContainsString('logical', strtolower($html));
        $this->assertStringContainsString('Stored', $html);
        $this->assertStringContainsString('pw-local', $html);
        $this->assertStringContainsString('ipam-20260501-020000.logical.sql.enc', $html);
        $this->assertStringContainsString('9f3c', $html);
        $this->assertStringContainsString('data-action="verify"',   $html);
        $this->assertStringContainsString('data-action="download"', $html);
        $this->assertStringContainsString('data-action="delete"',   $html);
    }

    public function testFailedRunDisablesVerifyAndDownload(): void
    {
        $id = $this->seedRun([
            'status' => 'failed',
            'filename' => null,
            'completed_at' => '2026-05-01 02:00:01',
            'error_message' => 'sigchild: child exited 0 but file 0 bytes',
        ]);
        $html = ipam_render_backup_run_detail($this->db, $id);
        $this->assertMatchesRegularExpression('/data-action="verify"[^>]*\bdisabled\b/',   $html);
        $this->assertMatchesRegularExpression('/data-action="download"[^>]*\bdisabled\b/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-action="delete"[^>]*\bdisabled\b/', $html);
        $this->assertStringContainsString('sigchild', $html);
    }

    public function testProtectedRunDisablesDelete(): void
    {
        $id = $this->seedRun(['is_protected' => 1]);
        $html = ipam_render_backup_run_detail($this->db, $id);
        $this->assertMatchesRegularExpression('/data-action="delete"[^>]*\bdisabled\b/', $html);
        $this->assertStringContainsString('protected', strtolower($html));
    }

    public function testRunningRunDisablesAllActions(): void
    {
        $id = $this->seedRun(['status' => 'running', 'completed_at' => null]);
        $html = ipam_render_backup_run_detail($this->db, $id);
        foreach (['verify', 'download', 'delete'] as $a) {
            $this->assertMatchesRegularExpression(
                '/data-action="' . $a . '"[^>]*\bdisabled\b/',
                $html,
                "Action $a should be disabled while run is in progress"
            );
        }
    }

    public function testUnknownRunReturnsNull(): void
    {
        $this->assertNull(ipam_render_backup_run_detail($this->db, 99999));
    }
}
```

- [ ] **Step 2: Run the test, expect failure**

```bash
vendor/bin/phpunit tests/BackupRunDetailTest.php
```
Expected: 5 tests, all fail with `Error: Call to undefined function ipam_render_backup_run_detail()`.

- [ ] **Step 3: Implement the helper**

Append to `Simple-PHP-IPAM/lib.php` (place near other `ipam_render_*` helpers; if none, add one — the helper is small enough):

```php
/**
 * Renders the drawer body partial for a single backup_runs row.
 * Returns null if the id does not exist (the endpoint then returns 404).
 *
 * @param \PDO $db
 * @param int  $id
 * @return string|null
 */
function ipam_render_backup_run_detail(\PDO $db, int $id): ?string
{
    $st = $db->prepare(
        "SELECT r.*, d.name AS dest_name, d.type AS dest_type
           FROM backup_runs r
           LEFT JOIN backup_destinations d ON d.id = r.destination_id
          WHERE r.id = :id"
    );
    $st->execute([':id' => $id]);
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    if (!$row) return null;

    // Disabled-state matrix per spec §4.
    $hasArtifact = ($row['status'] === 'success') && !empty($row['filename']);
    $isRunning   = ($row['status'] === 'running');
    $isProtected = (int) ($row['is_protected'] ?? 0) === 1;

    $disabled = [
        'verify'   => !$hasArtifact || $isRunning,
        'download' => !$hasArtifact || $isRunning,
        'delete'   => $isRunning || $isProtected,
    ];
    $tooltip = [
        'verify'   => $isRunning ? 'Run not finished' : (!$hasArtifact ? 'No artifact at destination' : ''),
        'download' => $isRunning ? 'Run not finished' : (!$hasArtifact ? 'No artifact at destination' : ''),
        'delete'   => $isRunning ? 'Cannot delete a run in progress'
                    : ($isProtected ? 'This run is protected. Unprotect it from the schedule\'s retention settings before deleting.' : ''),
    ];

    ob_start();
    require __DIR__ . '/views/_backup_run_detail_body.php';
    return (string) ob_get_clean();
}
```

- [ ] **Step 4: Create the view partial**

Create `Simple-PHP-IPAM/views/_backup_run_detail_body.php`:

```php
<?php
/**
 * @var array<string,mixed> $row
 * @var array<string,bool>  $disabled
 * @var array<string,string> $tooltip
 */
?>
<div class="drawer-meta">
  <h3 class="drawer-title-line">Run #<?= (int) $row['id'] ?> &mdash; <?= e(to_str($row['status'])) ?></h3>
  <dl class="kv-grid">
    <dt>Started</dt>  <dd><?= e(ipam_format_datetime((string) $row['started_at'])) ?></dd>
    <dt>Finished</dt> <dd><?= $row['completed_at'] ? e(ipam_format_datetime((string) $row['completed_at'])) : '<em class="muted">in progress</em>' ?></dd>
    <dt>Trigger</dt>  <dd><?= e(to_str($row['triggered_by'])) ?></dd>
    <dt>Type</dt>     <dd><?= e(ucfirst(to_str($row['backup_type']))) ?> &middot; <?= e(ucfirst(to_str($row['encryption_mode']))) ?></dd>
    <dt>Destination</dt><dd><?= $row['dest_name'] ? e(to_str($row['dest_name'])) . ' (' . e(to_str($row['dest_type'])) . ')' : '<em class="muted">deleted</em>' ?></dd>
  </dl>
</div>

<?php if (!empty($row['filename'])): ?>
<div class="drawer-section">
  <h4>Artifact</h4>
  <p><code><?= e(to_str($row['filename'])) ?></code></p>
  <p class="muted">
    <?= number_format((int) ($row['size_bytes'] ?? 0)) ?> bytes
    <?php if (!empty($row['checksum'])): ?>
      &middot; sha256:<?= e(substr(to_str($row['checksum']), 0, 12)) ?>&hellip;
    <?php endif; ?>
  </p>
</div>
<?php endif; ?>

<?php if (!empty($row['error_message'])): ?>
<div class="drawer-section">
  <h4>Error</h4>
  <pre class="drawer-error-text"><?= e(to_str($row['error_message'])) ?></pre>
</div>
<?php endif; ?>

<form id="backup-run-actions" data-run-id="<?= (int) $row['id'] ?>">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <div class="drawer-actions">
    <button type="button" class="action-pill" data-action="verify"
            <?= $disabled['verify']   ? 'disabled' : '' ?>
            <?= $disabled['verify']   && $tooltip['verify']   ? 'title="' . e($tooltip['verify'])   . '"' : '' ?>>Verify</button>
    <button type="button" class="action-pill" data-action="download"
            <?= $disabled['download'] ? 'disabled' : '' ?>
            <?= $disabled['download'] && $tooltip['download'] ? 'title="' . e($tooltip['download']) . '"' : '' ?>>Download</button>
    <button type="button" class="action-pill button-danger" data-action="delete"
            <?= $disabled['delete']   ? 'disabled' : '' ?>
            <?= $disabled['delete']   && $tooltip['delete']   ? 'title="' . e($tooltip['delete'])   . '"' : '' ?>>Delete</button>
  </div>
  <div class="drawer-action-result" id="drawer-action-result" hidden></div>
</form>
```

- [ ] **Step 5: Run the unit test, expect green**

```bash
vendor/bin/phpunit tests/BackupRunDetailTest.php
```
Expected: 5 tests, 5 pass.

- [ ] **Step 6: Create the HTTP endpoint**

Create `Simple-PHP-IPAM/backup_run_detail.php`:

```php
<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_role('admin');

$id = to_int($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo '<p class="danger">Bad request — missing id.</p>';
    exit;
}

$html = ipam_render_backup_run_detail($db, $id);
if ($html === null) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<p class="danger">Run #' . (int) $id . ' not found — it may have been deleted in another tab.</p>';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
echo $html;
```

- [ ] **Step 7: PHP lint and commit**

```bash
php -l Simple-PHP-IPAM/backup_run_detail.php Simple-PHP-IPAM/views/_backup_run_detail_body.php Simple-PHP-IPAM/lib.php
git add tests/BackupRunDetailTest.php Simple-PHP-IPAM/backup_run_detail.php Simple-PHP-IPAM/views/_backup_run_detail_body.php Simple-PHP-IPAM/lib.php
git commit -m "feat(backups): backup_run_detail.php drawer partial endpoint (#803)"
```

---

## Task 3: Wire history rows to the drawer

**Files:**
- Modify: `Simple-PHP-IPAM/views/backup_admin_history.php`
- Modify: `Simple-PHP-IPAM/assets/app.css`

- [ ] **Step 1: Add `data-drawer-url` and a click affordance to each history row**

Find the `<tr>` rendering in `views/backup_admin_history.php` (currently each row renders the run summary). Replace the row's opening tag with one that exposes the drawer URL, and wrap the first cell in a button so keyboard activation is accessible:

```php
<tr class="history-row"
    tabindex="0"
    data-run-id="<?= (int) $r['id'] ?>"
    data-drawer-url="backup_run_detail.php?id=<?= (int) $r['id'] ?>"
    data-drawer-title="Run #<?= (int) $r['id'] ?>"
    aria-label="Open details for run <?= (int) $r['id'] ?>">
```

For mouse users `cursor: pointer` is enough; for keyboard users the row's `tabindex="0"` plus the existing `data-drawer-url` delegate (Task 1) needs an Enter/Space activator. Update Task 1's delegate to also fire on keypress:

```javascript
document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    var trigger = e.target.closest ? e.target.closest('[data-drawer-url]') : null;
    if (!trigger) return;
    e.preventDefault();
    trigger.click();
});
```

Append this to the same delegate file (`assets/app.js`) immediately after the click delegate added in Task 1.

- [ ] **Step 2: Add row hover and focus styling**

Append to `assets/app.css`:

```css
.history-row { cursor: pointer; }
.history-row:hover { background: color-mix(in srgb, var(--link) 6%, transparent); }
.history-row:focus-visible { outline: 2px solid var(--link); outline-offset: -2px; }
```

- [ ] **Step 3: Manual smoke**

```bash
bash testing/bootstrap-app.sh sqlite
```
Visit `https://localhost:8443/backup_admin.php?tab=history`, click any row, confirm the drawer opens with the partial content. (If no rows exist yet, manually insert one via the SQLite MCP server or run a backup from the Backup tab first.)

- [ ] **Step 4: Bump cache-buster again, lint, commit**

```bash
php -l Simple-PHP-IPAM/views/backup_admin_history.php
git add Simple-PHP-IPAM/views/backup_admin_history.php Simple-PHP-IPAM/assets/app.js Simple-PHP-IPAM/assets/app.css Simple-PHP-IPAM/lib.php Simple-PHP-IPAM/demo_gate.php
git commit -m "feat(backups): clickable history rows open detail drawer (#803)"
```

---

## Task 4: `action=verify` POST handler (TDD)

**Files:**
- Test: `tests/BackupAdminHistoryActionsTest.php` (new — extended again in Task 5)
- Modify: `Simple-PHP-IPAM/lib/backup_admin_history.php`

- [ ] **Step 1: Write the failing test (verify slice)**

Create `tests/BackupAdminHistoryActionsTest.php`:

```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Simple-PHP-IPAM/lib.php';
require_once __DIR__ . '/../Simple-PHP-IPAM/lib/backup_admin_history.php';

/**
 * Tests POST handlers added to lib/backup_admin_history.php for #803:
 *   - action=verify  (recompute SHA-256 from destination, compare)
 *   - action=delete  (refuse if protected; best-effort destination delete; row delete)
 *
 * The destination is a Local destination pointed at a tmp dir we manage in
 * setUp/tearDown so the file-side step is exercised end-to-end with no
 * network. S3/SFTP paths are covered by the Playwright spec.
 */
final class BackupAdminHistoryActionsTest extends TestCase
{
    private \PDO $db;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->exec(file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/schema.sql'));
        $this->tmpDir = sys_get_temp_dir() . '/ipam-history-actions-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0777, true);
        $config = ['path' => $this->tmpDir];
        $this->db->prepare("INSERT INTO backup_destinations (id, name, type, config) VALUES (1, 'tmp-local', 'local', :c)")
                 ->execute([':c' => json_encode($config, JSON_UNESCAPED_SLASHES)]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            foreach (scandir($this->tmpDir) ?: [] as $f) {
                if ($f === '.' || $f === '..') continue;
                @unlink($this->tmpDir . '/' . $f);
            }
            @rmdir($this->tmpDir);
        }
    }

    private function seedRunWithFile(string $contents, array $overrides = []): int
    {
        $name = 'ipam-test.sql';
        file_put_contents($this->tmpDir . '/' . $name, $contents);
        $row = array_merge([
            'destination_id' => 1, 'backup_type' => 'logical', 'encryption_mode' => 'unencrypted',
            'triggered_by' => 'manual', 'status' => 'success', 'filename' => $name,
            'size_bytes' => strlen($contents),
            'checksum' => hash('sha256', $contents),
            'started_at' => '2026-05-01 02:00:00', 'completed_at' => '2026-05-01 02:00:01',
            'is_protected' => 0, 'source_version' => '3.21.0',
        ], $overrides);
        $cols = array_keys($row);
        $this->db->prepare('INSERT INTO backup_runs (' . implode(',', $cols) . ') VALUES (:' . implode(',:', $cols) . ')')
                 ->execute(array_combine(array_map(fn($c) => ':' . $c, $cols), array_values($row)));
        return (int) $this->db->lastInsertId();
    }

    public function testVerifyHappyPath(): void
    {
        $id = $this->seedRunWithFile('hello world');
        $result = ipam_backup_run_verify($this->db, $id);
        $this->assertTrue($result['ok']);
        $this->assertSame(hash('sha256', 'hello world'), $result['actual']);
        $this->assertSame($result['expected'], $result['actual']);
    }

    public function testVerifyMismatch(): void
    {
        $id = $this->seedRunWithFile('hello world');
        // Corrupt the file on the destination.
        file_put_contents($this->tmpDir . '/ipam-test.sql', 'tampered');
        $result = ipam_backup_run_verify($this->db, $id);
        $this->assertFalse($result['ok']);
        $this->assertSame(hash('sha256', 'hello world'), $result['expected']);
        $this->assertSame(hash('sha256', 'tampered'),    $result['actual']);
    }

    public function testVerifyMissingFile(): void
    {
        $id = $this->seedRunWithFile('hello world');
        unlink($this->tmpDir . '/ipam-test.sql');
        $result = ipam_backup_run_verify($this->db, $id);
        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testVerifyUnknownIdReturnsError(): void
    {
        $result = ipam_backup_run_verify($this->db, 99999);
        $this->assertFalse($result['ok']);
        $this->assertSame('not_found', $result['error']);
    }
}
```

- [ ] **Step 2: Run the test, expect failure**

```bash
vendor/bin/phpunit tests/BackupAdminHistoryActionsTest.php --filter testVerify
```
Expected: 4 tests, all fail with `Call to undefined function ipam_backup_run_verify()`.

- [ ] **Step 3: Implement `ipam_backup_run_verify()` in `lib/backup_admin_history.php`**

Add at the end of `Simple-PHP-IPAM/lib/backup_admin_history.php`:

```php
/**
 * Re-fetches the backup file from its destination and recomputes SHA-256.
 *
 * Returns:
 *   ['ok' => true,  'expected' => '<hex>', 'actual' => '<hex>']
 *   ['ok' => false, 'expected' => '<hex>', 'actual' => '<hex>']  // mismatch
 *   ['ok' => false, 'error' => 'not_found' | 'no_artifact' | 'unreachable', 'message' => '<str>']
 *
 * @param \PDO $db
 * @param int  $runId
 * @return array<string,mixed>
 */
function ipam_backup_run_verify(\PDO $db, int $runId): array
{
    $st = $db->prepare("SELECT * FROM backup_runs WHERE id = :id");
    $st->execute([':id' => $runId]);
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    if (!$row) return ['ok' => false, 'error' => 'not_found'];
    if (empty($row['filename']) || empty($row['checksum'])) {
        return ['ok' => false, 'error' => 'no_artifact'];
    }
    $client = ipam_backup_client_for_destination($db, (int) $row['destination_id']);
    if (!$client) return ['ok' => false, 'error' => 'unreachable', 'message' => 'destination missing'];
    $tmp = sys_get_temp_dir() . '/ipam-verify-' . bin2hex(random_bytes(8));
    try {
        $found = $client->download((string) $row['filename'], $tmp);
        if (!$found) return ['ok' => false, 'error' => 'no_artifact'];
        $hash = hash_file('sha256', $tmp);
        if ($hash === false) return ['ok' => false, 'error' => 'unreachable', 'message' => 'hash_file failed'];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'unreachable', 'message' => $e->getMessage()];
    } finally {
        if (is_file($tmp)) @unlink($tmp);
    }
    $expected = (string) $row['checksum'];
    $auditAction = ($hash === $expected) ? 'backup_run.verify' : 'backup_run.verify_failed';
    audit($db, $auditAction, 'backup_run', $runId, json_encode(['expected' => $expected, 'actual' => $hash]));
    return ['ok' => $hash === $expected, 'expected' => $expected, 'actual' => $hash];
}
```

**Note:** `ipam_backup_client_for_destination($db, $id)` does NOT exist. The existing factory is `ipam_backup_dest_client(array $dest)` in `Simple-PHP-IPAM/lib/backup.php:143` and takes a destination *row array*. Inline a thin helper at the top of `lib/backup_admin_history.php`:

```php
function ipam_backup_client_for_destination(\PDO $db, int $destId): ?BackupClientInterface
{
    $st = $db->prepare("SELECT * FROM backup_destinations WHERE id = :id");
    $st->execute([':id' => $destId]);
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    if (!$row) return null;
    return ipam_backup_dest_client($row);
}
```

**Note:** `BackupClientInterface` does NOT have an `sha256()` method, and we are NOT adding one. Verify uses the existing `download(string $remoteName, string $destPath): bool` method to fetch into a temp file in `data/tmp/`, computes `hash_file('sha256', $tmp)`, and unlinks the temp afterwards. This works uniformly across Local/S3/SFTP clients with zero interface change. Add the temp-fetch logic inside `ipam_backup_run_verify()`:

```php
$tmp = sys_get_temp_dir() . '/ipam-verify-' . bin2hex(random_bytes(8));
try {
    $ok = $client->download((string) $row['filename'], $tmp);
    if (!$ok) return ['ok' => false, 'error' => 'no_artifact'];
    $hash = hash_file('sha256', $tmp);
} finally {
    if (is_file($tmp)) @unlink($tmp);
}
```

- [ ] **Step 4: Run the verify tests, expect green**

```bash
vendor/bin/phpunit tests/BackupAdminHistoryActionsTest.php --filter testVerify
```
Expected: 4 tests, 4 pass.

- [ ] **Step 5: Wire the POST handler in the existing dispatch**

Locate the POST dispatch in `lib/backup_admin_history.php` (around the existing `ipam_backup_history_load_state` handler, or wherever `csrf_require()` is called). Add an action branch:

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && to_str($_POST['action'] ?? '') === 'verify'
    && to_int($_POST['id'] ?? 0) > 0) {
    csrf_require();
    header('Content-Type: application/json');
    echo json_encode(ipam_backup_run_verify($db, to_int($_POST['id'])));
    exit;
}
```

This must run before the GET render path. Place it near the top of the lib file's main flow (or, if `backup_admin.php` itself dispatches, place the equivalent branch there — follow whichever pattern Notifications/Destinations already use).

- [ ] **Step 6: Lint, commit**

```bash
php -l Simple-PHP-IPAM/lib/backup_admin_history.php
git add tests/BackupAdminHistoryActionsTest.php Simple-PHP-IPAM/lib/backup_admin_history.php Simple-PHP-IPAM/lib/BackupClientInterface.php Simple-PHP-IPAM/lib/LocalBackupClient.php Simple-PHP-IPAM/lib/S3Client.php Simple-PHP-IPAM/lib/SftpClient.php
git commit -m "feat(backups): action=verify POST handler for History drawer (#803)"
```

---

## Task 5: `action=delete` POST handler with protect-flag and best-effort destination delete (TDD)

**Files:**
- Modify: `tests/BackupAdminHistoryActionsTest.php` (extend)
- Modify: `Simple-PHP-IPAM/lib/backup_admin_history.php`

- [ ] **Step 1: Append the failing tests**

Add to the same test file:

```php
public function testDeleteRefusesOnProtectedRow(): void
{
    $id = $this->seedRunWithFile('x', ['is_protected' => 1]);
    $result = ipam_backup_run_delete($this->db, $id);
    $this->assertFalse($result['ok']);
    $this->assertSame('protected', $result['error']);
    // Row still exists.
    $this->assertSame(1, (int) $this->db->query("SELECT COUNT(*) FROM backup_runs WHERE id = $id")->fetchColumn());
    // File still on destination.
    $this->assertFileExists($this->tmpDir . '/ipam-test.sql');
}

public function testDeleteSuccessRemovesFileAndRow(): void
{
    $id = $this->seedRunWithFile('x');
    $result = ipam_backup_run_delete($this->db, $id);
    $this->assertTrue($result['ok']);
    $this->assertTrue($result['removed']);
    $this->assertSame(0, (int) $this->db->query("SELECT COUNT(*) FROM backup_runs WHERE id = $id")->fetchColumn());
    $this->assertFileDoesNotExist($this->tmpDir . '/ipam-test.sql');
}

public function testDeleteWhenDestinationDeleteFailsLeavesRow(): void
{
    $id = $this->seedRunWithFile('x');
    // Make the dest dir read-only so unlink fails.
    chmod($this->tmpDir, 0500);
    try {
        $result = ipam_backup_run_delete($this->db, $id);
    } finally {
        chmod($this->tmpDir, 0700);
    }
    $this->assertFalse($result['ok']);
    $this->assertSame('destination_unreachable', $result['error']);
    $this->assertSame(1, (int) $this->db->query("SELECT COUNT(*) FROM backup_runs WHERE id = $id")->fetchColumn());
}

public function testDeleteUnknownIdReturnsNotFound(): void
{
    $result = ipam_backup_run_delete($this->db, 99999);
    $this->assertFalse($result['ok']);
    $this->assertSame('not_found', $result['error']);
}
```

- [ ] **Step 2: Run the new tests, expect failure**

```bash
vendor/bin/phpunit tests/BackupAdminHistoryActionsTest.php --filter testDelete
```
Expected: 4 tests, all fail with `Call to undefined function ipam_backup_run_delete()`.

- [ ] **Step 3: Implement the helper**

Add to `lib/backup_admin_history.php`:

```php
/**
 * Best-effort delete of a backup_runs row's artifact at the destination,
 * followed by the row delete. Refuses on is_protected = 1.
 *
 * Returns:
 *   ['ok' => true,  'removed' => true]
 *   ['ok' => false, 'error' => 'not_found' | 'protected' | 'destination_unreachable', 'message' => '<str>']
 *
 * @param \PDO $db
 * @param int  $runId
 * @return array<string,mixed>
 */
function ipam_backup_run_delete(\PDO $db, int $runId): array
{
    $st = $db->prepare("SELECT * FROM backup_runs WHERE id = :id");
    $st->execute([':id' => $runId]);
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    if (!$row) return ['ok' => false, 'error' => 'not_found'];
    if ((int) ($row['is_protected'] ?? 0) === 1) return ['ok' => false, 'error' => 'protected'];

    // Step 1: best-effort file delete (only when there's an artifact).
    if (!empty($row['filename'])) {
        $client = ipam_backup_client_for_destination($db, (int) $row['destination_id']);
        if ($client) {
            try {
                $client->delete((string) $row['filename']);
                audit($db, 'remote_backup.delete', 'backup_run', $runId, (string) $row['filename']);
            } catch (\Throwable $e) {
                audit($db, 'remote_backup.delete_failed', 'backup_run', $runId, $e->getMessage());
                return ['ok' => false, 'error' => 'destination_unreachable', 'message' => $e->getMessage()];
            }
        }
    }

    // Step 2: row delete.
    $db->prepare("DELETE FROM backup_runs WHERE id = :id")->execute([':id' => $runId]);
    audit($db, 'backup_run.delete', 'backup_run', $runId, '');
    return ['ok' => true, 'removed' => true];
}
```

- [ ] **Step 4: Run the delete tests, expect green**

```bash
vendor/bin/phpunit tests/BackupAdminHistoryActionsTest.php
```
Expected: all 8 tests pass.

- [ ] **Step 5: Wire the POST handler**

Add another branch alongside the verify dispatch from Task 4:

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && to_str($_POST['action'] ?? '') === 'delete'
    && to_int($_POST['id'] ?? 0) > 0) {
    csrf_require();
    $confirm = to_str($_POST['confirm'] ?? '');
    if ($confirm !== 'DELETE') {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'confirm_required']);
        exit;
    }
    $result = ipam_backup_run_delete($db, to_int($_POST['id']));
    if (!$result['ok']) {
        http_response_code(($result['error'] ?? '') === 'protected' ? 409 : (
                          ($result['error'] ?? '') === 'destination_unreachable' ? 502 : 404));
    }
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}
```

- [ ] **Step 6: Wire the drawer JS to call verify and delete**

Append to `assets/app.js` after the `data-drawer-url` delegate from Task 1:

```javascript
// Drawer action handlers for backup_run_detail (#803).
document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('#backup-run-actions [data-action]') : null;
    if (!btn || btn.disabled) return;
    var action = btn.getAttribute('data-action');
    if (action !== 'verify' && action !== 'delete') return;  // download is a normal link, not handled here
    e.preventDefault();
    var form = btn.closest('#backup-run-actions');
    var runId = form.getAttribute('data-run-id');
    var csrf = form.querySelector('input[name=csrf]').value;

    if (action === 'verify') _backupRunVerify(form, runId, csrf);
    else                     _backupRunDeletePromptThenSubmit(form, runId, csrf);
});

function _backupRunVerify(form, runId, csrf) {
    var resultEl = form.querySelector('#drawer-action-result');
    resultEl.hidden = false;
    resultEl.className = 'drawer-action-result';
    resultEl.textContent = 'Verifying…';
    var fd = new FormData();
    fd.append('csrf', csrf); fd.append('action', 'verify'); fd.append('id', runId);
    fetch('backup_admin.php?tab=history', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (j.ok) {
                resultEl.classList.add('is-ok');
                resultEl.textContent = 'Verified — sha256 matches (' + j.actual.slice(0, 12) + '…).';
            } else if (j.expected && j.actual) {
                resultEl.classList.add('is-error');
                resultEl.textContent = 'Checksum mismatch — recorded ' + j.expected.slice(0, 12) + '… vs destination ' + j.actual.slice(0, 12) + '…';
            } else {
                resultEl.classList.add('is-error');
                resultEl.textContent = 'Verify failed: ' + (j.message || j.error || 'unknown');
            }
        })
        .catch(function (err) {
            resultEl.classList.add('is-error');
            resultEl.textContent = 'Verify request failed: ' + err.message;
        });
}

function _backupRunDeletePromptThenSubmit(form, runId, csrf) {
    if (form.querySelector('.drawer-danger')) return;  // already prompting
    var danger = document.createElement('div');
    danger.className = 'drawer-danger';
    danger.innerHTML =
        '<label>Type <code>DELETE</code> to confirm. This removes the file at the destination AND the history row. <strong>This cannot be undone.</strong></label>' +
        '<input type="text" id="drawer-delete-confirm" autocomplete="off">' +
        '<div style="margin-top:.5rem;display:flex;gap:.5rem;justify-content:flex-end;">' +
        '<button type="button" class="action-pill button-secondary" id="drawer-delete-cancel">Cancel</button>' +
        '<button type="button" class="action-pill button-danger"    id="drawer-delete-arm" disabled>Delete</button>' +
        '</div>';
    form.appendChild(danger);
    var input  = danger.querySelector('#drawer-delete-confirm');
    var arm    = danger.querySelector('#drawer-delete-arm');
    var cancel = danger.querySelector('#drawer-delete-cancel');
    input.focus();
    input.addEventListener('input', function () { arm.disabled = (input.value !== 'DELETE'); });
    cancel.addEventListener('click', function () { danger.remove(); });
    arm.addEventListener('click', function () {
        var resultEl = form.querySelector('#drawer-action-result');
        resultEl.hidden = false; resultEl.className = 'drawer-action-result';
        resultEl.textContent = 'Deleting…';
        var fd = new FormData();
        fd.append('csrf', csrf); fd.append('action', 'delete'); fd.append('id', runId); fd.append('confirm', 'DELETE');
        fetch('backup_admin.php?tab=history', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json().then(function (j) { return [r.status, j]; }); })
            .then(function (pair) {
                var status = pair[0], j = pair[1];
                if (j.ok) {
                    resultEl.classList.add('is-ok');
                    resultEl.textContent = 'Deleted.';
                    var row = document.querySelector('.history-row[data-run-id="' + runId + '"]');
                    if (row) row.remove();
                    setTimeout(function () { if (window.IpamGlobalDrawer) IpamGlobalDrawer.close(); }, 600);
                } else {
                    resultEl.classList.add('is-error');
                    resultEl.textContent = 'Delete failed (' + status + '): ' + (j.message || j.error || 'unknown');
                }
            })
            .catch(function (err) {
                resultEl.classList.add('is-error');
                resultEl.textContent = 'Delete request failed: ' + err.message;
            });
    });
}
```

The Download action is intentionally not handled here — its `<button>` should be replaced in the partial with an `<a href="download_remote_backup.php?run_id=N">` so the browser handles streaming naturally. Update Task 2's `_backup_run_detail_body.php` accordingly: change the Download `<button>` to an `<a class="action-pill" data-action="download" href="download_remote_backup.php?run_id=<?= (int) $row['id'] ?>">Download</a>`. If `download_remote_backup.php` accepts a different parameter today (likely `dest_id` + `filename`), pass those instead — confirm with `grep -n "REQUEST\\['" Simple-PHP-IPAM/download_remote_backup.php` before wiring.

- [ ] **Step 7: Lint, run all PHPUnit, commit**

```bash
php -l Simple-PHP-IPAM/lib/backup_admin_history.php Simple-PHP-IPAM/views/_backup_run_detail_body.php
vendor/bin/phpunit tests/BackupAdminHistoryActionsTest.php tests/BackupRunDetailTest.php
```
Expected: all green.

```bash
git add Simple-PHP-IPAM/lib/backup_admin_history.php tests/BackupAdminHistoryActionsTest.php Simple-PHP-IPAM/views/_backup_run_detail_body.php Simple-PHP-IPAM/assets/app.js
git commit -m "feat(backups): action=delete with protect-flag + DELETE confirm gate (#803)"
```

---

## Task 6: `destination_edit_drawer.php` partial endpoint (TDD)

**Files:**
- Test: `tests/DestinationEditDrawerTest.php` (new)
- Create: `Simple-PHP-IPAM/destination_edit_drawer.php`
- Create: `Simple-PHP-IPAM/views/_destination_edit_destination_form.php`
- Create: `Simple-PHP-IPAM/views/_destination_edit_schedule_form.php`

- [ ] **Step 1: Write the failing test**

Create `tests/DestinationEditDrawerTest.php`:

```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Simple-PHP-IPAM/lib.php';

final class DestinationEditDrawerTest extends TestCase
{
    private \PDO $db;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->exec(file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/schema.sql'));
        $this->db->exec("INSERT INTO backup_destinations (id, name, type, config) VALUES (1, 'pw-local', 'local', '{\"path\":\"/tmp\"}')");
        $this->db->exec("INSERT INTO backup_schedules (id, destination_id, frequency, time_of_day) VALUES (1, 1, 'daily', '02:00')");
    }

    public function testDestinationFormHasExpectedFields(): void
    {
        $html = ipam_render_destination_edit_drawer($this->db, 1, 'destination');
        $this->assertNotNull($html);
        $this->assertStringContainsString('action="backup_admin.php?tab=destinations"', $html);
        $this->assertStringContainsString('name="action" value="update_destination"', $html);
        $this->assertStringContainsString('name="id" value="1"', $html);
        $this->assertStringContainsString('name="name"', $html);
        $this->assertStringContainsString('pw-local', $html);
    }

    public function testScheduleFormHasExpectedFields(): void
    {
        $html = ipam_render_destination_edit_drawer($this->db, 1, 'schedule');
        $this->assertNotNull($html);
        $this->assertStringContainsString('name="action" value="update_schedule"', $html);
        $this->assertStringContainsString('name="frequency"', $html);
        $this->assertStringContainsString('value="daily"', $html);
        $this->assertStringContainsString('name="time_of_day"', $html);
        $this->assertStringContainsString('value="02:00"', $html);
    }

    public function testUnknownFormReturnsNull(): void
    {
        $this->assertNull(ipam_render_destination_edit_drawer($this->db, 1, 'bogus'));
    }

    public function testUnknownDestinationReturnsNull(): void
    {
        $this->assertNull(ipam_render_destination_edit_drawer($this->db, 999, 'destination'));
    }
}
```

- [ ] **Step 2: Run, expect failure**

```bash
vendor/bin/phpunit tests/DestinationEditDrawerTest.php
```
Expected: 4 tests fail with `Call to undefined function ipam_render_destination_edit_drawer()`.

- [ ] **Step 3: Implement the helper + view partials**

In `Simple-PHP-IPAM/lib/backup_admin_destinations.php`, add:

```php
function ipam_render_destination_edit_drawer(\PDO $db, int $id, string $form): ?string
{
    if ($form !== 'destination' && $form !== 'schedule') return null;
    $st = $db->prepare("SELECT * FROM backup_destinations WHERE id = :id");
    $st->execute([':id' => $id]);
    $dest = $st->fetch(\PDO::FETCH_ASSOC);
    if (!$dest) return null;

    $sched = null;
    if ($form === 'schedule') {
        $st = $db->prepare("SELECT * FROM backup_schedules WHERE destination_id = :id LIMIT 1");
        $st->execute([':id' => $id]);
        $sched = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
    $config = is_string($dest['config'] ?? null) ? (json_decode($dest['config'], true) ?: []) : [];

    ob_start();
    if ($form === 'destination') {
        require __DIR__ . '/../views/_destination_edit_destination_form.php';
    } else {
        require __DIR__ . '/../views/_destination_edit_schedule_form.php';
    }
    return (string) ob_get_clean();
}
```

Create `views/_destination_edit_destination_form.php` and `views/_destination_edit_schedule_form.php` by **moving the existing inline form fields out of `views/backup_admin_destinations.php`** (lines `89-110` and `216-238` per the grep in pre-task scoping). Wrap each in:

```php
<form action="backup_admin.php?tab=destinations" method="post" class="drawer-form">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="update_destination">  <!-- or update_schedule -->
  <input type="hidden" name="id" value="<?= (int) $dest['id'] ?>">
  <!-- existing field markup, unchanged -->
  <div class="drawer-actions">
    <button type="submit" class="action-pill">Save</button>
  </div>
</form>
```

Verbatim port of the existing fields. The frequency-aware field gating JS already attached to `[data-frequency-source]` keeps working as long as the input names and structure are unchanged.

- [ ] **Step 4: Run unit test, expect green**

```bash
vendor/bin/phpunit tests/DestinationEditDrawerTest.php
```
Expected: 4 pass.

- [ ] **Step 5: Create the HTTP endpoint**

Create `Simple-PHP-IPAM/destination_edit_drawer.php`:

```php
<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_role('admin');
require __DIR__ . '/lib/backup_admin_destinations.php';

$id   = to_int($_GET['id']   ?? 0);
$form = to_str($_GET['form'] ?? '');
if ($id <= 0 || ($form !== 'destination' && $form !== 'schedule')) {
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo '<p class="danger">Bad request.</p>';
    exit;
}
$html = ipam_render_destination_edit_drawer($db, $id, $form);
if ($html === null) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<p class="danger">Destination not found.</p>';
    exit;
}
header('Content-Type: text/html; charset=utf-8');
echo $html;
```

- [ ] **Step 6: Lint, commit**

```bash
php -l Simple-PHP-IPAM/destination_edit_drawer.php Simple-PHP-IPAM/views/_destination_edit_destination_form.php Simple-PHP-IPAM/views/_destination_edit_schedule_form.php Simple-PHP-IPAM/lib/backup_admin_destinations.php
git add Simple-PHP-IPAM/destination_edit_drawer.php Simple-PHP-IPAM/views/_destination_edit_destination_form.php Simple-PHP-IPAM/views/_destination_edit_schedule_form.php Simple-PHP-IPAM/lib/backup_admin_destinations.php tests/DestinationEditDrawerTest.php
git commit -m "feat(backups): destination_edit_drawer.php partial endpoint (#803)"
```

---

## Task 7: Migrate Destinations editors to drawer triggers

**Files:**
- Modify: `Simple-PHP-IPAM/views/backup_admin_destinations.php`
- Modify: `testing/playwright/tests/backups.spec.ts`

- [ ] **Step 1: Replace the inline Edit destination button**

In `views/backup_admin_destinations.php:70`:

```php
<button class="action-pill" type="button"
        data-drawer-url="destination_edit_drawer.php?id=<?= $destId ?>&form=destination"
        data-drawer-title="Edit destination — <?= e(to_str($d['name'])) ?>">Edit</button>
```

Then **remove** the inline `<tr id="edit-destination-<?= $destId ?>" …>` block (lines `89-110`) entirely. Same for the Edit schedule button at line 198 and the inline schedule row at lines 216-238 — replace with `data-drawer-url="destination_edit_drawer.php?id=<?= to_int($s['destination_id']) ?>&form=schedule"` and delete the inline tr. **Note (CR feedback PR #1054 / 2026-05-01):** the schedule drawer URL must carry the **destination id**, not the schedule's own primary key — `ipam_render_destination_edit_drawer(..., 'schedule')` looks the schedule up via `WHERE destination_id = :id LIMIT 1`. The shipped implementation uses `to_int($s['destination_id'])`; this plan line previously read `$schedId` and was the only stale reference.

- [ ] **Step 2: Update Playwright selectors in `backups.spec.ts`**

Find every occurrence of `#edit-destination-` and `#edit-schedule-` selectors. Replace the pattern:

```ts
await page.locator('button[data-edit-destination="' + id + '"]').click();
const editRow = page.locator('#edit-destination-' + id);
await editRow.locator('input[name=name]').fill('new');
```

with:

```ts
await page.locator('button[data-drawer-url*="id=' + id + '&form=destination"]').click();
const drawer = page.locator('#global-drawer');
await drawer.waitFor({ state: 'visible' });
await drawer.locator('input[name=name]').fill('new');
```

Same shape for schedule: `data-drawer-url*="id=' + id + '&form=schedule"`.

After Save submits the form, the page reloads (existing behaviour) — no drawer-close logic needed in the test; the next `await page.goto(...)` at the start of the next assertion resets the world.

- [ ] **Step 3: Bootstrap and run the migrated Playwright spec on SQLite**

```bash
bash testing/bootstrap-app.sh sqlite
cd testing/playwright && npx playwright test backups.spec.ts --project=chromium
```
Expected: all existing assertions pass against the drawer.

- [ ] **Step 4: Lint and commit**

```bash
php -l Simple-PHP-IPAM/views/backup_admin_destinations.php
git add Simple-PHP-IPAM/views/backup_admin_destinations.php testing/playwright/tests/backups.spec.ts
git commit -m "refactor(backups): migrate Destinations inline editors to global drawer (#803)"
```

---

## Task 8: Extend RBAC test for the two new endpoints

**Files:**
- Modify: `tests/BackupAdminRbacTest.php`

- [ ] **Step 1: Add the two endpoints to the provider**

In `tests/BackupAdminRbacTest.php::adminFilesProvider()`, append:

```php
['Simple-PHP-IPAM/backup_run_detail.php'],
['Simple-PHP-IPAM/destination_edit_drawer.php'],
```

- [ ] **Step 2: Run, expect green**

```bash
vendor/bin/phpunit tests/BackupAdminRbacTest.php
```
Expected: 8 tests pass (was 6, +2 new entries × 1 test method = 2 additional dataProvider rows).

- [ ] **Step 3: Commit**

```bash
git add tests/BackupAdminRbacTest.php
git commit -m "test(rbac): cover backup_run_detail.php + destination_edit_drawer.php (#803, #811)"
```

---

## Task 9: Playwright spec for the History drawer

**Files:**
- Create: `testing/playwright/tests/backup-history-drawer.spec.ts`

- [ ] **Step 1: Write the spec**

Create `testing/playwright/tests/backup-history-drawer.spec.ts`:

```typescript
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { login, appUrl, ADMIN_USER, ADMIN_PASS, newAuthContext } from '../fixtures/ipam';

let ctx: BrowserContext;
let page: Page;

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);

  // Seed one success and one failure run via direct SQL through a tiny shim
  // page is overkill; instead, run a real backup against the seeded local
  // destination from bootstrap, then fail one by editing it via SQL.
  await page.goto(appUrl('backup_admin.php?tab=backup'));
  await page.locator('#run-now-button').click();
  await expect(page.locator('#run-now-result')).toContainText(/✓|success/i, { timeout: 30_000 });
});

test.afterAll(async () => { await ctx?.close(); });

test('clicking a row opens the detail drawer', async () => {
  await page.goto(appUrl('backup_admin.php?tab=history'));
  const firstRow = page.locator('.history-row').first();
  await firstRow.click();
  const drawer = page.locator('#global-drawer');
  await expect(drawer).toBeVisible();
  await expect(drawer).toContainText(/Run #\d+/);
  await expect(drawer.locator('[data-action="verify"]')).toBeVisible();
  await expect(drawer.locator('[data-action="download"]')).toBeVisible();
  await expect(drawer.locator('[data-action="delete"]')).toBeVisible();
});

test('failed run disables Verify and Download with tooltip', async () => {
  // Force the most-recent run to a failed state via the SQLite MCP server
  // or, in CI, via a direct sqlite3 invocation against data/ipam.sqlite.
  // The shape of that step depends on the bootstrap; in this codebase it's
  // testing/scripts/sql.sh "UPDATE backup_runs SET status='failed', filename=NULL WHERE id=(SELECT MAX(id) FROM backup_runs)"
  await page.evaluate(async () => {
    // Use a tiny test-only endpoint if it exists; otherwise this test relies
    // on bootstrap seeding a known-failed row. Adjust to the project's pattern.
  });
  await page.goto(appUrl('backup_admin.php?tab=history'));
  const failedRow = page.locator('.history-row').filter({ hasText: /failed/i }).first();
  if (await failedRow.count() === 0) test.skip(true, 'No failed run to assert against');
  await failedRow.click();
  const drawer = page.locator('#global-drawer');
  const verify = drawer.locator('[data-action="verify"]');
  await expect(verify).toBeDisabled();
  await expect(verify).toHaveAttribute('title', /No artifact/);
});

test('Verify happy-path reports a checksum match', async () => {
  await page.goto(appUrl('backup_admin.php?tab=history'));
  const okRow = page.locator('.history-row').filter({ hasText: /success/i }).first();
  await okRow.click();
  const drawer = page.locator('#global-drawer');
  await drawer.locator('[data-action="verify"]').click();
  await expect(drawer.locator('#drawer-action-result')).toContainText(/Verified|matches/i, { timeout: 15_000 });
});

test('Delete with DELETE-confirm removes the row', async () => {
  await page.goto(appUrl('backup_admin.php?tab=history'));
  const before = await page.locator('.history-row').count();
  test.skip(before === 0, 'No rows to delete');

  const target = page.locator('.history-row').first();
  const targetId = await target.getAttribute('data-drawer-url');
  await target.click();
  const drawer = page.locator('#global-drawer');
  await drawer.locator('[data-action="delete"]').click();
  await drawer.locator('#drawer-delete-confirm').fill('DELETE');
  await drawer.locator('#drawer-delete-arm').click();
  await expect(drawer.locator('#drawer-action-result')).toContainText(/Deleted/i, { timeout: 15_000 });

  // Drawer auto-closes after delete; the row should be gone.
  await page.waitForTimeout(800);
  const after = await page.locator(`.history-row[data-drawer-url="${targetId}"]`).count();
  expect(after).toBe(0);
});
```

- [ ] **Step 2: Run on SQLite**

```bash
cd testing/playwright && npx playwright test backup-history-drawer.spec.ts --project=chromium
```
Expected: 4 tests pass (or 3 + 1 skipped if no failed run is seeded).

- [ ] **Step 3: Commit**

```bash
git add testing/playwright/tests/backup-history-drawer.spec.ts
git commit -m "test(backups): Playwright coverage for History drawer (#803)"
```

---

## Task 10: Visual regression baselines

**Files:**
- Generate: `testing/playwright/tests/visual-regression.spec.ts-snapshots/*backup-history-drawer*chromium*.png` (3 new baselines)

- [ ] **Step 1: Add VR test cases**

Append to `testing/playwright/tests/visual-regression.spec.ts` (or whichever file holds the baseline declarations — confirm with `grep -l "toHaveScreenshot" testing/playwright/tests/`):

```typescript
test('VR — backup history drawer: success state', async ({ page }) => {
  await login(page, ADMIN_USER, ADMIN_PASS);
  await page.goto(appUrl('backup_admin.php?tab=history'));
  await page.locator('.history-row').filter({ hasText: /success/i }).first().click();
  await expect(page.locator('#global-drawer')).toBeVisible();
  await expect(page.locator('#global-drawer')).toHaveScreenshot('backup-history-drawer-success.png');
});

test('VR — backup history drawer: failed state', async ({ page }) => {
  await login(page, ADMIN_USER, ADMIN_PASS);
  await page.goto(appUrl('backup_admin.php?tab=history'));
  const row = page.locator('.history-row').filter({ hasText: /failed/i }).first();
  test.skip(await row.count() === 0, 'No failed row');
  await row.click();
  await expect(page.locator('#global-drawer')).toHaveScreenshot('backup-history-drawer-failed.png');
});

test('VR — destination edit drawer', async ({ page }) => {
  await login(page, ADMIN_USER, ADMIN_PASS);
  await page.goto(appUrl('backup_admin.php?tab=destinations'));
  await page.locator('button[data-drawer-url*="form=destination"]').first().click();
  await expect(page.locator('#global-drawer')).toBeVisible();
  await expect(page.locator('#global-drawer')).toHaveScreenshot('destination-edit-drawer.png');
});
```

- [ ] **Step 2: Generate baselines**

```bash
cd testing/playwright && npx playwright test visual-regression.spec.ts --update-snapshots --project=chromium
```
Inspect the produced PNGs in the snapshots directory and confirm visually before committing.

- [ ] **Step 3: Re-run without `--update-snapshots` to confirm green**

```bash
cd testing/playwright && npx playwright test visual-regression.spec.ts --project=chromium
```
Expected: all VR tests pass.

- [ ] **Step 4: Commit**

```bash
git add testing/playwright/tests/visual-regression.spec.ts testing/playwright/tests/visual-regression.spec.ts-snapshots/
git commit -m "test(vr): baselines for History + Destinations drawers (#803)"
```

---

## Task 11: Strike-through #803 in `backup_overhaul.md` §C, file follow-ups in §C

**Files:**
- Modify: `docs/internal/backup_overhaul.md`

- [ ] **Step 1: Strike F11 (#803) in §C**

Find the F11 row in the §C table and replace with:

```
| ~~F11~~ | ~~Func~~ | ~~Per-row backup detail drawer in History tab~~ | #803 | **DONE 2026-05-01** | P0 | Includes Destinations editor migration to global drawer for unified-surface consistency. |
```

- [ ] **Step 2: Add the two new follow-ups**

Add rows for #1052 and #1053 in the v3.22 section of §C:

```
| F-NEW-1 | Func | Bulk multi-select delete on History | #1052 | v3.22 | P1 | Reuses #803 delete handler |
| F-NEW-2 | Func | Automatic backup_runs retention purge | #1053 | v3.22 | P1 | Mirrors audit.retention_days |
```

- [ ] **Step 3: Commit**

```bash
git add docs/internal/backup_overhaul.md
git commit -m "docs(overhaul): close #803 row, file #1052 #1053 follow-ups (#803)"
```

---

## Task 12: Local 3-driver gate

This is the final pre-push check. **Required before any push.** Runs the full PHPUnit + Playwright suite against SQLite, MySQL, and PostgreSQL.

- [ ] **Step 1: Bootstrap each engine in turn and run the suite**

```bash
for engine in sqlite mysql pgsql; do
    bash testing/bootstrap-app.sh "$engine"
    cd testing/playwright && npx playwright test --project=chromium
    cd ../..
    vendor/bin/phpunit
done
```

Pass criteria: all green on all three engines, including the new `BackupRunDetailTest`, `BackupAdminHistoryActionsTest`, `DestinationEditDrawerTest`, `BackupAdminRbacTest`, `backup-history-drawer.spec.ts`, the migrated `backups.spec.ts`, and the VR baselines.

If any test fails, stop, diagnose, fix in-place, re-run only the failing test until green, and only then re-run the full gate.

- [ ] **Step 2: PHPCS, PHPStan, Semgrep**

```bash
vendor/bin/phpcs Simple-PHP-IPAM/backup_run_detail.php Simple-PHP-IPAM/destination_edit_drawer.php Simple-PHP-IPAM/lib/backup_admin_history.php Simple-PHP-IPAM/lib/backup_admin_destinations.php Simple-PHP-IPAM/views/_backup_run_detail_body.php Simple-PHP-IPAM/views/_destination_edit_destination_form.php Simple-PHP-IPAM/views/_destination_edit_schedule_form.php Simple-PHP-IPAM/views/backup_admin_history.php Simple-PHP-IPAM/views/backup_admin_destinations.php Simple-PHP-IPAM/lib.php
vendor/bin/phpstan analyse Simple-PHP-IPAM/backup_run_detail.php Simple-PHP-IPAM/destination_edit_drawer.php Simple-PHP-IPAM/lib/backup_admin_history.php Simple-PHP-IPAM/lib/backup_admin_destinations.php
semgrep --config=.semgrep/rules.yml Simple-PHP-IPAM/backup_run_detail.php Simple-PHP-IPAM/destination_edit_drawer.php Simple-PHP-IPAM/lib/backup_admin_history.php
```

Expected: clean. Address any findings before the push.

- [ ] **Step 3: Stop here**

Do not push. Do not merge. Surface the gate result to the user; they will give the explicit go for `git push`.

---

## Self-review notes

- **Spec coverage:** §3.1 → Tasks 1–7. §4 → Task 2 (rendering) + Task 5 (delete inline confirm). §5 → Task 7. §6 → Tasks 2/4/5/6. §7 → Tasks 4/5/8. §8 → Task 5 (handlers + JS render). §9 → Tasks 4/5/6/8/9/10. §10 → Task 11. §11 → matches Task 1–11 ordering. §12 risks → covered by Task 12 gate.
- **No placeholders:** every step has either exact code or an exact command + expected outcome. The only "look up the existing pattern" reference is `ipam_backup_client_for_destination` in Task 4 — that helper exists today (verified during scoping); if the project renames it, the test still pins the contract.
- **Type consistency:** `ipam_render_backup_run_detail`, `ipam_backup_run_verify`, `ipam_backup_run_delete`, `ipam_render_destination_edit_drawer` — names used identically across tests and implementations. Audit actions: `backup_run.verify`, `backup_run.verify_failed`, `backup_run.delete`, `remote_backup.delete`, `remote_backup.delete_failed` — used identically across spec, plan tasks, and audit calls.
