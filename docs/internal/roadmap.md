# Roadmap — Simple-PHP-IPAM

> **What this is.** The single consolidated view of *what's planned, in what order, and why* for every release after the current shipped version. Synthesizes `code_quality_review.md`, `ux_overhaul.md`, `backup_overhaul.md`, `v4-release-stream.md`, `i18n-design.md`, `2026-05-08_Path_Forward.md`, `cleanup.md`, `lessons-learned.md`, and `test-improvements.md` into one place — plus the live state of GitHub milestones.
>
> **What this isn't.** Not a commitment to dates. Not a substitute for the source docs (each section here links back to its authoritative source for full context, finding IDs, and rationale). Not a multi-tenancy plan — that's deferred (see §6.4 and `v4-tenancy-design.md`).
>
> **How to use it.** Start here when prioritizing a release, juggling milestones, deciding what slips, or onboarding to the project's longer arcs. Drill into the source doc when you need the per-finding detail. Refresh this doc when a milestone closes or a stream gets re-chunked.
>
> **Current shipped: v3.27.6** (released 2026-05-10 — closes the v3.27.x cluster). **Pass C complete** (2026-05-10). Last refreshed: 2026-05-10 evening.

---

## 1. TL;DR — current state

| Stream | Span | Status | Anchor |
|---|---|---|---|
| **v3.27.x quick-win patches** | v3.27.4, .5, .6 | ✅ **all shipped (2026-05-10)** — cluster closed | §3 |
| **Pass C regression sweep** | one-time | ✅ **complete (2026-05-10)** — 15 findings triaged | §3.5 + `releases/ipam-3.27.6/regression-evidence/passC/PASS-C-SUMMARY.md` |
| **v3.27.7 hotfix (proposed)** | one release | **decision pending** — Pass C surfaced 1 High (webhook secret plaintext at rest) | §3.6 |
| **Test-tooling baseline** | v3.28.0 | scoped — see `test-improvements.md` | §4 |
| **Step-up coverage sweep (new)** | v3.28.1 or v3.29.0 | **decision pending** — Pass C bundle (F-S3-02 / F-S5-01 / F-S7-01) | §4.5 |
| **Code-quality refactor** | v3.29.0 → v3.31.0 | 87 issues filed, all open | `code_quality_review.md` §9 |
| **UX overhaul** | v3.32.0 → v3.36.0 | 82 issues filed, all open | `ux_overhaul.md` §9 |
| **Backup overhaul** | v3.21–v3.27 | mostly shipped; 1 doc-debt item left | `backup_overhaul.md` §10 |
| **v4.x enterprise auth + i18n** | v4.0.0 → v4.11.0 | 11 milestones placeholdered (one tracking issue each) | `v4-release-stream.md` §3 |
| **Multi-tenancy** | indefinite | DEFERRED pending sustainability/licensing model | `v4-tenancy-design.md` |

**Shape of the next year (without dates):**

```
v3.27.4/.5/.6 (shipped) ── Pass C (done) ── [decision: v3.27.7 hotfix? or hold?]
                                                  │
                                                  └── v3.28.0 (TEST INFRASTRUCTURE — Path Forward step 5)
                                                         │
                                                         ├── v3.28.1 — step-up coverage sweep + Pass C code-side findings
                                                         │       │
                                                         │       └── v3.29.0 → v3.31.0 (code-quality refactor)
                                                         │              │
                                                         │              └── v3.32.0 → v3.36.0 (UX overhaul)
                                                         │                     │
                                                         │                     └── ARCHITECTURE REVIEW gate (Path Forward step 8)
                                                         │                            │
                                                         │                            └── v4.0.0 (i18n phase 1) → … → v4.11.0
                                                         │
                                                         └── (any stream can pause for hotfixes off main)
```

---

## 2. Operating mode (binding)

The architectural decisions that produced the v3.21–v3.27 bug cluster were made unilaterally by Claude. **That stops.** Codified in `2026-05-08_Path_Forward.md` §2 and reinforced in `lessons-learned.md` §8.

**Eight commitment classes Claude does NOT unilateralize anymore:**

| Decision class | New mode |
|---|---|
| Cadence (release timing) | Recommend with options + tradeoffs; Sean decides |
| Architectural splits across releases | Propose integration test plan first; Sean approves before scope-locking the first release |
| Test bypass fixtures | Surface the bypass + the parallel non-bypassing spec; Sean signs off |
| Contract docs | Grep every documented event/site/sentinel against code BEFORE proposing for review |
| Accepting CR-suggested fixes | Always flag "what test would fail if this fix regressed an existing valid use case?" |
| Helper signatures and call sites | No "land helper, wire callers next release" — every helper lands with ≥1 caller |
| Migrations adding semantic columns | Land WITH every reader and writer that respects the new semantic |
| Contract doc authoring | Code-first, then doc. Never the reverse. |

**The decision-review gate:** every architectural decision is framed as `"I see options A/B/C with tradeoffs X/Y/Z. I recommend B because M. Want me to proceed with B, or pick differently?"` Sean decides.

**Mandatory pre-PR checklist** (`lessons-learned.md` §8 — applies to every feature PR):

- [ ] Contract doc lists every event/site/sentinel that participates in the new contract
- [ ] Repo-wide grep for the new helper / sentinel / event verb shows callers in every documented site (or rationale why a site is exempt)
- [ ] At least one test exercises the contract end-to-end (round-trip / event-to-effect / write-then-read), not just the units in isolation
- [ ] If a fixture exists that bypasses the new contract for test convenience, at least one spec exercises the contract WITHOUT the fixture
- [ ] When tightening a validator/guard, a test pins the existing valid use cases with their actual production-shape inputs
- [ ] When introducing a sentinel value, a test asserts production-shaped data carries the sentinel (not just that the sentinel is rejected when present)
- [ ] Failure paths surface through audit + error_log + UI — not just stderr

---

## 3. v3.27.x quick-win patch stream — ✅ CLOSED

Off-`main` hotfix releases for narrow fixes that should ship before the next minor. Branches off `main`, merges back, then `dev ← main` (`hotfix-release.md`).

**Cluster status (2026-05-10):** **all four patches shipped** — v3.27.4, v3.27.5, v3.27.6 close the cluster Sean set out to do off Pass A. v3.27.6 is the current shipped baseline. **Pass C ran against v3.27.6** (§3.5).

### v3.27.4 — Settings UX polish patch (milestone #78, 7 issues)

**Theme:** "Operator UX polish — the rough edges that aren't bugs but aren't right."

| Issue | Title | Source |
|---|---|---|
| #1133 | Lockout-guard refusal doesn't revert form state to last-saved config | wide-regression P2 |
| #1134 | Numeric input clamps to 1 on direct entry | wide-regression P2 |
| #1140 | XHR sudo-gated actions need auto-replay after OIDC step-up (UX trap under TTL=0) | v3.27.3 smoke |
| #1143 | `auth.ip_rate_limited` dampener TOCTOU — log noise only, atomic state row | v3.27.3 CR review |
| WR-04 | Tag-attach UI absent on subnet edit form | wide-regression deferred |
| TBD | OIDC settings need autocomplete hints for password manager autofill | wide-regression |
| TBD | Backup log displays UTC instead of user TZ | wide-regression |

**Status:** ✅ shipped 2026-05-10. Bundled the settings-UX P2s + the wheel-scroll regression + lockout banner.

### v3.27.5 — Password-manager autocomplete attributes (off-roadmap hotfix)

**Theme:** "LastPass kept autofilling the OIDC client secret after .4."

Same-day hotfix off v3.27.4. Added `data-1p-ignore`, `data-lpignore`, `data-bwignore` to OIDC and SMTP password fields. Sqlite-only Playwright pass.

**Status:** ✅ shipped 2026-05-10. #1137 architectural fix (dedicated `/settings/oidc` page) deferred to v3.28.0+.

### v3.27.6 — Restore-page redesign + #1146 XHR sudo replay regression (milestone closed)

**Theme:** "Restore is the operator's worst day; make it not feel that way." Plus the regression fix Sean caught during manual CDP testing of v3.27.5.

**Scope (shipped):**
- Restore page architecture: dropped the `<details>` "Advanced" disclosure; promoted upload form to peer `<section class="card">`. Select destination → enumerate backups → click restore, with upload as an obvious alternative. (`backup_admin_restore.php`)
- Restore-apply spinner overlay (#1135) with escalating progress messaging at 5s and 20s.
- #1146 XHR sudo replay marker premature consumption on `step_up.php` — moved `sessionStorage.removeItem` inside the `if (replayBtn)` guard.
- Regression spec `testing/playwright/tests/sudo-xhr-replay.spec.ts` (3 cases: marker survives no-button page, consumed on matching-button page, expired purged).

**Status:** ✅ shipped 2026-05-10. **Final v3.27.x release.** Closes the cluster.

### v3.27.7 — Pass C hotfix (PROPOSED — pending decision)

**Theme:** "F-S3-01 — webhook signing secret stored plaintext at rest is a v3.24–v3.27 vault-relocation miss; ship now or hold?"

**Scope (if approved):**
- Migrate `webhooks.secret` → `webhooks.secret_enc` using vault key with `purpose="webhook-secret"` (HKDF-derived, matching v3.27 vault key model).
- One-shot upgrade migration encrypts existing rows; drops plaintext column.
- Round-trip test (which then becomes a v3.28.0 fixture).

**Status:** **decision pending.** See §3.6 for the three options Sean has to choose between (hold for v3.28.x / hotfix now / bundle in v3.28.0 carve-out). Trade-off captured in `releases/ipam-3.27.6/regression-evidence/passC/PASS-C-SUMMARY.md` §"Recommended v3.28.0 scope adjustment".

---

## 3.5 Pass C — completed 2026-05-10

**What:** Path-Forward §step 4 manual regression sweep across the v3.21–v3.27 surfaces Pass A/B did NOT exercise (scanner async cron, webhook delivery, DHCP, tag CASCADE, notification dispatch, api.php sudo bypass, custom fields, reports/exports).

**Driver:** Claude-driven, risk-first, SQLite-only sweep against the v3.27.6 test instance. Approved by Sean 2026-05-10.

**Result:** 15 findings across 8 surfaces.

| Severity | Count | Headlines |
|---|---|---|
| High | 1 | F-S3-01 — webhook signing secrets stored plaintext at rest (missed during v3.24–v3.27 vault key relocation) |
| Important | 5 | Scanner observability + contract drift — F-S2-01 (IPv6 sync guard), F-S2-02 (`scan.schedule_create` doc-but-no-emitter), F-S2-03 (`scan.stale_update` emit-but-no-doc + raw INSERT), F-S2-04 (cron scan path has no audit row at all), F-S2-05 (`ipam_mark_stale_addresses` defensive clamp) |
| Medium | 5 | Step-up coverage bundle (F-S3-02 webhooks / F-S5-01 notify recipients / F-S7-01 custom field defs); F-S5-02 write-side race on state JSON blobs (pairs with #1143); F-S6-01 Kea/dhcpd FQDN regex parity |
| Low / Note / Forward-looking | 4 | F-S6-03 PD pruning, F-S5-03 "test alert" path, N-S7-02 per-field audit diff, F-S6-02 VRF filter (RBAC stream) |

**Two surfaces fully clean:** Surface 1 (api.php — `grep -c ipam_sudo_ api.php` → 0 by design, API keys are stateless and sudo is session-scoped) and Surface 4 (tag CASCADE — both chains empirically verified through the app's `ipam_db()` with PRAGMA fk=1).

**One subagent false-positive caught at verification** (Surface 8: Explore agent claimed `export_dns.php:14` missing `require_login()`; actual file has `require_login()` at line 16 — off-by-2 miscount). Captured as a methodology note in the summary; same class of risk as Pass A "subagent claims test pass without naming the test file."

**Full evidence:** `releases/ipam-3.27.6/regression-evidence/passC/` (plan, baseline, gzipped DB snapshot, 8 surface files, summary).

## 3.6 The three options Pass C surfaced for v3.28.0 scope

Pass C delivered a triaged list, not a release commitment. The decision Sean needs to make next (per Path Forward §2 decision-review gate):

| Option | Description | Trade-off |
|---|---|---|
| **A** — Hold all Pass C findings | Keep v3.28.0 pure test-tooling per the path forward. Pass C findings slot into v3.28.1+ via a new "Pass C cleanup" milestone. | Cleanest discipline. Webhook secret plaintext ages two months. |
| **B** — Carve a small security thread into v3.28.0 | F-S3-01 + step-up sweep + F-S5-02 ride along with test tooling. ≤1 week extra. | Middle path. Risks scope creep on a "pure" release. |
| **C** — v3.27.7 hotfix + v3.28.0 as planned + v3.28.1 sweep | Off-`main` hotfix for F-S3-01 only; v3.28.0 stays clean; bundle the rest in v3.28.1. | Most defense-in-depth-rich; multiplies release count. |

**My (Claude's) recommendation:** **C.** F-S3-01 is the highest-severity item from Pass C and was *already* in the v3.27 cluster's vault-relocation theme — finishing the job off `main` keeps the path forward's discipline intact (v3.28.0 stays pure test-tooling) without leaving the at-rest leak hanging. v3.28.1 then bundles the step-up sweep + the atomic-dampener pair (#1143 + F-S5-02), giving each thread one focused release.

**Sean decides.**

---

## 4. v3.28.0 — Test infrastructure (Path Forward step 5)

> **Operational plan: `docs/internal/test-improvements.md`.** Read that doc before scoping any v3.28.0 issue. The section below is the release-level summary; the operational doc has the fixture inventory, contract-linter spec, round-trip test taxonomy, and the four open questions for Sean to answer before scope-lock.

**No new features in v3.28.0.** This is the explicit response to the v3.21–v3.27 bug cluster: Pass A regression on 2026-05-08 surfaced 12 distinct bugs that passing CI + passing CodeRabbit + passing 3-driver Playwright did not catch. The pattern is documented exhaustively in `lessons-learned.md` §8.

**Three deliverables (Path Forward §1.5):**

1. **Inventory of test fixtures that bypass the path under test**, with parallel-spec backfills for each. `warmSudoGrant()` is the worked example (it minted sudo grants directly, hiding Bug X / Bug Z / Bug T from every step-up spec). Sweep for siblings.
2. **Three missing test classes wired into CI:**
   - **Round-trip tests** for every persistence path (encrypt → restore → byte-compare; dump → restore → row-equality; setting write → setting read → equality)
   - **Contract enforcement tests** — every helper has ≥1 caller in app code; every audit verb in `audit-actions.md` has ≥1 `audit()` call; every documented invalidation event has its handler call site
   - **Negative regression tests** — when a CR or security review tightens a validator/guard, a paired test pins existing valid use cases
3. **Contract-doc-vs-code linter** — CI script that grep-asserts contract docs (`step-up-auth.md`, `audit-actions.md`, `runtime-dependency-policy.md`) match actual code. Catches drift like Bug T (6 of 11 documented invalidation events had no caller).

**GitHub state:** milestone #55, 29 open issues. Closes findings A28, D4-partial, D6–D18 from `code_quality_review.md` plus the test-coverage gaps from §6.D and the Path Forward step 5 deliverables.

**Why pure test infrastructure as a release:** unusual but the right signal. The next feature release starts from a stronger baseline.

**Pass C dependency:** F-S2-02 and F-S2-03 (audit-actions doc drift on `scan.schedule_create` / `scan.stale_update`) need to be resolved so the v3.28.0 contract-doc linter is green on day one. Either ship the doc fixes inside v3.28.0 (lightweight code/doc change) or ship the linter with an explicit allowlist that empties by v3.28.1. Decision belongs in `test-improvements.md` §8 question 1.

---

## 4.5 v3.28.1 (proposed) — Pass C code-side cleanup + step-up coverage sweep

**Theme:** "Close out the Pass C code-side findings + the step-up bundle, now that v3.28.0 has the testing harness for it."

**Scope candidate (pending §3.6 decision on Option A/B/C):**

| Finding | Effort |
|---|---|
| F-S3-02 — webhook create/edit/delete sudo-gated | 1d |
| F-S5-01 — `backup.notify_recipients` + SMTP setting save sudo-gated | 1d |
| F-S7-01 — custom field def create/update/delete sudo-gated | 1d |
| F-S5-02 — write-side race on `destination_health` / `schedule_overdue_state` JSON (paired with #1143) | 2–3d |
| F-S2-01 — IPv6 sync-scan rejection | 0.5d |
| F-S2-04 — cron scan emits `scan.run` audit row | 0.5d |
| F-S2-05 — `ipam_mark_stale_addresses` threshold clamp | 0.5d |
| F-S6-01 — Kea JSON FQDN regex parity | 0.5d |

Step-up bundle (F-S3-02 / F-S5-01 / F-S7-01) is the headline — three sudo-gating gaps with the same shape. Land together so the policy registry expands once, not three times.

**Status:** depends on Option A/B/C decision from §3.6. If Option C, this slot is reserved; if Option B, items get absorbed into v3.28.0; if Option A, all of this slides to v3.29.0+.

---

## 5. v3.29.0 → v3.36.0 — Refactor + UX overhaul streams

Two parallel streams that both close before v4.0.0 by user directive (`ux_overhaul.md` §10, `code_quality_review.md` §10).

### 5.1 Code-quality refactor stream (v3.29.0 → v3.31.0)

Closes 67 of the 87 `code_quality_review.md` findings (the other 20 already shipped in v3.26.0; v3.28.0 covers the test-coverage subset above).

| Release | Theme | Issues | Closes findings |
|---|---|---|---|
| **v3.29.0** (#56) | `lib.php` decomposition + page-handler refactor | 16 | A11, A12, A14–A16, A23–A25, A27, A33, B7–B9, B11(re-order), C8 |
| **v3.30.0** (#57) | `api.php` + `import_csv` refactor + migrations cleanup | 18 | A6, A13, A17–A19, A29, A32, B5, B6, B10, B13, C2, C3-policy, C5–C7, C9–C12 |
| **v3.31.0** (#58) | Frontend modularization + P2/P3 polish | 16 | A20, A26, A31, A.P3 (A34–A42), B.P3 (B25–B34), C.P3 (C16–C18), D4-full, D5, D13–D21 |

**Per-milestone test umbrellas:** #1045 (v3.29.0), #1046 (v3.30.0), #1047 (v3.31.0). Each refactor PR must update or add the matching test in the same commit; umbrella stays open until all refactor issues in its milestone close.

**Source:** `code_quality_review.md` §9.

### 5.2 UX overhaul stream (v3.32.0 → v3.36.0)

Closes the 82 `ux_overhaul.md` findings. Audit scope **explicitly excludes** backup/restore UX — that's `backup_overhaul.md` §2 + §7 territory.

| Release | Theme | Issues | Effort |
|---|---|---|---|
| **v3.32.0** (#59) | Design-system foundation — type scale, badge primitive, color-not-only-signal, dark-mode brand-link, mobile font sizing | 12 | ~3–5d |
| **v3.33.0** (#60) | Sidebar + nav consolidation — group 22 admin links into 5 sections, command palette, theme toggle UX, sidebar collapse, post-timeout deep-link return | 11 | ~3–5d |
| **v3.34.0** (#61) | Data-heavy pages overhaul — pagination/virtualization (subnets renders 3,727 elements / 103k-px page today), drawer-edit, action consolidation, status-as-badge, URL-state filters, bulk-action bar, columns persistence | 14 | ~10–14d |
| **v3.35.0** (#62) | Search + audit + dashboard polish — instant search, pretty-printed audit (resolve `user#N`, `auth.passkey_challenge`), relative time, KPI clickable, recent-activity rendering | 20 | ~6–8d |
| **v3.36.0** (#63) | Admin pages standardization + import wizard + a11y sweep + mobile + auth polish — drawer-CRUD across 12 admin pages, empty states, settings save UX, import-CSV stepper + drag-drop, mobile responsive sweep, a11y sweep, login UX | 38 | ~10–14d |

Total ~35–49 engineer-days across 5 milestones, all before v4.0.0.

**Why this split** (`ux_overhaul.md` §9.1):
- v3.32.0 is the foundation — type scale + badge primitive + color-not-only-signal are leverage that every later milestone benefits from.
- v3.33.0 is the nav consolidation — once admin links are grouped into 5 sections, v4.0.0 can add tenant switcher / super-admin without further bloat.
- v3.34.0 is the highest-impact UX win — subnets/addresses are where operators spend 80% of their time.
- v3.35.0 is the polish for the second-most-used surfaces — search + audit + dashboard.
- v3.36.0 is the cleanup pass — admin pages, mobile, a11y, auth, all remaining P2/P3.

**Per-milestone test umbrellas:** #1035 (v3.32.0), #1036 (v3.33.0), #1037 (v3.34.0 — largest test impact), #1038 (v3.35.0), #1039 (v3.36.0).

**Source:** `ux_overhaul.md` §9.

### 5.3 Cross-stream interaction

| Cross-stream concern | Handling |
|---|---|
| Code-quality `B8` ↔ UX `L2` (addresses/subnets controller split is the data-flow prerequisite for the visual drawer rebuild) | Code-quality stream lands first; UX builds on top |
| Open Props (`C5`) ↔ design-system (`D13`) | Same work; resolved when v3.31.0's frontend modularization completes |
| Drawer pattern (UX `A12`) | Reference is `backup_overhaul.md` §2's existing drawer (already shipped) — extend, don't replace |

---

## 6. v4.x stream — enterprise auth + global reach

Forward-looking design, not a commitment. Captured in `v4-release-stream.md`. **Multi-tenancy is explicitly NOT in v4.x** — see §6.4 below.

### 6.1 Theme

Enterprise authentication (SAML / LDAP / OAuth / SCIM) + global reach (i18n / l10n) + the RBAC/groups/per-subnet ACL story that enterprise users need. v4.x has 11 placeholder releases tracking 11 features that don't fit comfortably in 1–2 minors.

### 6.2 Sequencing (locked 2026-05-07)

| Milestone | Theme | Tracking issue | Source |
|---|---|---|---|
| **v4.0.0** (#19) | i18n infrastructure (phase 1) — gettext-based catalog, `__()`/`_n()` helpers, per-user locale cascade, language picker | #1064 | `i18n-design.md` |
| **v4.1.0** (#29) | i18n extraction sweep (phase 2) — mechanical wrap-every-user-facing-string PR | #1063 | `i18n-design.md` |
| **v4.2.0** (#65) | OIDC engine swap — retire hand-rolled JWT/JWK, adopt firebase/php-jwt ^6.0 | #417 | `v4-release-stream.md` |
| **v4.3.0** (#66) | RBAC foundation: `groups` + `user_groups` join tables | #334 | `v4-release-stream.md` |
| **v4.4.0** (#67) | Editable RBAC engine — replace hard-coded `admin`/`readonly` with permission-target gates | #456 | `v4-release-stream.md` |
| **v4.5.0** (#68) | Per-subnet ACLs — resource-level ACLs complementing v4.4 RBAC | #333 | `v4-release-stream.md` |
| **v4.6.0** (#69) | i18n phase 3: first non-English catalog (fr-CA), validates translator workflow | #1066 | `i18n-design.md` |
| **v4.7.0** (#70) | SAML 2.0 SSO (SP role) via onelogin/php-saml ^4.0 — first major enterprise-auth integration | #1065 | `v4-release-stream.md` |
| **v4.8.0** (#71) | LDAP / Active Directory via symfony/ldap ^7.0 wrapping ext-ldap | #1069 | `v4-release-stream.md` |
| **v4.9.0** (#72) | Generic OAuth 2.0 (GitHub/GitLab/Bitbucket/custom) via league/oauth2-client ^2.7 | #1070 | `v4-release-stream.md` |
| **v4.10.0** (#73) | SCIM 2.0 provisioning — Okta/Azure AD lifecycle | #1068 | `v4-release-stream.md` |
| **v4.11.0** (#74) | i18n phase 4: crowdsourcing via self-hosted Weblate (subject to mid-stream sustainability checkpoint — drop if no community translation interest) | #1071 | `i18n-design.md` |

### 6.3 Hard sequencing constraints

- **v4.1 must follow v4.0** (extraction needs the helpers).
- **v4.4 must follow v4.3** (RBAC engine needs the groups foundation).
- **v4.5 must follow v4.4** (per-subnet ACLs need RBAC).
- **v4.7 should follow v4.2** (SAML reuses JWT primitives).
- **v4.6/v4.11 follow v4.0+v4.1** (translation needs catalog infrastructure).

**Soft constraint:** interleave i18n with auth releases so visible internationalization progress accompanies enterprise-feature ramp (avoids "12 releases of plumbing").

### 6.4 What's NOT in v4.x

| Item | Why not | Where it lives |
|---|---|---|
| **Multi-tenancy** | DEFERRED INDEFINITELY (locked 2026-05-08). Sean investigating licensing implications (likely paid add-on under different license). All tenancy-coupled tickets parked under milestone #64. | `v4-tenancy-design.md`, milestone #64 |
| **Plugin / extension framework** | Out of scope; lessons-learned says no premature abstractions | — |
| **WebUI for backup keys** | Already shipped in v3.26.0 | — |

### 6.5 Architecture-with-multi-tenancy-in-view rule (Path Forward §4)

Even though multi-tenancy is deferred, every v3.28.0+ design decision must keep an eventual multi-tenancy view:

- New data tables should consider `tenant_id` from the start (even if `NULL`-only in v3.x). Adding it later is harder than carrying it forward.
- Settings reads should continue going through `ipam_setting()` (the cascade-aware accessor). Direct `SELECT FROM settings` reads create future migration debt.
- Per-tenant key derivation (HKDF from `app_secret`) remains the model, even though the tenant_id parameter is unused in v3.x.

### 6.6 Sustainability checkpoint

Per `v4-release-stream.md` §10: stop or shorten the v4.x stream if (1) no enterprise customer has actually requested SAML/LDAP/SCIM by v4.5/v4.6, OR (2) maintainer burnout signals. Drop v4.11 (Weblate) by default unless community translation interest has materialized.

---

## 7. Backup overhaul — closing out

`backup_overhaul.md` was the design-and-tracking doc for the multi-release backup epic. Most of the work has shipped across v3.21–v3.27. **The doc retires when its §10 milestones are all complete and its running lists empty** — currently at:

| Bucket | Status |
|---|---|
| §6 Functionality (F1–F25) | F18, F19, F22 still open (F22 is v4.0.0 tenant policy — parked under multi-tenancy deferral); rest shipped. |
| §7 UI (U1–U10) | All shipped or absorbed into UX overhaul stream. |
| §8 Testing (T1–T10) | Cross-engine round-trip + MinIO/LocalStack + SFTP integration tests are the remaining gap; covered by v3.28.0 round-trip-test deliverable. |
| §8a Documentation (D1–D12) | D7 (tenancy doc) parked under multi-tenancy deferral; D12 (retire `backup_overhaul.md` itself) lands in v4.0.0 release cleanup. |

**Single most expensive lesson from this stream:** `lessons-learned.md` §8 — "feature added at one site, propagation to adjacent sites missed." Every Pass A bug was a variant of that pattern (encrypt-write-path, Bug X, Bug Y, Bug Z, Bug T, Bug U, Bug V, Bug W, Bug S, the five observability gaps). The mandatory pre-PR checklist in §2 above is the institutional response.

---

## 8. Architecture review backlog (Path Forward step 8)

**Not "rewrite the app" — "look at each candidate honestly and decide."** Each candidate gets a separate decision document under `docs/internal/architecture-decisions/` (TBD path) with options + tradeoffs + recommendation + Sean's stamp. **No architectural decision in this list gets implemented unilaterally by Claude.**

Initial candidates (open for revision):

| Candidate | Why it's on the list |
|---|---|
| **`lib.php` size** (~9000 lines, all functions in one namespace) | Module separation overdue; UX overhaul + code-quality refactor will surface every site that imports from it. Decision before v3.29.0. |
| **Per-key vs group-form bifurcation in `settings.php`** | Bug V's root cause. Pick one path. |
| **`backup.php` orchestrator/codec/dispatcher separation** | The abstraction layers that enabled the encrypt-write-path bug. Examine whether they help or hurt. |
| **`$config` global as the only config conduit** | Hides the dependency graph; "where does this setting come from?" is hard to answer in code. |
| **Settings table type system** | bool/int/string/json is impoverished; sensitive flag is bolted on. v3.27.x patches keep tripping over the 6-value TTL allowlist + silent coercion. |
| **Memory MCP discipline as the only cross-session continuity** | Useful but didn't prevent today's bugs because we didn't write observations linking v3.24 codec → v3.26 storage → v3.27 step-up. |
| **Contract-doc-as-source-of-truth model** | When `step-up-auth.md` says X and code does Y, X "wins" by convention — the drift produces bugs. Either lint docs as authoritative, or generate docs from code. v3.28.0 contract-doc linter is the first response. |

**Cadence:** one decision doc per architecture session. After each is locked, the resulting work becomes a milestone-or-issue with a normal scope-lock conversation.

---

## 9. Pre-ticket cleanup backlog

`cleanup.md` is the canonical pre-ticket holding pattern for low-risk code-health items spotted during other work. Triage rule: low risk + not blocking + verifiable mechanically + not a refactor. When ≥3 items accumulate in a category, batch into a GH issue.

**Currently active:** Canadian English standardization across code/comments/UI/docs (deferred to a dedicated localization release stream — possibly v4.6 alongside fr-CA catalog, or its own minor).

**Currently tracked in a GH issue:** #1062 (phpmd `unusedcode` cleanup, 5 items, scheduled for v3.33.0).

---

## 10. Stream-juggling protocol

When a milestone gets reshuffled, update **here first** so the canonical view is correct, then update the source doc(s) and GitHub.

**Slot-bumping pattern** (from 2026-05-07 v3.27.0 insertion): when a new minor is inserted, every later milestone in the same stream bumps one slot. Source-doc decision logs (`code_quality_review.md` §10, `ux_overhaul.md` §10) record the bump; their per-finding milestone references stay valid because the *theme* moved with the milestone, not the *finding*.

**Hotfix pattern** (`hotfix-release.md`): branch off `main`, merge back, then `dev ← main`. Hotfix scope must NOT pull in dev-stream work — that's how feature drift becomes hotfix risk. v3.27.3 is the worked example.

**Deferral pattern** (multi-tenancy 2026-05-08): when an entire stream is parked, retitle the milestone (`v4.0.0` → `Multi-tenancy (deferred)`), keep the issues, and cross-reference from this doc + the source design doc.

---

## 11. Cross-references

This doc is a pointer index. Source docs hold per-finding detail and the binding decisions:

| Concern | Source |
|---|---|
| Operating mode + Path Forward commitments | `2026-05-08_Path_Forward.md` |
| Cross-release lessons (curated) | `lessons-learned.md` |
| **v3.28.0 operational plan (test tooling)** | `test-improvements.md` |
| **Pass C findings + triage** | `releases/ipam-3.27.6/regression-evidence/passC/PASS-C-SUMMARY.md` |
| Code-quality refactor stream (87 findings) | `code_quality_review.md` |
| UX overhaul stream (82 findings) | `ux_overhaul.md` |
| Backup overhaul (mostly shipped) | `backup_overhaul.md` |
| v4.x stream rationale + sequencing | `v4-release-stream.md` |
| i18n design (v4.0/v4.1/v4.6/v4.11) | `i18n-design.md` |
| Multi-tenancy design (deferred) | `v4-tenancy-design.md` |
| Pre-ticket cleanup backlog | `cleanup.md` |
| Hotfix branch model | `hotfix-release.md` |
| Regular release procedure | `release-workflow.md` |
| Marketing-site update procedure | `marketing-site.md` |
| Wide-regression catalog (v3.27.2) | `2026-05-09_v3.27.2-wide-regression.md` |

**This doc is the entry point. Drill into the source doc when you need detail. Update both when a stream changes shape.**
