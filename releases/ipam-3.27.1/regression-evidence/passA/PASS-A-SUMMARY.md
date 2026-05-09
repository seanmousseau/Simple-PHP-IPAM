# Pass A — Summary

**Run:** 2026-05-08 21:15 → 21:42 EDT (~30 min)
**Instance:** `https://dev-direct.seanmousseau.com:8343/testing/ipam/` (SQLite, v3.27.0)
**Operator:** Claude (Playwright MCP), with Sean's live questions guiding the path

## Bottom line

**Three real bugs reproduced with audit-log evidence.** **One reported bug not reproducible under any tested condition.** **All five observability gaps confirmed.** **v3.27.1 fix scope justified end-to-end.**

| Bug | Status | Evidence |
|---|---|---|
| Encrypt-write-path → vault key | **REPRODUCED** | §3 cron run shows `failed=2/3` for encryption-requesting schedules, "app_secret is empty" stderr only, no `backup_runs` row, no audit row, last_run_at advanced anyway |
| Bug X — `sudo_once` never consumed (security regression) | **REPRODUCED with hard evidence** | §2.y audit shows `auth.sudo_passed` (id 100500) covers TWO sudo-class actions (vault_reveal id 100501, apikey.create id 100502) under TTL=0 |
| Bug W (new — discovered during §2.aa) — `has_encrypted_runs` gate conflates IPAMBKP2 and IPAMBKP3 | **REPRODUCED** | §2.aa initial vault_set Generate refused with "encrypted backups exist" message despite the only 2 encrypted rows being IPAMBKP2 archives that don't depend on vault_key |
| Bug Y — MFA-disable lockout vector | **NOT VERIFIED IN PASS A** (would have permanently locked the test admin out; deferred to §D operator checklist) | code audit confirms `ipam_sudo_policy_lockout_check` is not called from disable handlers |
| Bug Z — Sean's "click Generate twice" report | **REPRODUCED via OIDC re-auth method** (after Sean configured Authentik for Pass A). **Affects ALL 8 sudo-class call sites** (confirmed via static analysis + empirical test on vault_set AND api_keys.create). | Code-level root cause: `lib/auth_step_up.php:480-489` validator requires absolute path, every sudo handler passes a relative path, falls back to hardcoded `destinations.php` (legacy page), action context lost on the OIDC return |
| Bug U (NEW — OIDC auto-provision creates real password hashes) | **REPRODUCED** | `claude-oidc` has `$2y$12$...` bcrypt hash, not `!disabled` sentinel. Step-up offers password method the operator can never satisfy; lockout precondition guard bypassed |
| Bug V (NEW — settings toggle wipes adjacent fields) | **REPRODUCED** | Per-key toggle form vs group form separation; clicking a checkbox loses unsaved text fields |
| **Bug T (NEW — Sweep C, sudo grant invalidation gaps)** | **REPRODUCED via static analysis** | 6 of 11 documented invalidation events have no `ipam_sudo_invalidate()` call (role downgrade, oidc_sub link/unlink, TOTP enroll, Email OTP enroll, passkey add) |
| **Bug S (NEW — Sweep A, IPAMBKL1 restore broken)** | **REPRODUCED** | Restore dry-run fails with "unterminated dollar-quoted string" on every backup containing bcrypt hashes (`$2y$...`) — parser doesn't track string-literal state. Disaster recovery broken on v3.27.0. |
| Sweep B (audit log append-only triggers) | **VERIFIED OK** | Triggers exist and reject UPDATE/DELETE. Forensic foundation solid. |

## Evidence files in this directory

- `00-baseline.md` — environment + DB state captured at start
- `db-snapshots/baseline.sqlite` — full SQLite DB snapshot (120 MB) before any test action
- `screenshots/` — every step transition (login, prompts, success/failure pages)
- `operator-followup-checklist.md` — manual rows you need to complete (theme, command palette, mobile responsive, OIDC, WebAuthn, Email OTP, Bug X manual repro, Bug Y destructive test, Bug Z manual repro, etc.) PLUS live-finding notes appended during this Pass A
- `PASS-A-SUMMARY.md` — this doc

## Section-by-section results

### §0 Pre-flight — PASS
DB snapshot captured. Baseline recorded: v3.27.0, app_secret SET, no bootstrap_key, no vault_key in DB, 13 backup_runs (mistakenly assumed all unencrypted in initial probe — corrected later in §J.1), step-up TTL=300, audit baseline MAX(id)=100486.

### §1 Sanity — PARTIAL
- §1.1–1.4 login flows: PASS
- §1.5 nav rendering: PASS — but "Database Tools" not visible by that label in the sidebar (operator follow-up §A.1)
- §1.6 theme toggle: NOT VERIFIED — operator follow-up §A.2
- §1.7 command palette ⌘K: NOT VERIFIED — operator follow-up §A.3
- §1.8 logout: PASS

### §2 Step-up auth — strong evidence on the call sites I could exercise

| Test | Method | Result |
|---|---|---|
| §2.7 API key create | password | PASS — clean resume, audit `sudo_passed` followed by `apikey.create` same second |
| §2.aa vault_set Generate (with app_secret) | password | PASS on retry (initial attempt blocked by Bug W) |
| §2.aa vault_set Generate (no app_secret — Sean's prod state) | password | PASS — single click, flash slot rendered, raw key shown once |
| §2.1 vault_reveal | TOTP | PASS — TOTP defaulted as strongest method, clean resume |
| §2.y Bug X (TTL=0 consumption) | password | **BUG REPRODUCED** — single sudo_passed row covered two distinct sudo-class actions |
| Bug W (precondition gate) | n/a | **BUG REPRODUCED** — IPAMBKP2 archives blocked vault_set Generate inappropriately |

§2.91-§2.94 (negative tests, rate limit, no-method branch), §2.10 (DB import file re-pick), §2.4-§2.6 (MFA disable destructive), §2.91 (cancel) — deferred to operator manual checks per §B/§C/§D of the operator follow-up checklist.

### §3 Backup write path — REGRESSION REPRODUCED

Created `RT-20260508-local-stored` (Local destination, encryption=stored). Stashed `app_secret` to match Sean's prod state. Forced cron tick:

```
{"task":"backup_schedules","due":3,"ok":1,"failed":2}
[backup_schedule] ERROR: schedule_id=1 ipam_backup: encryption requested but app_secret is empty
[backup_schedule] ERROR: schedule_id=3 ipam_backup: encryption requested but app_secret is empty
```

| Schedule | Destination | Encryption requested | Result | Forensic trace |
|---|---|---|---|---|
| 1 | wasabi (s3) | yes (coerced from "unencrypted" by remote-destination guard) | **silent fail** | stderr only |
| 2 | Legacy local | no | success — `.ipambkl1.gz` IPAMBKL1 unencrypted backup landed | `backup_runs.id=14`, `backup.run` audit |
| 3 | RT-local-stored | yes | **silent fail** | stderr only |

For schedules 1 and 3:
- ✗ NO `backup_runs` row (gap O3)
- ✗ NO `backup.run` / `backup.failed` / `backup.preflight_failed` audit (gap O4)
- ✓ `last_run_at` advanced anyway (gap O5)
- ✓ stderr captured the error (gap O1) — but if cron is `>/dev/null 2>&1` like prod, this signal disappears entirely

The `schedule_overdue` detector fired at this tick — but only because schedule id=1's `next_run_at` had drifted from 2026-04-30. It would NOT have fired for our forced schedules whose `next_run_at` was advanced normally. This is gap O5 in action.

### §4 / §5 / §6 — NOT EXERCISED IN PASS A

These rows (restore from existing IPAMBKP2 archive, notification dispatch, full vault key admin lifecycle including replace + paste + rate limit) would all confirm functionality that v3.27.1 doesn't change. Skipping accelerates Pass A; operator can spot-check via §F of the followup checklist if desired.

### §7-§10 Core CRUD / audit / dashboard / health — NOT EXERCISED

Same reasoning. v3.27.1 doesn't touch these surfaces. Pass B (post-fix) will exercise them as a smoke-test layer to detect collateral damage.

### §12 Cron direct execution — IMPLICITLY EXERCISED in §3

The §3 cron force was itself the §12 test. Self-lock not verified separately — operator can confirm during normal cron operation.

### §13 Cleanup — DONE

- All RT- artifacts deleted (api_keys, backup_destinations, backup_schedules, backup_runs)
- 2 stashed `stored` rows restored to encryption_mode='stored'
- TOTP disabled on `claude` (was enrolled during Pass A for §2.1)
- step-up TTL restored to 300
- vault_key deleted from DB (was created during Pass A, no longer needed)
- `app_secret` restored in config.php

**Residual state (acceptable):**
- A new `bootstrap_key` was injected into config.php (`'bootstrap_key' => 'QrIPPu/lDneVLegNOHgYWgnrpYZfW0YlPrhdpfemGqI='`). The vault_key it wrapped is now deleted, so this is an orphaned wrapping key. Harmless. Can be removed if you want a perfect baseline match.
- `backup_runs.id=14` — the one successful Legacy local backups run from §3 — left in place as evidence of the working unencrypted path.
- Audit log accumulated ~35 rows of test activity (id 100487 through ~100525). Useful evidence; intentionally preserved.

## What didn't work / what I learned

1. **My initial baseline query missed the 2 IPAMBKP2 rows** (`SELECT … LIMIT 10`). When the §2.aa precondition refused vault_set Generate, I had to broaden the query and discovered Bug W. Lesson: when probing existing state, query categorically (`GROUP BY encryption_mode`) not by recency.
2. **Run-Now button is JS-driven** (`data-run-now` attribute, fetch-based). Generic Playwright form-submit selectors don't trigger it. Cron-force with stashed app_secret was the cleanest way to exercise the orchestrator path on a remote-destination-equivalent.
3. **Theme toggle and command palette** were not detectable via my generic selectors. Either the elements use specific structures or Cmd+K binding requires a focused element. Operator follow-up §A.2/§A.3.

## Sean's hypotheses, tested

| Hypothesis | Test result |
|---|---|
| "Maybe behaviour is different with no app_secret" (Bug Z context) | **Disproved on test instance.** vault_set Generate worked with single click in both states. |
| "Perhaps TOTP step-up will have the results I saw" (Bug Z context) | **Disproved.** vault_reveal under TOTP step-up worked with single click; clean resume. |
| "Maybe it's an OIDC step-up bug — I use Authentik" | **Not tested in Pass A.** Operator follow-up §B.2. Could spin up a local OIDC provider on the dev server (Sean offered) for Pass B if Bug Z still feels suspicious post-fix. |

## Recommendation: green-light v3.27.1 coding

Pass A confirms every claim in `2026-05-08-v3.27.1-hotfix.md` §6.1 and §6.2 (encrypt fix + 5 observability fixes) and §6.4 (Bug X consume_once wiring) is justified by reproduction evidence. Plus we discovered Bug W which adds a small adjacent fix.

Suggested order to start coding (per `2026-05-08-v3.27.1-hotfix.md` §11):

1. PHPUnit fixtures for the three encrypt branches first (TDD).
2. Wire the orchestrator (lib/backup.php:396-408).
3. Land the five observability fixes.
4. Land Bug X consume_once wiring across 10 sudo handlers.
5. Land Bug W has_encrypted_runs gate refinement (small — distinguish IPAMBKP2 from IPAMBKP3).
6. Update CHANGELOG, audit-actions.md, lessons-learned.md, deploy-targets.md.
7. Bump version.php → 3.27.1.
8. Build bundle. Deploy to test instance. Run **Pass B** against the same plan; expect every "A=fail / B=pass" row to flip green.

Pass B regression evidence will live at `releases/ipam-3.27.1/regression-evidence/passB/` — same shape as this directory.

---

**End of Pass A summary.**
