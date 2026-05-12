# Hotfix release procedure

> A hotfix release ships a narrow fix directly off `main` without going through the regular `dev`-integration window. It exists for security issues, post-release regressions, and time-sensitive bugs that should not wait for the next regular release. The shared release machinery (Phase 2 prep checklist, Phase 4 deploy) is unchanged from `docs/internal/release-workflow.md`; only the branch model and scope rules differ.
>
> **Use only when a regular release won't do.** If the fix can wait, it goes on `dev` like any other change.

## Examples

- **v3.15.1** — post-release fixes for v3.15.0 (post-login redirect, UTF-8 mail body, app_secret onboarding banner). Branched off `main` directly the day after v3.15.0 shipped.
- **v3.16.0** — narrow scope (3 fixes) treated like a hotfix even though it was MINOR-bumped. Same branch-off-main pattern.

## When this applies

- The fix is for a regression in the most recently shipped version.
- The fix is security-sensitive and time-sensitive.
- The fix is small enough that bundling it with unrelated `dev` work would mean either (a) waiting on unrelated work, or (b) cherry-picking, which is messier than just branching off `main`.

If the fix is large, multi-week, or has dependencies on other in-flight work, do **not** hotfix — land it on `dev` and ship in the next regular release.

## Versioning

- **PATCH bump** for bug fixes only (v3.15.0 → v3.15.1).
- **MINOR bump** if the hotfix necessarily ships a small backwards-compatible feature alongside (v3.15.x → v3.16.0). Avoid this if you can — minor bumps create user-facing release-notes obligations that hotfixes are trying to skip.

## Procedure

1. **Branch off `main`, not `dev`:**
   ```bash
   git checkout main && git pull origin main
   git checkout -b hotfix/v<X.Y.Z>
   ```

2. **Land the fix.** Each commit on the hotfix branch is its own normal review cycle (CodeRabbit + CI). Follow the Local Gate from `docs/internal/test-suites.md`. If the fix touches migrations, schema, dialects, or any multi-engine code, run all three drivers locally before pushing.

3. **Documentation audit (narrower than a regular release):**
   - `CHANGELOG.md` — add `## [X.Y.Z] - YYYY-MM-DD` with **Fixed** (and **Security** if applicable). No new comparison link tier; just the patch entry.
   - `README.md` — replace "What's new" in place if the change is user-visible. Skip if it's an invisible bug fix.
   - `CLAUDE.md` — bump "Current shipped version" line. Add to page inventory if any new pages (rare in a hotfix). Update any policy section that genuinely changed.
   - `docs/upgrading.md` — add a `### vX.Y.Z` section under "Version-specific upgrade notes" if there's anything operators must do.
   - `docs/<area>.md` — only the file(s) genuinely affected by the fix.

4. **Bump `version.php`** to `X.Y.Z`. No separate asset-cache-buster step — `page_header()` and `demo_gate.php` derive `?v=` from `IPAM_VERSION` + the asset's `filemtime()` automatically.

5. **Build the bundle** (same as regular release Phase 2 step 14):
   ```bash
   rm -f Simple-PHP-IPAM/data/*.bak Simple-PHP-IPAM/data/demo_last_reset.txt
   ./releases/make_releases.sh Simple-PHP-IPAM X.Y.Z
   mkdir -p releases/ipam-X.Y.Z
   mv ipam-X.Y.Z/ipam-X.Y.Z.tar.gz ipam-X.Y.Z/SHA256SUMS releases/ipam-X.Y.Z/
   git add releases/ipam-X.Y.Z/
   git commit -m "chore(release): add vX.Y.Z release bundle and SHA256SUMS"
   ```

6. **Open the PR `hotfix/v<X.Y.Z> → main`.** This is the deviation from the regular release workflow — there is no `dev` PR. CodeRabbit and CI review the full changeset. Same review-loop expectations as a regular release: every CR comment fixed, bundle rebuilt after every code-fix round (`docs/internal/release-workflow.md` Phase 3 — apply identically here).

7. **Merge** with explicit user approval. Tag, push tag, create the GitHub release, and deploy following Phase 4 of the regular release workflow:
   - Demo site (`demo.simplephpipam.com`)
   - Prod (`ipam.seanmousseau.com`)
   - 4 testing instances on `192.168.80.15`
   - Marketing site update if the release is visible (`docs/internal/marketing-site.md`)
   - Close milestone issues + Memory MCP "RELEASED" observation

8. **Bring the fix back to `dev`.** After merge, fast-forward `dev` from `main` (or merge `main` into `dev`) so the fix is part of the next regular release's history. Do this immediately — letting `dev` diverge from `main` causes pain in the next dev → main PR.

## What does **not** change vs a regular release

- The local gate (php -l, phpstan, phpcs, phpunit, semgrep, 3-driver Playwright if relevant)
- The release-bundle ships with the PR (not a follow-up)
- Bundle rebuilt after every code-fix review round
- Phase 4 deploy targets (demo, prod, 4 testing, marketing site)
- Memory MCP observation cadence
- The "no PR creation or merge without explicit user approval" rule

## What **does** change

- Branch source: `main`, not `dev`
- PR target: `main`, not `dev → main`
- No multi-feature integration window
- Scope is narrower — anything outside the targeted fix gets pushed back to `dev`
- README "What's new" can be skipped if the fix is invisible to users
- After-merge step: bring the fix back to `dev` (not in regular release flow)

## Cross-references

- `docs/internal/release-workflow.md` — the shared Phase 2/3/4 procedure.
- `docs/internal/test-suites.md` — Local gate + 3-driver pass requirement.
- `docs/internal/marketing-site.md` — only if the hotfix is user-visible.
