# Architecture Decision Records (ADRs)

Each file in this directory captures **one** architectural decision that was deliberately surfaced rather than made implicitly during a release. The process and rationale for this directory is set in `docs/internal/roadmap.md` § 10.

## Why a decision lives here

A decision goes here when **all three** are true:

1. The choice spans more than one release or one subsystem.
2. The choice is non-obvious — reasonable engineers could pick differently.
3. Implementing the wrong choice would create cleanup debt that compounds.

Day-to-day implementation choices (variable names, helper extractions, test patterns) do **not** belong here. The bar is "something the post-mortem 18 months from now will reference."

## Process

Each decision is one focused session with Sean. Claude prepares:

- A draft ADR with **context**, **options**, **tradeoffs**, and a **recommendation**.
- Pre-research: schemas, helpers, and live-code locations touched by the decision.
- Open questions where context is genuinely missing.

Sean stamps the ADR with **status: accepted** (or asks for revisions). The accepted ADR's "implications" section then drives GH issues or scope changes.

**No architectural decision in this list gets implemented unilaterally by Claude.** This rule is set in `roadmap.md` § 10 and is non-negotiable.

## File convention

```
<NNN>-<kebab-slug>.md
```

`NNN` is a zero-padded sequential number (`001`, `002`, …). Numbers are permanent — superseded ADRs are not renumbered; they update their **status** field to `superseded by 0NN` and the superseding ADR back-links.

## Template

See `_template.md`. Every ADR follows the same shape so reviewers can scan in seconds.

## Index

| # | Title | Status | Decided | Scope |
|---|---|---|---|---|
| 001 | Settings table type system | **accepted** | 2026-05-15 | refactor wave 1 prerequisite |
| 002 | Settings save semantics — per-key vs group-form | **accepted** | 2026-05-15 | refactor wave 1 prerequisite (Bug V #1121 root cause) |
| 003 | `$config` global as the only config conduit | **accepted** | 2026-05-15 | refactor wave 2 prerequisite |
| 004 | `lib.php` size + module shape | **accepted** | 2026-05-15 | refactor wave 1 blueprint |
| 005 | `backup.php` orchestrator/codec/dispatcher separation | **accepted** | 2026-05-15 | v4.0.0 backup cold break prerequisite |
| 006 | Memory MCP discipline as cross-session continuity | **accepted** | 2026-05-15 | process |

The locked sequence (smallest informing largest) is from roadmap § 10.1: **001 → 002 → 003 → 004 → 005 → 006**.

## Session-start audit checklist

This checklist is the **manual fallback** for ADR-006's three failure modes (orphan bug relations, stale release close-outs, observation-vs-flat-file drift). The **automated path is `bash testing/scripts/memory-audit.sh`** — run that whenever Memory MCP is reachable. Fall back to the steps below only when MCP is unavailable (Docker Desktop off, fresh machine without OneDrive sync restored, server volume missing) or when you want to spot-check the script's output by hand.

The wording mirrors ADR-006 § Implications #2; the ADR is the authoritative source.

### Check 1 — Orphan bug entities

For each `project:simple-php-ipam:bug:*` entity, verify it has at least one outgoing `affects` / `is-regression-of` / `caused-by` relation, **or** an explicit "no related bugs found" observation. Query:

```
mcp__MCP_DOCKER__search_nodes("project:simple-php-ipam:bug:")
```

Inspect each result. For any entity with zero relations and no explicit no-related-bugs observation, either add the missing relation or write the explicit observation now — orphan bug entities are the v3.24 → v3.26 → v3.27 cluster failure mode this ADR exists to prevent.

### Check 2 — Stale release close-outs

For each `project:simple-php-ipam:release:v*` entity older than the current shipped version (see `Simple-PHP-IPAM/version.php`), verify it has a `RELEASED` close-out observation including **tag**, **merge commit**, **bundle SHA256**, **deploy confirmation**, and **milestone close** (see ADR-006 § Implications #4 for the canonical field list). Backfill any missing close-out from `git log` / `releases/` data before continuing.

### Check 3 — Observation-vs-flat-file drift

For each "we always X" rule referenced in working memory (graph observations of the form "always do Y", "never do Z", invariants, footguns), verify a matching paragraph exists in `docs/internal/*.md`. If not, move it to the appropriate flat file (`design-document.md`, `coding-guide.md`, `security-model.md`, etc.) and update the graph observation to point at the doc. Load-bearing rules belong in flat files; the graph is the working layer on top.

---

ADR-006 itself is the authoritative source for these rules; this section is a procedural reference so the checklist is in front of you when the script can't run.
