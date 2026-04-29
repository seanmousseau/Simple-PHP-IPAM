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

**Manual dev-direct testing is only needed when:**
- You need `testing/scripts/test_api.sh` against a real deployment specifically (the containerized `DOCKER_CONTAINER=ipam-pw-test` path in CI covers regression on every PR via #451 — see the **test_api.sh** subsection above)
- You're verifying `timezone.spec.ts` (SSH-based remote config patching — skipped against containerized targets)
- You're testing real-IdP OIDC against a live provider
- You suspect a bug that only reproduces behind the reverse-proxy / shared Chrome stack

For those scenarios, follow the full dev-direct pipeline in the *Running the test suites* section above (Deploy → Pre-flight cleanup → Verify admin login → test_api.sh / Playwright dev-direct). For everything else, trust the CI run.
