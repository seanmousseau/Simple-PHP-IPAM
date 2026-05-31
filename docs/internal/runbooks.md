# Runbooks

> **Audience:** on-call operator or developer responding to a production incident on `ipam.seanmousseau.com`, `demo.simplephpipam.com`, or one of the four testing instances. This is the symptom→runbook index. Per-incident playbooks live in their own docs; this one routes you to the right one.

---

## Deploy targets covered

| Target | URL | Engine | Doc |
|---|---|---|---|
| Production | `https://ipam.seanmousseau.com` | SQLite | `deploy-targets.md` |
| Demo | `https://demo.simplephpipam.com` | SQLite (daily reset) | `deploy-targets.md` |
| Testing — sqlite | `https://dev-direct.seanmousseau.com:8343/claude/ipam/` | SQLite | `deploy-targets.md` |
| Testing — mysql | `https://dev-direct.seanmousseau.com:8343/claude/ipam-mysql/` | MySQL 8 | `deploy-targets.md` |
| Testing — pgsql | `https://dev-direct.seanmousseau.com:8343/claude/ipam-pgsql/` | PostgreSQL 16 | `deploy-targets.md` |
| Testing — mariadb | `https://dev-direct.seanmousseau.com:8343/claude/ipam-mariadb/` | MariaDB | `deploy-targets.md` |
| Marketing | `https://simplephpipam.com` | n/a (static + PHP) | `marketing-site.md` |

---

## Symptom → runbook

| Symptom | Go to | Type |
|---|---|---|
| Production regression discovered post-deploy | `incident-response.md` | Decision-making playbook (hotfix vs defer) |
| Need to actually cut a hotfix | `hotfix-release.md` | Mechanical procedure |
| CI check went red on a PR | `investigating-ci-failure.md` | Diagnostic |
| Backup didn't restore correctly / restore failed mid-run | `backup-restore-runbook.md` | Recovery playbook |
| Demo or testing instance won't accept the deploy bundle | `deploy-targets.md` | Per-target deploy procedure |
| Need to deploy to all 7 targets after a release | `deploy-targets.md` + `marketing-site.md` | Mechanical procedure |
| Marketing site cache not refreshing after release | `marketing-site.md` → "QUIC.cloud cache" | Procedure |
| Schema migration failed on upgrade | This doc → "Schema migration failure" | Inline runbook |
| SQLite `database is locked` errors | This doc → "SQLite write lock held" | Inline runbook |
| `attempt to write a readonly database` after rsync | This doc → "Apache cannot write SQLite" | Inline runbook |
| OIDC login broken after IdP rotation | This doc → "OIDC JWKS stale" | Inline runbook |
| Login attempts piling up; legitimate user locked out | This doc → "Login rate-limit lockout" | Inline runbook |
| Step-up auth silently failing for every admin | `auth-model.md` → Step-up + `lessons-learned.md` Bug Z | Diagnostic pointer |
| PHP log spam: `decrypt failed for key …` (sensitive setting unreadable after app_secret rotation) | This doc → "Sensitive setting envelope cannot decrypt" | Inline runbook |

---

## Inline runbooks (short)

### Schema migration failure

**Symptom:** users see an error page on first post-upgrade request; PHP log shows `apply_migrations()` throw.

1. **Read the error.** Get the migration version + the SQL line from `error.log`.
2. **Check FK state.** If the error is `FOREIGN KEY constraint failed`, the migration didn't bracket itself with `PRAGMA foreign_keys = OFF`. This is invariant #3.
3. **Restore from backup.** Migration failures should not corrupt data, but FK-cascade bugs (v2.2.1 class) silently wipe rows. Restore the pre-upgrade DB before retrying.
4. **Hotfix.** Goes through `hotfix-release.md`. The migration closure needs the FK-off bracket and the idempotency guard from `adding-a-migration.md`.

**Don't:** rerun the failed migration without restoring first — the rollback `ROLLBACK` only undoes the open transaction, not FK-cascade DELETEs that already happened.

### SQLite write lock held

**Symptom:** `database is locked` on writes; test suites hanging mid-run.

1. **Find the holder.** Usually `cron.php` running long-running scans.
   ```bash
   ssh root@192.168.80.15 \
     'docker exec dev_seanmousseau_com-apache-php-1 bash -c "ps -ef | grep -E \"cron.php|ping\""'
   ```
2. **Kill it.**
   ```bash
   ssh root@192.168.80.15 \
     'docker exec dev_seanmousseau_com-apache-php-1 bash -c "pkill -f cron.php; pkill -f ping"'
   ```
3. **Confirm WAL flushed.** SQLite checkpoints automatically; nothing else needed.

Detail in `test-suites.md` → "Pre-flight cleanup".

### Apache cannot write SQLite

**Symptom:** `attempt to write a readonly database` after a rsync deploy.

```bash
ssh root@192.168.80.15 \
  "chown -R www-data:www-data /opt/container_data/dev.seanmousseau.com/html/claude/ipam/"
```

The rsync runs as root and writes files owned by root. Apache runs as `www-data`. Always `chown` after rsync. Lesson is in `lessons-learned.md` §3.

### OIDC JWKS stale

**Symptom:** OIDC login worked yesterday; today every callback fails with "Invalid signature".

The JWKS cache TTL is 1 hour, with a single automatic cache-bust retry on signature failure. If the IdP rotated keys *and* you're hitting a still-cached stale-JWKS window after the auto-retry, the cache file lives at `data/tmp/oidc_jwks_<hash>.json` — delete it:

```bash
rm Simple-PHP-IPAM/data/tmp/oidc_jwks_*.json
```

Implementation reference in `auth-model.md` → OIDC.

### Login rate-limit lockout

**Symptom:** legitimate user (or test runner) sees "Too many failed login attempts."

```bash
ssh root@192.168.80.15 \
  'docker exec dev_seanmousseau_com-apache-php-1 sqlite3 \
   /var/www/html/claude/ipam/data/ipam.sqlite \
   "DELETE FROM login_attempts WHERE ip = '\''<IP>'\''"'
```

Or to clear the whole bucket (testing instance only): `DELETE FROM login_attempts`.

The rate-limit is stateful and survives password resets. Reset both the password AND the `login_attempts` rows when fixing a cred-drift.

### Sensitive setting envelope cannot decrypt

**Symptom:** `error.log` contains `ipam_setting: decrypt failed for key <name>: …` on every page load. Pre-v3.36.1: the whole app failed at the boot path with a red "Configuration error" screen. v3.36.1+: the app boots, the affected setting silently falls back to its registry default, log spam continues until the row is fixed.

**Root cause:** `app_secret` in `config.php` no longer matches the value used to encrypt the envelope. Either `config.php` was overwritten (the v3.36.0 release-tarball-clobbers-config.php class of bug — fixed in v3.36.1 via the release-builder exclude), or `app_secret` was rotated without re-encrypting DB-resident secrets.

**Recovery (pick one):**

1. **Resave via Settings UI** (preferred when the affected key has UI presence). Log in → Admin → Settings → find the key → re-enter the value → save. The save path re-encrypts under the current `app_secret`. Confirm by tailing `error.log` — the spam should stop.

2. **Headless null-out** (when no admin login is possible, or the affected key has no value to restore):

   ```bash
   # Dry-run to see what would be cleared:
   php tools/clear-broken-secret.php --all-broken --dry-run
   # Apply:
   php tools/clear-broken-secret.php --all-broken
   ```

   The tool refuses to touch non-sensitive keys, refuses to wipe rows that decrypt cleanly, and DELETEs the row so the next read falls through to the registry default (typically empty). Resave through the UI afterwards if you have the original plaintext.

3. **Restore the original `app_secret`** (when you have a backup of the old `config.php`). Drop the original value back into `config.php`, restart php-fpm or the OLS lsphp process so the in-memory cache invalidates, and the existing envelopes decrypt as before. Then rotate `app_secret` properly via tools/rotate-app-secret if/when you want a fresh key (re-encrypts every envelope in one shot — see #TBD when that lands).

**Don't:** edit the encrypted blob in the `settings` table by hand. The envelope carries a Poly1305 MAC; any byte you change makes the row fail to decrypt under any key. Always use the tool or the Settings UI.

---

## Detect → Triage → Hotfix → Post-mortem

The four-phase incident flow lives in `incident-response.md`. Read it before deciding hotfix-vs-defer; the decision criteria are encoded there with explicit rules ("data loss or corruption", "auth broken", "hard crash on primary path" → hotfix-now).

Memory MCP discipline: record observations on the release entity as you go (`project:simple-php-ipam:release:vX.Y.Z`). The post-mortem phase formalises them and decides whether a lesson generalises into `lessons-learned.md`.

---

## Cross-references

- `incident-response.md` — decision-making playbook for hotfix-vs-defer.
- `hotfix-release.md` — mechanical hotfix-cut procedure.
- `backup-restore-runbook.md` — recovery from backup.
- `deploy-targets.md` — per-target deploy procedures and rollback.
- `marketing-site.md` — `simplephpipam.com` operations.
- `investigating-ci-failure.md` — red-CI diagnostic.
- `test-suites.md` → "Pre-flight cleanup" — the recurring footguns.
- `lessons-learned.md` — what previous incidents taught us.

---

## Update protocol

- New symptom that doesn't route cleanly → add to "Symptom → runbook".
- New inline runbook needed and the steps fit in <30 lines → add inline here.
- New inline runbook would exceed 30 lines → promote to its own doc; link from the table.
- Deploy target added/removed → update "Deploy targets covered" together with `deploy-targets.md`.
