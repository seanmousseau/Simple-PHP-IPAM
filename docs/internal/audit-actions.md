# Audit log action reference

> Canonical list of every `audit()` action string used in the codebase. The naming convention is the load-bearing rule (kept in `CLAUDE.md`); this is the lookup table.

Call signature: `audit(PDO $db, string $action, string $entityType, ?int $entityId, string $details = ''): void` (see `Simple-PHP-IPAM/lib.php`). `$details` is optional and defaults to `''`. By convention `$details` is a JSON-encoded snapshot of the change so the audit log stays greppable, but the column is plain `TEXT` and any string is accepted.

**Convention:** `<entity>.<verb>` — lowercase, dot-separated, verb is one of `create`/`update`/`delete`/`toggle_active`/`set_role`/`reset_password`/etc. Never invent new verbs casually; reuse the existing vocabulary so log queries (`WHERE action LIKE 'subnet.%'`) stay consistent.

## Auth

```text
auth.login              auth.login_failed       auth.login_blocked
auth.oidc_login         auth.oidc_provision     auth.oidc_link       auth.oidc_failed
auth.mfa_method_switch  auth.mfa_preferred_set
auth.totp_login         auth.email_otp_login    auth.passkey_challenge
```

## Step-up auth (v3.27.0)

```text
auth.sudo_passed        auth.sudo_failed        auth.sudo_rate_limited
auth.step_up_policy.updated
```

`auth.sudo_passed` (`entity_type=auth`, `entity_id=null`) — emitted by `ipam_sudo_verify()` when a fresh proof satisfies the install's step-up policy (v3.27.0 #1107). `$details` carries `method=<m> ip=<ip>` where `<m>` is one of `totp|email_otp|webauthn|password|oidc_reauth`. Cached-grant short-circuits do **not** emit a row — the audit only fires when an actual proof is verified.

`auth.sudo_failed` (`entity_type=auth`) — emitted by `ipam_sudo_verify()` when a proof is rejected (v3.27.0 #1107). `$details` carries `method=<m> ip=<ip> reason=<r>` where `<r>` is a stable code (e.g. `totp_invalid`, `email_otp_invalid`, `webauthn_no_challenge`, `password_invalid`, `method_unavailable`, `disabled_password_hash`). Increments the `sudo` rate-limit bucket via `record_auth_failure()` so back-pressure matches a failed login.

`auth.sudo_rate_limited` (`entity_type=auth`) — emitted when the `sudo` bucket cap is hit (v3.27.0 #1107). `$details` carries `ip=<ip>`. Once recorded, subsequent proofs from the same IP are refused for the cap window without further audit rows.

`auth.step_up_policy.updated` (`entity_type=auth`) — emitted by `settings.php` after a step-up policy save commits (v3.27.0 #1108). `$details` carries `methods=<csv> ttl=<sec> by=<u>` where `<csv>` is the comma-separated list of allowed methods after the save and `<u>` is the saving admin's username. Saving the policy is itself a sudo action, so `auth.sudo_passed` typically appears immediately before this row.

## Core entities

```text
subnet.create   subnet.update   subnet.delete
address.create  address.update  address.delete
site.create     site.update     site.delete
vlan.create     vlan.update     vlan.delete
vrf.create      vrf.update      vrf.delete
contact.create  contact.update  contact.delete
tag.create      tag.update      tag.delete
```

## Users & access

```text
user.create         user.delete         user.toggle_active
user.set_role       user.reset_password user.update_profile
user.oidc_link      user.oidc_unlink

apikey.create       apikey.deactivate   apikey.activate     apikey.delete
```

## Custom fields

```text
custom_field.create  custom_field.update  custom_field.delete  custom_field.reorder
```

## DHCP & address operations

```text
dhcp_pool.reserve   dhcp_pool.clear
address.arp_import
```

## Bulk import/export

```text
db.export   db.import   db.import_failed
export.*    import.*
```

The `export.*` and `import.*` wildcards cover per-entity bulk operations (`export.subnets`, `import.addresses`, etc.) — use the format `<scope>.<entity>` when adding new ones.

## Scanner

```text
scan.run                 scan.schedule_create
scan.schedule_update     scan.schedule_delete
```

## Settings

```text
setting.update
```

Single action covers every setting change. The `$details` JSON contains old and new value (sensitive values are masked — see `adding-a-setting.md`).

## Backups

```text
backup.failed                       backup.skipped_concurrent     backup.reaped
backup.retention_pruned             backup.wal_checkpoint_failed
backup.connection_test_failed       backup.schedule_overdue       backup.encryption_change
backup.cancel                       backup.protect                backup.unprotect
backup.set_default_destination      backup.verify_bulk
backup.vault_key.revealed           backup.vault_key.set
backup.vault_key.replaced           backup.vault_key.sudo_failed
backup.vault_key.reveal_failed      backup.vault_key.reveal_rate_limited
backup_run.bulk_delete              backup_run.purge
```

`backup.vault_key.revealed` (`entity_type=vault`, `entity_id=null`) — emitted by the destinations admin's reveal-key handler (v3.26.0 #1098) after a successful sudo-mode password re-prompt. `$details` carries `user=<username> fingerprint=<8 hex>`. The raw key never appears in the audit row; the fingerprint lets a forensic investigation confirm which key was revealed without storing the secret in the audit log.

`backup.vault_key.set` / `backup.vault_key.replaced` (`entity_type=vault`) — emitted when an admin sets the first vault key, or replaces an existing one (v3.26.0 #1098). `$details` carries `user=<username> mode=<generate|paste> fingerprint=<8 hex>`. Replace is gated on `SELECT 1 FROM backup_runs WHERE encryption_mode != 'unencrypted'` returning empty so a key swap cannot orphan existing archives.

`backup.vault_key.sudo_failed` (`entity_type=vault`) — **DEPRECATED in v3.27.0; removed in v3.28.0.** Originally emitted when a vault-key admin action's local-password sudo prompt was refused (v3.26.0 #1098). v3.27.0 migrates the vault-key gate to the unified `ipam_sudo_verify()` helper, which emits `auth.sudo_failed` instead. The legacy action is retained as a parallel emit for one release so existing log queries don't break; new dashboards should query `auth.sudo_failed` with `details LIKE '%method=password%'`. Drop the alias when the v3.28.0 cleanup ticket lands.

`backup.vault_key.reveal_failed` (`entity_type=vault`) — emitted when reveal succeeded the sudo-prompt but no vault key is configured (v3.26.0 #1098). `$details` carries `user=<username> reason=<no_key>`. Distinct from `sudo_failed` so an incident response can tell "wrong password" from "key gone".

`backup.vault_key.reveal_rate_limited` (`entity_type=vault`) — emitted when reveal is refused because the per-IP cap (5 attempts / 15 minutes) has been hit (v3.26.0 #1098). `$details` carries `ip=<client_ip> user=<username>`.

`backup_run.bulk_delete` (`entity_type=backup_run`) — emitted once per row deleted via the History tab's bulk-select UI (v3.22.0 #1052). One audit entry per row keeps forensics aligned with the per-row `backup_run.delete` / `backup_run.delete_failed` vocabulary; `$details` carries `actor=bulk` so bulk deletions are distinguishable from single-row drawer deletes.

`backup_run.purge` (`entity_type=system`, `entity_id=null`) — emitted once per cron tick that actually deletes rows during the time-based `backup_runs` purge (v3.22.0 #1053). Driven by `backup_runs.retention_days` and `backup_runs.prune_batch_size`; skips rows with `status='running'` (reaper's job) and `is_protected=1` (operator keep). One audit entry per call (not per row) — purge volume can be large and per-row entries would drown the audit log; `$details` carries `deleted=N retention_days=R batch_size=B`. No row is emitted on a no-op tick.

`backup.skipped_concurrent` (`entity_type=destination`) — orchestrator refused to start because a non-stale `running` row already exists for the destination (v3.22.0 #815).

`backup.reaped` (`entity_type=backup_run`) — reaper marked a stuck `running` row as `failed` past the threshold (v3.22.0 #815). The orchestrator runs the reaper inline; cron Task 8b runs it independently every tick so liveness doesn't depend on someone clicking Run-now.

`backup.wal_checkpoint_failed` (`entity_type=system`, `entity_id=null`) — SQLite `PRAGMA wal_checkpoint(...)` raised an exception during a backup or restore (v3.22.0 #819). The `$details` field encodes `context=<site> error=<truncated message>` where `<site>` is `backup` or `restore`. The checkpoint is best-effort (an optimization, not a correctness requirement) so the operation continues after logging.

`backup.connection_test_failed` (`entity_type=destination`) — emitted by cron Task 6c when a destination's periodic connection re-test transitions from healthy to failing (v3.22.0 §2.4). Emitted once per healthy→failing transition, not on every persistent-failure tick — the per-destination state map in the `backup.destination_health` setting gates re-alerting until recovery. `$details` carries `name=<destname> message=<truncated client error>`. Recovery does not emit a corresponding audit row.

`backup.schedule_overdue` (`entity_type=schedule`) — emitted by cron Task 6d when an active schedule's `next_run_at` is older than `now() - backup.notify_overdue_grace_minutes` and no alert has yet been emitted for that exact `next_run_at` value (v3.22.0 §2.4). Per-schedule cooldown lives in the `backup.schedule_overdue_state` setting, keyed by schedule id; once the schedule actually fires (advancing `next_run_at`), the state resets and a fresh overdue cycle is detectable. `$details` carries `destination=<destname> expected_at=<iso> overdue_minutes=N`.

`backup.encryption_change` (`entity_type=destination`) — emitted by the destination edit handler in `lib/backup_admin_destinations.php` when an admin changes a destination's encryption mode (v3.22.0 §2.4; v3.25.0 #851 generalised from the legacy `encrypt` boolean to the `default_encryption_mode` enum `stored|transitory|unencrypted`). Recorded independently of the generic `destination.update` audit so a security-conscious operator can grep audit history for all crypto-policy changes without having to diff the generic update payload. `$details` carries `name=<destname> old=<mode> new=<mode>`.

`backup.cancel` (`entity_type=destination`) — emitted by `ipam_backup_run_for_destination()` when an in-flight backup is canceled by an operator before or during upload (v3.25.0 #856). `$details` carries `run_id=<id> phase=<before-upload|mid-upload>` and the truncated underlying error when canceled mid-upload. The corresponding `backup_runs` row is marked `status='failed'` with `error_message='canceled-by-operator: <phase>'` rather than introducing a new `canceled` enum value to keep the multi-engine schema parity surface unchanged.

`backup.protect` / `backup.unprotect` (`entity_type=backup_run`) — emitted by the History tab's protect/unprotect action handler in `lib/backup_admin_history.php` (v3.25.0 #847). `$details` carries `is_protected=<0|1>`. Protected rows are excluded from `ipam_retention_compute_deletions()` and refused by `ipam_backup_run_delete()` until unprotected.

`backup.set_default_destination` (`entity_type=destination`) — emitted by the `set_default_destination` action handler in `lib/backup_admin_destinations.php` (v3.25.0 #848). `$details` carries `name=<destname>`. Setting a row to default clears every other row's `is_default` flag in the same transaction (single-row uniqueness enforced application-side; see `ipam_destinations_set_default()`).

`backup.verify_bulk` (`entity_type=destination`) — emitted by the destination tab's "Verify all" bulk action (v3.25.0 #850). `$details` carries `total=N success=N failed=N` summary; per-row verify outcomes are emitted as the existing `backup_run.verify` audit so the bulk action does not double-log.

---

## Adding a new action

1. Match the existing `<entity>.<verb>` shape. Add to this table when the PR lands.
2. Use one of the existing verbs (`create`/`update`/`delete`/`toggle_active`/etc.) unless the action is genuinely novel.
3. The `$details` field should be a JSON string with enough context to reconstruct what happened — for `update` actions, include both old and new values where it adds value.
4. For sensitive values (credentials, keys), mask in `$details` before encoding.

## Cross-references

- `CLAUDE.md` → "Audit logging" — naming convention.
- `adding-an-api-endpoint.md` step 5 — when API write handlers must emit audit entries.
- `Simple-PHP-IPAM/lib.php` `audit()` — implementation.
