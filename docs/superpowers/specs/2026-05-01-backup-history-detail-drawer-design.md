# Backup History per-row detail drawer + Destinations drawer migration — design

**Status:** approved 2026-05-01
**Issues:** #803 (per-row history detail drawer, F11 in `backup_overhaul.md`)
**In-scope follow-on (same release):** migration of Destinations inline edit/schedule editors to the global drawer — completed under #803 to keep the unified surface internally consistent.
**Spawned issues filed during design:** #1052 (bulk multi-select delete, v3.22), #1053 (automatic `backup_runs` retention purge, v3.22).

---

## 1. Goal

After #803, an operator on the History tab can click any backup run and see the full payload — destination, schedule context, artifact metadata, error excerpt — without leaving the page or hunting in audit. From the same drawer they can verify the artifact's checksum, download it, or delete it (row + remote file in one action).

The Destinations tab gets the same treatment: edit destination and edit schedule both move from inline expanders into the same global drawer. This brings the entire unified surface to a single drill-down vocabulary.

## 2. Non-goals

- Bulk multi-select delete on the History tab — filed as **#1052** for v3.22.
- Automatic `backup_runs` retention purge — filed as **#1053** for v3.22.
- A "stale running" reaper — already on v3.22's roadmap as #797 F32.
- Filter chips on History — that's #804, lands immediately after #803.
- Restoring directly from the drawer — out; restore lives on its own tab. The drawer can deep-link to `backup_admin.php?tab=restore&run_id=N` if there's appetite later, but no link in v3.21.

## 3. Architecture

### 3.1 Components

| Component | Status | Purpose |
|---|---|---|
| `Simple-PHP-IPAM/backup_admin.php` | existing | Unified surface router; History tab passes payload to view. |
| `Simple-PHP-IPAM/views/backup_admin_history.php` | existing → modified | Each row gains `data-drawer-url`, `cursor: pointer`, hover highlight. |
| `Simple-PHP-IPAM/views/backup_admin_destinations.php` | existing → modified | Edit destination / edit schedule buttons gain `data-drawer-url`; inline `#edit-destination-N` / `#edit-schedule-N` regions are removed. |
| `Simple-PHP-IPAM/backup_run_detail.php` | **new** | Read-only HTML partial endpoint. GET `?id=N`. Renders the drawer body for a `backup_runs` id. |
| `Simple-PHP-IPAM/destination_edit_drawer.php` | **new** | Read-only HTML partial endpoint. GET `?id=N&form=destination\|schedule`. Renders the drawer body for the destination edit form or schedule edit form. |
| `Simple-PHP-IPAM/lib/backup_admin_history.php` | existing → extended | Add POST handlers `action=verify` and `action=delete`. |
| `Simple-PHP-IPAM/lib/backup_admin_destinations.php` | existing → modified | Existing POST handlers untouched at the protocol level; the inline-form rendering moves out to `destination_edit_drawer.php`. |
| `Simple-PHP-IPAM/assets/app.js` | existing → extended | Generic `data-drawer-url` opener: fetches the URL into `#global-drawer`, traps focus, Esc/backdrop close, action POSTs render results inline. |
| `Simple-PHP-IPAM/assets/app.css` | existing → minor | Drawer body layout helpers; "danger zone" inline-confirm region styling. |

### 3.2 Boundaries

- **Read-only partials (`backup_run_detail.php`, `destination_edit_drawer.php`)** have zero side effects, gate `require_role('admin')`, and can be unit-tested by asserting their HTML output.
- **Action handlers** (verify, delete) accept `id` + verb + CSRF, return JSON. They don't know about drawers, only about the underlying state mutation.
- **Drawer JS** is presentation-only — replacing it (e.g., with htmx later) doesn't require server changes.

### 3.3 Data flow — opening the drawer

```text
Click row
  → JS reads data-drawer-url
  → fetch GET backup_run_detail.php?id=N
  → response = HTML partial
  → JS injects into #global-drawer body
  → JS opens drawer (focus-trap + aria-modal=true)
```

### 3.4 Data flow — Verify

```text
Verify button click
  → JS POST backup_admin.php?tab=history with { csrf, action=verify, id }
  → handler streams the file from the destination
  → SHA-256 recomputed, compared to backup_runs.checksum
  → JSON { ok, expected, actual, latency_ms }
  → JS renders inline result block under the action bar
  → audit: backup_run.verify or backup_run.verify_failed
```

### 3.5 Data flow — Delete

```text
Delete button click
  → drawer expands an inline "danger zone" with a text input
  → user types DELETE
  → button arms; click POSTs { csrf, action=delete, id }
  → handler: refuse if is_protected = 1 (HTTP 409)
  → handler: best-effort destination file delete
  → if file delete fails → HTTP 502 with destination error; row stays
  → if file delete succeeds → DELETE FROM backup_runs WHERE id = ?
  → JSON { ok, removed: true }
  → JS animates row out of the table, closes drawer
  → audit: remote_backup.delete (or .delete_failed) + backup_run.delete
```

## 4. Drawer body — History detail

```text
─────────────────────────────────────
Run #1234 — success                  ✕
─────────────────────────────────────
Started   2026-05-01 02:00:00 EDT
Finished  2026-05-01 02:00:14 EDT  (14s)
Trigger   schedule (daily 02:00 UTC)
Type      Logical · Stored encryption
Dest      MinIO (s3) — ipam/prod/

Artifact
  ipam-20260501-020000.logical.sql.enc
  17.4 MB · sha256:9f3c…b2e1

Schedule
  daily 02:00 UTC · GFS 7/4/12

(only on failure)
Error
  > sigchild: child exited 0 but file 0 bytes
  (sigchild fallback reclassified as failed)
─────────────────────────────────────
[ Verify ]  [ Download ]   [ Delete ]
─────────────────────────────────────
```

**Disabled-state matrix:**

| State | Verify | Download | Delete |
|---|---|---|---|
| `status = 'success'`, file present, not protected | enabled | enabled | enabled |
| `status = 'failed'` | disabled (tooltip: "No artifact at destination") | disabled (same tooltip) | enabled |
| `is_protected = 1` | enabled | enabled | disabled (tooltip: "This run is protected. Unprotect it from the schedule's retention settings before deleting.") |
| `status = 'running'` | disabled (tooltip: "Run not finished") | disabled | disabled (tooltip: "Cannot delete a run in progress") |

`is_protected` is read from the column already present in `backup_runs` (default 0; no migration needed). The UI for setting the flag ships in F14 (v3.25); until then no row will ever be protected, but the disabled-state code path exists from day one.

## 5. Drawer body — Destinations migration

The existing inline `#edit-destination-N` and `#edit-schedule-N` regions are removed. The Edit destination and Edit schedule buttons get `data-drawer-url="destination_edit_drawer.php?id=N&form=destination|schedule"`.

Drawer body for **edit destination** is the same form fields as today, just rendered inside the drawer container instead of a row-expander. POST target stays `backup_admin.php?tab=destinations` with the existing `update_destination` action.

Drawer body for **edit schedule** is the same fields. POST target stays `backup_admin.php?tab=destinations` with the existing `update_schedule` action.

Form `id` attributes stay stable to minimise churn; only the wrapping container differs. Playwright selectors that read `#edit-schedule-${id}` change to read `#global-drawer` once the drawer is open. The existing `backups.spec.ts` is updated in lockstep — no test gets deleted, every assertion that exists today still has a counterpart.

## 6. Server endpoints

### 6.1 `backup_run_detail.php` — new

- **Method:** GET
- **Auth:** `require_role('admin')`
- **CSRF:** not required (idempotent read)
- **Query:** `id` (int)
- **Response:** HTML partial (no `<html>`, no `<head>`, no `<body>`). Includes the action-bar markup with disabled-state classes pre-applied.
- **404:** if id not found.
- **Added to `tests/BackupAdminRbacTest.php` provider** so the role gate is enforced from day one.

### 6.2 `destination_edit_drawer.php` — new

- **Method:** GET
- **Auth:** `require_role('admin')`
- **CSRF:** not required (idempotent read)
- **Query:** `id` (int), `form` (`destination` | `schedule`)
- **Response:** HTML partial.
- **400:** if `form` is anything else.
- **Added to `tests/BackupAdminRbacTest.php` provider.**

### 6.3 `backup_admin.php?tab=history` POST extensions

Two new actions in the existing handler in `lib/backup_admin_history.php`:

- `action=verify` — `id` (int). Streams the file from the destination, recomputes SHA-256, compares. Returns JSON `{ ok, expected, actual, latency_ms }`. Audit `backup_run.verify` / `backup_run.verify_failed`.
- `action=delete` — `id` (int). Refuse with HTTP 409 if `is_protected = 1`. Otherwise best-effort destination delete (HTTP 502 on failure; row not deleted), then `DELETE FROM backup_runs`. JSON `{ ok, removed: true }`. Audit `remote_backup.delete` (or `.delete_failed`) and `backup_run.delete`.

## 7. Auth, CSRF, and audit

| Endpoint | Auth | CSRF | Audit on success | Audit on failure |
|---|---|---|---|---|
| `backup_run_detail.php` (GET) | admin | n/a | — (read) | — |
| `destination_edit_drawer.php` (GET) | admin | n/a | — (read) | — |
| `backup_admin.php?tab=history` action=verify (POST) | admin | required | `backup_run.verify` | `backup_run.verify_failed` |
| `backup_admin.php?tab=history` action=delete (POST) | admin | required | `remote_backup.delete` + `backup_run.delete` | `remote_backup.delete_failed` (no row delete attempted) |
| Existing `backup_admin.php?tab=destinations` POST handlers | admin | required | unchanged | unchanged |

## 8. Error handling

- **Drawer fetch fails (network or 5xx):** drawer shows centred error block with Retry button.
- **404 on detail fetch:** drawer shows "Run #N not found — it may have been deleted in another tab." with a Refresh-page button.
- **Verify mismatch:** drawer surfaces "Checksum mismatch — recorded `9f3c…` vs destination `a012…`" inline. Does not change `backup_runs.status`. Audit `backup_run.verify_failed`.
- **Delete with destination unreachable:** HTTP 502 with destination error; drawer surfaces "Could not delete file at destination: <error>. The history row was not removed; retry once the destination is reachable. Issue #1053 will eventually purge stale rows automatically."
- **Delete on protected row:** HTTP 409. Drawer's Delete button should already be disabled; this is a defence-in-depth path for forged requests.
- **All AJAX errors** render inline with `aria-live="polite"` so screen readers announce them.

## 9. Testing

### 9.1 PHPUnit — new files

- `tests/BackupRunDetailTest.php`
  - `backup_run_detail.php` returns 200 + partial for an existing run
  - returns 404 for an unknown id
  - disabled-state classes are pre-rendered (parse the HTML and assert)
  - `is_protected = 1` row marks Delete disabled
  - failed run marks Verify + Download disabled

- `tests/BackupAdminHistoryActionsTest.php`
  - verify happy-path returns ok + matching SHA
  - verify mismatch returns ok=false + both hashes
  - delete refuses HTTP 409 on protected row
  - delete returns 502 + leaves row when destination unreachable (mock destination)
  - delete success removes row + writes both audit rows

- `tests/DestinationEditDrawerTest.php`
  - returns 200 + partial for `form=destination`
  - returns 200 + partial for `form=schedule`
  - returns 400 for unknown `form`
  - returns 404 for unknown id

### 9.2 PHPUnit — extended

- `tests/BackupAdminRbacTest.php` — add `backup_run_detail.php` and `destination_edit_drawer.php` to the provider; the existing role-guard lint covers them automatically.

### 9.3 Playwright

- Extend `testing/playwright/tests/backup_restore.spec.ts` (or add `backup-history-drawer.spec.ts`):
  - Click row → drawer opens with body content + three actions
  - Failed run → Verify + Download disabled with the right tooltip text
  - Protected run (seeded via SQL) → Delete disabled with the right tooltip
  - Verify success path
  - Delete success removes row from table, closes drawer
  - Delete on protected row receives 409 (defence-in-depth)

- Update `testing/playwright/tests/backups.spec.ts` — replace `#edit-schedule-${id}` / `#edit-destination-${id}` selectors with the drawer-based equivalents. No test is removed; every assertion that currently passes against the inline editor must still pass against the drawer.

### 9.4 Visual regression

- Add one chromium baseline for the open History drawer (success state).
- Add one chromium baseline for the open History drawer (failed state with disabled actions).
- Add one chromium baseline for the open Destinations edit-destination drawer.
- Update existing Destinations baseline that captured the inline editor expanded.

## 10. Out-of-scope items spawned during design

| ID | Description | Milestone |
|---|---|---|
| #1052 | Bulk multi-select delete on History tab | v3.22.0 |
| #1053 | Automatic retention purge for `backup_runs` rows | v3.22.0 |

Both filed in this brainstorm; rows added to `docs/internal/backup_overhaul.md` §C as part of the implementation PR.

## 11. Build sequence (preview — full plan in writing-plans phase)

1. Generic drawer JS (`data-drawer-url` opener), CSS for the inline danger-zone confirm region.
2. `backup_run_detail.php` partial endpoint + History view wiring.
3. `lib/backup_admin_history.php` action=verify + audit + tests.
4. `lib/backup_admin_history.php` action=delete + protect-flag check + best-effort destination delete + audit + tests.
5. `destination_edit_drawer.php` partial endpoint.
6. Migrate Destinations inline editors to drawer; update `backups.spec.ts` selectors.
7. Visual regression baselines.
8. CLAUDE.md (D8) + README "What's new" (D9) updates batched with #804's filter chips so both ship in the same release-prep PR.

## 12. Risks and mitigations

| Risk | Mitigation |
|---|---|
| Drawer focus-trap regresses other tabs | Existing Wave 4 baseline + explicit Tab/Shift+Tab tests stay green; add one drawer-open test per migrated surface. |
| Destinations migration breaks bookmarked deep-links | The Destinations tab URL is unchanged; only the row-expander is gone. No deep-link surface is lost. |
| Verify is slow on large encrypted files | Streamed SHA-256 (already the pattern in `download_remote_backup.php`); drawer shows a spinner while in flight; no synchronous blocking. |
| Delete races with another tab | The row's `id` is the only state needed; second delete returns 404, which the drawer surfaces gracefully. |
| F14 protect-flag UI lands later than expected | The `is_protected` column is already in schema; the disabled-state code path is exercised by tests using direct SQL seeding. No real-world impact until the protect UI ships. |
