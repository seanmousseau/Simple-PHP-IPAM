# Internal documentation

Single-source-of-truth doc set for Simple PHP IPAM. **Reference docs** (the WHY behind the code) and **procedure docs** (the how-to recipes), kept here to survive across sessions and shipped on every release tarball.

This directory is internal: it speaks to developers and AI agents working on the code, not operators running it. Operator and integrator docs live in `docs/` (one level up).

---

## Reading order for a new agent / new contributor

1. **`design-document.md`** — architecture and the load-bearing invariants table. Every row was bought with a real incident. Read once, refer back when touching that part of the code.
2. **`coding-guide.md`** — conventions specific to this codebase. The PR-time gates at the bottom are non-negotiable.
3. **`testing-guide.md`** — test layers, when to add what. Pair with `test-suites.md` (the "how to run" detail).
4. Skim the procedure-doc index below; read on demand when the task matches.

---

## Reference docs (the WHY)

| Doc | Read when |
|---|---|
| `design-document.md` | Onboarding; touching architecture; before changing anything in the invariants table |
| `coding-guide.md` | Writing or reviewing code |
| `testing-guide.md` | Adding or maintaining tests |
| `design-guide.md` | Touching UI (HTML, CSS, JS in `assets/`) |
| `security-model.md` | Security review; assessing threat surface; reasoning about trust boundaries |
| `auth-model.md` | Touching auth, OIDC, MFA, or step-up code |
| `api-contract.md` | Changing the API surface or response envelope |
| `data-dictionary.md` | Looking up a column type, FK, or unique constraint across SQLite/MySQL/PostgreSQL |
| `config-reference.md` | Looking up an operator-tunable knob |
| `runbooks.md` | Production incident or unfamiliar deploy issue |
| `lessons-learned.md` | Cross-release rollup of hard-won lessons; read at session start |

## Procedure docs (the HOW)

### Releasing

| Doc | Use when |
|---|---|
| `release-kickoff-prompt.md` | Starting a new release session (paste-and-go template) |
| `release-workflow.md` | Cutting a regular release (Phase 1–4) |
| `hotfix-release.md` | Cutting a hotfix off `main` |
| `incident-response.md` | Production regression — decide hotfix vs defer |
| `deploy-targets.md` | Deploying a release bundle to any of the 7 targets |
| `marketing-site.md` | Anything touching `simplephpipam.com` |
| `investigating-ci-failure.md` | A check went red on a PR |

### Code & data

| Doc | Use when |
|---|---|
| `adding-a-migration.md` | Writing a `migrations.php` closure |
| `adding-a-page.md` | Creating a new PHP page |
| `adding-a-setting.md` | Adding an admin-tunable setting |
| `adding-an-api-endpoint.md` | Extending `api.php` |
| `adding-a-runtime-dependency.md` | Proposing a new Composer package or vendored frontend asset |
| `runtime-dependency-policy.md` | Full policy behind the dep whitelist |

### Subsystems

| Doc | Use when |
|---|---|
| `audit-actions.md` | Adding a new `audit()` action verb |
| `auth-model.md` | Modifying login/OIDC/MFA/step-up — covers step-up entirely (no separate step-up doc) |
| `scanner.md` | Touching scan/probe/ARP code |
| `backup-restore-runbook.md` | Backup/restore recovery |
| `backup-formats-matrix.md` | Quick lookup of backup format compatibility |
| `ipambkl1-format.md` | Touching `ipam_backup_logical_*` or `ipam_restore_logical_*` |
| `page-inventory.md` | Looking up an existing page |

### Forward-looking design (v4.x — not in force in v3.x)

| Doc | Use when |
|---|---|
| `v4-release-stream.md` | Sequencing v4.x stream work |
| `v4-tenancy-design.md` | Multi-tenancy design (opt-in v4.0.0+) |
| `i18n-design.md` | i18n / l10n rollout planning |
| `parked-features.md` | Features intentionally deferred (e.g. IPAMBKP3 transitory write path) |

### Backlog and meta

| Doc | Use when |
|---|---|
| `cleanup.md` | Pre-ticket backlog for low-risk code-health items |
| `coderabbit-config.md` | Debugging `.coderabbit.yaml` ↔ org-config inheritance |
| `roadmap.md` | Forward planning across releases |

### Archive

`archive/` holds historical planning + post-mortem snapshots that are no longer load-bearing (`backup_overhaul.md`, `code_quality_review.md`, `ux_overhaul.md`, dated session-plan docs, retired test plans). Kept for incident-chain context; not part of the live doc set.

---

## Doc design principles

These docs follow a small set of rules to stay maintainable across sessions:

1. **Single source of truth.** Each fact lives in exactly one doc. Others link, never restate.
2. **Audience-first.** Each doc names its audience at the top. The audience determines voice and scope.
3. **Maintainability over completeness.** Stale docs are worse than absent docs. Every doc has an "Update protocol" section.
4. **Link to source of truth, don't duplicate.** Code is the source of truth for WHAT; docs are the source of truth for WHY.
5. **Concrete > abstract.** File paths, function names, version constants. Reference actual past incidents when explaining an invariant.
6. **No emojis** unless the file's existing style uses them.

---

## When in doubt

- "How does X work?" → reference docs (start with `design-document.md`).
- "How do I do X?" → procedure docs.
- "What went wrong last time?" → `lessons-learned.md` then the per-release Memory MCP entity.
- "Where does this constant live?" → `data-dictionary.md` (columns), `config-reference.md` (knobs), `coding-guide.md` (whitelist).

---

## Maintaining this set

`coding-guide.md` → "PR-time gates" lists every PR-time doc update that is enforced. The release-workflow phase 4 includes a doc-sweep step. The lessons-learned doc is the chronological rollup; the design-document invariants table is the curated subset that protects specific code locations. Keep both up to date; they serve different purposes.

Adding a new doc: follow the design principles above. Cross-link aggressively. Add a row to the appropriate table in this README. Avoid restating content from existing docs.
