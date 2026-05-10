# Surface 3 — webhook delivery + reaper

**Verdict:** ⚠️ **PASS WITH FINDINGS** (1 High, 2 Medium). SSRF guard + state machine + CSRF + payload all clean.
**Date:** 2026-05-10
**Method:** Static audit via Claude Explore agent. Live exercise skipped — test instance has 0 webhooks and seeding one would mutate test state; static + schema audit was sufficient for Pass C triage.

## Clean items (verified)

| # | Property | Evidence |
|---|---|---|
| 1 | URL validation + SSRF guard | `ipam_validate_webhook_url()` (`lib.php:8878-8978`) blocks non-HTTP(S), loopback, RFC-1918, link-local, CGNAT, multicast, IPv6 ULA/link-local/multicast, IPv4-mapped IPv6. DNS-rebinding defended by dual-stack A+AAAA resolution check. Bypass only via opt-in `webhook.allow_private_ips` setting. |
| 3 | Retry cap + backoff | 3 attempts max (`lib.php:9154`); fixed intervals (T+60s, T+360s) per `lib.php:9147-9148, cron.php:484`. URL re-validated on retry (`lib.php:9169-9172`). |
| 4 | Delivery state machine | Implicit states via nullable `delivered_at` + `attempt` column. Terminal states: Delivered (`delivered_at NOT NULL`), Exhausted (`attempt=3, delivered_at NULL`), SSRF-blocked (attempt bumped to 3 with error). |
| 5 | `destination_health` interaction | None — `destination_health` is backup-only; webhooks have their own retry path. |
| 6 | CSRF | `webhooks.php:7` calls `csrf_require()` unconditionally; all 5 POST handlers checked. |
| 7 | Admin role | `webhooks.php:6` `require_role('admin')`. |
| 8 | Test-send endpoint authz | Same admin+CSRF gate; URL is fetched from DB row, not request body — no injection. SSRF re-validated on test-send. |
| 9 | Payload contents | `{event, timestamp, version, actor{user_id,username}, data}` (`lib.php:9070-9077`). No passwords/API keys/raw `ip_bin` by design. Response body truncated to 2KB for storage. |

## Findings

### F-S3-01 — High — Webhook signing secrets stored plaintext at rest

**Where:** `webhooks` table, `secret` column (`schema.sql`) — `TEXT NOT NULL`, no `_enc` suffix. Inserted raw at `webhooks.php:99, 132`. Retrieved raw for HMAC at `lib.php:9026, 9074`.
**What:** The per-webhook HMAC-SHA256 signing secret is stored as plaintext. Compare TOTP secrets, which were migrated to `totp_secret_enc` (AES-256-GCM) in v3.6.0, and the backup vault relocation done across v3.24–v3.27. Webhook secrets were missed during that cleanup.
**Risk:**
- A database dump (legitimate backup file, leaked snapshot, db_tools export) exposes every outbound webhook destination + its signing key.
- Attacker holding a stolen secret can forge webhook deliveries to the same endpoint (impersonation) or, if the endpoint is shared infra, target multiple tenants.
- Mitigates somewhat because `app_secret` is also required to decrypt other rows in a v3.26+ install — but if the DB dump includes `config.php` (typical for ops mistakes), the secret is immediately usable.
**Fix:** Migrate `webhooks.secret` → `webhooks.secret_enc` using the v3.27 vault key (HKDF-derived with purpose `"webhook-secret"`). Provide a one-shot upgrade migration that encrypts existing rows, then drops the plaintext column. Treat as the same architectural class as the v3.27 vault-key work.
**Severity:** **High** — security gap, not currently exploitable without DB access but matches the v3.27.0 architectural goal of "no sensitive secrets in DB plaintext."

### F-S3-02 — Medium — No sudo step-up on webhook create/edit/delete

**Where:** `webhooks.php` lines 77 (create), 108 (edit), 161 (delete). Only `require_role('admin')` + CSRF; no `ipam_sudo_require()`.
**What:** v3.27.0 introduced step-up auth for sensitive admin actions (vault key reveal/set, MFA disable, settings_reveal, db_tools import, API key create, step-up policy save). Webhook create/edit/delete is comparable in blast radius — adding an exfiltration webhook is a one-step credential-bypass-to-data-exfil path — but is not sudo-gated.
**Risk:**
- A compromised admin session (phished, MFA-bypassed, stolen browser cookie) can install an arbitrary webhook URL without re-auth.
- Especially problematic combined with F-S3-01 (plaintext secret) — attacker creates a webhook with a secret they control, then receives the signed payloads with no further interaction.
**Fix:** Add `ipam_sudo_require('webhook.admin', 300)` to the create/edit/delete handlers. Policy key `webhook.admin` joins the existing step-up policy registry.
**Severity:** **Medium** — defense-in-depth gap; admin session compromise is the precondition.

### F-S3-03 — Medium — `webhook_deliveries` queue grows unbounded if destination is down

**Where:** `lib.php:9211-9220` (`webhook_prune()`) deletes by age only — `created_at < now - retention_days`. No row-count cap, no per-webhook ceiling.
**What:** If a webhook destination is down (and stays down) and the configured retention is N days, every event during that window adds 3 rows to `webhook_deliveries` (one per attempt) without bound. On a busy install with frequent events (subnet edits, address changes), the table can balloon before retention kicks in.
**Risk:**
- Disk-pressure on the DB volume; slows down audit/delivery queries.
- Particularly painful on SQLite where `VACUUM` is a hard rebuild.
**Fix:** Either (a) add a per-webhook delivery cap (e.g., 1000 pending) with eviction of the oldest pending rows, OR (b) add a global ceiling on the `webhook_deliveries` table with eviction back-pressure, OR (c) document the operator responsibility to set `webhook.retention_days` tight enough.
**Severity:** **Medium** — operational footgun, not a security issue.

## Test-instance state

No mutations performed. `webhooks` and `webhook_deliveries` both remain at 0 rows post-Surface 3.
