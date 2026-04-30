# Release workflow

> Full release procedure for `Simple-PHP-IPAM` — from preparing the release on `dev`, through PR review, merge, tag, GitHub release, and deploy to demo + prod + 4 testing instances.
>
> **Required reading before cutting a release.**

The release bundle **ships with the release PR**, not in a follow-up PR. Feature code and the prebuilt tarball land in a single merge to `main`, and CodeRabbit reviews both in one cycle. The previous workflow (merge → build bundle → second archive PR → second CR review) was abandoned because the second CR pass on a binary tarball added nothing but latency.

### Phase 1 — land the release content on `dev`

Normal feature PRs into `dev` through the session. Every feature/fix/docs commit goes through its own review cycle; nothing in this phase is release-specific.

**Before locking scope, re-validate every milestone issue body against current code.** Issue bodies are written at filing time and may not reflect post-CR-round reality. Two of five #762 items deferred from the v3.17.0 release were already shipped during v3.17 CR rounds before v3.18.0 kicked off — assuming the issue body was authoritative would have meant duplicate-fixing already-fixed code. For each open milestone issue, grep for the named function / file / line ranges and confirm the described problem still exists. Update the issue body or close the line item before scope-lock.

### Phase 2 — prepare the release on `dev`

Once everything that belongs in `vX.Y.Z` is on `dev`, do the following **in order** on `dev`:

1. **Documentation audit — required, not optional.** Review every file in `docs/` and update for any changed features, new config keys, new pages, renamed UI elements, or deprecated behaviour. Specific checks:
   - `docs/index.md` — features list and documentation table (add new guides, update feature bullets)
   - `docs/api.md` — new resources, changed parameters, updated examples
   - `docs/upgrading.md` — add a `### vX.Y.Z` section at the top of "Version-specific upgrade notes" listing new pages, features, breaking changes (if any), and nav/URL changes
   - `docs/configuration.md` — new settings, changed defaults
   - `docs/scanning.md`, `docs/smtp.md`, `docs/security.md`, `docs/oidc.md` — any area the release touched
   - Any new `docs/*.md` file for a new major feature (e.g. `docs/devices.md`)
   - **Stale version strings** — grep for the old version number: `grep -r "vX.(Y-1)" docs/`
2. **Update `CLAUDE.md`** — update the "Current shipped version" line near the top, the page inventory table if new pages were added, and any policy section affected by the release. This file is the agent's source of truth; keeping it current prevents bad decisions in future sessions.
3. **Update Memory MCP** — create or update the release entity `project:simple-php-ipam:roadmap:vX.Y.Z` with an observation recording what was built. Close out the previous roadmap entity with a "RELEASED" observation including the merge commit hash and bundle SHA256. Update the bare `project:simple-php-ipam` entity with the new current version if it has a version observation.
4. Update `testing/samples/large-db-sample/gen_large_db.php` and sample datasets if schema or data model changed.
5. Update `testing/scripts/test_api.sh` if API endpoints were added or changed.
6. Bump `Simple-PHP-IPAM/version.php` to `X.Y.Z`.
7. Bump the asset cache-buster `?v=X.Y.Z` in `page_header()` (`lib.php`) **and** `demo_gate.php:74-75` if any CSS/JS changed.
8. Update `CHANGELOG.md` with the full `## [X.Y.Z] - YYYY-MM-DD` entry and add the comparison link at the bottom.
9. Update `README.md` — replace the existing `## What's new in vA.B.C` block **in place** with a vX.Y.Z summary (README only ever carries the single latest release).
9a. **Audit the new CHANGELOG section for unresolved deferral language.** Run:
    ```bash
    bash testing/scripts/check_changelog_followups.sh CHANGELOG.md
    ```
    Any match for `follow-up`, `deferred`, `pending`, `to be added`, `coming soon`, `will be added`, `future release`, `tracked separately`, or `in a (future|later)` MUST either reference a GitHub issue (`#NNN`) on the same line, or live under a top-level `### Known limitations` subsection of the release entry where each line still references an issue. The lint enforces this; CI will fail without it. Surfaced as #785 after a v3.17.0 CHANGELOG bullet shipped saying "MySQL/PostgreSQL backup pending follow-up" with NO tracking issue — three releases passed before an operator hit the gap in production. Documented in this file so the convention can't be forgotten.

    **Known-limitations convention:** any release that ships a feature in a partial / engine-restricted / opt-in state MUST add a `### Known limitations` subsection at the top of the release entry, BEFORE `### Added` / `### Changed`. Each item must include a tracking issue. Format:
    ```markdown
    ## [X.Y.Z] - YYYY-MM-DD

    ### Known limitations
    - **<feature> is partial.** <one-line scope description>. Tracked as #NNN, target milestone vA.B.C.

    ### Added
    ...
    ```
    This makes partial-ship visible at the top of the release notes (and the marketing-site What's New section) instead of buried in a mid-bullet aside.

10. Run the full local gate until clean:
    ```bash
    for f in Simple-PHP-IPAM/<changed>.php; do php -l "$f"; done
    vendor/bin/phpstan analyse --memory-limit=1G
    vendor/bin/phpcs
    vendor/bin/phpunit
    semgrep --config=.semgrep/rules.yml --error Simple-PHP-IPAM/
    ```
11. Run the containerized Playwright harness end-to-end — not just the new spec. The full suite is the gate on release-readiness, not feature-completeness:
    ```bash
    bash testing/playwright/bootstrap-app.sh sqlite
    (cd testing/playwright && \
      IPAM_BASE_URL=https://127.0.0.1:8443 IPAM_ADMIN_USER=demo IPAM_ADMIN_PASS=demo \
      npx playwright test)
    bash testing/playwright/teardown-app.sh
    ```
    Only proceed if the full suite is green (pre-existing flaky tests excepted — log them in the PR description).
12. Run the dev-direct pipeline only if the release touches something the containerized harness can't cover (real-IdP OIDC, `test_api.sh` regressions, `timezone.spec.ts`).
13. **Clean `Simple-PHP-IPAM/` of any local test debris** before building — the release script excludes `*.sqlite` but **not** the various artifacts produced by `bootstrap-app.sh` and prior runs. Run all of these:
    ```bash
    git restore Simple-PHP-IPAM/config.php                       # bootstrap-app.sh rewrites this
    rm -f Simple-PHP-IPAM/config.php.prebootstrap-backup         # bootstrap-app.sh's saved original
    rm -f Simple-PHP-IPAM/config.php.bak-v3upgrade               # legacy upgrade-script backup
    rm -f Simple-PHP-IPAM/data/*.bak Simple-PHP-IPAM/data/demo_last_reset.txt
    rm -rf Simple-PHP-IPAM/data/sessions                         # PHP session files from local runs
    ls Simple-PHP-IPAM/data/   # expect only ipam.sqlite, tmp/, possibly .htaccess
    ```
    Stray files baked into the tarball is a recurring class of bug across releases — the cleanup glob has grown over time. Treat `git status` showing anything under `Simple-PHP-IPAM/` other than your release-prep edits as a red flag.
14. **Build the bundle:**
    ```bash
    ./releases/make_releases.sh Simple-PHP-IPAM X.Y.Z
    ```
    If `rsync` is not available, replicate the same logic with `cp -a` (copy dotfiles with `cp -a src/. dest/`), permission sanitisation, and `tar --numeric-owner --owner=0 --group=0`. Verify the tarball contains:
    - `upgrade.sh` with execute permission (`-rwxr-xr-x` in `tar tvf ...`)
    - Both `.htaccess` files (root and `data/`)
    - `settings.php`, `lib.php`, and any other newly-added PHP files
    - **No** `data/ipam.sqlite`, no `data/.db_initialized`, no `data/*.bak`
15. Move the bundle into its permanent home and commit:
    ```bash
    mkdir -p releases/ipam-X.Y.Z
    mv ipam-X.Y.Z/ipam-X.Y.Z.tar.gz ipam-X.Y.Z/SHA256SUMS releases/ipam-X.Y.Z/
    rmdir ipam-X.Y.Z 2>/dev/null || true
    git add releases/ipam-X.Y.Z/
    git commit -m "chore(release): add vX.Y.Z release bundle and SHA256SUMS"
    ```
16. Push `dev` and open the release PR `dev → main`. CodeRabbit + CI review the full changeset — code, docs, tests, and bundle — in one cycle.

### Phase 3 — responding to review comments

If CodeRabbit or a human reviewer asks for a change on the open PR:

1. Make the code/doc/test fix on `dev` and re-run the relevant local gate checks.
2. **Rebuild the bundle so it reflects the fix — but only if the fix touched files inside `Simple-PHP-IPAM/`.** The bundle is built from the `Simple-PHP-IPAM/` subtree only; top-level files (`.coderabbit.yaml`, `.github/`, `composer.json`, `docs/`, `releases/`, `tests/`, `website/`) are NOT in the tarball and changes to them do NOT require a rebuild. If the only files changed since the last bundle commit are outside `Simple-PHP-IPAM/`, the existing tarball still matches the deployed code state — skip the rebuild. Verify with:

   ```bash
   git diff --stat releases/ipam-X.Y.Z/SHA256SUMS..HEAD -- Simple-PHP-IPAM/
   ```

   If the diff is empty, no rebuild is needed and the tarball stays valid through merge. Otherwise rebuild is non-negotiable: the merged `releases/ipam-X.Y.Z/ipam-X.Y.Z.tar.gz` must match the final code state. Delete the old artifact, rerun the cleanup + build, commit as a new `chore(release): rebuild vX.Y.Z bundle after review fixes` commit (or amend, if the review round happens on a single unreviewed change):
   ```bash
   rm -rf releases/ipam-X.Y.Z ipam-X.Y.Z
   rm -f Simple-PHP-IPAM/data/*.bak Simple-PHP-IPAM/data/demo_last_reset.txt
   ./releases/make_releases.sh Simple-PHP-IPAM X.Y.Z
   mkdir -p releases/ipam-X.Y.Z
   mv ipam-X.Y.Z/ipam-X.Y.Z.tar.gz ipam-X.Y.Z/SHA256SUMS releases/ipam-X.Y.Z/
   rmdir ipam-X.Y.Z 2>/dev/null || true
   git add releases/ipam-X.Y.Z/
   git commit -m "chore(release): rebuild vX.Y.Z bundle after review fixes"
   ```
3. Push the same branch. CI re-runs; CodeRabbit re-reviews. Repeat until the code is clean.
4. **Never merge a PR where `releases/ipam-X.Y.Z/ipam-X.Y.Z.tar.gz` predates the final code commit.** If you forget step 2 on the last round, catch it before merge: the `git log --name-only` should show the rebuild commit after every code-fix round.

### Phase 4 — merge and release

1. Merge the PR once all checks are green.
2. Pull `main` locally.
3. Tag and push:
   ```bash
   git tag -a vX.Y.Z -m "vX.Y.Z - <one-line summary>"
   git push origin vX.Y.Z
   ```
4. Create the GitHub release, attaching the bundle from the in-tree path (or the local copy — both SHA match because the tree is now authoritative):
   ```bash
   gh release create vX.Y.Z \
     releases/ipam-X.Y.Z/ipam-X.Y.Z.tar.gz \
     releases/ipam-X.Y.Z/SHA256SUMS \
     --title "vX.Y.Z - <summary>" \
     --notes "<markdown body>"
   ```
5. Verify the release is live: `gh release view vX.Y.Z --json url,assets`.
6. **Deploy to demo site** (`demo.simplephpipam.com`, OpenLiteSpeed on `root@192.168.80.23`):
   Use the release tarball + `upgrade.sh` — **never raw `rsync` from the working tree**, which silently excludes `vendor/` (gitignored) and breaks the Composer autoloader.
   ```bash
   scp releases/ipam-X.Y.Z/ipam-X.Y.Z.tar.gz root@192.168.80.23:/tmp/
   ssh root@192.168.80.23 "
     rm -rf /tmp/ipam-X.Y.Z && mkdir /tmp/ipam-X.Y.Z
     tar -xzf /tmp/ipam-X.Y.Z.tar.gz -C /tmp/ipam-X.Y.Z
     cd /tmp/ipam-X.Y.Z/ipam-X.Y.Z
     bash upgrade.sh --yes /usr/local/lsws/vhosts/demo.simplephpipam.com/html
     chown -R nobody:nogroup /usr/local/lsws/vhosts/demo.simplephpipam.com/html
   "
   ```
   Verify: browse `https://demo.simplephpipam.com/` and confirm the version shown in the footer.
7. **Deploy to prod instance** (`ipam.seanmousseau.com`, OpenLiteSpeed on `root@192.168.80.23`):
   ```bash
   ssh root@192.168.80.23 "
     cd /tmp/ipam-X.Y.Z/ipam-X.Y.Z
     bash upgrade.sh --yes /usr/local/lsws/vhosts/ipam.seanmousseau.com/html
     chown -R nobody:nogroup /usr/local/lsws/vhosts/ipam.seanmousseau.com/html
   "
   ```
   **DB:** MySQL at `192.168.80.13:3306`, database `ipam`, user `ipam`. Credentials are in `config.php` on the server (not in `dev-secrets.env`). `data/ipam.sqlite` on disk is stale/unused — always verify migration state against MySQL `schema_migrations`, not the SQLite file.
   Verify: browse `https://ipam.seanmousseau.com/` and confirm the version in the footer.
8. **Deploy to 4 testing instances** (`root@192.168.80.15`):
   The DB hostnames (`mariadb`, `postgres`) are Docker-internal and unreachable from the host, so `upgrade.sh` must run **inside the container** via `docker exec`. Copy the tarball into the container, extract it, and run `upgrade.sh` from there.
   ```bash
   scp releases/ipam-X.Y.Z/ipam-X.Y.Z.tar.gz root@192.168.80.15:/tmp/
   ssh root@192.168.80.15 "
     docker cp /tmp/ipam-X.Y.Z.tar.gz dev_seanmousseau_com-apache-php-1:/tmp/
     docker exec dev_seanmousseau_com-apache-php-1 bash -c '
       rm -rf /tmp/ipam-X.Y.Z && mkdir /tmp/ipam-X.Y.Z
       tar -xzf /tmp/ipam-X.Y.Z.tar.gz -C /tmp/ipam-X.Y.Z
       cd /tmp/ipam-X.Y.Z/ipam-X.Y.Z
       for dir in ipam ipam-maria ipam-mysql ipam-postgres; do
         bash upgrade.sh --yes /var/www/html/testing/\$dir
       done
     '
     chown -R www-data:www-data /opt/container_data/dev.seanmousseau.com/html/testing/
   "
   ```
9. **Update the marketing website** (`simplephpipam.com`) — required on every release. Follow the full procedure in `docs/internal/marketing-site.md`: version bump in `front-page.php` (four places), feature card updates, the 7-step add-a-doc procedure if the release ships new `docs/*.md` guides, deploy via rsync, and the OPcache + cachedata + rewrite-flush + LSCache+QUIC.cloud purge sequence.

10. **Close milestone issues** — close every GitHub issue in the vX.Y.Z milestone:
   ```bash
   gh issue close <N> --comment "Released in vX.Y.Z. See https://github.com/seanmousseau/Simple-PHP-IPAM/releases/tag/vX.Y.Z"
   ```
11. **Update Memory MCP** — write a final "RELEASED" observation to the `project:simple-php-ipam:roadmap:vX.Y.Z` entity with: merge commit hash, bundle SHA256, tag, deploy confirmation, and all issues closed. Also update the bare `project:simple-php-ipam` entity if it has a "current version" observation.

---

## Related procedure docs

- `docs/internal/hotfix-release.md` — when the fix can't wait for the regular `dev → main` cycle.
- `docs/internal/marketing-site.md` — Phase 4 step 9 expanded.
- `docs/internal/test-suites.md` — Local gate + 3-driver pass.
- `docs/internal/adding-a-migration.md` — Phase 2 schema/migration steps.
- `docs/internal/adding-a-page.md` — when the release introduces new pages.
- `docs/internal/investigating-ci-failure.md` — when Phase 2/3 CI goes red.
