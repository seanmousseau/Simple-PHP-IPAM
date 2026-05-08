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

## Tracked (in a GH issue)

| Issue | Milestone | Item | Status |
|---|---|---|---|
| #1062 | v3.33.0 | phpmd `unusedcode` cleanup — 5 items in `lib.php`, `lib/backup.php`, `lib/backup_admin_destinations.php` | open |

## Completed

| Closed in | Item |
|---|---|

*(empty)*

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
