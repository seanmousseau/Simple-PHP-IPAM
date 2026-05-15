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
| 002 | Settings save semantics — per-key vs group-form | **draft** | — | refactor wave 1 prerequisite (Bug V #1121 root cause) |
| 003 | `$config` global as the only config conduit | not started | — | refactor wave 2 prerequisite |
| 004 | `lib.php` size + module shape | not started | — | refactor wave 1 blueprint |
| 005 | `backup.php` orchestrator/codec/dispatcher separation | not started | — | v4.0.0 backup cold break prerequisite |
| 006 | Memory MCP discipline as cross-session continuity | not started | — | process |

The locked sequence (smallest informing largest) is from roadmap § 10.1: **001 → 002 → 003 → 004 → 005 → 006**.
