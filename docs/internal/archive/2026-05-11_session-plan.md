# 2026-05-11 session plan

> **Origin:** end-of-day 2026-05-10 after v3.27.7 shipped + backup/restore architecture chat with Sean. Locked in via `roadmap.md` §3.7 / §4.6 / §6.7 and `decrypt-tool-test-plan.md`. This doc captures the four items pending across sessions so neither of us forgets when context compacts.
>
> **Slot:** 2026-05-11 morning onward, fresh head.
>
> **Status legend:** ⏳ pending · 🟡 in progress · ✅ done · ❌ blocked

---

## Recommended order (3 → 2 → 4 → 1)

### ⏳ 1. Security review

**What:** Sweep recent v3.27.x code + the broader codebase for security findings before locking v3.28.0 scope. Both mechanical (semgrep MCP) and judgment-driven (`pr-review-toolkit:code-reviewer` agent with a security-focused prompt).

**Inputs available at session start:**
- `releases/2026-05-10_security-review/semgrep-findings.md` — semgrep MCP scan output (will be generated tonight before sign-off)
- `releases/2026-05-10_security-review/code-reviewer-findings.md` — code-reviewer agent's pass against the v3.21–v3.27 backup/restore code + the new v3.27.7 webhook crypto + step-up surfaces (will be generated tonight)

**Goal of the morning:** read both reports, triage findings into Blocker / High / Medium / Low / wontfix, slot into v3.27.9 / v3.28.0 / v3.28.x / v3.29.0 / backlog.

**Output:** a triaged findings table that becomes the input for step 2.

---

### ⏳ 2. Roadmap review + milestone/issue shuffle

**What:** The big cleanup pass. Decisions outstanding from prior sessions:

| Question | State |
|---|---|
| §3.6 A/B/C decision on how to handle Pass C step-up bundle (v3.27.x hotfix vs v3.28.0 carve-out vs v3.28.1) | Open — partially answered by the v3.27.8 / v3.28.x / v4.0.0 plan but step-up bundle (F-S3-02 / F-S5-01 / F-S7-01) still needs a slot |
| Milestone #55 (v3.28.0) has 31 issues; path forward locks v3.28.0 to ~8 deliverables. Re-triage which 23+ go where. | Open |
| 15 Pass C findings have no GH issues filed | Open |
| 4 orphan Pass A bugs (#1120, #1121, #1122, #1123) — close vs re-milestone | Open |
| v3.27.8 milestone creation + populating with bugs A+B+C+D from CDP diagnosis + drop-silent-plaintext-fallback | Open |
| v3.28.x legacy-writer-retirement milestone — create + populate | Open |
| v4.0.0 milestone — currently labeled "i18n phase 1" only; add backup cold-break scope per §6.7 | Open |
| New security findings from step 1 above | Pending step 1 |

**Output:** clean milestone list, every Pass C finding has an issue, orphans closed or re-slotted, v3.28.0 issue count reflects locked scope.

---

### ⏳ 3. v3.28.0 scope lockdown

**What:** Final convergence on what's actually in v3.28.0. Depends on (2) being done.

**Anchor doc:** `docs/internal/test-improvements.md` §5 already lists the 8 locked deliverables. After (2), v3.28.0 milestone (#55) should match that list 1-to-1, with everything else moved out.

**Decision-review gate questions in `test-improvements.md` §8** that need answers before scope-lock:
1. Ship Pass C contract-drift code fixes (F-S2-02, F-S2-03) inside v3.28.0 or v3.28.1?
2. Webhook signing round-trip Node-receiver-fixture confirmation (npm dev-dep lane OK?)
3. `ipam_setting()` enforcement linter: allowlist-with-fix-by-version OR fix all direct reads in v3.28.0?
4. Round-trip test runtime budget: small fixtures only OR nightly larger set?

**Output:** signed-off v3.28.0 scope. After lockdown, the release can begin via `/release-kickoff v3.28.0`.

---

### ⏳ 4. Decrypt-tool thorough test execution

**What:** Pass 1 manual execution of `decrypt-tool-test-plan.md`. 7 fixtures × 8 cases + 8 cross-cutting = 64 datapoints.

**Why last:** execution-heavy (~3–4 focused hours of fixture generation + tool runs). Deadline is "before v3.28.x writer-retirement starts," which is downstream of v3.28.0 — there's runway. Can also run as a parallel background task while (3) is being decided.

**Open questions to resolve before Pass 1 starts** (from `decrypt-tool-test-plan.md`):
1. F1 producer: synthesize from v3.18 docker image OR find real-world archive?
2. F4 transitory passphrase: CLI/API path for deterministic capture, or capture once + reuse?
3. C7 round-trip plaintext: canonical fixture source — `tests/fixtures/decrypt-tool/plaintext-source.sqlite`?

**Output:** results grid (7×8 + 1×8) committed at `releases/2026-05-11_decrypt-tool-pass1/results.md` plus a "findings" section for every yellow/red cell.

---

## Cross-session continuity

If this session gets compacted before we get through all four:
- Read this doc first (`docs/internal/2026-05-11_session-plan.md`).
- Read `roadmap.md` §3.7 / §4.6 / §6.7 for the backup-architecture plan context.
- Read `decrypt-tool-test-plan.md` for the test matrix.
- Read the security findings files (paths above) once they exist.
- Memory MCP has end-of-day observations on `project:simple-php-ipam` from 2026-05-10.

---

## Out-of-scope for this morning

Things that exist on the roadmap but aren't queued for today:
- v3.27.8 actual implementation (UI bug fixes, drop silent fallback) — happens after (3) lockdown OR in parallel if Sean wants to overlap
- v3.28.x legacy-writer-retirement implementation
- v4.0.0 cold-break implementation
- Pass C surface re-runs (only if findings from (1) suggest the methodology missed something)
