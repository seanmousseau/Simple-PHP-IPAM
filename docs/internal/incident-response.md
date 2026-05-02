# Incident response

> Playbook for production incidents — what to do when `ipam.seanmousseau.com`, `demo.simplephpipam.com`, or a critical user-reported bug surfaces a regression in a shipped release. Complements `hotfix-release.md` (which is the *mechanical* hotfix procedure). This doc is the *decision-making* layer that wraps it.
>
> **Trigger:** a regression in production that wasn't caught by the local gate or CI, or an exploit/security report that needs an unscheduled release.

---

## The four phases

```
Detect  →  Triage  →  Hotfix  →  Post-mortem
```

Each phase has a defined exit criterion. Don't skip ahead.

---

## Phase 1 — Detect

How you'll usually find out:

- **Self-discovered post-deploy.** Manual smoke-test caught it within minutes of shipping (the "S3 SigV4" v3.19.1 path).
- **CI-after-the-fact.** A scheduled `composer audit` or nightly Playwright run goes red after a merge.
- **User report.** Issue filed against a recent release.
- **Synthetic monitoring.** Demo or prod returns 5xx on a probe.

**Initial capture (before fixing anything):**

```bash
# Confirm the version actually deployed
curl -sk https://ipam.seanmousseau.com/version.php
curl -sk https://demo.simplephpipam.com/version.php

# Pull the relevant log lines from the failing host
ssh root@192.168.80.23 "tail -200 /usr/local/lsws/logs/error.log | grep -i 'ipam\|fatal\|exception'"
```

Record what you saw, when, and on which version into a draft Memory MCP observation on the affected release entity (e.g. `project:simple-php-ipam:release:v3.19.0`). You'll fill it out fully in the post-mortem phase.

**Exit criterion:** You can name the version, the affected target(s), and have at least one concrete error string or repro step.

---

## Phase 2 — Triage

Decide the response track. The decision is binary: **hotfix now**, or **defer to next regular release**.

### Hotfix-now criteria (any one is sufficient)

- **Data loss or corruption.** Migrations dropping rows, writes silently failing, IP allocations being lost.
- **Authentication broken.** Users cannot log in, MFA bypass, session fixation, or unauthenticated access to admin paths.
- **Hard crash on a primary path.** Login page, dashboard, or subnet/address listings 500 for all users.
- **Security advisory you cannot mitigate by config alone.** A `composer audit` advisory in a runtime dep with no workaround.
- **Active exploitation.** Reported in the wild, even before a fix exists.

### Defer-to-next-release criteria

- **Cosmetic.** Misaligned button, wrong colour, typo.
- **Workaround exists.** Setting toggle, role downgrade, or a documented fix that operators can apply.
- **Affects an opt-in feature.** A setting that's off by default, a beta gate.
- **Affects only one engine** in a way that's already documented and that engine's user base is small (e.g. an SQLite-only edge case in a Postgres-only feature).

If you're between criteria, **defer**. A wrong hotfix is worse than a delayed one — it doubles the deploy churn and erodes trust in the release process.

### What to communicate during triage

- If the user reported it, acknowledge with the version, the impact assessment, and the chosen track ("we'll hotfix as v3.19.1 today" or "we'll roll this into v3.20.0 next week").
- Open a GitHub issue if one doesn't exist yet. Tag with the affected release milestone retroactively + the next-release milestone.

**Exit criterion:** Decision recorded in the Memory MCP entity for the affected release (`hotfix-now` or `deferred to vX.Y.Z`).

---

## Phase 3 — Hotfix

Mechanical procedure lives in **`hotfix-release.md`**. Specific to incident response:

### Branch off `main`, NOT `dev`

This is the load-bearing rule. By the time a regression is in production, `dev` has almost certainly diverged (dependabot merges, in-flight features, the v3.15.1 case). Branching off `main` ensures you ship exactly the production code + your minimal fix, with nothing else.

After merge, sync `dev ← main` immediately. Do not let the divergence persist.

### Keep scope ruthless

A hotfix branch should contain **only the targeted fix** and the version-bump artifacts. Resist all temptation to:

- Bundle in a small unrelated improvement "while we're here"
- Refactor the affected code beyond what the fix needs
- Update a doc that you noticed is stale
- Bump a dependency

Each of those is a separate PR for the next regular release. The hotfix's diff should be small enough to review in five minutes.

### Test the regression specifically

Add a test case that reproduces the original failure and proves the fix works. The local gate's existing pass is necessary but not sufficient — if the bug got past CI once, the existing test surface didn't catch it. Without a new test, you'll re-ship the same regression in a future release.

For the v3.21.1 MySQL HY093 case: the fix added a 3-driver migration replay test that exercises the dedup path with the engine that originally failed.

### Deploy in the prescribed order

`deploy-targets.md` covers the mechanics. For an incident response specifically:

1. **Demo first.** Lowest blast radius — if the migration or the fix itself is broken, you find out on the SQLite copy.
2. **Four testing instances** — verifies the multi-engine surface.
3. **Prod last.** Only after demo + all four testing instances are confirmed clean on the new version.

If demo or any testing instance fails post-upgrade, **stop**. Do not proceed to prod. Roll back the failing target (`deploy-targets.md` → Rollback) and start a new diagnostic loop. Pushing forward to "see if prod behaves the same" turns a contained incident into a multi-environment incident.

**Exit criterion:** All seven targets on the new version, all probes green, the original regression confirmed fixed in production.

---

## Phase 4 — Post-mortem

Do this within 24 hours of the deploy, while the context is fresh. The output is one Memory MCP observation and (sometimes) one new entry in `lessons-learned.md`.

### Memory MCP observation template

Add to the affected release entity (`project:simple-php-ipam:release:vX.Y.Z`):

```
INCIDENT — <one-line description>

What broke: <symptom users saw>
Root cause: <single-sentence technical cause>
Why CI/local gate missed it: <gap in test coverage, environmental difference, etc.>
Fix: PR #NNNN, hotfix vX.Y.(Z+1), commit <short SHA>
Detection lag: <time from deploy to detection>
Resolution lag: <time from detection to prod-fixed>

Generalizable lesson (if any): <one sentence — promote to lessons-learned.md if non-obvious>
```

### When to promote to `lessons-learned.md`

The bar for promotion is: **same class of bug has bitten more than once**, OR **a generalisable rule emerges that another release could violate**. Examples:

- v3.19.1 (S3 SigV4) → "validate signed requests against AWS reference vectors before shipping new signers" — promoted to §5.
- v3.21.1 (MySQL HY093) → "named placeholders cannot be referenced more than once in MySQL prepared statements" — promoted to §1.
- v3.15.1 (PHPMailer Encoding=base64 broke test grep) → "set CharSet=UTF-8 only, leave Encoding default" — promoted to §4.

A one-off bug that doesn't generalise stays in the release entity and is **not** promoted. `lessons-learned.md` is a curated index, not a bug log.

### What to NOT do in the post-mortem

- Don't blame a person, a model, or "the release process." Focus on the gap that let the bug through and how to close it.
- Don't promise process changes you can't follow through on. "We'll always test S3 against real AWS" is meaningless if the testing instances don't have AWS credentials. "We'll add a unit test with the canonical request fixture from the AWS SigV4 spec" is actionable.
- Don't skip the post-mortem because the fix was small. The whole point is the *gap*, which is independent of fix size.

**Exit criterion:** Memory MCP observation written, GitHub issue closed with a link to the hotfix release, `lessons-learned.md` updated if a new generalisable rule emerged.

---

## Common patterns from the last six months

| Incident | Detection | Root cause class | Where the gate missed it |
|---|---|---|---|
| v3.15.1 — UTF-8 mail / app_secret banner | Self, post-deploy | Test grep regex too literal; whitelist walked wrong key level | Playwright assertion was too strict; `ipam_config_stale_keys()` had no test for nested keys |
| v3.19.1 — S3 SigV4 canonicalRequest | Self, post-deploy | Hand-rolled signing diverged from AWS spec on edge case | No reference-vector test for the signer |
| v3.21.1 — MySQL HY093 dedup | Self, post-deploy | Named placeholder reused in prepared statement | 3-driver migration replay test didn't exercise this specific dedup path |

Pattern: **all three were caught self-post-deploy, not by user report.** The local gate is good enough that user reports are rare; the lag is between "shipped" and "ran the smoke test." That suggests the highest-leverage process change would be **automated post-deploy smoke tests** rather than tightening pre-deploy gates further.

---

## Cross-references

- `hotfix-release.md` — mechanical hotfix procedure, invoked from Phase 3.
- `deploy-targets.md` — deploy ordering and rollback, invoked from Phase 3.
- `release-workflow.md` — referenced for the shared Phase 4 deploy/observe steps.
- `lessons-learned.md` — promoted post-mortem rules.
- `test-suites.md` — local gate that the incident bypassed.
