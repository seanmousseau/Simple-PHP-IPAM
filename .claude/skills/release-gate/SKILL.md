---
name: release-gate
description: Run the full local release gate for Simple PHP IPAM — PHP lint + PHPStan + PHPCS + PHPUnit + Semgrep, then (optionally) the containerized Playwright harness, then build and stage the release bundle. Use only when explicitly preparing a release; never invoke for routine PR work. Accepts an argument like "2.8.0" for bundle-building, or "check" to run just the gate without building.
disable-model-invocation: true
---

# Release gate

Runs the canonical pre-release gate from CLAUDE.md without the copy-paste. User-invoked only (`/release-gate`) because it has real side effects: Docker containers, bundle builds, commits.

## When to invoke

- `/release-gate check` — run lint + PHPStan + PHPCS + PHPUnit + Semgrep. Fast. Use on any PR that touches PHP.
- `/release-gate full` — `check` plus the containerized Playwright harness. ~2 min. Use before opening a release PR.
- `/release-gate build X.Y.Z` — `full`, then clean `Simple-PHP-IPAM/data/` of test debris, then `./releases/make_releases.sh Simple-PHP-IPAM X.Y.Z`, then stage the bundle into `releases/ipam-X.Y.Z/`. Does **not** commit — leaves the tree staged for review.

## What to run (in order; abort on first failure)

### 1. Per-file PHP lint — only changed files

```bash
git diff --name-only --diff-filter=AM HEAD | grep -E '\.php$' | xargs -r -I{} php -l {}
```

If nothing is staged, fall back to linting every `.php` under `Simple-PHP-IPAM/`.

### 2. PHPStan (level 9)

```bash
vendor/bin/phpstan analyse --memory-limit=1G
```

Memory-limit is non-negotiable — 128M default crashes the parallel workers.

### 3. PHPCS (PSR-12 with exclusions)

```bash
vendor/bin/phpcs
```

### 4. PHPUnit

```bash
vendor/bin/phpunit
```

### 5. Semgrep

```bash
semgrep --config=.semgrep/rules.yml --error Simple-PHP-IPAM/
```

The `--error` flag is required — without it semgrep exits 0 even on findings.

**Stop here for `check` mode.**

### 6. Containerized Playwright (full mode only)

```bash
bash testing/playwright/bootstrap-app.sh sqlite
(cd testing/playwright && \
  IPAM_BASE_URL=https://127.0.0.1:8443 IPAM_ADMIN_USER=demo IPAM_ADMIN_PASS=demo \
  npx playwright test)
status=$?
bash testing/playwright/teardown-app.sh
[[ $status -eq 0 ]] || exit $status
```

Always run teardown even on failure — the Docker container will otherwise leak.

**Stop here for `full` mode.**

### 7. Bundle build (build mode only — requires X.Y.Z argument)

Before invoking `make_releases.sh`:

```bash
# Clean release-build-time debris that the tar excludes list doesn't catch
rm -f Simple-PHP-IPAM/data/*.bak Simple-PHP-IPAM/data/demo_last_reset.txt
ls Simple-PHP-IPAM/data/   # expect: ipam.sqlite, tmp/, possibly .htaccess
```

Verify `Simple-PHP-IPAM/version.php` already matches X.Y.Z. If it doesn't, stop and tell the user — version bump is manual (CLAUDE.md step 4 of Phase 2).

Build:

```bash
./releases/make_releases.sh Simple-PHP-IPAM X.Y.Z
```

Stage:

```bash
mkdir -p releases/ipam-X.Y.Z
mv ipam-X.Y.Z/ipam-X.Y.Z.tar.gz ipam-X.Y.Z/SHA256SUMS releases/ipam-X.Y.Z/
rmdir ipam-X.Y.Z 2>/dev/null || true
```

Verify the tarball contents:

```bash
tar tvf releases/ipam-X.Y.Z/ipam-X.Y.Z.tar.gz | grep -E '(upgrade\.sh|\.htaccess|settings\.php|lib\.php|ipam\.sqlite)' | head -20
```

Expected: `upgrade.sh` has `-rwxr-xr-x`, both `.htaccess` files present, no `ipam.sqlite`.

**Do not commit.** Print the staged files and hand back to the user for the release PR.

## Error handling

- PHPStan / PHPCS / PHPUnit / Semgrep failures: print the tool's output and stop. Do not attempt to auto-fix.
- Playwright failure: print the **first failure** (not the summary count) — clustering is documented in CLAUDE.md. Always tear down the container.
- Bundle build failure: most commonly `rsync` missing or `Simple-PHP-IPAM/data/` has stale state. Check for `*.bak` files before re-running.

## Never do

- Never run the dev-direct Playwright path from this skill — it's shared state and out of scope. If the user needs dev-direct, they run it manually per CLAUDE.md.
- Never commit the bundle — that's a manual step so the release PR remains reviewable.
- Never bump `version.php` or touch `CHANGELOG.md` — those are Phase 2 human steps.
