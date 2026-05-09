# Deploy targets

> Canonical procedure for every deploy target the project ships to. Linked from `release-workflow.md` Phase 4 and `hotfix-release.md` step 7. Replaces the scattered guidance previously held only in auto-memory `feedback_*.md` files.

There are **seven** deploy targets, in three classes:

| Class | Targets | DB engine | Path style |
|---|---|---|---|
| Production-grade (host PHP) | `demo.simplephpipam.com`, `ipam.seanmousseau.com` | SQLite (demo), MySQL (prod) | OpenLiteSpeed vhost on `192.168.80.23` |
| Multi-engine testing | `ipam`, `ipam-maria`, `ipam-mysql`, `ipam-postgres` | SQLite, MariaDB, MySQL, PostgreSQL | Apache vhost in `dev_seanmousseau_com-apache-php-1` container on `192.168.80.15` |
| Marketing site | `simplephpipam.com` | n/a (WordPress) | OpenLiteSpeed vhost on `192.168.80.23` — see `marketing-site.md` |

---

## Pre-deploy invariants

These apply to every target:

1. **Always deploy via the release tarball + `upgrade.sh`.** Never raw-rsync the working tree. `rsync` honours `.gitignore` and silently skips `vendor/`, breaking every page that uses a Composer class (PHPMailer, TwoFactorAuth, lbuchs/WebAuthn, phpseclib).
2. **Bundle must already exist** in `releases/ipam-X.Y.Z/ipam-X.Y.Z.tar.gz` and be committed to the repo (regular release Phase 2 step 14, hotfix step 5).
3. **Verify the SHA256** locally before pushing to any target:
   ```bash
   shasum -a 256 -c releases/ipam-X.Y.Z/SHA256SUMS
   ```
4. **Deploy order matters when the release contains migrations.** Demo first (lowest blast radius), then the four testing instances, then prod. Catch migration issues on disposable targets before touching the prod DB.

---

## Class 1 — Production-grade hosts (`192.168.80.23`)

PHP runs on the host. DB is reachable from the host. `upgrade.sh` runs natively.

```bash
TAG=X.Y.Z
scp releases/ipam-${TAG}/ipam-${TAG}.tar.gz root@192.168.80.23:/tmp/
ssh root@192.168.80.23 "
  set -e
  rm -rf /tmp/ipam-${TAG} && mkdir /tmp/ipam-${TAG}
  tar -xzf /tmp/ipam-${TAG}.tar.gz -C /tmp/ipam-${TAG}
  cd /tmp/ipam-${TAG}/ipam-${TAG}

  # demo first (SQLite, lowest risk)
  bash upgrade.sh --yes /usr/local/lsws/vhosts/demo.simplephpipam.com/html
  chown -R nobody:nogroup /usr/local/lsws/vhosts/demo.simplephpipam.com/html

  # then prod (MySQL — verify migrations cleared on demo first)
  bash upgrade.sh --yes /usr/local/lsws/vhosts/ipam.seanmousseau.com/html
  chown -R nobody:nogroup /usr/local/lsws/vhosts/ipam.seanmousseau.com/html
"
```

OpenLiteSpeed runs PHP as `nobody:nogroup` — the chown is non-optional or `data/` writes fail.

### Verify

```bash
curl -sk https://demo.simplephpipam.com/login.php | grep -oE "app\.css\?v=${TAG}"
curl -sk https://ipam.seanmousseau.com/login.php  | grep -oE "app\.css\?v=${TAG}"
```

The `login.php` page is the cheapest post-deploy probe — unauthenticated, fast, and the `<link rel="stylesheet" href="assets/app.css?v=X.Y.Z.<mtime>">` cache-buster (emitted by `page_header()` in `lib.php`) carries the deployed version string. **`version.php` is not a probe** — it only defines the `IPAM_VERSION` constant and emits no body, so OpenLiteSpeed/Apache return 403 on a direct GET.

---

## Class 2 — Multi-engine testing instances (`192.168.80.15`)

The four instances live under `/opt/container_data/dev.seanmousseau.com/html/testing/{ipam,ipam-maria,ipam-mysql,ipam-postgres}/` on the host, mapped to `/var/www/html/testing/...` inside container `dev_seanmousseau_com-apache-php-1`.

**`upgrade.sh` must run inside the container** because DB hostnames (`mariadb`, `mysql`, `postgres`) are Docker-network-only and do not resolve from the host. The host then re-owns the files for Apache.

```bash
TAG=X.Y.Z
scp releases/ipam-${TAG}/ipam-${TAG}.tar.gz root@192.168.80.15:/tmp/
ssh root@192.168.80.15 "
  set -e
  docker cp /tmp/ipam-${TAG}.tar.gz dev_seanmousseau_com-apache-php-1:/tmp/
  docker exec dev_seanmousseau_com-apache-php-1 bash -c '
    set -e
    rm -rf /tmp/ipam-${TAG} && mkdir /tmp/ipam-${TAG}
    tar -xzf /tmp/ipam-${TAG}.tar.gz -C /tmp/ipam-${TAG}
    cd /tmp/ipam-${TAG}/ipam-${TAG}
    for dir in ipam ipam-maria ipam-mysql ipam-postgres; do
      bash upgrade.sh --yes /var/www/html/testing/\$dir
    done
  '
  chown -R www-data:www-data /opt/container_data/dev.seanmousseau.com/html/testing/
"
```

Apache inside the container runs as `www-data`. The trailing host-side chown is what keeps the SQLite WAL files writable when SSH'd into the host or when the container restarts.

### Verify (all four)

```bash
for inst in ipam ipam-maria ipam-mysql ipam-postgres; do
  printf "%-16s " "${inst}:"
  curl -sk -u "${IPAM_BASIC_USER}:${IPAM_BASIC_PASS}" \
    "https://dev-direct.seanmousseau.com:8343/testing/${inst}/login.php" \
    | grep -oE "app\.css\?v=${TAG}" | head -1
done
```

Each line should print `app.css?v=X.Y.Z` (the cache-buster carries the deployed version). A blank line or a `<br>`-laden 500 error means the migration failed for that engine — investigate before declaring the deploy complete. As above, `version.php` is not a probe.

### Migration verification

After upgrade, tail the audit log on each instance for any `migration.failed` entries — the `apply_migrations()` machinery records both successes and exceptions. SQLite WAL contention (a stale `cron.php` holding the lock) is the single most common cause of false-positive migration failures here; see `test-suites.md` footgun (2).

---

## Class 3 — Marketing site (`simplephpipam.com`)

See **`marketing-site.md`** — that doc is the source of truth. Summary only:

1. Update `website/front-page.php` (4 version slots: hero badge, hero download button, quickstart download button, quickstart `tar` command).
2. Add or refresh feature cards if the release introduces user-visible features.
3. Link any new `docs/<slug>.md` from the relevant feature card (the `feedback_marketing_docs_links.md` rule).
4. Deploy via `git push` on `simple-php-ipam-website`, then rsync to `192.168.80.23`, then chown to `nobody:nogroup`.
5. **Cache purge sequence is four steps, not one** — OPcache reset → wipe `cachedata/` → `wp rewrite flush` → `wp litespeed-purge all`. QUIC.cloud edge caches even 404s, so partial purges leave stale state.

---

## Cron wrapper script (per-target requirement, v3.27.1+)

Observability gap O2 from Pass A 2026-05-08: `cron.php` emits failures to STDERR; if the cron entry redirects `> /dev/null 2>&1`, every failure disappears. The encrypt-write-path bug failed silently for two weeks because of this exact gap.

**Every deploy target's cron wrapper must redirect to a log file**, not `/dev/null`. The recommended shape:

```bash
#!/bin/bash
# /root/scripts/ipam-cron.sh — invoked by /etc/crontab every 15 min
runuser --user nobody -- /usr/local/lsws/lsphp85/bin/php \
    /usr/local/lsws/vhosts/ipam.seanmousseau.com/html/cron.php \
    >> /var/log/ipam-cron.log 2>&1
```

Plus a `logrotate` config at `/etc/logrotate.d/ipam-cron`:

```
/var/log/ipam-cron.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
    create 0640 root adm
}
```

v3.27.1's other observability fixes (O1 `cron.php $fail()` → audit + error_log; O3 orchestrator preflight failed-row INSERT; O4 `backup.preflight_failed` audit verb; O5 overdue detector reads last-run status) cover the visibility gap from inside the application. **The cron wrapper redirect is the host-side belt-and-suspenders** — the application can write to error_log and audit_log even when STDERR is `/dev/null`'d, but operators reading the wrapper script for the first time should see logging by default.

**Verify after deploy:**

```bash
ssh root@192.168.80.23 'tail -f /var/log/ipam-cron.log' &
# wait for next */15 cron tick
# expect to see JSON lines like {"task":"tmp_cleanup",...}
```

If `/var/log/ipam-cron.log` is missing or empty after 30 min, the wrapper script needs the redirect added.

### Implementation on testing host (`192.168.80.15`) — 2026-05-09

The four testing instances share a single wrapper invoked every 15 min:

- **Path:** `root@192.168.80.15:/opt/container_data/dev.seanmousseau.com/scripts/ipam-cron.sh`
- **Schedule:** every 15 minutes (host crontab).
- **Log:** `root@192.168.80.15:/var/log/ipam-cron.log` (host-side, not inside the container).
- **Behaviour:** runs `cron.php` for each of the 4 testing instances in sequence — sqlite, mysql, mariadb, postgres — by `docker exec`ing the apache-php container as `www-data`. Each invocation prints a header line so the log is grep-able by instance.

Equivalent shape (current contents):

```bash
#!/bin/bash
echo "Running IPAM Cron on Test Instances..."
for inst in ipam ipam-mysql ipam-maria ipam-postgres; do
    echo "Running Cron on IPAM ${inst#ipam-}.."
    docker exec dev_seanmousseau_com-apache-php-1 \
        runuser --user www-data -- \
        php /var/www/html/testing/${inst}/cron.php
done
echo "IPAM Cron Run Completed."
```

The wrapper itself doesn't redirect to the log — the **host crontab entry** does, via `>> /var/log/ipam-cron.log 2>&1` on the cron line. That keeps the script reusable for ad-hoc invocations without forcing the redirect path.

**Tail it during a deploy verification:**

```bash
ssh root@192.168.80.15 'tail -f /var/log/ipam-cron.log'
```

Each tick produces ~4 instance blocks; on a healthy install you should see no `cron.task_failed` rows in any instance's audit log either.

### Implementation on prod host (`192.168.80.23`)

👤 **Operator follow-up.** The host crontab + wrapper for `demo.simplephpipam.com` and `ipam.seanmousseau.com` should match the shape above. Confirm both have the redirect to `/var/log/ipam-cron.log` (or equivalent) — the v3.27.1 silent-failure incident specifically blamed this gap on prod.

---

## Recurring footguns

| Footgun | Symptom | Fix |
|---|---|---|
| Raw rsync from working tree | `Class "PHPMailer\PHPMailer\PHPMailer" not found` on first SMTP page hit | Always tarball + `upgrade.sh` |
| Forgot the chown after rsync to `192.168.80.15` | `attempt to write a readonly database` from SQLite | `chown -R www-data:www-data` after every transfer |
| `upgrade.sh` run on host (not container) for testing instances | `SQLSTATE[HY000] [2002] php_network_getaddresses: getaddrinfo for mariadb failed` | Run inside container — Docker-internal hostnames don't resolve from host |
| Demo + prod deployed in parallel without checking demo first | Prod inherits a broken migration | Always demo → testing → prod, in that order |
| Marketing site updated without `wp rewrite flush` | `/docs/<new-slug>/` returns 404 even after `litespeed-purge all` | Run the full 4-step purge from `marketing-site.md` |
| `vendor/` ad-hoc rsync | Composer class not found, but only on some pages | Use the tarball; if you must rsync, sync `vendor/` separately and chown |
| `releases/ipam-X.Y.Z/` not committed before deploy | Hard to roll back; SHA mismatch on subsequent fetches | Commit the bundle in the same PR as the version bump |

---

## Rollback

`upgrade.sh` makes a timestamped backup of the target directory and the SQLite DB (when present) before applying. Roll back by stopping the vhost, swapping the directory back, and restoring the DB:

```bash
# on whichever target needs reverting
cd /usr/local/lsws/vhosts/<vhost>/        # or /var/www/html/testing/<inst>/ in the container
ls -1d html.bak.* | tail -1               # find the most recent backup
mv html html.failed && mv html.bak.<timestamp> html
chown -R nobody:nogroup html              # or www-data:www-data on the testing instances
```

For prod MySQL rollbacks (no on-disk SQLite to revert), use the `mysqldump` taken by Phase 4 deploy prep — the release-workflow doc covers this.

---

## Cross-references

- `release-workflow.md` Phase 4 — invokes this doc.
- `hotfix-release.md` step 7 — invokes this doc.
- `marketing-site.md` — full procedure for Class 3.
- `test-suites.md` — pre-deploy local gate, including the 7 dev-direct footguns.
- `lessons-learned.md` §3 — release/deploy lessons across versions.
