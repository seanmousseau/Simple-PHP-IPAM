# Code cleanup backlog

> Internal tracker for low-risk code-health items that should not be lost between releases. The GitHub milestone system is the source of truth for *scheduled* cleanup work; this doc is the **pre-ticket backlog** — a place for items spotted during other work to live until they're batched into a GH issue.
>
> **Workflow:** notice cleanup-worthy code → add a row here → batch into a GH issue when ≥3 items accumulate or when planning a release → mark the row "ticketed (#NNNN)" → close the row when the issue ships.

## Triage rules

A cleanup item belongs here only if **all four** are true:

1. **Low risk** — no behavioural change, or a behavioural change so minor it could ship in any patch.
2. **Not blocking** — does not affect a known bug, security issue, or in-flight feature. Anything time-sensitive goes straight to a GH issue with a milestone.
3. **Verifiable mechanically** — `php -l`, PHPStan, PHPCS, the local gate, or a single targeted Playwright spec proves it correct after the change.
4. **Not a refactor** — moving code, renaming, or restructuring does not belong here. Those need design discussion.

If an item is large (>30 lines diff) or touches multiple files, file it as a GH issue immediately rather than parking it here.

## Categories

| Category | What goes here |
|---|---|
| **Dead code** | Unused variables, unused parameters (only if signature-compat is verified-not-required), unreachable branches |
| **Style drift** | PHPCS exceptions left over from old code that the established rules now cover |
| **Comment rot** | Comments that have drifted from the code they describe |
| **Stale TODOs** | TODO/FIXME comments older than 12 months whose context is recoverable |
| **Doc drift** | Internal docs (under `docs/internal/`) that reference code that has moved or been renamed |
| **Localization** | Canadian English standardization (deferred — see backlog row). Maintainer is in Canada; eventual goal is `colour`/`normalise`/`organize`/`program` consistency across code, comments, UI strings, and docs. Holding for a dedicated localization release stream so changes don't bleed into feature releases |

## Active backlog (not yet ticketed)

| Added | Item | Where | Category | Notes |
|---|---|---|---|---|
| 2026-05-01 | Canadian English standardization across code, comments, UI strings, docs | Repo-wide | Localization | Deferred to a dedicated localization release stream (no version assigned). Maintainer is in Canada. Today the codebase is mixed British/American (e.g. `colour`, `normalise` British; `Authorization Code` American as it's a spec proper noun). When the localization stream is spun up, audit + sweep in one PR per surface (code, UI, docs) to keep diffs reviewable. Until then, keep CR spelling nits flagged as deliberate-not-applied with a one-line reason — see PR #1061 round 2 |
| 2026-05-11 | S-005 — `recaptcha_action` config value flows to UI via `data-recaptcha-action` without echo escape audit | `auth.php` / login surface | Style drift / security-Note | From `releases/2026-05-10_security-review/code-reviewer-findings.md` S-005 (Note). Admin-controlled value reaches a DOM data-attribute. Realistic exposure is zero (admin already controls reCAPTCHA action) but worth an `e()` audit when login surface is next touched (likely v3.36-era UX cleanup). Opportunistic fix |
| 2026-05-11 | S-010 — `ipam_restore_degraded_database_unsupported` runs a child process via the shell | `lib/backup.php` | Style drift / security-Note | From security-review S-010 (Note, marked N/A). Helper for a deliberately-unsupported path. Tracked here so the inevitable future refactor doesn't reintroduce shell metacharacter handling without re-checking |

## Tracked (in a GH issue)

| Issue | Milestone | Item | Status |
|---|---|---|---|
| #1062 | v3.33.0 | phpmd `unusedcode` cleanup — 5 items in `lib.php`, `lib/backup.php`, `lib/backup_admin_destinations.php` | open |

## Completed

| Closed in | Item |
|---|---|
| dev (post-v3.27.9) | `demo_gate.php` favicon + `open-props.min.css` `?v=` cache-busters were hardcoded literals that had to be bumped by hand each release and drifted when a release didn't touch CSS. Now derived from `IPAM_VERSION` like `page_header()` (+ `filemtime()` for `app.css`/`app.js`). Stale "bump `demo_gate.php:74-75`" instructions removed from CLAUDE.md, `release-workflow.md`, `hotfix-release.md`. |
| v3.28.3 | Missing `[3.3.0]` compare-link entry in `CHANGELOG.md` bottom block. Pre-existing gap discovered during the v3.27.7 link-block migration; one-line addition placed in the existing descending order. |
| v3.28.3 | S-011 — Webhook signing secret length cap. Defensive `strlen($secret) > 4096` check added to both `create` and `edit` actions in `webhooks.php` alongside the v3.28.2 `ipam_app_secret()` refactor window. Cap is not load-bearing (DB column already enforces limit at write time) but matches the rest of the input-validation policy. |

---

## How items reach this doc

- **From a tool run** — running phpmd, PHPStan with `--level=10`, or a CR review surfaces a low-risk pattern. Add a row.
- **From feature work** — while implementing feature X, you spot dead code in nearby file Y. Don't bundle the cleanup into the feature PR; add a row here so the feature PR stays scoped, then batch later.
- **From a CR comment** — CodeRabbit flags a stylistic issue that's real but not load-bearing for the current PR. Add a row, reply to the CR comment with a link to this doc.

## How items leave this doc

- **Batched into a GH issue** — when ≥3 items accumulate in a category, or when planning a release with cleanup capacity, open one issue listing all items. Move the rows from "Active" to "Tracked" with the issue number.
- **Determined to be wrong** — if review of a row reveals it was a false positive (e.g. parameter is signature-compat and cannot be removed), delete the row. Don't leave stale entries.
- **Aged out** — items unaddressed for 18+ months should be re-evaluated. The codebase may have changed, the item may no longer apply, or the item may genuinely deserve promotion to a tracked issue.

## What does NOT belong here

- **Bugs.** File a GH issue immediately.
- **Security issues.** File a GH issue with the security label, or follow `incident-response.md` if it's already in production.
- **Feature requests.** GH issue, with the relevant version milestone.
- **Architectural changes.** Need brainstorming + a design doc, not a backlog row.
- **TODO comments in code.** A code TODO is its own tracker; don't duplicate it here unless you're about to ticket it.

## Cross-references

- `lessons-learned.md` — promoted post-incident rules that often spawn cleanup items.
- `incident-response.md` — what to do when "cleanup" is actually "we shipped a regression."
- `release-workflow.md` — when a release has spare capacity, draw from this doc.
