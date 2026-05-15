# ADR-006: Memory MCP discipline as cross-session continuity

**Status:** accepted
**Decided:** 2026-05-15
**Scope:** process / tooling decision. Locks the role Memory MCP plays in this project's session-to-session continuity and what we do when it's unavailable or wrong.
**Stamped by:** Sean Mousseau

---

## Context

This project uses the **Memory MCP server** (Docker MCP Toolkit, `claude-memory` volume, persistent, daily-backed-up to OneDrive) as the **sole agent session-state store**. The rules are documented in `~/.claude/CLAUDE.md` and the project-local `CLAUDE.md` instructs every fresh session to:

```
open_nodes(["user:sean"])
search_nodes("project:simple-php-ipam")
```

…before doing any other work. Observations get written as work happens, one per meaningful change with a short commit hash. Releases close out with a `RELEASED` observation on the `project:simple-php-ipam:release:vX.Y.Z` entity (tag, merge commit, bundle SHA256).

The roadmap §10 calls this out as a candidate for an explicit decision:

> "Memory MCP discipline as the only cross-session continuity — Useful but didn't prevent today's bugs (no observations linking v3.24 codec → v3.26 storage → v3.27 step-up)."

The honest critique embedded in that line: Memory MCP **didn't catch** the v3.24 → v3.26 → v3.27 backup-bug cluster the roadmap is built around. The graph had observations about each release individually but no relation linking them. The bug cluster was visible only in hindsight when Sean compared the three releases manually.

So ADR-006 is doing two things at once:
1. **Affirm** the role of Memory MCP as the cross-session continuity layer (no parallel mechanism — flat files for load-bearing rules, graph for working state, that's the model).
2. **Define** what "discipline" actually means — i.e. when does the graph fail to do its job, and what's the corrective?

### Three failure modes observed in practice

**Failure mode 1 — Orphan observations.** An observation gets created on a bug entity (`project:simple-php-ipam:bug:v3.24-encrypt-write-path`) but never gets `affects` / `is-regression-of` / `caused-by` relations linking it to related bugs in earlier or later releases. The graph contains the facts but the two-hop query "what bugs in v3.26 share a root cause with v3.24 bugs?" returns nothing because the relations don't exist.

The v3.24 → v3.26 → v3.27 cluster was exactly this. Three separate bug entities, each with observations, no relations between them.

**Failure mode 2 — Stale observations.** An observation says "bug X is open, regression test in tests/Y" but bug X was actually closed two releases ago and the test moved. The graph is wrong; a fresh session reading it gets a misleading mental model.

This usually happens during long release cycles where the "close-out" step gets compressed and bug entities don't get the final "Fixed in `<commit>`" observation.

**Failure mode 3 — Implicit knowledge that should be in flat files.** "We always X" type rules — `ipam_bind_binary()` for binary IPs, FK-cascade footgun, the three schema files staying in lockstep — get an observation but never make it to the design-document.md or coding-guide.md flat file. Future sessions miss the rule because they didn't query the right entity.

The flat-file vs graph boundary is in `~/.claude/CLAUDE.md` ("Anything already documented in a flat `~/.claude/projects/<project>/memory/*.md` file — flat files are the ultimate source of truth for load-bearing rules; the graph is a fast working layer on top.") but it's easy to drift in either direction: putting load-bearing rules in graph observations (lose them across MCP failures), or putting working state in flat files (becomes stale rapidly).

## Decision drivers

- **Continuity must survive a server restart.** Memory MCP is Dockerised; restarting Docker Desktop loses the in-memory cache. The `claude-memory` volume + daily backup is the persistence layer. If the volume is missing or the backup hasn't run, the agent has to know and surface it.
- **Continuity must survive a session compaction.** When context is compressed, the summary captures the prose; the explicit graph reads on next session capture the structured state. Both layers must reinforce each other.
- **Flat-file source of truth is non-negotiable.** Anything that would be load-bearing for a security or data-integrity rule must live in `docs/internal/*.md`, not in the graph.
- **Discipline must be checkable.** A rule that says "always link related bug entities with relations" is only useful if there's a lightweight way to ask "are there orphan bug entities?" without reading the whole graph by hand.
- **Cost of wrong-state must be low.** A stale graph observation should be cheap to spot and cheap to correct; not a release-stopper.

## Options considered

### Option A — Status quo, plus a session-start audit

**Mechanism:** Keep the existing setup. Add a one-step audit at the start of every release session (or every Nth session):

- For each open bug entity, verify it has at least one outgoing `affects` or `is-regression-of` relation — or an explicit "no related bugs" observation.
- For each release entity older than the current shipped version, verify it has a `RELEASED` close-out observation.
- For each "we always X" rule cited in an observation, verify a matching paragraph exists in `docs/internal/*.md`.

The audit is a checklist in this ADR, executed manually at sprint kickoffs and pre-release.

**Pros:**
- Zero infrastructure work.
- Catches the three failure modes directly.
- The checklist is sized to "5-minute task during sprint kickoff."

**Cons:**
- Manual; requires discipline to run.
- Doesn't prevent the failure modes; only detects them after the fact.

### Option B — Scripted audit + automatic relation-creation prompts

**Mechanism:** A small script (`testing/scripts/memory-audit.sh` or similar) calls Memory MCP's read APIs, identifies orphan bug entities, stale releases, and observation-without-flat-file rules, and emits a report. The agent runs the script as part of `/release-kickoff` and `/release-gate`.

**Pros:**
- Automated; no manual discipline cost per session.
- Output is concrete: "Bug entity X has no relations; suggest adding one of these."
- Re-runnable any time.

**Cons:**
- More infrastructure than the failure modes really warrant — this isn't a security issue, just a working-memory-hygiene issue.
- The script has to be maintained as the entity-naming convention evolves.
- Memory MCP doesn't have a "show me all entities with zero outgoing relations" primitive; the script has to read graph then filter, which is non-trivial for large graphs.

### Option C — Move some graph use cases back to flat files

**Mechanism:** Redefine the boundary. Specifically:
- **Bug clusters** (the v3.24 → v3.26 → v3.27 case) move into a flat `docs/internal/bug-clusters.md` with explicit cross-references. Each cluster has one section; each section names the root cause and lists the bugs that share it.
- **Release close-out** stays in graph (cheap, structured, queryable).
- **Cross-session "remember to check X next session"** notes stay in graph.

**Pros:**
- The information that benefited least from being graph-shaped (clusters) gets durable flat-file form.
- Reduces what we have to police in the graph.

**Cons:**
- New `docs/internal/bug-clusters.md` file becomes another moving part to maintain.
- The graph still has bug entities; now they have a sibling representation in flat-file form. Two sources of truth.

### Option D — Defer; redefine when Memory MCP itself changes

**Mechanism:** Keep the status quo. Memory MCP is a healthy tool; its failure modes are real but rare. Next time the MCP server is upgraded or replaced, re-examine.

**Pros:**
- Zero cost now.

**Cons:**
- The roadmap §10 line explicitly flagged this as a candidate for decision. D is "don't decide," which is also a decision but a lazy one.
- The failure modes are observable today; ignoring them is a slow drift toward "the graph is full of stale stuff that nobody trusts."

## Recommendation

**Accepted: Option B (scripted audit) for the audit itself + Option A's discipline rules for everything else.**

Claude's original draft recommended Option A (manual checklist). Sean overrode to scripted audit on 2026-05-15 with the following rationale: a manual checklist that depends on the controller-agent remembering to run it has the same failure mode as the orphan-relations problem the ADR was opened to fix. Automating it removes the discipline-on-discipline regress.

The scripted audit lives at `testing/scripts/memory-audit.sh`, runs as part of `/release-kickoff`, and produces a structured report against the three failure modes. Manual checklist is preserved as a fallback if MCP is unavailable when the script runs.

The decision drivers tip toward A because:

1. **The failure modes aren't security or data issues.** They are working-memory hygiene. The corrective for working-memory hygiene is a checklist, not infrastructure.

2. **A checklist that runs at sprint kickoff is a high-leverage moment.** The cost of catching a stale observation or orphan bug is bounded by "I have to look at this entity." Catching it now (before kickoff) prevents the bad mental model from steering the release.

3. **Option B's automation is over-investment.** A `memory-audit.sh` script for this size of graph (~50 entities, growing slowly) is more code than the failure mode justifies. Maintenance cost on the script likely exceeds the time savings.

4. **Option C's flat-file alternative loses the relational query.** The whole point of the graph is two-hop queries — "what else is related." Moving bug clusters to a flat file loses that.

5. **A is reversible and forward-compatible.** If the manual audit consistently fails (we forget to run it), we can build Option B's script later. If we find specific use cases that want flat files, we can adopt Option C selectively.

The accompanying discipline rules below codify what "doing it right" looks like — turning the existing ad-hoc practice into a written contract.

## Implications

### Discipline rules (the actual contract this ADR locks)

1. **At session start.** Run the two-call pattern that's already in `CLAUDE.md`:
   ```
   open_nodes(["user:sean"])
   search_nodes("project:simple-php-ipam")
   ```
   No exceptions. If Memory MCP is unavailable, surface immediately to Sean.

2. **At release kickoff (`/release-kickoff`).** Before locking scope, run the audit checklist:
   - Are there any open `project:simple-php-ipam:bug:*` entities with no outgoing relations? If yes, add one or explicitly observe "no related bugs found."
   - Does every previously-shipped `project:simple-php-ipam:release:v*` have a `RELEASED` close-out observation? If not, add it from `git log` data.
   - For every "we always X" rule referenced in working memory, is there a matching paragraph in `docs/internal/*.md`? If not, move it.

3. **During work.** One observation per meaningful change. Required fields:
   - One factual sentence that stands alone.
   - Files / functions touched.
   - Short commit hash (`git rev-parse --short HEAD`).
   - Test result when the observation closes out a chunk.

4. **At release close-out.** Two writes:
   - Final `RELEASED` observation on the release entity (tag, merge commit, bundle SHA256, deploy confirmation, milestone close).
   - Update the bare `project:simple-php-ipam` entity if its "current version" observation moved.

5. **When the graph disagrees with reality.** Correct the graph **before** continuing the task. A stale observation that gets read next session is worse than no observation.

6. **What does NOT belong in the graph:**
   - Code patterns, conventions, architecture — those go in `docs/internal/*.md`.
   - Git history — `git log` / `git blame` are authoritative.
   - Ephemeral in-session task state — `TaskCreate` for that.
   - Anything already in a load-bearing flat file (`design-document.md`, `coding-guide.md`, `security-model.md`, etc.).

7. **What DOES belong in the graph:**
   - Releases (with their merge commit, bundle SHA, deploy state).
   - Bugs and their relations to releases and other bugs.
   - Hot-spot files (frequent-edit files that benefit from a "history of changes" trail).
   - Cross-session "remember to check X" notes that don't fit in `TaskCreate`'s ephemeral scope.
   - Architectural decisions (this ADR set itself becomes graph entities at acceptance — see implications below).

### Memory MCP entity additions from the ADR sprint itself

Each of ADR-001 through ADR-006 gets a graph entity:

```
project:simple-php-ipam:adr:001-settings-type-system
project:simple-php-ipam:adr:002-settings-save-semantics
project:simple-php-ipam:adr:003-config-global
project:simple-php-ipam:adr:004-lib-php-size-module-shape
project:simple-php-ipam:adr:005-backup-separation
project:simple-php-ipam:adr:006-memory-mcp-discipline
```

Each entity gets one observation: status, decided-date, scope, and a link to the flat-file ADR. Relations between ADRs (e.g. ADR-001 → ADR-002 "informs," ADR-004 → ADR-001 "consumes") get added as edges.

### Files changed

- `docs/internal/architecture-decisions/README.md` — adds a "Session-start audit checklist" section reproducing Option A's three checks for easy reference at sprint kickoff.
- `~/.claude/CLAUDE.md` — does NOT change. ADR-006 ratifies the existing rules; doesn't extend them. (The session-start audit is a release-kickoff step, not a per-session step.)
- `CLAUDE.md` (project-local) — adds a one-line pointer to ADR-006 in the "Session start" section.

### GH issues to open

- `tools: testing/scripts/memory-audit.sh — automated audit script for ADR-006's three failure modes (orphan bug relations / stale release close-outs / graph rules that should be in flat files)` — milestone none, lands when next convenient.
- `docs(internal): add 'Session-start audit checklist' to architecture-decisions/README.md as the script's manual fallback (ADR-006)` — same.
- `chore(memory): backfill graph entities for ADR-001 through ADR-006 with their decided-date + flat-file pointer (Option Q2 = pointer + status only) + cross-ADR relations` — same.

### Schema migrations needed

None. Pure process ADR.

### Docs to update

- `docs/internal/architecture-decisions/README.md` — add the checklist section.
- `docs/internal/roadmap.md` § 10 — mark ADR-006 row as decided.
- This file itself becomes part of the source-of-truth flat-file set.

### Future ADRs unblocked

None directly. ADR-006 is process; it doesn't gate any code work.

## Open questions

All four resolved at stamping (2026-05-15):

1. ~~Automate the audit?~~ **Resolved: script it now.** `testing/scripts/memory-audit.sh` lands as the canonical audit; the manual checklist becomes a fallback only when MCP is unavailable. Reasoning: a manual checklist has the same forgetting-discipline failure mode as the orphan-relations problem this ADR exists to fix.
2. ~~ADR graph shape?~~ **Resolved: pointer + status only.** Each ADR entity carries status, decided-date, scope (one line), and a path to the flat file. The body stays in `docs/internal/architecture-decisions/`. Graph is index cards; flat files are the documents.
3. ~~ADR-006 close-out?~~ **Resolved: acceptance closes it forever.** Process ADRs don't ship. There is no future "RELEASED vX.Y.Z" observation expected on the `project:simple-php-ipam:adr:006-memory-mcp-discipline` entity. Reaffirmation is implicit — if practice diverges from the ADR, that's a re-stamping event whenever it happens.
4. ~~Belt-and-braces backup?~~ **Resolved: skip for now.** Existing stack is 4 layers deep (live Docker volume → daily JSON dump in OneDrive → Time Machine local snapshot → load-bearing data already lives in git-versioned flat files). Sean is separately evaluating **Arq backup for macOS** which would add cloud-redundant fileystem-level snapshots; if Arq lands, that's effectively the 5th layer with no Memory-MCP-specific work needed. Re-evaluate only if (a) load-bearing data starts living in the graph without a flat-file mirror, or (b) a near-miss restore reveals a real gap.

## References

- `~/.claude/CLAUDE.md` — Memory MCP session-state rules (global, applies to every project).
- `CLAUDE.md` (project-local) — "Session start" two-call pattern.
- `docs/internal/roadmap.md` § 10 (locked 2026-05-11) — ADR-006's source.
- `~/bin/backup-claude-memory.sh` — the daily backup script + launchd agent.
- v3.24 / v3.26 / v3.27 backup-bug cluster — the historical case the roadmap line references; would have been caught earlier with stricter relation discipline.
