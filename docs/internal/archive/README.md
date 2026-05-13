# Archived internal docs

Historical planning + post-mortem snapshots that are no longer load-bearing for the live doc set. Kept so that incident chains and commit-message references still resolve, but **not** part of the active reference surface.

| File | Why archived | Live successor |
|---|---|---|
| `backup_overhaul.md` | Multi-phase v3.21–v3.22 backup architecture spec. Decisions are now reflected in shipped code and in `ipambkl1-format.md` / `backup-formats-matrix.md`. | `ipambkl1-format.md`, `backup-restore-runbook.md`, `parked-features.md` |
| `code_quality_review.md` | One-off code-review report from v3.27 review cycle. Findings either shipped or migrated to `cleanup.md`. | `cleanup.md` |
| `ux_overhaul.md` | Pre-v3.8.0 sidebar redesign spec. Shipped; the design now lives in code + `design-guide.md`. | `design-guide.md` |
| `step-up-auth.md` | Folded into `auth-model.md` → Step-up auth section (the implementation reference for the entire auth surface lives in one doc now). | `auth-model.md` → "Step-up auth (v3.27.0)" |
| `test-improvements.md` | Test-suite improvement backlog; items shipped or moved to `cleanup.md` / lessons-learned. | `testing-guide.md`, `lessons-learned.md` §4 |
| `decrypt-tool-test-plan.md` | Conformance plan for the IPAMBKP3 decrypt tool. Plan executed; results captured in release evidence. | `ipambkl1-format.md`, `backup-restore-runbook.md` |
| `2026-05-08_Path_Forward.md` | Dated session-plan after Pass A regression sweep. Lessons promoted to `lessons-learned.md` §8. | `lessons-learned.md` |
| `2026-05-09_v3.27.2-wide-regression.md` | Wide-regression session record for v3.27.2. | Per-release Memory MCP entity + `lessons-learned.md` |
| `2026-05-11_session-plan.md` | Dated session plan. | Per-release Memory MCP entity |

If a reference outside this directory still points at one of these files, the link will resolve here. Update the reference to the live successor when you next touch the referring file.
