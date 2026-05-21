# Roadmap — Simple-PHP-IPAM

> **What this is.** The single consolidated view of *what's planned, in what order, and why* for every release after the current shipped version. Synthesizes `archive/code_quality_review.md`, `archive/ux_overhaul.md`, `archive/backup_overhaul.md`, `v4-release-stream.md`, `i18n-design.md`, `archive/2026-05-08_Path_Forward.md`, `cleanup.md`, `lessons-learned.md`, `archive/test-improvements.md`, the Pass C findings, and the 2026-05-10 security review into one place — plus the live state of GitHub milestones.
>
> **What this isn't.** Not a commitment to dates. Not a substitute for the source docs (each section here links back). Not a multi-tenancy plan — that's deferred (see §8.4 and `v4-tenancy-design.md`).
>
> **How to use it.** This doc is the authoritative *suggested ordering* reference — GitHub milestones are now theme-named, not version-named, from v3.30.0 onward (the **2026-05-11 reshape**). When deciding what ships next, this doc maps theme → suggested version slot. Drill into the source doc when you need per-finding detail. Update this doc whenever a stream gets re-chunked, a milestone is renamed, or scope flips.
>
> **Current shipped: v3.33.0** (released 2026-05-19 — Refactor Wave 2, closes milestone #57). Releases since the last roadmap refresh: v3.27.9 hotfix, v3.28.0 DR + security stabilization, v3.28.1 overflow, v3.28.2 install-key lifecycle, v3.28.3 hotfix, v3.29.0 test infrastructure (#80), v3.30.0 Refactor wave 1 + ADR-004 lib.php decomposition (#56), v3.31.0 settings encrypt-at-rest / ADR-001 part 2, v3.32.0 code-quality + hardening (#84), v3.33.0 Refactor wave 2 (#57). **Pass C complete** (2026-05-10). **Security review complete** (2026-05-10). **Roadmap reshape: 2026-05-11.** **ADR-007 (Open Props adoption) drafted: 2026-05-20.** Last refreshed: 2026-05-20.

---

## 1. TL;DR — current state

**The 2026-05-11 reshape (this doc):** the previous roadmap front-loaded everything pre-v4.0.0 (3 refactor + 5 UX minors stacked before v4.0.0). With "land before v4.0.0" lifted by Sean's call:
- **v3.28.0 identity flipped** from "pure test tooling" → **DR + security stabilization** (consolidates old §3.7 v3.27.8 architectural fix + old §4.5 step-up bundle + old §4.6 legacy writer retirement + 7 new security-review findings + 8 Pass C findings).
- **Test infrastructure moved to v3.29.0** (what was old v3.28.0 content — 28 issues re-milestoned).
- **UX overhaul straddles v4.0.0** — foundation/nav/data-heavy before, polish/cleanup after, schedule by readiness.
- **Milestones #56–#63 renamed theme-only** (no version numbers) so reshuffling later is cheap. This doc holds the suggested ordering.

| Stream | Suggested slot | Milestone | Status |
|---|---|---|---|
| **v3.27.x quick-win patches** | shipped | — | ✅ closed (v3.27.4–v3.27.9) |
| **Pass C regression sweep** | one-time | — | ✅ complete (2026-05-10), 15 findings triaged |
| **Security review (semgrep + code-reviewer)** | one-time | — | ✅ complete (2026-05-10), 38 semgrep WARNINGs + 12 code-reviewer findings (0C/0H/4M/4L/4N) |
| **v3.27.8 — backup/restore bug hotfix** | shipped | — | ✅ shipped 2026-05-11 |
| **v3.28.0 — DR + security stabilization** | shipped | #55 | ✅ shipped 2026-05-13 |
| **v3.28.1 — DR + security overflow** | shipped | #81 | ✅ shipped 2026-05-14 |
| **v3.28.2 / v3.28.3 — install-key lifecycle + hotfix** | shipped | — | ✅ shipped 2026-05-14 |
| **Architecture-decision sprint** (ADR-001…006) | between v3.28.0 ship and v3.29.0 kickoff | — | ✅ complete — 5 of 6 accepted + implemented in v3.30.0; ADR-005 (`backup.php` separation) decided 2026-05-15, **implementation deferred** |
| **v3.29.0 — Test infrastructure** | shipped | #80 | ✅ shipped 2026-05-14 |
| **v3.30.0 — Refactor wave 1 (lib.php decomposition + ADR-001/-002/-003/-004/-006)** | shipped | #56 | ✅ shipped 2026-05-17 |
| **v3.31.0 — Settings encrypt-at-rest (ADR-001 part 2) + webhook crypto consolidation** | shipped | — | ✅ shipped 2026-05-18 |
| **v3.32.0 — Code-quality + hardening + deps refresh** | shipped | #84 | ✅ shipped 2026-05-18 |
| **v3.33.0 — Refactor wave 2 (api.php + import_csv + migrations)** | shipped | #57 | ✅ shipped 2026-05-19 |
| **Refactor wave 3 — frontend modularization** | **next refactor slot** | #58 (theme-named, 17 open) | scoped (§6) |
| **UX overhaul — straddles v4.0.0** | next minor onward (pre-v4) and post-v4.0.0 | #59 / #60 / #61 / #62 / #63 | 95 open across 5 milestones (§7); **+ ADR-007 Open Props adoption arc straddles v3.35.0 + v3.36.0 (#1255–#1259)** — see §7.1 |
| **v4.0.0 — i18n phase 1 + backup cold break** | when ready (decoupled from UX/refactor finish) | v4.0.0 (#19, 5 open) | scoped (§8 + §8.7) |
| **v4.x enterprise auth + i18n** | post-v4.0.0 | v4.1.0–v4.11.0 | placeholdered (§8) |
| **Multi-tenancy** | indefinite | Multi-tenancy (deferred) (#64) | DEFERRED |

**Shape of the next year (without dates):**

```
v3.27.7…v3.27.9 (shipped) ── v3.28.0–3 (DR + security stabilization + hotfixes, shipped)
                                  │
                                  └── v3.29.0 (test infrastructure, shipped 2026-05-14)
                                          │
                                          └── v3.30.0 (Refactor wave 1 — lib.php decomposition + ADRs, shipped 2026-05-17)
                                                  │
                                                  └── v3.31.0 (Settings encrypt-at-rest + webhook crypto consolidation, shipped 2026-05-18)
                                                          │
                                                          └── v3.32.0 (Code-quality + hardening + deps refresh, shipped 2026-05-18)
                                                                  │
                                                                  └── v3.33.0 (Refactor wave 2 — api.php + import_csv + migrations, shipped 2026-05-19)
                                                                          │   ◀── YOU ARE HERE
                                                                          │
                                                                          └── v3.34.0+ (Refactor wave 3 — frontend modularization, #58)
                                                                                  │
                                                                                  ├── UX foundation + nav + data-heavy (#59 / #60 / #61, interleave pre-v4)
                                                                                  │
                                                                                  └── v4.0.0 (i18n phase 1 + BACKUP COLD BREAK, #19)
                                                                                          │
                                                                                          ├── v4.1.0 (i18n extraction)
                                                                                          ├── v4.2.0 (OIDC engine swap)
                                                                                          └── UX polish + UX cleanup interleaved with v4.3+ (RBAC / SAML / LDAP / OAuth / SCIM / fr-CA)
```

---

## 2. Operating mode (binding)

The architectural decisions that produced the v3.21–v3.27 bug cluster were made unilaterally by Claude. **That stops.** Codified in `archive/2026-05-08_Path_Forward.md` §2 and reinforced in `lessons-learned.md` §8.

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

**Cluster status (2026-05-20):** v3.27.4 → v3.27.9 all shipped. v3.27.8 backup/restore hotfix shipped 2026-05-11; v3.27.9 shipped 2026-05-11 (CR fixes).

### v3.27.4 — Settings UX polish patch (shipped 2026-05-10)
Bundled the settings-UX P2s + wheel-scroll regression + lockout banner.

### v3.27.5 — Password-manager autocomplete attributes (shipped 2026-05-10)
Same-day hotfix off v3.27.4. Added `data-1p-ignore` / `data-lpignore` / `data-bwignore` to OIDC and SMTP password fields.

### v3.27.6 — Restore-page redesign + #1146 XHR sudo replay regression (shipped 2026-05-10)
Restore page architecture redesign + apply-spinner overlay (#1135) + `step_up.php` replay marker fix.

### v3.27.7 — Pass C F-S3-01 webhook crypto + CSP inline-handler fix (shipped 2026-05-10)
Webhook signing secret encrypted at rest (`$2W$` AES-GCM envelope, mirrors v3.6.0 TOTP). 10 inline event handlers converted to `data-*` delegated handlers. 14/14 CI green, 13/13 CR threads resolved across 4 review rounds. Deployed to all 7 targets (tag `v3.27.7`, merge `576660a`, bundle SHA `92f01cbe…`).

### v3.27.8 — Backup/restore bug cluster (shipped 2026-05-11)

**Theme:** "v3.27.7 deploy revealed backup architecture is in worse shape than v3.27.x patches suggested. Stabilize disaster recovery before any further v3.28+ work."

Origin: post-v3.27.7-deploy CDP session with Sean against the SQLite test instance surfaced four distinct bugs + a silent-failure pattern that needs to land in a focused hotfix.

**Scope (locked):**

| # | Bug | Root cause | Fix |
|---|---|---|---|
| A | Restore tab → Verify/Delete → unstyled `backup_run_detail.php` page | Drawer-partial endpoint loaded as full page. History tab uses `data-drawer-url`; Restore tab uses `<a href>`. | Convert `views/backup_admin_restore.php:125` from `<a href>` to drawer-pattern button matching History tab line 227. |
| B | Restore-tab "Encryption" badge mislabels in both directions | `lib/backup_admin_restore.php` controller sets `is_encrypted` from filename suffix only, ignoring DB ground truth. | Join `backup_runs.encryption_mode` + `backup_runs.backup_type`; fall back to filename heuristic only for orphan files. |
| C | Restore-tab "Type" column also filename-suffix-based | Same controller. | Same fix as B. |
| D | Backup wrote artifact to S3 but no `backup_runs` row created | S3 bucket had 9 files; `backup_runs` had 11 rows for that destination (2 correctly retention-pruned, **1 file with no DB row**). Orchestrator wrote file but INSERT either failed silently or never ran. | Investigation required: error_log scan, audit_log check, instrumentation of post-upload DB write. Fix in v3.27.8 only if root cause is small; otherwise carve into v3.28.0 with a flag. |
| E | Destinations tab "Stored key" badge contradicts "No vault key configured" card | `ipam_setting('backup_vault_key')` returns a 104-byte `IPAMWK1.…` envelope; `ipam_vault_unwrap()` throws because bootstrap key rotated. `$vaultStatus['present']` falls through to false despite envelope existing. | Detect unwrap failure explicitly: "Vault envelope exists but unreadable — bootstrap key has changed." |

**Plus the architectural fix in v3.27.8:**

- **Drop the silent plaintext fallback.** When a destination is configured for `encryption_mode='stored'` and the vault key is missing or unreadable, the orchestrator currently silently writes plaintext to a `.enc`-suffixed file. v3.27.8 makes this a hard preflight failure with `backup.preflight_failed` audit + visible `backup_runs.status='failed'` row.

**Note:** the *formal* legacy writer retirement (deprecate `app_secret` mode entirely, add banners, write `docs/upgrading.md` §4.0) moved to v3.28.0 (§4) per the 2026-05-11 reshape. v3.27.8 just stops the silent regression to plaintext for current `stored`-mode destinations.

**Status:** ✅ **shipped 2026-05-11** (merge `311f553b` via PR #1173). Predecessor to v3.28.0's bigger DR+security work, which also shipped (2026-05-13).

---

## 3.5 Pass C — completed 2026-05-10

**What:** Path-Forward §step 4 manual regression sweep across the v3.21–v3.27 surfaces Pass A/B did NOT exercise.

**Result:** 15 findings across 8 surfaces.

| Severity | Count | Headlines |
|---|---|---|
| High | 1 | F-S3-01 — webhook signing secrets stored plaintext at rest (shipped in v3.27.7) |
| Important | 5 | Scanner observability + contract drift — F-S2-01 / F-S2-02 / F-S2-03 / F-S2-04 / F-S2-05 |
| Medium | 5 | Step-up coverage bundle (F-S3-02 / F-S5-01 / F-S7-01); F-S5-02 atomic JSON state; F-S6-01 Kea/dhcpd FQDN parity |
| Low / Note / Forward-looking | 4 | F-S6-03 PD pruning, F-S5-03 test-alert path, N-S7-02 per-field audit diff, F-S6-02 VRF filter |

**Two surfaces fully clean:** Surface 1 (api.php — sudo not applicable to stateless API keys) and Surface 4 (tag CASCADE — empirically verified).

**Slotting:** F-S3-01 shipped in v3.27.7. F-S3-02 / F-S5-01 / F-S7-01 / F-S5-02 / F-S2-01 / F-S2-04 / F-S2-05 / F-S6-01 → **v3.28.0** as new GH issues (#1156–#1163 filed 2026-05-11). F-S2-02 / F-S2-03 (audit-vocab doc drift) → v3.29.0 as part of contract-doc linter work.

**Full evidence:** `releases/ipam-3.27.6/regression-evidence/passC/`.

---

## 3.6 Security review — completed 2026-05-10

**What:** Semgrep MCP scan + `pr-review-toolkit:code-reviewer` agent against v3.27.7 codebase.

**Semgrep:** 38 WARNINGs across 3 rule families (`taint-unsafe-echo-tag` 26, `tainted-filename` 6, `unlink-use` 6), concentrated in legacy CSV/search/import code. Triage: scheduled for refactor wave 2 (#57 — `api.php + import_csv + migrations`) where the underlying code is being rewritten anyway. Direct GH-issue filing skipped to avoid duplicate work — these surface as part of the refactor scope.

**Code-reviewer:** 12 findings, **0 Critical / 0 High / 4 Medium / 4 Low / 4 Note**. v3.27.7 work specifically (webhook crypto, vault envelope, restore-token HKDF, SSRF guard) reviewed cleanly — no Pass-A-class footguns introduced.

**Slotting (filed as new GH issues 2026-05-11):**

| ID | Severity | Slot | Issue |
|---|---|---|---|
| S-003 (gzip-bomb restore DoS) | Medium | v3.28.0 | #1149 |
| S-006 (`ipam_webhook_dispatch` silent swallow) | Medium | v3.28.0 | #1150 |
| S-008 (readonly API skips `is_active`) | Medium | v3.28.0 | #1151 |
| S-001 (test-fire audit details) | Low | v3.28.0 | #1152 |
| S-002 (`audit_log` LIKE pattern) | Low | v3.28.0 | #1153 |
| S-004 (`move_uploaded_file` mode) | Low | v3.28.0 | #1154 |
| S-007 (retry-path verification test) | Low | v3.28.0 | #1155 |
| S-005 / S-009 / S-010 / S-011 / S-012 | Note | Not filed; absorbed into v3.29.0 test-infra (S-012 decrypt-tool argv) and v4.2 OIDC engine swap (S-009 raw exception) per source doc recommendations |

**Full evidence:** `releases/2026-05-10_security-review/` (`semgrep-summary.md`, `semgrep-raw.json`, `code-reviewer-findings.md`).

---

## 4. v3.28.0 — DR + security stabilization (#55) — ✅ SHIPPED 2026-05-13

> **Identity flip (2026-05-11 reshape):** old v3.28.0 was "pure test tooling." Test tooling moved to v3.29.0 (§5).
>
> **Carve (2026-05-11, after gap-analysis review):** 8 low-friction items split out to a new v3.28.1 milestone (#81) so v3.28.0 stays shippable. See §4.1.
>
> **Shipped status:** v3.28.0 closed milestone #55. v3.28.1 (overflow, #81) shipped 2026-05-14. v3.28.2 added an install-key lifecycle fix; v3.28.3 hotfix (anchor + webhook cap + CHANGELOG link) shipped 2026-05-14. Scope below preserved for historical reference.

**Theme:** "Finish the cluster. Stop silent failures. Ship the security mediums. Retire the legacy backup writer."

**Scope (locked, 12 issues):**

| Bucket | Items | Issues |
|---|---|---|
| **Legacy backup writer retirement** | Disable orchestrator's `app_secret` write path; preflight failure with clear message; reader stays intact; banner on Backup/Restore tabs; `docs/upgrading.md` §4.0 migration path; decrypt-tool prominent placement; CHANGELOG explicit Deprecated + Removed entries. **Hard gate: cannot merge until #1165 decrypt-tool Pass 1 is 100% green.** | #1164 (epic) |
| **Decrypt-tool Pass 1 manual execution** | 7 fixtures × 8 cases + 8 cross-cutting = 64 datapoints per `archive/decrypt-tool-test-plan.md`. Output: `releases/2026-05-11_decrypt-tool-pass1/results.md`. Merge gate on #1164. | #1165 |
| **Pass C step-up coverage sweep** | Sudo-gate webhook + notify_recipients/SMTP + custom field def CRUD (one policy-registry expansion for all 3) | #1156, #1157, #1158 |
| **Atomic-state JSON pairing** | F-S5-02 destination_health/schedule_overdue_state + #1143 auth.ip_rate_limited dampener (same TOCTOU shape, land together) | #1159, #1143 |
| **Security-review Mediums** | S-003 gzip-bomb decompressed cap; S-006 webhook silent swallow → `error_log`; S-008 readonly API `is_active` check | #1149, #1150, #1151 |
| **CSP regression guard** | CI assertion that no inline `on*` handlers slip back in | #906 |
| **OIDC autocomplete hints** | v3.27.5 follow-up architectural fix | #1137 |

**Pre-PR checklist (per Operating Mode §2)** applies in full. Every helper has ≥1 caller, every contract has ≥1 round-trip test, every sentinel has a positive-shape test.

**Out of scope:** test-infrastructure deliverables (round-trip taxonomy, contract-doc linter, fixture-bypass sweep) — those live in v3.29.0. Low-friction Pass C / security-review items — see v3.28.1 below.

### 4.1 v3.28.1 — DR + security overflow (#81) — ✅ SHIPPED 2026-05-14

**Theme:** "The low-friction tail of Pass C + security-review Lows that didn't need to gate v3.28.0."

| Bucket | Items | Issues |
|---|---|---|
| **Pass C scanner observability** | F-S2-01 IPv6 sync-scan rejection; F-S2-04 cron `scan.run` audit row; F-S2-05 `ipam_mark_stale_addresses` clamp; F-S6-01 Kea/dhcpd FQDN regex parity | #1160, #1161, #1162, #1163 |
| **Security-review Lows** | S-001 audit-details hygiene (test-fire URL); S-002 audit LIKE pattern; S-004 backup file-mode; S-007 retry-path verification test | #1152, #1153, #1154, #1155 |

**Effort estimate:** ~1–2d total. Ships right after v3.28.0 — same theme, smaller surface, no architectural changes.

**Post-v3.28.0 hotfix budget:** the 1–2 week window after v3.28.0 deploy is reserved for incident response on the legacy-writer-retirement migration. v3.28.1 ships only after that window closes clean.

---

## 5. v3.29.0 — Test infrastructure (#80) — ✅ SHIPPED 2026-05-14

> **Slot-bumped (2026-05-11 reshape):** what was old v3.28.0 milestone #55 content. 28 issues re-milestoned to new milestone #80. Milestone #80 closed on release.

> **Operational plan: `docs/internal/archive/test-improvements.md`.**

**No new features in v3.29.0.** This is the explicit response to the v3.21–v3.27 bug cluster: passing CI + passing CodeRabbit + passing 3-driver Playwright did not catch the 12 distinct bugs Pass A surfaced.

**Three deliverables (Path Forward §1.5):**

1. **Inventory of test fixtures that bypass the path under test**, with parallel-spec backfills. `warmSudoGrant()` is the worked example.
2. **Three missing test classes wired into CI:** round-trip tests (encrypt → restore → byte-compare); contract enforcement tests (every helper has ≥1 caller; every audit verb has ≥1 `audit()` call); negative regression tests (CR/security-tightened guards get paired tests pinning valid use cases).
3. **Contract-doc-vs-code linter** — CI script that grep-asserts contract docs match code. Catches drift like Bug T (6 of 11 documented invalidation events had no caller).

**Plus (added 2026-05-11 gap-analysis pass):**
- **Decrypt-tool Pass 2 automation** — green-path + wrong-cred + tampered + truncated cases per `archive/decrypt-tool-test-plan.md` become PHPUnit; remaining cases become a CI shell script. Pass 1 manual execution (#1165) gates this work.
- **S-012 decrypt-tool argv passphrase hardening** (env-var only, refuse argv).
- **F-S2-02 / F-S2-03 audit-vocab doc drift** — fixed as part of contract-doc linter coming online green on day one.
- **Semgrep WARNING sweep (#1166)** — close 38 legacy XSS/SSRF/path-traversal WARNINGs (~1d work, mostly `e()` wraps + one realpath guard). Establishes "main has zero semgrep WARNINGs" CI baseline.
- **Composer dep refresh + CVE recheck (#1167)** — bump pinned versions of phpmailer / twofactorauth / webauthn / phpseclib; run `composer audit`. Cadence question (every 3 minors?) decided at scope-lock.

**Open questions in `archive/test-improvements.md` §8** to be answered at scope-lock.

---

## 6. Refactor stream (v3.30.0 → v3.34.0+)

Originally three refactor waves; ADR-001 (settings type system) added a fourth slot for encrypt-at-rest. Closes 50 of the 87 `archive/code_quality_review.md` findings (the other 37 already shipped through v3.26.0 + are absorbed into v3.29.0 test backfill).

**Actual slot mapping (waves slid by one minor against the original 2026-05-11 plan because v3.32.0 became a code-quality/hardening release):**

| Slot | Milestone | Theme | Status |
|---|---|---|---|
| v3.30.0 | **Refactor wave 1 — lib.php decomposition** (#56) | `lib.php` (~12.5k lines) decomposed into 12 focused `lib/*.php` modules; settings type registry moves from DB column to PHP (ADR-001 Option D); per-user theme migration; ADR-002/-003/-004/-006 implemented | ✅ shipped 2026-05-17 |
| v3.31.0 | **Settings encrypt-at-rest + webhook crypto consolidation** (ADR-001 part 2) | Four sensitive `settings` rows now encrypted at rest via shared crypto pipeline; legacy webhook-secret encryption consolidated onto same pipeline; two idempotent re-encryption migrations; two more `lib/*.php` extractions finish wave 1 | ✅ shipped 2026-05-18 |
| v3.32.0 | **Code-quality + hardening + dependency-maintenance** (#84) | Site-hierarchy server-side guards (previously UI-only); dependency upgrades; bug fixes. No schema migrations. *Note: replaced the original wave-2 slot — wave 2 slid to v3.33.0.* | ✅ shipped 2026-05-18 |
| v3.33.0 | **Refactor wave 2 — api.php + import_csv + migrations** (#57) | `api.php` decomposition (`api_paginated_query()`, `api_bulk_create()`); `import_csv.php` state machine extracted to `lib/csv_import.php`; migration helpers; ADR-003 `global $config` sweep completed (#1207); 22 issues closed | ✅ shipped 2026-05-19 |
| v3.34.0 | **Refactor wave 3 — frontend modularization** (#58) | `assets/app.js` (3,439 lines) split into 46 per-concern `assets/modules/*.js` modules loaded as ordered `<script defer>` tags; test umbrella `#1047` lands the module-load-order + `window.*` contract + `prefers-reduced-motion` smoke; P2/P3 polish | **in progress** |

**Closes findings (cumulative across waves):** A6, A11–A20, A23–A27, A29, A31–A33, A.P3, B5–B11, B13, B.P3, C2, C3-policy, C5–C12, C.P3, D4-full, D5, D13–D21.

**Per-milestone test umbrellas:** #1045 (wave 1 + ADR-001 work, v3.30.0 + v3.31.0), #1046 (wave 2, v3.33.0), #1047 (wave 3, v3.34.0+). Each refactor PR must update or add the matching test in the same commit.

**Architecture-review dependency (§10):** ADR-001 → ADR-004 + ADR-006 accepted and implemented in v3.30.0. ADR-005 (`backup.php` separation) decided 2026-05-15; implementation deferred to a later release.

---

## 7. UX overhaul stream — straddles v4.0.0

> **2026-05-11 reshape:** the 5 UX milestones no longer have to fit before v4.0.0. Foundation + nav + data-heavy ship pre-v4 (now suggested v3.35.0 → v3.37.0, slid one minor from the original plan because wave 2 took v3.33.0); polish + cleanup ship post-v4 interleaved with v4.x enterprise auth + i18n releases. **v4.0.0 ships when i18n phase 1 + backup cold break are ready — not gated on UX completion.**

Audit scope **explicitly excludes** backup/restore UX — that's `archive/backup_overhaul.md` territory.

| Suggested slot | Milestone | Theme | Issues | Effort |
|---|---|---|---|---|
| v3.35.0 (pre-v4, suggested) | **UX foundation — design system** (#59) | Type scale, badge primitive, color-not-only-signal, dark-mode brand-link, mobile font sizing **+ ADR-007 phase 1: Open Props adoption (vendor + sizing/color migration; aliases kept for compat)** | 15 (12 original + #1255 #1256 #1257) | ~6–8d (was ~3–5d) |
| v3.36.0 (pre-v4, suggested) | **UX nav — sidebar + command palette + theme** (#60) | Group 22 admin links into 5 sections, command palette, theme toggle, sidebar collapse, post-timeout deep-link return **+ ADR-007 phase 2: OP shadows/easings/animations + hand-rolled token cleanup + closes #941** | 14 (11 original + #1258 #1259 + reframed #941) | ~5–7d (was ~3–5d) |
| v3.37.0 (pre-v4, suggested) | **UX data-heavy — subnets/addresses** (#61) | Pagination/virtualization (subnets renders 3,727 elements today), drawer-edit, action consolidation, status-as-badge, URL-state filters, bulk-action bar, columns persistence | 14 | ~10–14d |
| post-v4 interleave | **UX polish — search + audit + dashboard** (#62) | Instant search, pretty-printed audit, relative time, KPI clickable, recent-activity rendering | 20 | ~6–8d |
| post-v4 interleave | **UX cleanup — admin + a11y + mobile + auth** (#63) | Drawer-CRUD across 12 admin pages, empty states, settings save UX, import-CSV stepper, mobile responsive sweep, a11y sweep, login UX | 38 | ~10–14d |

**Why this split:**
- Foundation + nav + data-heavy are the highest-impact UX wins; ship before v4.0.0 so the i18n extraction sweep in v4.1.0 runs against the new UI shape rather than the legacy one.
- Polish + cleanup are lower per-issue impact but larger surface area; OK to interleave with enterprise-auth releases.

**Per-milestone test umbrellas:** #1035, #1036, #1037, #1038, #1039.

**Source:** `archive/ux_overhaul.md` §9 + `architecture-decisions/007-open-props-adoption.md`.

### 7.1 ADR-007 Open Props adoption arc (straddles v3.35.0 + v3.36.0)

Captured 2026-05-20; **corrected 2026-05-20 (later same day)** — see ADR-007 amendment header. The "vendor + load" step is already done (the OP vendor file has been in the tree since `e433f134 feat(#506)` and `lib/presentation.php:386` already loads it). The remaining four issues are real and scoped correctly.

| Slot | Phase | Issues | Effort |
|---|---|---|---|
| **v3.35.0** (#59) | **Phase 1 — Foundation:** migrate spacing/radii/font-size + neutral colors. Hand-rolled tokens kept as aliases for one-release compat. (#1255 vendor + load is **already done** — pre-v3.34.0.) | #1256 (sizing migration), #1257 (color migration) | ~3d |
| **v3.36.0** (#60) | **Phase 2 — Polish:** adopt OP shadows + easings + animation primitives. Delete alias block. Audit `!important`. Close #941. | #1258 (secondary tokens), #1259 (cleanup), reframed #941 (closes via #1259) | ~1.5d |

**Why this slotting:**
- #59 UX foundation is "type scale, badge primitive, color-not-only-signal, dark-mode brand-link, mobile font sizing" — every item wants a coherent token vocabulary. OP migration during #59 means the token rename does real visual work (paired with the design items), not standalone churn.
- #60 UX nav includes the theme toggle, which pairs naturally with OP's dark/light pattern + `prefers-reduced-motion` story.
- Splitting across two releases keeps each PR review-sized and avoids cramming a design-system flip into a single release.

**Fold-in evaluation:** the existing #59 scope items (badge primitive, color-not-only-signal, dark-mode brand-link) already pair perfectly — they consume OP tokens directly. The dark-mode override consolidation across the 4 scattered `html[data-theme=dark]` blocks in `app.css` folds into ADR-007-3 (#1257) since that issue touches every color token anyway. The `prefers-reduced-motion` `!important` audit folds into ADR-007-4 (#1258).

**Backward-compat note:** v3.35.0 keeps the hand-rolled `--space-*` / `--radius-*` / `--font-size-*` block as aliases pointing at OP values, so installs with custom CSS referencing old token names continue to work. Aliases drop in v3.36.0 — CHANGELOG entry required.

---

## 8. v4.x stream — enterprise auth + global reach

Forward-looking design. Captured in `v4-release-stream.md`. **Multi-tenancy is explicitly NOT in v4.x** — see §8.4.

### 8.1 Theme
Enterprise authentication (SAML / LDAP / OAuth / SCIM) + global reach (i18n / l10n) + RBAC / groups / per-subnet ACL. 11 placeholder milestones.

### 8.2 Sequencing (locked 2026-05-07; **v4.0.0 wall removed 2026-05-11**)

| Milestone | Theme | Tracking issue |
|---|---|---|
| **v4.0.0** (#19) | i18n infrastructure (phase 1) + **backup cold break** (§8.7) | #1064 |
| **v4.1.0** (#29) | i18n extraction sweep (phase 2) | #1063 |
| **v4.2.0** (#65) | OIDC engine swap — retire hand-rolled JWT/JWK, adopt firebase/php-jwt ^6.0 **+ S-009 raw-exception fix + #1137 OIDC settings page rewire** | #417 |
| **v4.3.0** (#66) | RBAC foundation: `groups` + `user_groups` join tables | #334 |
| **v4.4.0** (#67) | Editable RBAC engine — replace hard-coded `admin`/`readonly` with permission-target gates | #456 |
| **v4.5.0** (#68) | Per-subnet ACLs — resource-level ACLs complementing v4.4 RBAC | #333 |
| **v4.6.0** (#69) | i18n phase 3: first non-English catalog (fr-CA) | #1066 |
| **v4.7.0** (#70) | SAML 2.0 SSO (SP role) via onelogin/php-saml ^4.0 | #1065 |
| **v4.8.0** (#71) | LDAP / Active Directory via symfony/ldap ^7.0 wrapping ext-ldap | #1069 |
| **v4.9.0** (#72) | Generic OAuth 2.0 via league/oauth2-client ^2.7 | #1070 |
| **v4.10.0** (#73) | SCIM 2.0 provisioning — Okta/Azure AD lifecycle | #1068 |
| **v4.11.0** (#74) | i18n phase 4: crowdsourcing via Weblate (drop by default unless community translation interest emerges) | #1071 |

### 8.3 Hard sequencing constraints
- **v4.1 must follow v4.0** (extraction needs the helpers).
- **v4.4 must follow v4.3** (RBAC engine needs the groups foundation).
- **v4.5 must follow v4.4** (per-subnet ACLs need RBAC).
- **v4.7 should follow v4.2** (SAML reuses JWT primitives).
- **v4.6/v4.11 follow v4.0+v4.1** (translation needs catalog infrastructure).

**Soft constraint:** interleave i18n with auth releases so visible internationalization progress accompanies enterprise-feature ramp. Also interleave UX polish/cleanup (§7) into the v4.x slots — both add visible operator value.

### 8.4 What's NOT in v4.x

| Item | Why not | Where it lives |
|---|---|---|
| **Multi-tenancy** | DEFERRED INDEFINITELY (locked 2026-05-08). Sean investigating licensing implications. | `v4-tenancy-design.md`, milestone #64 |
| **Plugin / extension framework** | Out of scope; no premature abstractions | — |
| **WebUI for backup keys** | Already shipped in v3.26.0 | — |

### 8.5 Architecture-with-multi-tenancy-in-view rule
Per Path Forward §4. Every v3.28.0+ design decision must keep an eventual multi-tenancy view: new data tables consider `tenant_id` from the start; settings reads go through `ipam_setting()`; per-tenant key derivation (HKDF from `app_secret`) remains the model.

### 8.6 Sustainability checkpoint
Per `v4-release-stream.md` §10: stop or shorten the v4.x stream if no enterprise customer requests SAML/LDAP/SCIM by v4.5/v4.6, OR if maintainer burnout signals. Drop v4.11 (Weblate) by default unless community translation interest materializes.

### 8.7 Backup architecture cold break (v4.0.0)

**Theme:** "Eliminate the backup-system tech debt that's been weighing the project down across v3.21–v3.27."

Bundled into v4.0.0's i18n release because major-version is the right vehicle for breaking changes. The two scopes are independent (one touches backup code, one touches rendering layer).

**Scope:**

| What goes away | What stays / takes over |
|---|---|
| IPAMBKP1 + IPAMBKP2 codecs (writer + reader) | IPAMBKP3 (writer + reader) |
| SQLite-binary backup type (writer + reader) | IPAMBKL1 logical backup (writer + reader) |
| `.sql.gz` bare reader path | IPAMBKL1 inside IPAMBKP3 envelope, or unencrypted IPAMBKL1 |
| `encryption_mode='app_secret'` | `unencrypted` / `stored` only |
| `backup_type='database'` | `backup_type='logical'` only |
| In-app restore for legacy `.enc` files | `tools/decrypt-backup.php` standalone tool — Pass 1+2 verified |

**Upgrade gate (`upgrade.sh`):**

```sql
SELECT count(*) FROM backup_runs
 WHERE encryption_mode != 'unencrypted'
   AND filename NOT LIKE '%.ipambkp3'
   AND status IN ('success', 'retention_pruned');
```

- If `> 0`: abort upgrade with 3-option message (restore on v3.x first / decrypt offline with the tool / acknowledge data loss with `--accept-legacy-backup-loss`).
- The flag writes a `settings.legacy_backup_loss_acknowledged_at` marker + an audit row.
- Block based on `backup_runs` rows, not destination listObjects. Faster, offline-safe.

**Migration documentation (must land in v3.28.0):**
- `docs/upgrading.md` §4.0 — explicit two-step migration: install v3.x, restore needed backups, upgrade
- Walkthrough for the standalone decrypt tool on the marketing-site docs page
- `tools/decrypt-backup.php` gets prominent placement in v4.0.0 release tarball + release notes

**Testing prerequisite:** `docs/internal/archive/decrypt-tool-test-plan.md` Pass 1 (manual, 7 fixtures × 8 cases + 8 cross-cutting) must be 100% green before the v4.0.0 writer-retirement PR opens. Pass 2 (PHPUnit + CI shell) ships in v3.29.0.

---

## 9. Backup overhaul — closing out

`archive/backup_overhaul.md` was the design-and-tracking doc for the multi-release backup epic. Most work shipped across v3.21–v3.27.

| Bucket | Status |
|---|---|
| §6 Functionality (F1–F25) | F18, F19, F22 still open (F22 is v4.0.0 tenant policy — parked under multi-tenancy deferral); rest shipped. |
| §7 UI (U1–U10) | All shipped or absorbed into UX overhaul stream. |
| §8 Testing (T1–T10) | Cross-engine round-trip + MinIO/LocalStack + SFTP integration tests are the remaining gap; **covered by v3.29.0** (was v3.28.0 before reshape). |
| §8a Documentation (D1–D12) | D7 (tenancy doc) parked under multi-tenancy deferral; D12 (retire `archive/backup_overhaul.md` itself) lands in v4.0.0 release cleanup. |

**Single most expensive lesson from this stream:** `lessons-learned.md` §8 — "feature added at one site, propagation to adjacent sites missed." The mandatory pre-PR checklist in §2 is the institutional response.

---

## 10. Architecture review backlog

**Not "rewrite the app" — "look at each candidate honestly and decide."** Each candidate gets a separate decision document under `docs/internal/architecture-decisions/` with options + tradeoffs + recommendation + Sean's stamp. **No architectural decision in this list gets implemented unilaterally by Claude.**

| Candidate | Why it's on the list | Gate / Status |
|---|---|---|
| **`lib.php` size** (~9000 lines, all functions in one namespace) | Module separation overdue; refactor wave 1 (v3.30.0) is the first surface. | ~~Decision before refactor wave 1 starts.~~ ✅ ADR-004 accepted; **implemented in v3.30.0** (12 modules extracted, `lib.php` ~12.5k→~7.9k lines). |
| **Per-key vs group-form bifurcation in `settings.php`** | Bug V's root cause. | ~~Decision before refactor wave 1.~~ ✅ ADR-002 accepted (corrected + re-stamped); **implemented in v3.30.0**. |
| **`backup.php` orchestrator/codec/dispatcher separation** | Enabled the encrypt-write-path bug. | ADR-005 **decided 2026-05-15; implementation deferred to a later release.** |
| **`$config` global as the only config conduit** | Hides the dependency graph. | ~~Decision before refactor wave 2 (v3.31.0).~~ ✅ ADR-003 accepted; **implemented in v3.30.0** for extracted `lib/*.php` modules. Full-codebase sweep completed in **v3.33.0 (#1207 closed)** with ADR-004 linter widened to enforce repo-wide. |
| **Settings table type system** | bool/int/string/json is impoverished; sensitive flag bolted on. | ~~Decision before refactor wave 1.~~ ✅ ADR-001 accepted (**amended/reversed to Option D** — registry stays in PHP, no DB table); **implemented in v3.30.0**. |
| **Memory MCP discipline as the only cross-session continuity** | Useful but didn't prevent today's bugs (no observations linking v3.24 codec → v3.26 storage → v3.27 step-up). | ~~Decision in v3.28.0→v3.29.0 sprint.~~ ✅ ADR-006 accepted; **implemented in v3.30.0**. |
| **Contract-doc-as-source-of-truth model** | When `auth-model.md` "Step-up auth" section says X and code does Y, X "wins" by convention — drift produces bugs. v3.29.0 contract-doc linter is the first response. | Decided 2026-05-11: code-first, lint docs against code. |
| **CSS design-token base** | `app.css` hand-rolls its own `--space-*` / `--radius-*` / `--font-size-*` scales; `--font-size-*` and `--text-*` self-duplicate; #941 ("reconcile with Open Props") was filed under a wrong premise (OP not actually loaded). | **ADR-007 drafted 2026-05-20.** Recommends adopting Open Props as the token base, migrating over v3.35.0 (#59) + v3.36.0 (#60). 5 tracking issues opened (#1255–#1259). #941 reframed and re-milestoned to #60 (closes via #1259). |

### 10.1 Architecture-decision sprint (between v3.28.0 and v3.29.0)

**Locked 2026-05-11.** A focused window between v3.28.0 ship and v3.29.0 kickoff dedicated to clearing the gating decisions for refactor wave 1.

**Six decisions in order — all accepted:**

1. ✅ **ADR-001 — Settings type system** — small surface, informs the next two. Amended/reversed to **Option D**: registry stays in PHP, no DB table. Implemented v3.30.0.
2. ✅ **ADR-002 — Per-key vs group-form bifurcation in `settings.php`** — Bug V root cause. Corrected + re-stamped. Implemented v3.30.0.
3. ✅ **ADR-003 — `$config` global as the only config conduit** — pairs with #1 and #2. Implemented v3.30.0 for extracted modules; full-codebase sweep completed v3.33.0 (#1207 closed).
4. ✅ **ADR-004 — `lib.php` size + module shape** — the big one, refactor wave 1's blueprint. Implemented v3.30.0.
5. **ADR-005 — `backup.php` orchestrator/codec/dispatcher separation** — also gates v4.0.0 cold break. Decided 2026-05-15; **implementation deferred to a later release.**
6. ✅ **ADR-006 — Memory MCP discipline as the only cross-session continuity** — process decision, not code. Implemented v3.30.0.

**Output per decision:** one doc under `docs/internal/architecture-decisions/<NNN>-<slug>.md` with options + tradeoffs + recommendation + Sean's stamp + the resulting work (GH issues or scope changes).

**Cadence:** one session per decision. ~6 sessions before v3.29.0 work begins. Worth the queue time — refactor wave 1 without these decisions is the kind of unilateral architectural call that produced the v3.21–v3.27 bug cluster.

**Note on contract-doc-vs-code model (#7 in table above):** decided 2026-05-11 — code-first, lint docs against code (the v3.29.0 contract-doc linter is the enforcement). No standalone decision doc needed.

### 10.2 Post-sprint ADRs

ADRs added outside the original 6-decision sprint window. Each gets the same options-+-tradeoffs treatment under `docs/internal/architecture-decisions/`.

| ADR | Subject | Status | Slot |
|---|---|---|---|
| **ADR-007** | Adopt Open Props as the CSS design-token base | **draft 2026-05-20** | Implementation across v3.35.0 (#59) + v3.36.0 (#60). See §7.1. |

---

## 11. Pre-ticket cleanup backlog

`cleanup.md` is the canonical pre-ticket holding pattern for low-risk code-health items.

**Currently active:** Canadian English standardization across code/comments/UI/docs (deferred to a dedicated localization stream — possibly v4.6 alongside fr-CA catalog, or its own minor).

**Currently tracked in a GH issue:** #1062 (phpmd `unusedcode` cleanup, 5 items, slot TBD with refactor wave 3 or post-v4 UX cleanup).

---

## 12. Stream-juggling protocol

When a milestone gets reshuffled, **update this doc first** so the canonical view is correct, then update source doc(s) and GitHub.

**Theme-naming pattern (2026-05-11 reshape):** milestones from v3.30.0 onward have theme-only titles (no `vX.Y.Z`). The version slot for each theme lives in this doc's §6 and §7 tables. When ordering changes, update those tables — milestones stay put.

**Hotfix pattern** (`hotfix-release.md`): branch off `main`, merge back, then `dev ← main`. Hotfix scope must NOT pull in dev-stream work. v3.27.7 is the most recent worked example.

**Deferral pattern** (multi-tenancy 2026-05-08): when an entire stream is parked, retitle the milestone, keep the issues, cross-reference from this doc + the source design doc.

---

## 13. Cross-references

This doc is a pointer index. Source docs hold per-finding detail and the binding decisions:

| Concern | Source |
|---|---|
| Operating mode + Path Forward commitments | `archive/2026-05-08_Path_Forward.md` |
| Cross-release lessons (curated) | `lessons-learned.md` |
| **v3.29.0 operational plan (test tooling)** | `archive/test-improvements.md` |
| **Pass C findings + triage** | `releases/ipam-3.27.6/regression-evidence/passC/PASS-C-SUMMARY.md` |
| **Security review (2026-05-10) outputs** | `releases/2026-05-10_security-review/semgrep-summary.md` + `code-reviewer-findings.md` |
| **Decrypt-tool test plan (Pass 1 / Pass 2)** | `archive/decrypt-tool-test-plan.md` |
| **Session plan continuity** | `2026-05-11_session-plan.md` |
| Code-quality refactor stream (87 findings) | `archive/code_quality_review.md` |
| UX overhaul stream (82 findings) | `archive/ux_overhaul.md` |
| Backup overhaul (mostly shipped) | `archive/backup_overhaul.md` |
| v4.x stream rationale + sequencing | `v4-release-stream.md` |
| i18n design (v4.0/v4.1/v4.6/v4.11) | `i18n-design.md` |
| Multi-tenancy design (deferred) | `v4-tenancy-design.md` |
| Pre-ticket cleanup backlog | `cleanup.md` |
| Hotfix branch model | `hotfix-release.md` |
| Regular release procedure | `release-workflow.md` |
| Marketing-site update procedure | `marketing-site.md` |
| Wide-regression catalog (v3.27.2) | `2026-05-09_v3.27.2-wide-regression.md` |

**This doc is the entry point. Drill into the source doc when you need detail. Update both when a stream changes shape.**
