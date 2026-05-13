# Parked features

Features that have been designed (or partially built), deliberately not shipped, and where the design notes still apply. Use this doc as a holding pen so the work isn't lost between sessions.

Promotion rules:

- A parked item moves out of this doc only when (a) it has a GitHub issue with a milestone, or (b) the design has been actively rejected and the entry is deleted with a one-line note in `docs/internal/lessons-learned.md`.
- When updating an entry, keep the "Status" line current — that's the field future-you (or another agent) will read first.

---

## Operator-passphrase backup creation (IPAMBKP3 transitory write path)

**Status:** parked as of v3.28.0 (2026-05-13). Codec ready; no UI/orchestrator caller.

**What works today**

- `backup_encrypt_stream_v3()` in `Simple-PHP-IPAM/lib.php` supports `BACKUP_V3_MODE_TRANSITORY` end-to-end (Argon2id KDF, AES-256-CTR, HMAC-SHA256, header-embedded parameters).
- Restore-side handles transitory archives via the upload-and-restore wizard (`views/backup_admin_restore.php:204-211`) and `tools/decrypt-backup.php --passphrase`.

**What's missing**

- No caller invokes `backup_encrypt_stream_v3()` with `BACKUP_V3_MODE_TRANSITORY`. The single call site (`Simple-PHP-IPAM/lib/backup.php:1027`) always passes `BACKUP_V3_MODE_STORED`.
- `run_backup_now.php` accepts only `destination_id` — no passphrase parameter.
- The destination picker hides the transitory radio for new rows (`views/_destination_picker_fields.php:13-16`) — it's preserved on edit but not creatable.

**Why parked, not built**

- The differentiator is "no key at rest on this server." That covers a narrow slice: ad-hoc encrypted exports before a risky change, archives handed to legal/auditors, or air-gapped installs where the operator refuses to commit `backup_vault_key` to disk.
- For scheduled backups it's structurally a non-starter — no operator is present at 02:00 to type a passphrase. Transitory is inherently a manual flow.
- For *manual* backups, Stored mode is strictly better UX in 99% of cases, and the v3.28.0 marketing copy can be reworded to describe transitory as the upload-and-restore credential (which is shipped) rather than a creator-side flow (which isn't).

**Design sketch** (when this comes off the shelf)

1. `run_backup_now.php` gains a `passphrase` POST field. Reject when set on any trigger other than `manual` (cron/CLI cannot supply one).
2. `ipam_backup_run_for_destination()` grows a `?string $passphrase = null` parameter and routes to `BACKUP_V3_MODE_TRANSITORY` when present, regardless of the destination's `default_encryption_mode`.
3. Backup tab gets a "Encrypt with my passphrase (transitory)" toggle that reveals passphrase + confirm fields. Argon2id derivation takes ~300ms at default params — render a "deriving key…" beat in the UI before the upload begins. Browser autofill disabled; clipboard paste allowed.
4. Server-side: validate min length (recommend 12+), call `sodium_memzero()` on the passphrase variable after the codec returns, never log the value, never include it in `backup_runs.details`.
5. `backup_runs.encryption_mode` records `transitory` for the resulting row (the column already accepts it).
6. Audit row: `backup.run` with `encryption_mode=transitory` and `triggered_by=manual`. Do not include the passphrase or any derivative.
7. Tests: round-trip create → download → upload → restore with the same passphrase across SQLite / MySQL / PostgreSQL; passphrase rejected on cron path; passphrase-less manual run still works.

**Original reference**

- `docs/superpowers/plans/2026-05-08-v3.27.1-hotfix.md:221` — "If an operator wants per-archive passphrase protection they use the manual run-now flow with a separate prompt (out of scope for this hotfix; track separately)."

**Decision trigger**

Build it when a real operator asks for it on the issue tracker, when an enterprise-tier evaluation gates on "no key at rest," or when a v4.x release has slack for a small backup-tab feature. Until then the codec sits ready and the docs/marketing reflect upload-side only.
