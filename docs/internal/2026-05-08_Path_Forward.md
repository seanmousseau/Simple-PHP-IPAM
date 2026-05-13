# Path Forward — agreed 2026-05-08

**Origin:** Pass A regression sweep on 2026-05-08 surfaced 12 bugs in the v3.21–v3.27 stream, the consequence of cadence + chunking + testing decisions made (largely by Claude, unilaterally) during 3 weeks of high-velocity development. Sean called the result honestly: "We failed bad." This doc is the agreed-upon path forward, binding on Claude going forward the same way `CLAUDE.md` is.

> **Status:** binding from this date. Read at session start in conjunction with `CLAUDE.md` and `lessons-learned.md`. When this doc and another doc disagree, **this doc wins** until both are reconciled in writing.

---

## 1. The 9-step recovery plan

### Step 1 — Ship v3.27.1 (stop the bleeding)

**Scope (locked):**
- Encrypt-write-path → vault key (with `app_secret` legacy fallback, throw with actionable message if neither set)
- Five observability fixes (O1–O5 in the architecture doc)
- Bug X — `sudo_once` consumption wired across 10 sudo-class handlers
- Bug W — `has_encrypted_runs` gate distinguishes IPAMBKP2 from IPAMBKP3
- Bug Z — OIDC return-path validator accepts relative paths (or rationalised to a single shared helper with `step_up.php:30`)
- Bug T — Six missing `ipam_sudo_invalidate()` call sites added
- Bug S — `ipam_restore_split_sql_statements` tracks single-quoted string state so `$2y$` inside literals doesn't read as a dollar-quote opener

**Discipline:** every fix lands paired with the regression test that would have caught it. No fix without its test. Round-trip tests for every persistence path touched. Contract enforcement test for `sudo_once`/`sudo_invalidate`. Negative regression test for the OIDC validator.

**Test tooling additions are scoped to the bugs being fixed.** Not a full suite overhaul — that comes in step 5. The discipline is: each fix in this patch ships with the test that would have caught it, no more, no less.

**Reference docs:** `docs/superpowers/plans/2026-05-08-v3.27.1-hotfix.md` (architecture + scope), `docs/superpowers/plans/2026-05-08-v3.27.1-manual-regression-plan.md` (Pass A baseline + Pass B template), `releases/ipam-3.27.1/regression-evidence/passA/` (Pass A evidence).

### Step 2 — Ship v3.27.2

**Scope (locked):**
- Bug Y — MFA-disable lockout precondition guard
- Bug U — OIDC auto-provision uses `!disabled` sentinel for password_hash
- Bug V — Settings page boolean toggles unified with group-form path
- Self-test CLI (`tools/backup-self-test.php`) + UI button ("Test backup" sibling to "Test connection")
- Legacy IPAMBKP2 nudge banner on backup admin
- Whatever Pass B (post-v3.27.1) regression surfaces as collateral or as v3.27.2-shaped findings

Same test discipline as v3.27.1. Patch-shaped scope, minor-shaped weight — that's an honest CHANGELOG framing, not a release-engineering hack to hide it.

### Step 3 — Pass B and Pass D (manual regression by Claude before each merge)

After v3.27.1 candidate is built and deployed to the SQLite test instance: Claude executes Pass B against the existing regression plan (`2026-05-08-v3.27.1-manual-regression-plan.md` §16), fills the "B" column, demands every "expected fail on A, pass on B" row flips green. Captures evidence at `releases/ipam-3.27.1/regression-evidence/passB/`. Compares A↔B; any A-pass / B-fail row is a release blocker.

After v3.27.2 candidate is built: Claude executes Pass D against the same plan + any new rows that came out of v3.27.1's findings. Same evidence-capture rigour.

**No merge-to-main without Pass B / Pass D evidence captured and reviewed.**

### Step 4 — Pass C sweep after v3.27.2 ships

Manual Playwright + operator-side regression sweep across the v3.21–v3.27 surfaces we did NOT exercise in Pass A:

| Surface | Why it's at risk |
|---|---|
| Scanner subsystem (ARP import, TCP scan, async cron) | v3.21+ rapid evolution; multi-path |
| Webhook delivery | v3.22 reaper + retry coordination |
| DHCP pools | Touched in v3.21+; cascade behaviour with subnets |
| Tag join tables (`subnet_tags`, `address_tags`) | CASCADE behaviour on entity delete |
| Notification dispatch state machine | `destination_health` + `schedule_overdue` cooldowns |
| API.php endpoints | Sudo-class actions exposed via API key auth — does API bypass step-up? |
| Custom fields | Schema-change interactions |
| Reports / exports | Recently surfaced in nav |

Operator-side rows continue to live in `releases/ipam-3.27.1/regression-evidence/passA/operator-followup-checklist.md` and its analogues for later passes.

Pass C produces a triaged bug list slotted into v3.27.3 / v3.28.0 / v3.29.0 as needed.

### Step 5 — Test tooling baseline (proposed v3.28.0)

**No new features in v3.28.0.** Just test infrastructure. Three deliverables:

a. **Inventory of test fixtures that bypass the path under test**, with parallel-spec backfills for each. `warmSudoGrant()` is the worked example; sweep for siblings.

b. **Three missing test classes** wired into CI:
   1. **Round-trip tests** for every persistence path (encrypt → restore → byte-compare; dump → restore → row-equality; setting write → setting read → equality)
   2. **Contract enforcement tests** — every helper has at least one caller in app code; every audit verb in `audit-actions.md` has at least one `audit()` call; every documented invalidation event has its handler call site
   3. **Negative regression tests** — when a CR suggestion or security review tightens a validator/guard, a paired test pins existing valid use cases

c. **Contract-doc-vs-code linter** — a CI script that grep-asserts the contract docs (`step-up-auth.md`, `audit-actions.md`, `runtime-dependency-policy.md`, etc.) match actual code. Catches drift like Bug T (6 of 11 documented invalidation events had no caller).

Shipping v3.28.0 as pure test infrastructure is unusual but the right signal: the next feature release is starting from a stronger baseline.

### Step 6 — Additional v3.27.x patches as needed

No artificial cap. If Pass B / Pass D / Pass C surface more issues, they get patches. The bias is toward more small patches over fewer larger ones IF the issues are independent — and toward bundling IF the issues share an architectural area.

### Step 7 — Release process review

A new doc — `docs/internal/release-discipline.md` — separate from the existing procedural `release-workflow.md`. Captures:
- The Mandatory pre-PR checklist from `lessons-learned.md §8`
- The new rules locked in this conversation (no helper without a caller, no contract doc without grep verification, no architectural split across releases without integration test plan, etc.)
- The cadence cap and scope-lock framing rules
- The decision-review gate (see §3 of this doc)

Authored after v3.27.2 ships. Binding on every release going forward.

### Step 8 — Architecture review

Structured backlog of decisions to examine and decide whether they stay. Not "rewrite the app" — "look at each candidate honestly and decide."

Initial candidates (open for revision):

- **`lib.php` size** (~9000 lines, all functions in one namespace). Module separation overdue.
- **Per-key vs group-form bifurcation** in `settings.php`. Bug V's root cause. Pick one path.
- **`backup.php` orchestrator/codec/dispatcher separation** that enabled the read-vs-write gap. Examine whether the abstraction layers help or hurt.
- **`$config` global as the only config conduit.** Hides the dependency graph; "where does this setting come from?" is hard to answer in code.
- **Settings table type system** — bool/int/string/json is impoverished; sensitive flag is bolted on.
- **Memory MCP discipline as the only cross-session continuity.** Useful but didn't prevent today's bugs because we didn't write observations linking v3.24 codec → v3.26 storage → v3.27 step-up.
- **The contract-doc-as-source-of-truth model.** When `step-up-auth.md` says X and code does Y, X "wins" by convention but the drift produces bugs. Either treat docs as authoritative (and lint against them) or treat code as authoritative (and generate docs from it).

Each candidate gets a separate decision document under `docs/internal/architecture-decisions/` with options + tradeoffs + recommendation + Sean's stamp. **No architectural decision in this list gets implemented unilaterally by Claude.**

### Step 9 — Re-chunk v3.28.0+ and the v4.x.x stream

The current v4.x.x scope (multi-tenancy + i18n + RBAC + SAML + LDAP + OAuth + SCIM across ~6 releases) is exactly the shape that produced today's bugs. Pushing v4 → v5 (or further) is on the table.

**Decision (locked 2026-05-08):**

- **Multi-tenancy is deferred indefinitely.** Sean is investigating licensing implications (likely paid add-on under a different license).
- **However, all v3.28.0+ design decisions must keep an eventual multi-tenancy view.** Schema changes, helper signatures, settings cascade, audit log — anything new should not lock us into a massive future rewrite when multi-tenancy lands. Specifically:
  - New data tables should consider `tenant_id` from the start (even if the column is unused / `NULL`-only in v3.x). Adding it later is harder than carrying it forward.
  - Settings reads should continue going through `ipam_setting()` (the cascade-aware accessor). Direct `SELECT FROM settings` reads create future migration debt.
  - Per-tenant key derivation (HKDF from `app_secret`) remains the model, even though the tenant_id parameter is unused in v3.x.
- **The remaining v4.x.x stream (i18n, RBAC, SAML, LDAP, OAuth, SCIM) gets re-chunked.** Each gets its own decision doc, its own scope-lock, and ships in its own minor (or its own deferral). One feature per release minimum, not the current "one stream of releases per feature area."
- **v4.0.0's actual scope gets re-decided** after step 5 (test tooling) and step 8 (architecture review) finish. Don't pre-commit to v4 features until the foundation is honest.

**Honest framing:** "v3.x → v4.x as the path to enterprise-worthy" was the implicit goal of v3.21–v3.27. We didn't reach it. v3.27.1 + v3.27.2 + v3.28.0 (test tooling) + Pass C + architecture review + re-chunk is the corrected path. v4 starts when the foundation is solid, not on a calendar.

---

## 2. What I (Claude) commit to NOT doing unilaterally going forward

The architectural decisions that produced today's bugs were made by me without bringing options to Sean. That stops. Specifically:

| Decision class | New mode |
|---|---|
| **Cadence** (release timing) | I bring a recommended cadence with options + tradeoffs. Sean decides. |
| **Architectural splits across releases** | If a feature spans N releases, I propose the integration test plan FIRST. Sean approves before scope-locking the first release. |
| **Test bypass fixtures** | I bring the proposed fixture, what it bypasses, and the parallel spec that exercises the bypassed path. Sean signs off on the bypass. |
| **Contract docs** | Before proposing for review, I grep every documented event/site/sentinel against actual code. If drift exists, I fix the drift first or document the drift explicitly. |
| **Accepting CR-suggested fixes** | I explicitly flag "what test would fail if this fix regressed existing valid use cases?" Either add the test or surface the gap to Sean. |
| **Helper signatures and call sites** | No "land helper, wire callers next release." Every helper lands with at least one caller in app code. |
| **Migrations adding semantic columns** | Land WITH every reader and writer that should respect the new semantic. Not "add column, retrofit later." |
| **Authoring contract docs** | Code-first, then doc. Never the reverse. |

**The decision-review gate:** before any architectural decision, I frame it as:

> "I see options A, B, C with tradeoffs X / Y / Z. I recommend B because of M. Want me to proceed with B, or pick differently?"

And Sean decides. No more "I OK'd it because it seemed reasonable."

---

## 3. Specific decisions I made unilaterally that produced bugs

For the record. Future me reads this and recognises the pattern.

| Decision | Bug it produced |
|---|---|
| 7 minors in 10 days cadence | Architectural splits across releases (root cause of integration gaps) |
| Splitting vault relocation across v3.24 / v3.26 / v3.27 | Encrypt-write-path bug (codec landed v3.24, storage v3.26, write side never wired) |
| "Land helper, wire callers next release" pattern | Bug X (`sudo_once` defined v3.27.0, zero callers) |
| `warmSudoGrant()` test bypass without parallel non-bypassing spec | Bug X, Bug Z (across 8 handlers), Bug T all hidden behind the fixture |
| Authoring `step-up-auth.md` 11-event invalidation list without grep-verifying call sites | Bug T (6 of 11 events have no caller) |
| Treating CR-resolved as a quality gate | Bug Z (validator tightened, no negative regression test for relative-path callers) |
| Per-release scope locks for cross-release architectural changes | Encrypt-write-path bug (no one owned the integration) |

Each was a unilateral call. Each contributed to the failure.

---

## 4. Architecture-with-multi-tenancy-in-view rule

Multi-tenancy is deferred but not abandoned. **Every architectural decision in v3.28.0+ must keep the door open.**

Concrete rules (binding):

1. **New data tables:** consider `tenant_id INTEGER NULL` from creation. Even if NULL-only in v3.x, it removes a future migration. Index it. Schema parity tests assert the column exists.
2. **Settings reads:** must go through `ipam_setting()`. Direct `SELECT value FROM settings` is forbidden in new code. Existing direct readers from this audit get tracked for migration in step 5 (test tooling) or step 8 (architecture review).
3. **Per-tenant key derivation:** HKDF from `app_secret` with a tenant_id-bearing context string. v3.x context is `"ipam-v3:" || purpose`; v4-and-later context will include tenant_id. Helpers that derive keys today should accept a `?int $tenantId = null` parameter even if it's unused.
4. **Audit log:** rows include `tenant_id` column from the next schema change that touches `audit_log`. NULL in v3.x.
5. **Foreign keys:** new FKs should consider whether they need to be (`tenant_id`, `entity_id`) composite when multi-tenancy lands. Document the consideration in the migration's PR.
6. **URL prefix model:** `/t/<slug>/` was the prior decision. Keep that as the design constraint for new URL paths — server-relative redirects, no leading-slash assumptions that break under a path prefix.
7. **No multi-tenancy code in v3.x.** The rules above are about not closing doors, not opening them. v3.x stays single-tenant in behavior.

These rules go into `CLAUDE.md` after this doc is finalised.

---

## 5. Scope and process for v3.27.1 (starting now)

**Branch:** `v3.27.1-hotfix` off `main` per `docs/internal/hotfix-release.md`.

**Implementation order (paired tests + fixes):**

1. **Test infrastructure first.** Round-trip test fixture for backup encrypt/restore. Reused by encrypt-write-path AND Bug S fixes. PHPUnit harness that produces a synthetic 1KB SQL → encrypt with given key/method → restore via dispatcher → byte-compare.
2. **Bug S — IPAMBKL1 restore parser.** Test: produce IPAMBKL1 with bcrypt-hash-bearing INSERT statement, restore, assert no parser error. Fix: `ipam_restore_split_sql_statements` tracks single-quoted-string state. Disaster recovery first.
3. **Encrypt-write-path → vault key.** Test: vault key set + app_secret empty + encryption requested → orchestrator produces IPAMBKP3, restores byte-equal. Test: app_secret set + no vault key → orchestrator produces IPAMBKP2 (legacy fallback). Test: neither set + encryption requested → throws clear error, writes failed `backup_runs` row + audit. Fix: `lib/backup.php:396-408` per architecture doc §6.1.
4. **Five observability fixes.** Tests: each gap has a test asserting the new behavior (audit row written / error_log emitted / `backup_runs` failed-row visible / overdue detector reads last-run status / cron wrapper logs). Fixes: per architecture doc §6.2.
5. **Bug W — `has_encrypted_runs` gate.** Test: install with IPAMBKP2 archives + no vault key → `vault_set` Generate is permitted (since IPAMBKP2 doesn't depend on vault key). Fix: distinguish IPAMBKP2 from IPAMBKP3 in the gate query.
6. **Bug X — `sudo_once` consumption.** Test: TTL=0 + sudo action #1 + sudo action #2 → action #2 re-prompts (one `auth.sudo_passed` per action). Fix: `ipam_sudo_consume_once()` called from each of the 10 handlers.
7. **Bug Z — OIDC validator.** Test: relative path (`backup_admin.php?tab=destinations`) survives validation. Test: open-redirect attempts (`//evil.com`, `\\foo`, `..`) still rejected. Fix: rewrite validator at `lib/auth_step_up.php:480-489` to mirror `step_up.php:30-49`.
8. **Bug T — Sudo invalidation triggers.** Tests: each of the 6 missing events clears the grant. Fixes: add `ipam_sudo_invalidate()` to each handler.
9. **Update docs:** `audit-actions.md` (new `backup.preflight_failed`), `lessons-learned.md` (already done), `step-up-auth.md` (re-grep the 11 events post-fix), `deploy-targets.md` (cron wrapper logging), `CHANGELOG.md` (Q5 framing).
10. **Bump `version.php` to 3.27.1.**
11. **Build release bundle.** Commit. Deploy to SQLite test instance. Pass B.
12. **Pass B regression.** Fill the B column of the regression plan. Compare A↔B. Surface any A-pass / B-fail row as a blocker.
13. **Deploy to other testing instances** (mysql / mariadb / pgsql) per `deploy-targets.md`. 3-driver gate.
14. **PR to main** with full Pass B evidence linked.

---

## 6. Honest closing

This conversation surfaced 12 bugs in 30 minutes, escalated three of them to v3.27.1 from initial deferral, found a 13th bug (Bug T) on top, identified the architectural pattern that produced all of them, and revealed a test tooling gap that has been quietly present for longer than v3.27.0.

We failed badly in the v3.21–v3.27 stream. The path forward is honest about that. v3.27.1 + v3.27.2 + Pass C + test tooling baseline + architecture review + re-chunked v3.28.0+ is the corrected approach. v4 starts when the foundation is honest. Multi-tenancy is deferred indefinitely with the door kept open via the rules in §4.

This doc is binding on Claude going forward. It supersedes prior implicit operating norms wherever it conflicts. When this doc and another doc disagree, this doc wins until both are reconciled in writing.

— **Agreed:** Sean Mousseau (proposed) + Claude (committed), 2026-05-08.
