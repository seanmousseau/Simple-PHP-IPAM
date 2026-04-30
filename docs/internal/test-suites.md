# Test suites — local & CI

> How to run the local test suites: static analysis (php -l, phpstan, phpcs, phpunit, semgrep), the containerized Playwright harness (`bootstrap-app.sh sqlite|mysql|pgsql`), and the dev-direct path for the few specs containers can't cover.
>
> **Required reading before pushing.** The "Local gate" section at the bottom is the must-pass checklist before every PR.

## Running the test suites

Two paths exist as of v2.5.2:

1. **Containerized** — a fully self-contained Dockerized Apache+PHP instance on `https://127.0.0.1:8443` seeded from `demo_seed.php`. No SSH, no dev-direct dependencies, no shared state. Use this for Playwright unless you need something that only a real deployment can test (real-IdP OIDC, the `timezone.spec.ts` remote-config flow). See `testing/playwright/README.md` for full instructions. In short:
   ```bash
   (cd testing/playwright && npm ci && npx playwright install chromium)
   bash testing/playwright/bootstrap-app.sh sqlite
   (cd testing/playwright && \
     IPAM_BASE_URL=https://127.0.0.1:8443 IPAM_ADMIN_USER=demo IPAM_ADMIN_PASS=demo \
     npx playwright test)
   bash testing/playwright/teardown-app.sh
   ```
   The `.github/workflows/playwright.yml` CI job runs the same commands against the same image; a failing CI run can almost always be reproduced locally verbatim.

2. **Manual against dev-direct** — the shared test server at `https://dev-direct.seanmousseau.com:8343/claude/ipam`. Needed for `test_api.sh`, real-IdP OIDC scenarios, and any quick verification against a "real" install. The dev environment is shared, stateful, and has multiple footguns that have caused repeated false failures — read this whole section before invoking the suites. All commands assume cwd is the repo root.

See the dedicated subsections below: **Local PHP tools**, **Deploy**, **Pre-flight cleanup**, **Verify admin login**, **test_api.sh**, **Playwright (dev-direct)**.

### Local PHP tools

These have no dev-server dependency. Must be green before deploying.

```bash
php -l Simple-PHP-IPAM/<changed-file>.php   # one per changed file
vendor/bin/phpstan analyse --memory-limit=1G   # default 128M crashes parallel workers
vendor/bin/phpcs
vendor/bin/phpunit
semgrep --config=.semgrep/rules.yml --error Simple-PHP-IPAM/
```

### Deploy

The API and Playwright suites run against the live `/claude/ipam/` deploy, not your local copy.

```bash
rsync -az --delete \
  --exclude='data/' --exclude='config.php' --exclude='.htaccess' \
  Simple-PHP-IPAM/ root@192.168.80.15:/opt/container_data/dev.seanmousseau.com/html/claude/ipam/
ssh root@192.168.80.15 \
  "chown -R www-data:www-data /opt/container_data/dev.seanmousseau.com/html/claude/ipam/ && \
   docker exec dev_seanmousseau_com-apache-php-1 php /var/www/html/claude/ipam/migrate.php"
```

Always `chown -R www-data` after rsync — Apache cannot write the SQLite WAL otherwise.

### Pre-flight cleanup

The suites are sensitive to leftover state from prior runs. Run this before every test invocation:

```bash
ssh root@192.168.80.15 \
  'docker exec dev_seanmousseau_com-apache-php-1 bash -c "pkill -f cron.php; pkill -f ping; true"'
ssh root@192.168.80.15 \
  "docker exec dev_seanmousseau_com-apache-php-1 sqlite3 /var/www/html/claude/ipam/data/ipam.sqlite \
   'DELETE FROM login_attempts;'"
```

- **Stale cron processes** can hold the SQLite write lock for hours (50+ active scan_schedules × 254 IPs each). Symptom: `database is locked` errors mid-suite, or `cron.php` hanging silently after Task 5.
- **login_attempts** is the rate limiter. Any prior failed login (wrong creds, hung CSP test) leaves rows that cause subsequent good logins to return *"Too many failed login attempts. Please try again later"*.

### Verify admin login before running the suites

`~/.claude/dev-secrets.env` can drift from what's stored in the dev DB. Verify before assuming creds are wrong:

```bash
bash -c 'set -a; source ~/.claude/dev-secrets.env; set +a; \
  rm -f /tmp/c.txt; \
  curl -k -sS -u "$IPAM_BASIC_USER:$IPAM_BASIC_PASS" -c /tmp/c.txt -b /tmp/c.txt \
    https://dev-direct.seanmousseau.com:8343/claude/ipam/login.php > /tmp/login.html; \
  CSRF=$(grep -oE "name=\"csrf\" value=\"[^\"]*\"" /tmp/login.html | head -1 | sed "s/.*value=\"//;s/\"//"); \
  curl -k -sS -u "$IPAM_BASIC_USER:$IPAM_BASIC_PASS" -c /tmp/c.txt -b /tmp/c.txt -L \
    --data-urlencode "username=$IPAM_ADMIN_USER" \
    --data-urlencode "password=$IPAM_ADMIN_PASS" \
    --data-urlencode "csrf=$CSRF" \
    -w "%{url_effective}\n" -o /dev/null \
    https://dev-direct.seanmousseau.com:8343/claude/ipam/login.php'
```

Success: URL ends with `/dashboard.php`. Failure: still `/login.php`.

**Always use `--data-urlencode` for the password**, not `-d`. The default bootstrap password contains `!` which curl's `-d` does not encode, breaking the form post.

If creds are wrong, reset the DB password to match secrets via a small PHP tempfile (avoids shell-escaping the bcrypt `$` chars):

```bash
cat > /tmp/setpw.php <<'PHP'
<?php
$p = getenv('NEW_PASS');
$h = password_hash($p, PASSWORD_DEFAULT);
$db = new PDO('sqlite:/var/www/html/claude/ipam/data/ipam.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->prepare("UPDATE users SET password_hash=:h, is_active=1 WHERE username='admin'")
   ->execute([':h' => $h]);
$db->exec("DELETE FROM login_attempts");
echo password_verify($p, $h) ? "ok\n" : "FAIL\n";
PHP
scp /tmp/setpw.php root@192.168.80.15:/tmp/setpw.php
bash -c 'set -a; source ~/.claude/dev-secrets.env; set +a; \
  ssh root@192.168.80.15 "docker cp /tmp/setpw.php dev_seanmousseau_com-apache-php-1:/tmp/setpw.php && \
    docker exec -e NEW_PASS='\''$IPAM_ADMIN_PASS'\'' dev_seanmousseau_com-apache-php-1 php /tmp/setpw.php"'
```

### test_api.sh

**Containerized (preferred since v2.9.0 / #451):**

```bash
bash testing/playwright/bootstrap-app.sh sqlite
DOCKER_CONTAINER=ipam-pw-test bash testing/scripts/test_api.sh https://127.0.0.1:8443
bash testing/playwright/teardown-app.sh
```

No SSH, no shared state, no `BASIC_AUTH`. The same flow runs in CI's `api-tests` job under `playwright.yml`.

**Against dev-direct (use only when you need the shared deployment):**

```bash
bash -c 'set -a; source ~/.claude/dev-secrets.env; set +a; \
  BASIC_AUTH="$IPAM_BASIC_USER:$IPAM_BASIC_PASS" \
  SSH_HOST=root@192.168.80.15 \
  SSH_DB_PATH=/opt/container_data/dev.seanmousseau.com/html/claude/ipam/data/ipam.sqlite \
  bash testing/scripts/test_api.sh https://dev-direct.seanmousseau.com:8343/claude/ipam'
```

- **`BASIC_AUTH=user:pass`** is required for dev-direct. Exporting `IPAM_BASIC_USER`/`PASS` alone is not enough — the script reads the combined `BASIC_AUTH` env var. Forgetting this returns 401 on every POST/PUT.
- **`SSH_HOST` + `SSH_DB_PATH`** let the script auto-create an API key on the dev server. Without them you must pass `API_KEY=...`.
- Expect ~150 PASS, 0 FAIL, possibly 1 SKIP. A `database is locked` error means a stray cron is holding the lock — re-run pre-flight cleanup.

### Playwright (dev-direct)

Prefer the containerized local path described at the top of this section. Use dev-direct only when you need something that only a real deployment can test (real-IdP OIDC, `timezone.spec.ts` which SSHes to patch remote config, etc.). Most timezone and OIDC specs self-skip against non-dev-direct targets via an `isDevServer()` guard.

```bash
bash -c 'set -a; source ~/.claude/dev-secrets.env; set +a; \
  IPAM_BASE_URL=https://dev-direct.seanmousseau.com:8343/claude/ipam \
  npx --prefix testing/playwright playwright test \
    --config=testing/playwright/playwright.config.ts > /tmp/pw.log 2>&1; \
  echo "exit=$?"; tail -40 /tmp/pw.log'
```

- The suite has 329 tests and takes 25–30 minutes. Always run in the background with `run_in_background: true` and a Monitor watching for `exit=` in the output file.
- **Do not pipe through `tail`** during the run — Playwright's reporter buffers and you lose all output until the end. Redirect to a file instead.
- Failures cluster: if the auth fixture fails, every dependent test fails. Always look at the **first failure**, not the count. The most common first-failure causes:
  1. Bad admin password (see *Verify admin login* above).
  2. `database is locked` from a stale cron — see pre-flight cleanup.
  3. Rate-limited from prior failed runs (`login_attempts` not cleared).
  4. The dev container is down or unreachable.
- Single-test debug: append `pages.spec.ts:42 --reporter=line` (or any `file.spec.ts:line`).

## Local gate — required before opening a PR

Starting in v2.5.2, the containerized Playwright harness runs automatically on every PR targeting `dev` or `main` via `.github/workflows/playwright.yml` (full suite + `.htaccess` subset, both against a fresh Dockerized Apache+PHP instance). That changes what has to be run locally.

**Required every push — must ALL be green before `git push`:**

**Step 1: Static analysis (fast, ~5s):**
```bash
php -l Simple-PHP-IPAM/<file>.php   # each changed file
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/phpcs
vendor/bin/phpunit
semgrep --config=.semgrep/rules.yml --error Simple-PHP-IPAM/
```

**Step 2: Containerized test suite (required, ~2–10 min per driver):**

GitHub Action minutes are a finite paid resource. **Never push to `dev` or open a PR without a full local dockerized pass.** Run against every DB driver your changes could affect:

```bash
# SQLite (always required)
bash testing/playwright/bootstrap-app.sh sqlite
DOCKER_CONTAINER=ipam-pw-test bash testing/scripts/test_api.sh https://127.0.0.1:8443
(cd testing/playwright && \
  IPAM_BASE_URL=https://127.0.0.1:8443 IPAM_ADMIN_USER=demo IPAM_ADMIN_PASS=demo \
  npx playwright test --project=chromium)
bash testing/playwright/teardown-app.sh

# MySQL (required if changes touch migrations, schema, dialects, or multi-engine code)
bash testing/playwright/bootstrap-app.sh mysql
DOCKER_CONTAINER=ipam-pw-test bash testing/scripts/test_api.sh https://127.0.0.1:8443
(cd testing/playwright && \
  IPAM_BASE_URL=https://127.0.0.1:8443 IPAM_ADMIN_USER=demo IPAM_ADMIN_PASS=demo \
  npx playwright test --project=chromium)
bash testing/playwright/teardown-app.sh

# PostgreSQL (required if changes touch migrations, schema, dialects, or multi-engine code)
bash testing/playwright/bootstrap-app.sh pgsql
DOCKER_CONTAINER=ipam-pw-test bash testing/scripts/test_api.sh https://127.0.0.1:8443
(cd testing/playwright && \
  IPAM_BASE_URL=https://127.0.0.1:8443 IPAM_ADMIN_USER=demo IPAM_ADMIN_PASS=demo \
  npx playwright test --project=chromium)
bash testing/playwright/teardown-app.sh
```

Do not push until **all drivers are green**. A failing CI run wastes Action minutes and creates PR noise.

### Backup integration gate (#789, v3.20.0)

`bootstrap-app.sh` always starts a MinIO sidecar (`http://minio:9000`) on the docker network and seeds two `backup_destinations` rows via `testing/playwright/fixtures/seed-backup-destinations.php`:

- `ci-minio` — S3 destination targeting the MinIO sidecar (bucket `ipam-backups`, prefix `ci/`)
- `ci-local` — local destination at `data/tmp/ipam-backup-ci-local`

`testing/playwright/tests/backup-integration.spec.ts` exercises connection-test → run-now → backup_history round-trip against both destinations on each driver. **This gate is REQUIRED for any change that touches the backup engine** — specifically:

- `Simple-PHP-IPAM/lib/backup.php`
- `Simple-PHP-IPAM/lib/S3Client.php`
- `Simple-PHP-IPAM/lib/SftpClient.php`
- `Simple-PHP-IPAM/lib/LocalBackupClient.php`
- `Simple-PHP-IPAM/lib/BackupClientInterface.php`
- `run_db_backup_if_due` / `backup_run_dump` paths in `lib.php`

SFTP coverage is intentionally out of scope (deferred to v3.23.0 #833 — requires an `sshd` sidecar). The MinIO + local pair is enough to catch regressions in the upload path on all three engines.

The image (`testing/playwright/Dockerfile.apache`) bundles `default-mysql-client` and `postgresql-client` so the native dump path can run inside the test container — without these, `mysqldump` / `pg_dump` invocations fail and the upload short-circuits.

**Manual dev-direct testing is only needed when:**
- You need `testing/scripts/test_api.sh` against a real deployment specifically (the containerized `DOCKER_CONTAINER=ipam-pw-test` path in CI covers regression on every PR via #451 — see the **test_api.sh** subsection above)
- You're verifying `timezone.spec.ts` (SSH-based remote config patching — skipped against containerized targets)
- You're testing real-IdP OIDC against a live provider
- You suspect a bug that only reproduces behind the reverse-proxy / shared Chrome stack

For those scenarios, follow the full dev-direct pipeline in the *Running the test suites* section above (Deploy → Pre-flight cleanup → Verify admin login → test_api.sh / Playwright dev-direct). For everything else, trust the CI run.

## Containerized harness — recurring footguns

The containerized harness is mostly self-contained, but it does mutate a few files in the working tree and a few categories of test interact in ways that are easy to miss until a full-suite run on pgsql or mysql surfaces them. Read this section before any release-gate run, and before any commit that includes `Simple-PHP-IPAM/config.php`.

### `bootstrap-app.sh` overwrites `config.php`

`bootstrap-app.sh sqlite|mysql|pgsql` rewrites `Simple-PHP-IPAM/config.php` to point at the per-driver test container, and saves the original to `Simple-PHP-IPAM/config.php.prebootstrap-backup`. `teardown-app.sh` restores the original — but only if you reach it cleanly. If the bootstrap is run again before teardown, or the teardown is interrupted, your working tree is left pointing at the test driver.

**Always run before any commit:**

```bash
git restore Simple-PHP-IPAM/config.php
rm -f Simple-PHP-IPAM/config.php.prebootstrap-backup
```

If you don't, your release-prep commit will land at `HEAD` with `config.php` pointing at `pgsql:host=ipam-pw-pgsql:5432;dbname=ipam_pw`, which is a real bug that ships in the bundle.

The release-workflow Phase 2 step 13 cleanup list now includes this; do it routinely.

### Tests that depend on cascade side-effects

Several specs were authored against the legacy `group=mfa` POST cascade behaviour: posting a settings group with a missing boolean key sets that key to `false` as a side effect of HTML form convention. This was load-bearing — `email_otp.spec.ts`'s `beforeEach` posted `group=mfa, k_mfa__email_otp_enabled=1` to enable Email OTP AND, by side effect, disable `mfa.totp_enabled` and `mfa.passkeys_enabled` so the test user's login routed to email OTP rather than a competing verify page.

When v3.18.0 (#756) added a per-key save path that does not cascade, those side-effect dependencies broke under parallel workers — the test user kept TOTP/passkey enrollments from earlier specs and login routed to the wrong verify page.

**When changing settings/auth POST semantics, sweep specs for sibling-bool re-assertion bandages** before assuming you've covered all callers:

```bash
grep -rn "k_mfa__\|group: 'mfa'" testing/playwright/tests/
```

If a spec posts `group=mfa` with only one key set, it is almost certainly relying on the cascade as a side-effect setup mechanism — verify by reading the test, then either keep the legacy POST shape if you really need cascade-clear behaviour, or convert to per-key POSTs that explicitly disable each sibling. Don't migrate blindly.

### Each spec needs a unique CIDR for fixtures it creates

`testing/playwright/fixtures/ipam.ts` exports a shared `TEST_CIDR2='10.88.0.0/24'` and `TEST_IP='10.88.0.10'` constant. Five specs (`addresses`, `contacts`, `exports`, `search`, `unassigned`) all create `10.88.0.0/24` and address `10.88.0.10` in their `beforeAll`. Under parallel workers (the Playwright default), these specs race: one spec's `afterAll` deletes the subnet while another spec is mid-test, and `subnetIdFor()` starts returning the wrong row.

**New specs that create subnets at module scope MUST claim a unique CIDR.** Convention: declare a local constant in the spec file, with a unique-to-the-file `10.NN.0.0/24`:

```ts
// testing/playwright/tests/my-new-feature.spec.ts
const MY_FEATURE_CIDR = '10.NN.0.0/24'; // pick NN unique to this file
const MY_FEATURE_IP   = '10.NN.0.10';
```

Currently in use:
- `10.88.0.0/24` — `addresses`, `contacts`, `exports`, `search` (legacy shared via `TEST_CIDR2`)
- `10.91.0.0/24` — `unassigned` (carved out in v3.18.0 #760)
- `10.93.0.0/24` — `custom-fields-csv`

Pick a fresh `NN` outside that set (`10.92`, `10.94`, `10.95`, …) for any new spec. The shared `TEST_CIDR2` constant is for legacy specs only — new specs declare a local constant. Do not migrate the four legacy specs off `TEST_CIDR2` unless you are also addressing the CIDR-race directly; partial migration leaves the same race in a smaller surface.

The `deleteSubnet()` fixture in `testing/playwright/fixtures/ipam.ts` is now a bounded loop (defence-in-depth against orphan rows when `UNIQUE(cidr, vrf_id)` doesn't dedupe NULL `vrf_id` per SQL standard), but the loop is not a substitute for unique-CIDR-per-spec — the loop only handles the same spec's own orphans, not cross-spec races.

### Dashboard is excluded from visual-regression — manual smoke check during release gate

`tests/visual-regression.spec.ts` no longer captures `dashboard.php`. Every widget on the dashboard reflects live DB state — security-warning banner (config / app_secret / HTTPS state), Top IPv4 Subnets by Usage (live address counts), Addresses by Site (per-site totals), Expiring Addresses (lease-date sensitive), Recent Activity (audit_log timestamps + ordering). Under the parallel Playwright harness, dozens of workers mutate the shared SQLite DB concurrently with the dashboard capture, so every widget renders differently between baseline-snapshot and re-run. Masking individual widgets via Playwright `mask` failed (variable widget heights leak diff pixels into the surrounding layout); DOM-removal via `page.evaluate` only solved one of five volatile sources. Restoring dashboard VR coverage requires a mutation-isolated capture path (separate worker pool with its own DB, or a serial pre-mutation capture step) — tracked as a v3.20.0 follow-up.

**Manual smoke check during every release gate.** Because the dashboard is a high-traffic page that can break in obvious ways (broken widget, broken theme, layout collapse on small viewports), the AI agent driving the release gate MUST manually verify it. Procedure:

1. After `bootstrap-app.sh sqlite` is up, use the Playwright MCP to navigate to `https://127.0.0.1:8443/dashboard.php` (after logging in as `demo`/`demo`) at viewport widths 1440, 1024, 768, and 375.
2. At each viewport, take a screenshot via `browser_take_screenshot` and **read it** (multimodal — the agent has visual access to PNGs).
3. Check for: missing widgets (top-subnets, by-site, expiring-addresses, recent-activity, metrics row), broken layout (overflow, collapsed columns, off-canvas elements), broken theme switch (light vs dark — toggle via the data-theme attribute), zero-data states (empty tables should show a graceful empty state, not a broken table head).
4. If anything looks wrong, treat it as a release-blocking failure and surface to the user before pushing.
5. Repeat the same pass for the **mysql** and **pgsql** drivers — dashboard rendering differences across engines are the most common visible regressions.

Skipping this manual check during a release run is a gate violation. The check is fast (~30 seconds per driver) and catches the failure mode that automated VR was supposed to but cannot reliably cover under parallelism.

### Visual-regression baselines are platform-suffixed and need refreshing on intentional UI/seed changes

`testing/playwright/tests/visual-regression.spec.ts-snapshots/` holds per-platform PNG baselines (`*-darwin.png`, `*-linux.png`). Filenames include the host OS because Chromium's text shaping and AA differ between CoreText (macOS) and HarfBuzz+FreeType (Linux Docker), producing low-amplitude pixel diffs even when the bundled `@font-face` woff2s render identically.

CI's `playwright.yml` job runs in Linux Docker, so it only validates and refreshes `*-linux.png` baselines. **Darwin baselines drift over time** — every release that grows `demo_seed.php` (more rows on the captured pages) or restructures a captured page will eventually push the rendered page taller than the committed darwin baseline, and a local-mac VR run shows a cluster of visual-regression failures with the signature: `Expected an image WIDTHpx by Hpx, received WIDTHpx by H'px` where `H' < H`, on `subnets-*` / `addresses-*` / `search-*` across the 4 viewports. (Dashboard is excluded from automated VR — see the section above.)

**This is not a regression.** It is platform-suffixed baseline maintenance debt.

**When you see this pattern during a local-mac gate run:**

1. Confirm that none of the touched files in your branch are UI / seed / template changes. (`git diff origin/main...HEAD --stat | grep -E 'subnets\.php|dashboard\.php|demo_seed\.php|page_header|app\.css'` — should be empty.)
2. If your branch is UI-clean, refresh the darwin baselines in a dedicated commit on your release branch:

   ```bash
   bash testing/playwright/bootstrap-app.sh sqlite
   (cd testing/playwright && \
     IPAM_BASE_URL=https://127.0.0.1:8443 IPAM_ADMIN_USER=demo IPAM_ADMIN_PASS=demo \
     npx playwright test visual-regression.spec.ts --update-snapshots)
   git add testing/playwright/tests/visual-regression.spec.ts-snapshots/
   git commit -m "test(vr): refresh darwin baselines for vX.Y.Z"
   ```

3. If your branch DID intentionally change UI / seed / templates, the same procedure applies but the commit message should reference the changes that justify the refresh.

Linux baselines are CI's responsibility; do not refresh them locally. If a `*-linux.png` baseline ever needs refreshing, do it in a dedicated PR run through the CI pipeline so the captures come from the canonical environment.
