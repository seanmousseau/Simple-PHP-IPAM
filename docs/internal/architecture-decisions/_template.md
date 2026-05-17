# ADR-NNN: <one-line decision title>

**Status:** draft | accepted | superseded by 0NN
**Decided:** YYYY-MM-DD (or `—` while draft)
**Scope:** what this gates (release / subsystem / process)
**Stamped by:** <name> (left blank in draft)

---

## Context

What is the situation today? What is forcing this decision? Code locations, schema, history. 2-5 paragraphs maximum — link to source files rather than quote them.

## Decision drivers

Bulleted list of constraints that constrain the choice. Examples:

- Engine portability (sqlite / mysql / pgsql)
- Backward compatibility for existing installs
- Migration cost
- Operator-visibility (does this change something visible in admin UI?)
- Coupling to other ADRs in the sprint

## Options considered

### Option A — <name>

One-sentence summary.

**Mechanism:** how it works.

**Pros:** bullets.

**Cons:** bullets.

### Option B — <name>

(same shape)

### Option C — <name>

(same shape)

Add as many options as are honestly distinct. Strawman options are fine if they're labeled as such — pretending they're real wastes session time.

## Recommendation

**Pick <Option X>.** One paragraph explaining why this beats the others on the decision drivers above. Be specific about which drivers tipped the balance.

## Implications

What changes if this is accepted? Concrete, not aspirational:

- GH issues to open: #
- GH issues to close: #
- Files that change: paths
- Schema migrations needed: version slot
- Docs to update: paths
- Future ADRs unblocked: 0NN

## Open questions

Anything Claude can't answer from code alone. The stamping session is for resolving these.

## References

- `docs/internal/roadmap.md` § 10 (locked 2026-05-11)
- `docs/internal/<related doc>.md` § <section>
- GH issue / PR / commit links
