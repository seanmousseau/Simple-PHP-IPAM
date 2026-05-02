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
backup.failed              backup.skipped_concurrent     backup.reaped
backup.retention_pruned    backup.wal_checkpoint_failed
backup_run.bulk_delete     backup_run.purge
```

`backup_run.bulk_delete` (`entity_type=backup_run`) — emitted once per row deleted via the History tab's bulk-select UI (v3.22.0 #1052). One audit entry per row keeps forensics aligned with the per-row `backup_run.delete` / `backup_run.delete_failed` vocabulary; `$details` carries `actor=bulk` so bulk deletions are distinguishable from single-row drawer deletes.

`backup_run.purge` (`entity_type=system`, `entity_id=null`) — emitted once per cron tick that actually deletes rows during the time-based `backup_runs` purge (v3.22.0 #1053). Driven by `backup_runs.retention_days` and `backup_runs.prune_batch_size`; skips rows with `status='running'` (reaper's job) and `is_protected=1` (operator keep). One audit entry per call (not per row) — purge volume can be large and per-row entries would drown the audit log; `$details` carries `deleted=N retention_days=R batch_size=B`. No row is emitted on a no-op tick.

`backup.skipped_concurrent` (`entity_type=destination`) — orchestrator refused to start because a non-stale `running` row already exists for the destination (v3.22.0 #815).

`backup.reaped` (`entity_type=backup_run`) — reaper marked a stuck `running` row as `failed` past the threshold (v3.22.0 #815). The orchestrator runs the reaper inline; cron Task 8b runs it independently every tick so liveness doesn't depend on someone clicking Run-now.

`backup.wal_checkpoint_failed` (`entity_type=system`, `entity_id=null`) — SQLite `PRAGMA wal_checkpoint(...)` raised an exception during a backup or restore (v3.22.0 #819). The `$details` field encodes `context=<site> error=<truncated message>` where `<site>` is `backup` or `restore`. The checkpoint is best-effort (an optimization, not a correctness requirement) so the operation continues after logging.

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
