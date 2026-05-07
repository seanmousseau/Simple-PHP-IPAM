# Backup & Restore — incident runbook

> **Audience:** the operator on the receiving end of a 3am pager when a backup or restore goes wrong. Not a feature guide — see [docs/backups.md](../backups.md) and [docs/restore.md](../restore.md) for that.
>
> **Living document.** Each milestone appends new failure modes as they surface. If you fix a backup-related production issue, add a section here with the symptom, the diagnosis steps, and the fix.

---

## Contents

- [First five minutes](#first-five-minutes)
- [Where to look](#where-to-look)
- [Failure modes](#failure-modes)
  - [Stuck `running` row](#stuck-running-row)
  - [S3 403 / SigV4 mismatch](#s3-403--sigv4-mismatch)
  - [`mysqldump` exits 0 but file is empty](#mysqldump-exits-0-but-file-is-empty)
  - [SFTP fingerprint mismatch](#sftp-fingerprint-mismatch)
  - [Restore dry-run fails to split](#restore-dry-run-fails-to-split)
  - [Encryption mismatch (`IPAMBKP*`)](#encryption-mismatch-ipambkp)
  - [Stale staging files filling `data/tmp/`](#stale-staging-files-filling-datatmp)
  - [`next_run_at` stuck in the past](#next_run_at-stuck-in-the-past)
- [Cross-version restore](#cross-version-restore)
- [Tenant-export hand-off (forward-looking)](#tenant-export-hand-off-forward-looking)

---

## First five minutes

1. **Get the run ID.** Open `backup_admin.php?tab=history` and grab the failed row's `id`. Every step below references it.
2. **Confirm the failure is recent.** A row with `status = 'failed'` from three weeks ago is not your incident — check `started_at` against the current time.
3. **Read the row's `error_message` column** before opening anything else. 70% of incidents are answered in one line there.
4. **Check `audit_log`** for the matching action — `backup.failed`, `destination.test` (if the operator just changed the destination), or `db.restore` rows around the same timestamp.
5. **Don't restart cron yet.** A retrying schedule will keep producing the same failure rows; pause the destination first if you need quiet to investigate.

```sql
-- Get the most recent failure with its full context
SELECT r.id, r.started_at, r.completed_at, d.name AS dest, r.triggered_by,
       r.backup_type, r.encryption_mode, r.error_message
  FROM backup_runs r
  LEFT JOIN backup_destinations d ON d.id = r.destination_id
 WHERE r.status = 'failed'
 ORDER BY r.started_at DESC
 LIMIT 5;
```

---

## Where to look

| Source | What it tells you | How to read it |
|---|---|---|
| `backup_runs` (DB table) | Per-run truth. Status, timestamps, destination, error message. | The History tab is a paginated view; for ad-hoc queries hit the DB directly. |
| `audit_log` (DB table) | Action-level events: who triggered, when, the destination touched. | Filter by `entity_type = 'backup'` or `'destination'` for the relevant window. |
| `data/tmp/` | Staged restore files (`restore_*.sqlite`, `restore_*.sql`) and partial decryption temps (`*.decrypting.*`). | `ls -la data/tmp/` — anything older than 1h is leaked. |
| `data/ipam-cron.log` (or wherever you point cron stderr) | Stack traces when the dump fails before a `backup_runs` row is written. | `tail -200` and search for `Exception` / `Error`. |
| Web server error log (`/var/log/apache2/error.log`, etc.) | Manual-run failures hit here, not cron's log. | Look around the `started_at` timestamp of the failed row. |
| S3 / SFTP server logs | Authoritative for "did the upload actually happen." | Provider-specific. CloudTrail for AWS, S3 access logs, the SFTP server's own log. |
| **Admin → Health** | SMTP transport status, `app_secret` presence, disk-free in `data/`. | The fastest pre-flight before deeper digging. |

---

## Failure modes

### Stuck `running` row

**Symptom:** History shows a row in `running` status that hasn't finished after several minutes; the schedule will not fire again because the orchestrator thinks the previous run is still in flight.

**Diagnose:**

```sql
SELECT id, started_at, destination_id, triggered_by
  FROM backup_runs
 WHERE status = 'running'
   AND started_at < datetime('now', '-30 minutes');
```

If the PHP process that wrote the row is no longer running (`ps -ef | grep cron.php` shows nothing), the row is orphaned.

**Fix (manual until the stale-row reaper in [#797 F32](https://github.com/seanmousseau/Simple-PHP-IPAM/issues/797) ships in v3.22):**

```sql
UPDATE backup_runs
   SET status = 'failed',
       completed_at = datetime('now'),
       error_message = 'orphaned by operator — original process gone'
 WHERE id = <run_id>;
```

Then unpause the destination's schedule. The next cron tick will retry.

---

### S3 403 / SigV4 mismatch

**Symptom:** `error_message` contains `SignatureDoesNotMatch` or `403 Forbidden` from S3.

**Common causes (in descending order):**

1. **Clock drift on the IPAM host.** SigV4 signatures are time-bound. More than ~15 minutes of skew rejects every request.

   ```bash
   timedatectl status        # confirm NTP is active and synchronised
   ```

2. **Rotated access key.** The destination's stored `secret_key` no longer matches the live key.

   - Open Destinations → Edit on the row → re-enter the `secret_key` (the field is masked, you must paste it fresh) → Test.

3. **Bucket policy or IAM change.** The key is valid but no longer authorised for `s3:PutObject` on the bucket.

   - Provider-specific check (AWS IAM Access Analyser, Backblaze B2 application key permissions, R2 token scopes).

4. **`endpoint_url` mismatched to `region`.** Cloudflare R2 with `region = us-east-1` but `endpoint_url = <account>.r2.cloudflarestorage.com` will fail SigV4 validation. R2 must use `region = auto`.

**Bonus check:** the v3.19.1 SigV4 canonicalisation hotfix is in production. Confirm `IPAM_VERSION` is `>= 3.19.1` if you're seeing this on a fresh install or a long-paused environment:

```bash
grep IPAM_VERSION Simple-PHP-IPAM/version.php
```

---

### `mysqldump` exits 0 but file is empty

**Symptom:** `backup_runs.size_bytes = 0` (or near-zero) despite `status = 'success'`. Restoring this file produces an empty database.

**Cause:** historical sigchild bug — the orchestrator collected the wrong exit code from a child shell. Wave 1 of v3.21.0 ([#805](https://github.com/seanmousseau/Simple-PHP-IPAM/issues/805)) fixed this with a file-size fallback: a backup with size `< 1KB` is now reclassified as `failed` even if the child exited 0.

**If you're seeing this on v3.21+:**

1. Confirm the version: `grep IPAM_VERSION Simple-PHP-IPAM/version.php`.
2. Check `mysqldump` is actually on the host and runnable as the web user:

   ```bash
   sudo -u www-data which mysqldump
   sudo -u www-data mysqldump --version
   ```

3. Run it manually with the destination's credentials and confirm output:

   ```bash
   sudo -u www-data mysqldump -h <host> -u <user> -p<pass> <database> | head -20
   ```

   If this is empty too, the issue is auth or grants (`SELECT, LOCK TABLES, SHOW VIEW, EVENT, TRIGGER` are all required).

---

### SFTP fingerprint mismatch

**Symptom:** `error_message` contains `host fingerprint does not match` or `unexpected fingerprint`.

**Cause:** the SFTP server presented a different host key than the destination has pinned.

**Diagnose:**

```bash
ssh-keyscan -t rsa,ed25519 <host>
ssh-keygen -lf - <<<"$(ssh-keyscan -t rsa,ed25519 <host>)"
```

Compare the output `SHA256:<base64>` to the destination's stored `fingerprint`.

**Fix (only if the new fingerprint is legitimate — verify out-of-band first):**

- Edit the destination → paste the new fingerprint → Test.

**Do not** simply blank the fingerprint to make the error go away. An empty fingerprint accepts any key, including a man-in-the-middle.

---

### Restore dry-run fails to split

**Symptom:** During the dry-run step the wizard reports a parse error such as `unterminated string literal at offset N` or `unexpected EOF inside BEGIN/END block`.

**Cause prior to v3.21:** the legacy regex-based splitter ([#806](https://github.com/seanmousseau/Simple-PHP-IPAM/issues/806)) miscounted statement boundaries inside multi-line `TRIGGER` bodies, dollar-quoted PostgreSQL functions, or string literals containing semicolons.

**Confirm you're on the lexer-based splitter (v3.21+):**

```bash
grep -l "RestoreSqlLexer\|sql_split_lexer" Simple-PHP-IPAM/lib*.php Simple-PHP-IPAM/lib/
```

If that returns nothing, the install pre-dates the rewrite. Upgrade to v3.21+ and re-stage.

**If you're on v3.21+ and still hitting it:** the input is genuinely malformed. Capture the file and the error:

```bash
cp data/tmp/restore_*.sql /tmp/restore-bug-<run_id>.sql
shasum -a 256 /tmp/restore-bug-<run_id>.sql
```

File a bug with the SHA-256 and the byte offset from the error. Do not paste the SQL — it may contain customer data.

---

### Encryption mismatch (`IPAMBKP*`)

**Symptom:** Stage step fails with `Cannot decrypt backup: HMAC verification failed` or `unknown magic header`.

**Diagnose — check the magic header directly:**

```bash
xxd -l 8 /path/to/backup.enc
# 00000000: 4950 414d 424b 5032           IPAMBKP2     # v3.19+ streaming
# 00000000: 4950 414d 424b 5031           IPAMBKP1     # v3.17–v3.18 single-shot
```

**Common causes:**

1. **`app_secret` rotated.** Files encrypted with the old key cannot be decrypted with the new one. Recovery requires the old key — check `config.php.bak-*` files in the repo root, or operator backup of the secret.
2. **File truncated mid-upload.** HMAC fails because the trailing tag is missing. Compare `size_bytes` in `backup_runs` to the destination's reported size.

   ```bash
   # AWS S3
   aws s3api head-object --bucket <bucket> --key <prefix>/ipam-...enc --query ContentLength
   # SFTP
   ls -la /remote/path/ipam-...enc
   ```

3. **Cross-format restore attempt.** A file encrypted with IPAMBKP1 will fail on a host that lost the legacy decrypt path (it shouldn't — back-compat is permanent — but check `lib*.php` for `backup_decrypt(`).

**Future-proofing (v3.24's IPAMBKP3):** v3.24 introduces an envelope where the data-encryption key is wrapped, so rotating `app_secret` no longer invalidates old files. Until then, **do not rotate `app_secret` without first decrypting all backups you might need to restore.**

---

### Stale staging files filling `data/tmp/`

**Symptom:** Disk-free alert on the IPAM host. `data/tmp/` is large.

**Cause:** A staged restore was never applied or cancelled, and `tmp_cleanup.php` hasn't run (or `cron.php` itself isn't firing).

**Diagnose:**

```bash
ls -laSh data/tmp/ | head -20
find data/tmp/ -name 'restore_*' -mmin +60     # older than 1h — should be cleaned
```

**Fix:**

```bash
# Manual sweep — same logic as tmp_cleanup.php
find data/tmp/ -name 'restore_*' -mmin +60 -delete
find data/tmp/ -name '*.decrypting.*' -mmin +60 -delete
```

Then confirm `cron.php` is actually firing — see [`next_run_at` stuck in the past](#next_run_at-stuck-in-the-past) for the broader cron-isn't-running diagnosis.

---

### `next_run_at` stuck in the past

**Symptom:** Schedule's `next_run_at` is hours or days old; no new `backup_runs` rows have appeared.

**Cause hierarchy:**

1. **`cron.php` itself is not running.**

   ```bash
   sudo crontab -l -u www-data | grep cron.php   # the entry exists?
   sudo grep CRON /var/log/syslog | tail -20      # cron actually fires it?
   ls -la data/last_cron.txt 2>/dev/null          # if you have the lazy-housekeeping marker
   ```

2. **No active row in `backup_destinations` + `backup_schedules`** — the unified scheduler iterates only active rows, so an install with every destination or schedule disabled looks idle. (The legacy `backup.enabled` toggle was retired in v3.26.0 #1059.)

   ```sql
   SELECT bd.id, bd.name, bd.is_active AS dest_active,
          bs.frequency, bs.is_active AS sched_active
     FROM backup_destinations bd
     LEFT JOIN backup_schedules bs ON bs.destination_id = bd.id;
   ```

3. **A failed run left the destination in a state where the next attempt also fails synchronously**, masking the schedule. Read the most recent `backup_runs` row for that destination — usually `error_message` tells you the underlying cause.

**Fix:** address the cause; do not manually `UPDATE` `next_run_at` to a fresher value to "unstick" it. The orchestrator computes that value from the schedule's frequency on every successful run; a hand-edited value will be overwritten on the next fire.

---

## Cross-version restore

**Direction:** same-or-newer target only. Restoring an older backup into a newer install is supported and runs `apply_migrations()` after the SQL apply. Restoring a newer backup into an older install is **not** supported and the wizard refuses.

**If you absolutely need to restore an older-version backup into an old install:**

1. Stand up a temporary install of the same version the backup was taken on (`git checkout v<X.Y.Z>`).
2. Restore there.
3. Run the IPAM upgrade procedure (`upgrade.sh`) to reach the target version.
4. Take a fresh backup at the target version.
5. Restore that backup into the production install.

This is multi-hour work; do not invent shortcuts that bypass the migration chain — every closure in `migrations.php` exists because something would otherwise break.

---

## Tenant-export hand-off (forward-looking)

Reserved for v4.0.0 multi-tenancy. When a single-tenant operator wants to spin off a sub-tree of data into its own tenant install, the export will run as a Logical backup scoped to one `tenant_id`. This section will document the procedure when the v4.0.0 conversion wizard ships. Until then: tenant export is not supported; copying selective rows out of the database is operator-DIY territory.
