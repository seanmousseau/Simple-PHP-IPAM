# Pass A — Operator follow-up checklist

> Items I cannot verify from a headless Playwright session. Each row needs your hands on the keyboard, your eye on the screen, your phone for OTP, your security key, or your knowledge of how the UI is *supposed* to feel. Pair this with `00-baseline.md` and the screenshots under `screenshots/` so you can correlate.

**Test target:** `https://dev-direct.seanmousseau.com:8343/testing/ipam/`
**Login:** `claude` / `ClaudeTesting123!`
**Why these rows ended up here:** see the Reason column. Most are "auth method I can't physically satisfy" or "visual judgment I can't substitute for."

---

## How to use this

For each row: do the step, fill the **Observed** and **Pass/Fail** columns, jot anything weird in **Notes**. Anything you flag here goes into the v3.27.1 review checkpoint or the v3.27.2 backlog. If a row PASSES exactly as Expected, just write "PASS" — that's enough.

When done, save this file in place (it's already under `releases/ipam-3.27.1/regression-evidence/passA/`) so it lands in the release evidence archive alongside my screenshots.

---

## A. Visual / UX rows from §1 Sanity that I couldn't verify

| # | Step | Expected | Reason needs you | Observed | Pass/Fail | Notes |
|---|---|---|---|---|---|---|
| A.1 | After login, look at the sidebar nav. Is **"Database Tools"** visible as a separate item? | Plan §1.5 says it should be visible on SQLite installs, hidden on MySQL/PG. Missing in my probe — could be nested under Settings or hidden behind something I didn't click. | I queried `aside a, nav a, .sidebar a` selectors; didn't see it. Could be a styling/aria-label thing my selector missed. | | | |
| A.2 | Click the theme toggle / cycler (it should change theme between **light → dark → auto**). Reload the page. | Theme persists across reload via cookie (per CLAUDE.md UI conventions section). CSS custom properties on `<html data-theme=...>` flip. | I queried `[data-theme-toggle]`, `aria-label*="theme"`, links to `theme.php` — none matched. The toggle exists somewhere but my generic selectors missed it. | | | |
| A.3 | Press **⌘K** (or Ctrl+K on a non-Mac). | Command palette opens; type "Subnets" → Enter → navigates to `/subnets.php`. | Cmd+K from Playwright didn't surface a `dialog`, `[data-command-palette]`, `.cmdk`, `.cmdpalette`, `.palette`, `#cmd`, or `#palette` element. Possibly fired but not caught by my generic selectors, or needs a specific focus target, or uses a non-standard structure. | | | |
| A.4 | Open `Backup admin → Destinations`. Confirm the Reveal vault key control is a **primary inline button next to the fingerprint** — NOT buried inside a `<details>` disclosure. | Per `docs/internal/step-up-auth.md` §Prompt UX, this was the v3.27.0 fix for #1111. | Visual judgement; my snapshot can describe element existence but not "does it look like the right discoverable affordance vs hidden." | | | |
| A.5 | Resize the browser to mobile width (<1024px). Open the hamburger menu. Verify the sidebar slides in as a full-height overlay; Escape and backdrop-click both close it. | Plan §1.5 mobile behaviour. | I'm running headless at 1280x900; can't easily judge mobile interaction quality. | | | |

---

## B. Auth-method rows I cannot complete because I have no body

| # | Method | Step | Expected | Notes |
|---|---|---|---|---|
| B.1 | **WebAuthn passkey** | Plan §2.x — for any sudo action, choose Passkey method, complete with a registered authenticator. | Verify proof; `auth.sudo_passed reason=webauthn`; action proceeds. | I cannot interact with platform authenticators. Run on a workstation that has a passkey registered for the `claude` account. |
| B.2 | **OIDC re-auth fallback** | If your install has OIDC wired up (`oidc.client_id`, etc.), trigger a sudo action and choose "Sign in with identity provider again." | Redirect to IdP, re-authenticate, callback returns to original action. `sudo_method=oidc_reauth` audit. | The test instance OIDC state is unknown to me. If unconfigured, this entire branch is dead-code in the prompt UI. If configured, only you can complete the IdP loop. |
| B.3 | **Email OTP** | Settings → enable email_otp on `claude`. Trigger sudo action, choose Email OTP, click "Send code", enter the code from the email. | Verify, action proceeds. | I cannot read the test admin's mailbox. Verify SMTP delivery is configured on the test instance first (`Settings → Email`) — if not, the "Send code" branch will silently or loudly fail. |
| B.4 | **TOTP** | Account → enroll TOTP on `claude` (capture the QR / secret in your authenticator app). Sign out, sign back in, then trigger any sudo action and choose TOTP. | Verify with the live 30s code. | Can be done by me if you write the TOTP secret to `~/.claude/dev-secrets.env` after enrolling and let me read it via `oathtool` on the dev host. Otherwise yours. |

---

## C. Bug X verification (Bug X = sudo_once never consumed)

If you want to confirm the security regression I identified before any code lands, this 5-minute manual check is the cleanest proof.

| # | Step | Expected (current v3.27.0 — bug present) | Observed | Pass/Fail |
|---|---|---|---|---|
| C.1 | Settings → Authentication → step_up group. Set **`auth.step_up.ttl_seconds = 0`**. Save (will require step-up to save the policy itself; complete it). Note the audit row. | Policy saved. `auth.step_up_policy.updated` audit row. | | |
| C.2 | Log out fully, log back in (fresh session). Open API Keys → New. Fill name `RT-20260508-bugx-1`, submit. | Step-up prompt fires (no warm grant on fresh session). Submit password proof. Action proceeds — key created. | | |
| C.3 | **Without taking any other action**, open Account → 2FA section. Click "Disable" on any MFA method (or any other sudo-class affordance). | **BUG REPRODUCED** if action proceeds with NO step-up prompt. Expected under TTL=0 design: prompt fires again. Observed in code: `sudo_once` flag from C.2 is still set, so `ipam_sudo_active()` returns true, action passes silently without re-proof. | | |
| C.4 | Inspect the audit log filter `auth.sudo_passed`. Count rows in this session. | If only **one** `auth.sudo_passed` row spans both C.2 and C.3 actions → bug confirmed. If two rows → either consume_once is being called from somewhere I missed, or my analysis is wrong. | | |

---

## D. Bug Y verification (Bug Y = MFA-disable lockout, no precondition guard)

> ⚠️ **DESTRUCTIVE — do this on a throwaway user, not on `claude`.** This action would lock the throwaway user out of step-up entirely.

| # | Step | Expected (current v3.27.0 — bug present) | Observed | Pass/Fail |
|---|---|---|---|---|
| D.1 | Users → New. Create `RT-20260508-mfauser`, role `admin`, password `lockout-test-pw-2026!`. | User created. | | |
| D.2 | Log out as `claude`. Log in as `RT-20260508-mfauser`. Account → enroll **only TOTP** (no email OTP, no passkey). | TOTP enrolled. | | |
| D.3 | Settings → Authentication → step_up group. Set `allow_provider_reauth = false`. Save (will need step-up; complete via TOTP). | Policy saved. Now this user's only step-up method is TOTP. | | |
| D.4 | Account → 2FA → Disable TOTP. Step-up with TOTP. | Bug present: TOTP disable succeeds. User now has zero satisfiable step-up methods. | | |
| D.5 | While still logged in as `RT-20260508-mfauser`, try to do any sudo action (e.g. create an API key). | Bug confirmed: step-up prompt renders the "No method available" branch with only a Cancel link. User is permanently stranded — including from re-enrolling TOTP, which is itself a sudo action under most policies. | | |
| D.6 | Cleanup: log back in as `claude`, restore `allow_provider_reauth = true`, delete `RT-20260508-mfauser`. | | | |

---

## E. Bug Z verification (vault_set "click Generate twice")

> The bug you originally reported. My code audit found nothing structural; trying to reproduce it in §2.aa of my Playwright sweep. If you have a moment, do this manually too — fresh hands sometimes catch what mine miss.

| # | Step | Expected (per §2.aa) | Observed | Pass/Fail |
|---|---|---|---|---|
| E.1 | Backup & Restore → Destinations. Confirm there is **no vault key configured** (or delete it via SQL if one exists). | Set vault key → Generate button is the visible affordance; Reveal/Replace are hidden. | | |
| E.2 | Click Generate. Step-up prompt fires. Submit password proof. | Three possible outcomes: (a) raw key shown once on next page → ALL GOOD, no Bug Z. (b) Bounced back to the form, no key visible → Bug Z REPRODUCED. (c) Anything else weird → describe. | | |
| E.3 | If E.2 was (b): click Generate again. Does it succeed this time? | If yes → matches your original report. Capture screenshots of both attempts and the audit log diff (we expect to see how many `backup.vault_key.set` rows landed). | | |
| E.4 | If E.2 was (a): inspect the network request log of the step-up submit. The redirect target should be `backup_admin.php?tab=destinations`. Confirm the rendered page has the **flash slot** showing the raw key (not just a generic "vault key set" message). | The raw 32-byte base64 key should be on screen, with a "copy this offline now" affordance. | | |

---

## F. Things I'm doing in Playwright but you may want to spot-check visually

| # | What I'm doing | What you should look at | Why your eye matters |
|---|---|---|---|
| F.1 | Creating `RT-20260508-` test fixtures (subnets, VLANs, sites, etc.) for §3 and §7 | Visit Audit log; filter `details LIKE '%RT-20260508-%'` after the run | Catches whether audits are firing for actions that don't normally surface in my snapshot diff |
| F.2 | Forcing a scheduled backup (§3.x) by manipulating `next_run_at` and running cron | The Backup admin → History tab after — does the failed/success row look right to you? Are the columns intelligible? | Visual judgement: would an operator reading this History at 2am understand what happened? |
| F.3 | Creating a backup vault key (§6.2) | The flash slot rendering in §F.3 — is the "copy this once" message prominent enough? Does the page pass the noob-friendliness bar from your earlier note? | "noob friendly" is your standard, not mine — verify the UX matches your intent |
| F.4 | Triggering a `backup.preflight_failed` audit (Pass B only — this audit verb doesn't exist on v3.27.0) | n/a for Pass A; flag for Pass B | — |

---

## G. Things I'm explicitly NOT testing in Pass A (out of scope)

For your awareness so we don't both wonder "did claude check that?":

- **Production deployment.** I'm only on the SQLite test instance. Demo / prod / mysql / pgsql / maria testing instances are out of scope for Pass A.
- **3-driver Playwright gate.** That's an automated CI run, not part of the manual sweep — runs at v3.27.1 release time.
- **Visual regression vs. an earlier baseline.** I'm capturing screenshots but not diffing them against v3.26.0 or v3.27.0-pre-step-up.
- **Performance / load testing.** Out of scope for a regression sweep.
- **Browser matrix.** Playwright defaults to Chromium. Safari/Firefox quirks not tested.
- **Mobile breakpoints.** Touched on briefly in §A.5; not exhaustively walked.
- **Accessibility audit.** Tab order, screen reader behaviour, focus visibility — none of that is in the regression plan. If you want a separate accessibility pass, file as a v3.28.0 candidate.

---

## H. Cleanup tasks for after my Pass A finishes

> I'll do most of this automatically per §13 of the regression plan. This list is what to verify after I declare Pass A complete:

| # | Check | Expected |
|---|---|---|
| H.1 | `SELECT COUNT(*) FROM users WHERE username LIKE 'RT-20260508-%'` | Zero |
| H.2 | `SELECT COUNT(*) FROM backup_destinations WHERE name LIKE 'RT-20260508-%'` | Zero |
| H.3 | `SELECT COUNT(*) FROM api_keys WHERE name LIKE 'RT-20260508-%'` | Zero (deactivated AND deleted) |
| H.4 | `SELECT COUNT(*) FROM subnets WHERE description LIKE '%RT-20260508-%'` | Zero |
| H.5 | `SELECT COUNT(*) FROM webhooks WHERE name LIKE 'RT-20260508-%'` | Zero |
| H.6 | `grep app_secret /opt/container_data/dev.seanmousseau.com/html/testing/ipam/config.php` | Returns the SAME value as in `00-baseline.md` (I will have flipped it for §3.4 then restored) |
| H.7 | `SELECT key, value FROM settings WHERE key LIKE 'auth.step_up.%'` | Same values as `00-baseline.md` (I will have flipped TTL for §2.y then restored) |
| H.8 | `SELECT COUNT(*) FROM backup_runs WHERE filename LIKE '%RT-20260508-%' OR triggered_by = 'selftest'` | Zero |

If H.1–H.8 don't all show "Zero" / "matches baseline" after Pass A wraps, I left fixtures behind. Run the cleanup manually using the patterns above (`DELETE FROM ... WHERE name LIKE 'RT-20260508-%'`).

---

## I. Notes section for anything else you spot

Free-form. If something feels weird during your run-through and there's no row for it, write it here. I'll read this back as part of the Pass A debrief and we'll decide which observations become v3.27.1 issues, v3.27.2 issues, or just lessons to file.

```
(your notes here)
```

---

## J. Pass A live findings (added during the sweep)

### J.1 Bug W — vault_set Generate blocked by legacy IPAMBKP2 archives

**Discovered:** 2026-05-08 21:25 EDT during §2.aa attempt on the SQLite test instance.

**Symptom:** Test instance had two historical `encryption_mode='stored'` rows in `backup_runs` (filenames `*.enc`, IPAMBKP2 archives produced before v3.26.0 vault_key existed). Attempting vault_set Generate from a clean session with no current vault key was refused with:

> "Cannot generate a new vault key while encrypted backups exist (any orphaned key would strand them). Paste the original key from your password manager to recover, or purge encrypted backup history first."

**Why it's wrong:** IPAMBKP2 archives are encrypted with `app_secret`, not `backup_vault_key`. Generating a new vault key cannot orphan them. The `ipam_vault_key_status()` precondition gate's query (`encryption_mode != 'unencrypted'`) is too broad — it doesn't distinguish IPAMBKP2 (legacy) from IPAMBKP3 (vault-key-protected).

**Real-world impact:** any operator on v3.27.0+ who used encrypted backups before v3.26.0 cannot set up a vault key the easy way (Generate). They have to either purge their backup history (data loss for an audit/restore-window perspective) or paste a key they've never had.

**Slot:** v3.27.1, per architecture doc §9.2. Logged.

**Operator action:** none required for Pass A — I'll work around it by clearing the test instance's `stored` rows for the rest of the sweep, then restore them in §13 cleanup.

### J.2 §2.7 API key create — full PASS

API key creation under step-up password proof: form rendered prompt with all 4 hidden fields round-tripped, submitted proof, action resumed, key created with raw token shown once. Audit log shows `auth.sudo_passed` (id 100491) immediately followed by `apikey.create` (id 100492) at the same timestamp. Resume mechanism healthy on this call site.

### J.3 §1 visual rows still pending

§A rows in this checklist (theme toggle, command palette, Database Tools nav, mobile responsive) still need your eye — Playwright probes didn't surface them through generic selectors.

### J.4 Bug X — REPRODUCED with hard evidence

**Discovered:** 2026-05-08 21:30 EDT, Pass A §2.y test on the SQLite test instance.

**Repro steps executed:**
1. Set `auth.step_up.ttl_seconds = 0` directly in the settings table.
2. Logged out → logged in fresh (cold session, no warm grant).
3. Sudo action #1: clicked Reveal vault key on Backups → Destinations. Step-up prompt fired. Submitted password proof. Vault key revealed.
4. Without logging out, immediately navigated to API Keys → New, filled fields, submitted (sudo action #2).
5. **Action #2 ran with no step-up prompt.**

**Audit log proof:**

```
100500  auth.sudo_passed           method=password         01:30:15
100501  backup.vault_key.revealed                          01:30:15  ← action #1
100502  apikey.create              name=RT-20260508-bugx   01:30:32  ← action #2, no sudo_passed between
```

Only ONE `auth.sudo_passed` row covers two distinct sudo-class actions. Per the policy registry's UI text and `lib/auth_step_up.php:78` documentation, TTL=0 should produce a one-shot grant consumed after each action. The `sudo_once` flag never cleared because `ipam_sudo_consume_once()` is defined but called by zero handlers (verified earlier with repo-wide grep).

**Conclusion:** Bug X verified. v3.27.1 fix scope (consume_once wiring across 10 handlers) justified. Locking the regression with a PHPUnit test post-fix per architecture doc §6.4.

### J.5 Bug Z reproduction attempt (Sean's "click Generate twice")

**Discovered during Pass A §2.aa, 2026-05-08 21:25–21:28 EDT.**

**Could not reproduce on the test instance** — vault_set Generate succeeded end-to-end on the second attempt (after Bug W cleared) with raw key shown once on the redirect target, audit rows in proper order at the same second. Resume mechanism healthy.

**However**, the test instance has `app_secret` SET in `config.php`, while sean's prod box at the time of his original report had `app_secret` ABSENT. Sean's hypothesis: the absence of `app_secret` may change behaviour on the vault_set path. Will retry vault_set Generate after emptying `app_secret` in the test instance's config.php to match the prod state — added as §K below.

### J.7 Bug V — settings.php boolean toggles wipe unsaved sibling text fields

**Discovered:** 2026-05-08 21:52 EDT, while sean was configuring OIDC for the Bug Z OIDC retest.

**Reported symptom:** "When I checked auto-provision users under oidc, my entire config I entered was wiped."

**Audit-log evidence of the original incident (rows 100527 → 100528-100532):** sean toggled `oidc.auto_provision` ON at 01:50:35 (audit row 100527, single-field per-key save) WHILE he had typed values into client_id, client_secret, discovery_url, redirect_uri but had not yet clicked the group's Save button. The per-key save navigated, the page re-rendered from DB (which still had empty values for those fields), and his typed input was visually gone. He then re-entered everything ~90 seconds later and clicked group save (audit rows 100528–100531 all at 01:52:13).

**Mechanism (verified by code + DOM inspection):**

1. The `<input type="checkbox" name="k_oidc__auto_provision">` lives inside the OIDC group form (`<form><input name="group" value="oidc">...`).
2. `app.js` binds that visible checkbox to a SEPARATE hidden per-key form: `<form id="toggle-k_oidc__auto_provision"><input name="key" value="oidc.auto_provision">`.
3. Clicking the visible checkbox triggers JS that appends `value=1` (or `value=0`) to the hidden form and submits IT — not the group form.
4. The per-key save handler at `settings.php:97-170` processes only that single field, fires audit, redirects.
5. Page reloads. Group form's text inputs (client_id, etc.) re-render from DB — operator's typed-but-unsaved values are gone.

**Repro on test instance:** confirmed live in this session — typed `BUG-REPRO-CLIENT-ID-12345` into client_id, clicked `auto_provision` checkbox, page reloaded with the DB value (`EDT7yd...` from sean's prior real save) and my test value gone.

**Root cause family:** same architectural pattern — affordances visually unified but structurally separate code paths. Same as encrypt-write-path bug (read migrated, write not), Bug X (function defined, never called), and the resume-mechanism complexity that initially worried us in §2.aa.

**Fix options (ranked):**

1. **Best:** make the visible checkbox part of the group form's POST. Drop the per-key toggle entirely. Group save handles all fields atomically. Simplest, eliminates the bug class.
2. **Good:** keep the per-key toggle but have its handler ALSO consume any group-form fields present in the POST (i.e. include the group form's inputs in the per-key submission). Requires JS changes too.
3. **Defensive:** add a JS `beforeunload` warning when the group form has dirty fields. UX nag, doesn't fix the underlying separation.

**Slot:** add to **v3.27.2** alongside Bug Y — both are "step-up era settings UX" issues that share affordance-vs-handler-separation as the underlying cause. Could rationalise as a single "settings save semantics" PR.

**Operator follow-up needed:** confirm the typed values that got wiped were the same ones you re-entered (i.e. confirm this is purely a UX bug and not something that wiped DB-persisted values too). If you find any DB rows that look damaged, flag immediately.

### J.8 Bug Z — REPRODUCED via OIDC step-up (sean's exact scenario)

**Discovered:** 2026-05-08 22:06 EDT. After sean configured Authentik OIDC on the test instance.

**Repro steps executed:**
1. Logged into IPAM as `claude-oidc` via OIDC (auto-provisioned admin).
2. Cleared encrypted backup_runs (Bug W workaround).
3. Triggered vault_set Generate on `backup_admin.php?tab=destinations`.
4. Step-up prompt offered OIDC re-auth method.
5. Selected OIDC method, clicked "Re-authenticate with identity provider".
6. Redirected to Authentik with `prompt=login`.
7. Re-authenticated successfully.
8. Redirected back to IPAM... but landed on `destinations.php` (the LEGACY page) instead of `backup_admin.php?tab=destinations`.

**Audit log:** single `auth.sudo_passed method=oidc_reauth user=claude-oidc` row at 02:06:50. **NO `backup.vault_key.set` row.** **NO vault_key in DB.** Action silently dropped.

**Code-level root cause: `lib/auth_step_up.php:480-489` (in `ipam_sudo_oidc_reauth_redirect_url`):**

```php
$safe = 'destinations.php';
if ($returnPath !== ''
    && $returnPath[0] === '/'              // ← BUG: requires leading slash
    && !str_starts_with($returnPath, '//')
    ...) {
    $safe = $returnPath;
}
```

The validation **requires absolute path (leading `/`)**. The vault key handler passes a **relative path** (`'backup_admin.php?tab=destinations'`) because `$redirectBase` from `backup_admin.php:60` is relative. The validator fails, falls back to the hardcoded `'destinations.php'`, and the original action context is lost.

**The fix was supposed to prevent open-redirect attacks against installs served at a path prefix (`/claude/ipam/`)** — per the CodeRabbit round 3 comment at lines 477-479. But the implementation rejects every relative path that callers actually use. The other return-path validator in `step_up.php:30-49` accepts relative paths just fine.

**Sean's prod sequence reconstructed (now with full understanding):**

1. Sean triggers vault_set Generate from `backup_admin.php?tab=destinations`
2. Step-up prompt fires; only OIDC re-auth available (claude-oidc has no password)
3. The validator rejects `'backup_admin.php?tab=destinations'`, stashes `'destinations.php'`
4. OIDC round-trip to Authentik
5. Lands on `destinations.php` (legacy page) — confusing UI, no vault_set ran
6. Sean clicks Generate again on whatever page he ended up on (perhaps navigates back to backup_admin)
7. Now warm sudo grant exists from step 4
8. vault_set runs, audit row 2602 (`backup.vault_key.set`) fires

**Slot:** v3.27.2 per sean's commitment. **One-line fix:**

Either:
- (A) Fix the validator at `lib/auth_step_up.php:480-489` to also accept relative paths (mirror `step_up.php:30-49`'s logic), OR
- (B) Have the vault handler pass an absolute path: change `$stepUpReturnPath = $redirectBase` to `$stepUpReturnPath = '/' . ltrim($redirectBase, '/')` at `lib/backup_admin_destinations.php:467`

Recommendation: **fix A** (validator). It catches the entire class for every other call site too. Pair with a regression test that asserts `'backup_admin.php?tab=destinations'` survives validation.

**Same architectural pattern as the other bugs we found today:** a security guard added without considering the existing call patterns. The CodeRabbit feedback was acted on but the resulting code didn't preserve the actual valid use case.

### J.9 Bug U — OIDC auto-provision creates users with REAL password hashes

**Discovered:** 2026-05-08 22:06 EDT, while testing Bug Z via OIDC.

**Symptom:** the step-up prompt offered "Account password" as a method for `claude-oidc` (an OIDC-only user with no known password). Per `lib/auth_step_up.php:184-188`, password should only appear if `password_hash` is non-empty AND doesn't start with `!disabled`. Inspection of the DB shows `claude-oidc` has `password_hash = $2y$12$YNUoYOAF...` — a real 60-byte bcrypt hash.

**Effect:** OIDC-provisioned users have a usable but secret password hash that the operator never sees. The step-up prompt offers "Account password" as a method but the user has no way to satisfy it. Worse, the OIDC-only-admin lockout protection model assumes `!disabled` sentinel; auto-provisioned users bypass that protection silently.

**Slot:** v3.27.2. Fix is a one-liner in the OIDC auto-provision code path: set `password_hash = '!disabled'` instead of generating a random hash. Pair with a regression test that asserts `users.password_hash LIKE '!%' OR password_hash = ''` for every row with a non-empty `oidc_sub`.

### J.10 Bug T — 6 of 11 documented sudo-grant invalidation events don't actually invalidate

**Discovered:** 2026-05-08 22:25 EDT, Pass A Sweep C.

`docs/internal/step-up-auth.md` "Sudo grant invalidation" lists 11 events that should call `ipam_sudo_invalidate()`. Static analysis of all callers shows **5 sites + 1 implicit (logout via `logout_user()` clearing `$_SESSION`)**, leaving **6 missing**:

| Event | Handler site | Status |
|---|---|---|
| logout | `logout.php` (via `logout_user()` clearing `$_SESSION`) | ✓ implicit |
| password change | `change_password.php:153` | ✓ |
| **role downgrade (admin→readonly)** | `users.php:143-145` (`set_role`) | ❌ MISSING |
| **`oidc_sub` link** | `users.php:180` (`link_oidc`) | ❌ MISSING |
| **`oidc_sub` unlink** | `users.php:194` (`unlink_oidc`) | ❌ MISSING |
| **TOTP enroll** | `totp_enroll.php:75` | ❌ MISSING |
| TOTP disable | `change_password.php:103` | ✓ |
| **Email OTP enroll** | `change_password.php:272` | ❌ MISSING |
| Email OTP disable | `change_password.php:317` | ✓ |
| **Passkey add (register)** | `passkey_register.php` | ❌ MISSING |
| Passkey delete | `change_password.php:342` | ✓ |
| Step-up policy save | `settings.php:155, 327` | ✓ |

**Effect:** sudo grants outlive every MFA-enrollment change and `oidc_sub` change. The role-downgrade case is the most concerning — it's the documented mechanism for locking down a compromised account, and it doesn't actually clear the elevated sudo grant.

**Slot:** v3.27.1 — same step-up subsystem area as Bug X (consume_once wiring), Bug Y (MFA-disable lockout guard), and Bug Z (OIDC validator). Folding into the same PR is consistent and the fix per missing site is a single line.

**Fix shape:** add `ipam_sudo_invalidate();` after the audit row in each of the 6 missing sites. Pair with PHPUnit tests that explicitly assert the grant is cleared after each event.

### J.11 Sweep B — audit log append-only triggers VERIFIED

Tested 2026-05-08 22:23 EDT.
- Trigger exists and rejects `UPDATE audit_log SET details='TAMPERED' WHERE id=100548` with `Error: stepping, audit_log is append-only (19)`.
- Trigger rejects `DELETE FROM audit_log WHERE id=100548` similarly.
- Row 100548's `details` confirmed unchanged after both attempts.

The forensic foundation we relied on across all of Pass A's "the audit log proves it" arguments is solid. No bug. Forensic claims stand.

### J.12 Bug S — IPAMBKL1 restore fails on every backup containing bcrypt password hashes

**Discovered:** 2026-05-08 22:21 EDT, Pass A Sweep A.

**Repro:** Took a fresh IPAMBKL1 backup of the test instance via the orchestrator (the §3 successful unencrypted run, `backup_runs.id=14`, file `ipam-backup-20260509-014032-b633e43d.ipambkl1.gz`, 7.8MB). Audit row `backup.run` confirmed success, checksum recorded. Then attempted Restore via the wizard. Step 2 dry-run failed with:

> "Dry run failed: ipam_restore_split: unterminated dollar-quoted string $0N$ at end of input — backup may be truncated"

**File analysis:** the archive is structurally complete:
- Magic `IPAMBKL1\n` ✓
- JSON header with `format_version: 1`, `schema_version: 59`, `last_migration_version: 3.27.0-step-up-policy-settings`, `exported_at: 2026-05-09T01:40:18Z` ✓
- 794,059 lines of SQL/JSON body ✓
- JSON footer `{"footer":true,"checksum_sha256":"5255f0e8b049b1b2..."}` ✓

The error message "backup may be truncated" is misleading. The file is NOT truncated.

**Real root cause:** the file body contains bcrypt password hashes (`$2y$12$FOuQZcROadm...`). PostgreSQL uses `$tag$ ... $tag$` for dollar-quoted strings, and `ipam_restore_split_sql_statements` (`lib/backup.php:2014`) tries to honor that syntax for cross-engine SQL compatibility. **The parser sees `$2y$` and `$12$` as opening dollar-quote tags but never finds a matching close — because they're inside a single-quoted string literal, not real dollar-quotes.** SQL standard says `$tag$` is only a dollar-quote OUTSIDE any single-quoted string.

**Operator impact:** every install with at least one user who has a bcrypt password hash (which is every install — the bootstrap admin always has one) cannot restore an IPAMBKL1 backup. **Disaster recovery is broken on v3.27.0.**

**Fix shape:** in `ipam_restore_split_sql_statements`, track quote state. Only recognize `$tag$` as a dollar-quote opener when the parser is NOT currently inside a single-quoted string literal. Add regression test with a fixture containing bcrypt hash strings inside `INSERT INTO users` payloads.

**Slot:** **v3.27.1.** Disaster recovery cannot be deferred. Same severity as the encrypt-write-path bug — backups landing fine, restores broken.

**Same architectural family as everything else found today:** parser added with one set of assumptions, didn't account for the actual data shape the dump emits. The IPAMBKL1 producer correctly emits SQL with bcrypt hashes inside `'$2y$...'` string literals; the consumer's parser doesn't track quote state. Read-side and write-side don't agree on the contract.

### J.6 Bug Z retest with `app_secret` ABSENT — STILL NOT REPRODUCED

**Discovered:** 2026-05-08 21:32 EDT.

**Setup:** Stashed `app_secret` and `bootstrap_key` in config.php (commented out via `sed`). Deleted `backup_vault_key` row from settings. Logged out, logged back in cold. State now matches sean's prod at the time of his original report.

**Result:** Single click on Generate → step-up prompt → password proof → vault_set succeeded end-to-end. Raw key shown once on the redirect target with flash message "Vault key configured. Copy the value below offline; this is your only chance." Audit log shows clean sequence:

```
100505  auth.sudo_passed       method=password                                01:32:27
100506  setting.update         backup_vault_key (***/***)                    01:32:27
100507  backup.vault_key.set   user=claude mode=generate fingerprint=acc58169 01:32:27
```

A new `bootstrap_key` was correctly injected into config.php (`'bootstrap_key' => 'QrIPPu/lDneVLegNOHgYWgnrpYZfW0YlPrhdpfemGqI='`).

**Conclusion:** Sean's hypothesis ("maybe behaviour is different with no app_secret") is disproved on this test instance. The flow works correctly with or without app_secret. The most likely explanation for the original "click Generate twice" memory remains the May 7 sudo_failed → May 9 success day-gap. **No structural Bug Z exists in the code path I tested.** Closing the speculative track unless you reproduce it on prod after v3.27.1 lands.
