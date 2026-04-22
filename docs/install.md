# Installation Guide

## Contents

- [Requirements](#requirements)
- [Step 1 — Download a release](#step-1-download-a-release)
- [Step 2 — Set file permissions](#step-2-set-file-permissions)
- [Step 3 — Configure the application](#step-3-configure-the-application)
- [Step 4 — Configure your web server](#step-4-configure-your-web-server)
- [Step 5 — Verify the install](#step-5-verify-the-install)
- [First login](#first-login)
- [File permissions reference](#file-permissions-reference)

---

## Requirements

| Requirement | Details |
|---|---|
| **PHP** | 8.2 or later (8.3 recommended) |
| **PHP extensions** | `pdo`, `pdo_sqlite`, `openssl` |
| **Web server** | Apache, LiteSpeed, nginx, or Caddy (see [Step 4](#step-4-configure-your-web-server)) |
| **SQLite** | 3.x via PDO SQLite |
| **HTTPS** | Required — the app redirects all HTTP traffic to HTTPS |
| **Writable `data/` dir** | The web server user needs read/write access to `data/` (and `data/tmp/` for CSV import) |

There are **no additional dependencies** for v2.x features. VLANs, VRFs, contacts, tags, site hierarchy, address expiry, and MAC address fields are all managed within the same SQLite database and require no extra extensions or services.

**Optional integrations** (all opt-in via `config.php`):

| Feature | Prerequisite |
|---------|-------------|
| OIDC single sign-on | `openssl` extension (standard) + reachable OIDC provider |
| Login bot mitigation | Third-party CAPTCHA account (Turnstile, hCaptcha, reCAPTCHA, Friendly Captcha) |
| reCAPTCHA Enterprise | Google Cloud project + reCAPTCHA Enterprise API key |
| Utilization email alerts | Server-side MTA (`mail()` function; most PHP hosts provide this) |

### `upgrade.sh` dependencies (optional)

`bash`, `rsync`, `tar`, `stat`, `find`, `chmod`, `sort`, `sed`, `head`, `rm`

Optional: `php` CLI (for automatic DB migrations), `chown` (for ownership alignment).

### Upload limits for DB import

**⚙ Admin → Database Tools** lets you export and re-import the full SQLite database as a SQL dump. The bundled `Simple-PHP-IPAM/.htaccess` sets reasonable defaults (`upload_max_filesize 512M`, `post_max_size 520M`) that cover typical installs, but a dump with long audit history or a very large address space can exceed them.

If you hit a "413 — Uploaded file too large" error page, raise **both** values in one of the following places (both must be raised; `post_max_size` should be slightly larger than `upload_max_filesize`):

| Server type | Where to set |
|---|---|
| Apache + mod_php | `Simple-PHP-IPAM/.htaccess` — edit the `php_value` lines at the top |
| PHP-FPM | `/etc/php/*/fpm/pool.d/*.conf` via `php_admin_value[...]` |
| nginx / Caddy with PHP-FPM | Same as PHP-FPM above — `.htaccess` does not apply |
| CGI or other SAPI | System `php.ini` |

After raising the limits, restart the FPM pool or web server for the change to take effect. Apache + mod_php picks up `.htaccess` changes on the next request.

The application itself also enforces a soft cap via the `import_sql_max_mb` setting in `config.php` (default **200 MB**), which prevents db_tools.php from accepting a file larger than this value even if PHP would allow it. Raise that value too if you need to import a larger dump.

---

## Step 1 — Download a release

Download the latest release archive from the [Releases](../../../releases) page and extract it, or clone the repository:

```bash
# Option A — download a release bundle
tar -xzf ipam-0.11.tar.gz -C /var/www/

# Option B — clone the repository
git clone https://github.com/seanmousseau/Simple-PHP-IPAM.git /var/www/ipam
```

The application files live inside the `Simple-PHP-IPAM/` subdirectory of the repo. Point your web server document root at that directory.

> **About `vendor/` (v2.9.0+).** Starting in v2.9.0, release tarballs bundle a small set of pre-built PHP libraries under `Simple-PHP-IPAM/vendor/`. You do **not** need Composer on the target server — the libraries are pre-packaged. Do not edit anything inside `vendor/`; it will be overwritten on the next upgrade.
>
> The bundled `vendor/.htaccess` denies direct HTTP access to library source **on Apache and LiteSpeed only**. nginx and Caddy do not process `.htaccess` files — if you use either, add an explicit deny rule for `/vendor/` alongside the `/data/` rule shown in [Step 4](#step-4-configure-your-web-server).
>
> nginx:
> ```nginx
> location ^~ /vendor/ {
>     deny all;
>     return 403;
> }
> ```
>
> Caddy:
> ```caddyfile
> @vendor path /vendor/*
> respond @vendor 403
> ```

---

## Step 2 — Set file permissions

```bash
# Replace www-data with your web server user (e.g. apache, nginx, _www on macOS)
chown -R www-data:www-data /var/www/ipam
find /var/www/ipam -type f -name '*.php' -exec chmod 0644 {} \;
find /var/www/ipam -type d -exec chmod 0755 {} \;

# Restrict the data directory
chmod 0700 /var/www/ipam/data
```

The `data/` directory and the SQLite database file are created automatically on first request. If they already exist:

```bash
chmod 0700 /var/www/ipam/data
chmod 0600 /var/www/ipam/data/ipam.sqlite
```

---

## Step 3 — Configure the application

Copy or edit `config.php`. See the [Configuration guide](configuration.md) for all available settings.

At minimum, **change the default admin password** before the site receives any traffic.

### MySQL (experimental)

> ⚠️ **MySQL support is experimental in v2.10.0 and will be promoted to stable in v3.0.0.** The default driver is SQLite; you must opt in explicitly. Report bugs at the [GitHub tracker](https://github.com/seanmousseau/Simple-PHP-IPAM/issues). See the [Known issues](#mysql-known-issues) list below for current limitations.

**Requirements:**

- MySQL **8.0.29** or newer (the installer rejects earlier versions). 8.0.29 is the effective floor because the bootstrap path uses `CREATE TRIGGER IF NOT EXISTS`, which landed in that release.
- The default connection charset must be `utf8mb4`. `utf8mb4_general_ci` for the database default collation is fine — the application explicitly pins `utf8mb4_bin` on case-sensitive columns (usernames, CIDRs, hostnames, IP text, API key hashes) so cross-engine string comparison stays consistent with SQLite.
- An InnoDB-backed database. Every table in `schema.mysql.sql` declares `ENGINE=InnoDB` explicitly.
- A dedicated MySQL account with these privileges on the IPAM database: `CREATE`, `ALTER`, `INDEX`, `INSERT`, `UPDATE`, `DELETE`, `SELECT`, `REFERENCES`, `TRIGGER`. `DROP` is only needed if you intend to run `db_tools.php` import/export or uninstall the application — omit it otherwise to narrow the blast radius.

**Prepare the database:**

```sql
CREATE DATABASE ipam CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER 'ipam'@'localhost' IDENTIFIED BY 'a-strong-random-password';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, TRIGGER
  ON ipam.* TO 'ipam'@'localhost';
FLUSH PRIVILEGES;
```

**`config.php` stub for MySQL:**

```php
<?php
return [
    'db_driver' => 'mysql',
    'db_dsn'    => 'mysql:host=127.0.0.1;port=3306;dbname=ipam;charset=utf8mb4',
    'db_user'   => 'ipam',
    'db_pass'   => 'a-strong-random-password',

    // Bootstrap admin used on the very first page load only.
    'bootstrap_admin' => [
        'username' => 'admin',
        'password' => 'ChangeMeNow!12345',
    ],

    // Rest of the file is identical to the SQLite example.
    // session_idle_seconds, force_https, oidc, etc.
];
```

On the first request, the installer loads `schema.mysql.sql`, creates the bootstrap admin account, and pre-seeds `schema_migrations` with every historical version so subsequent migration runs are no-ops. An **admin UI beta banner** appears on every page while `db_driver=mysql` is active — you can dismiss it per browser, and it resurfaces whenever you upgrade the application.

<a id="mysql-known-issues"></a>
**Known issues and current limitations:**

- **Binary binding requires PDO `PARAM_LOB`.** The application already does this everywhere internally via `ipam_bind_binary()`. If you write a custom script that talks to `ipam.sqlite`-style columns (`ip_bin`, `network_bin`), bind with `PDO::PARAM_LOB` or your values will be string-escaped and corrupted.
- **Backups are SQLite-format only.** `db_tools.php` export produces a SQLite dump that can only be imported back into a SQLite install. Cross-engine migration (MySQL ↔ SQLite ↔ Postgres) lands in v3.0.0 via a dedicated `migrate_db.php` tool. For now, use native MySQL tools (`mysqldump`) for MySQL-to-MySQL backups.
- **CHECK constraints require MySQL 8.0.16+.** The schema declares CHECK constraints on enum columns (`role`, `theme`, `status`, `level`, `method`) and range columns (`vlan_id`, `tcp_port`, prefix delegation). Versions below 8.0.16 silently ignore them, leaving invariant enforcement to the application layer only.
- **Playwright MySQL matrix slot (v2.10.0+).** The CI matrix covers the full 329-test end-to-end suite against a containerized `mysql:8.0` service on every PR alongside the per-commit PHP QA + `test_api.sh` slot. Track progress at [#433](https://github.com/seanmousseau/Simple-PHP-IPAM/issues/433).

### MariaDB (experimental)

> ⚠️ **MariaDB support is experimental in v2.12.0 and will be promoted to stable in v3.0.0.** MariaDB uses the same `mysql` PDO driver and `db_driver => 'mysql'` config key as MySQL. The default driver is SQLite; you must opt in explicitly. Report bugs at the [GitHub tracker](https://github.com/seanmousseau/Simple-PHP-IPAM/issues).

**Requirements:**

- MariaDB **10.11** or newer (the installer rejects earlier versions). 10.11 is the minimum because it is the current LTS branch with `CREATE TRIGGER IF NOT EXISTS` support and full `utf8mb4` defaults.
- All MySQL requirements above apply: `utf8mb4` charset, InnoDB storage engine, identical privilege set.

**Prepare the database:**

```sql
CREATE DATABASE ipam CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER 'ipam'@'localhost' IDENTIFIED BY 'a-strong-random-password';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, TRIGGER
  ON ipam.* TO 'ipam'@'localhost';
FLUSH PRIVILEGES;
```

**`config.php` stub for MariaDB:**

```php
<?php
return [
    'db_driver' => 'mysql',
    'db_dsn'    => 'mysql:host=127.0.0.1;port=3306;dbname=ipam;charset=utf8mb4',
    'db_user'   => 'ipam',
    'db_pass'   => 'a-strong-random-password',

    'bootstrap_admin' => [
        'username' => 'admin',
        'password' => 'ChangeMeNow!12345',
    ],
];
```

Configuration is identical to MySQL — MariaDB speaks the same wire protocol and uses the same PDO `mysql` driver. The application auto-detects MariaDB from the server version string and enforces the 10.11+ floor.

**Known issues:** Same as MySQL above. Additionally:

- **MariaDB version strings contain a `5.5.5-` prefix** in some deployments (historical protocol compatibility lie). The application strips this prefix automatically — no user action needed.

### PostgreSQL (experimental)

> ⚠️ **PostgreSQL support is experimental in v2.11.0 and will be promoted to stable in v3.0.0.** The default driver is SQLite; you must opt in explicitly. Report bugs at the [GitHub tracker](https://github.com/seanmousseau/Simple-PHP-IPAM/issues). See the [Known issues](#postgresql-known-issues) list below for current limitations.

**Requirements:**

- PostgreSQL **14** or newer (the installer rejects earlier versions). 14 is the effective floor because the bootstrap path uses `CREATE OR REPLACE TRIGGER`, which landed in that release and removes the drop-and-recreate race window from the append-only self-heal path.
- A cluster initialised with a **deterministic collation**. Standard `initdb` defaults (`C`, `POSIX`, `en_US.UTF-8`, `en_US.utf8`) all qualify. ICU non-deterministic collations are **not** supported — the schema pins `COLLATE "C"` on byte-comparable columns (usernames, CIDRs, IP text, hashes, settings keys) so exact-equality, uniqueness, and ordering stay deterministic regardless of the cluster's default.
- `pdo_pgsql` PHP extension (Debian/Ubuntu: `apt install php-pgsql`; RHEL/Rocky: `dnf install php-pgsql`). Verify with `php -m | grep pdo_pgsql`.
- A dedicated Postgres role with **`CONNECT`** on the database, **`CREATE`** on the `public` schema (for first-run schema install), and standard DML (`SELECT`, `INSERT`, `UPDATE`, `DELETE`) on the application tables. Once the schema is installed you can revoke `CREATE` if you prefer a tighter lockdown — migrations are stamped on first run so v2.11.0 does not need `CREATE` at runtime.

**Prepare the database:**

```sql
CREATE DATABASE ipam;
CREATE USER ipam WITH PASSWORD 'a-strong-random-password';
GRANT CONNECT ON DATABASE ipam TO ipam;
\c ipam
GRANT CREATE, USAGE ON SCHEMA public TO ipam;
```

After the first request (which creates every table), you can optionally tighten the grants:

```sql
REVOKE CREATE ON SCHEMA public FROM ipam;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO ipam;
GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO ipam;
```

**`config.php` stub for PostgreSQL:**

```php
<?php
return [
    'db_driver' => 'pgsql',
    'db_dsn'    => 'pgsql:host=127.0.0.1;port=5432;dbname=ipam',
    'db_user'   => 'ipam',
    'db_pass'   => 'a-strong-random-password',

    // Bootstrap admin used on the very first page load only.
    'bootstrap_admin' => [
        'username' => 'admin',
        'password' => 'ChangeMeNow!12345',
    ],

    // Rest of the file is identical to the SQLite example.
    // session_idle_seconds, force_https, oidc, etc.
];
```

On the first request, the installer loads `schema.pgsql.sql`, creates the bootstrap admin account, and pre-seeds `schema_migrations` with every historical version so subsequent migration runs are no-ops. An **admin UI beta banner** appears on every page while `db_driver=pgsql` is active — you can dismiss it per browser, and it resurfaces whenever you upgrade the application.

<a id="postgresql-known-issues"></a>
**Known issues and current limitations:**

- **BYTEA columns are returned as PHP streams.** pdo_pgsql returns `ip_bin` and `network_bin` as stream resources instead of byte strings. The application auto-unwraps this at the PDO layer via a `PgsqlStatement` subclass installed on the pgsql connection, so every built-in call site works transparently. If you write a **custom** PHP script that talks to an IPAM Postgres database, remember to call `stream_get_contents()` on any BYTEA column you read — or attach the `PgsqlStatement` class yourself via `$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PgsqlStatement::class])`.
- **Binary binding requires PDO `PARAM_LOB`.** Same rule as MySQL: the application already does this everywhere via `ipam_bind_binary()`. Custom scripts inserting into `ip_bin` / `network_bin` must bind with `PDO::PARAM_LOB` or Postgres will reject the byte string with `invalid byte sequence for encoding "UTF8"` the moment a non-ASCII byte shows up.
- **`GENERATED BY DEFAULT AS IDENTITY` sequences.** The schema uses modern IDENTITY columns (PG10+). Explicit `INSERT ... (id, ...) VALUES (N, ...)` is accepted, but does **not** advance the backing sequence — subsequent implicit inserts would collide at id=1. The demo seed and test fixtures handle this by calling `setval(pg_get_serial_sequence('t', 'id'), MAX(id))` after bulk-inserting explicit ids. Custom scripts doing the same pattern must do the equivalent.
- **Backups are SQLite-format only.** `db_tools.php` export produces a SQLite dump that can only be imported back into a SQLite install. Cross-engine migration (SQLite ↔ MySQL ↔ Postgres) lands in v3.0.0 via a dedicated `migrate_db.php` tool. For now, use native Postgres tools (`pg_dump` / `pg_restore`) for Postgres-to-Postgres backups.
- **No per-connection FK enforcement toggle.** Unlike SQLite (`PRAGMA foreign_keys`) and MySQL (`SET FOREIGN_KEY_CHECKS`), Postgres has no session-level FK switch. The application does not need to disable FKs for normal operation — the v2.1.0-vrfs table-rebuild migration that forced the SQLite PRAGMA-off dance is pre-stamped on fresh Postgres installs and never re-runs, so the schema stays consistent under `ON DELETE CASCADE` / `SET NULL` / `RESTRICT`.
- **Playwright PostgreSQL matrix slot (v2.11.0+).** The CI matrix covers the full ~330-test end-to-end suite against a containerized `postgres:14-alpine` service on every PR alongside the per-commit PHP QA + `test_api.sh` slot. Track progress at [#388](https://github.com/seanmousseau/Simple-PHP-IPAM/issues/388).

---

## Step 4 — Configure your web server

### Apache (virtual host example)

```apache
<VirtualHost *:443>
    ServerName ipam.example.com
    DocumentRoot /var/www/ipam

    SSLEngine on
    SSLCertificateFile    /etc/ssl/certs/ipam.crt
    SSLCertificateKeyFile /etc/ssl/private/ipam.key

    <Directory /var/www/ipam>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>

# Redirect HTTP → HTTPS
<VirtualHost *:80>
    ServerName ipam.example.com
    Redirect permanent / https://ipam.example.com/
</VirtualHost>
```

Ensure `mod_rewrite` and `mod_headers` are enabled:

```bash
a2enmod rewrite headers
systemctl reload apache2
```

### nginx

nginx does not process `.htaccess` files. Use a server block like the following:

```nginx
server {
    listen 80;
    server_name ipam.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name ipam.example.com;

    ssl_certificate     /etc/ssl/certs/ipam.crt;
    ssl_certificate_key /etc/ssl/private/ipam.key;

    root /var/www/ipam;
    index index.php;

    autoindex off;

    # Security headers
    add_header X-Frame-Options           "SAMEORIGIN"             always;
    add_header X-Content-Type-Options    "nosniff"                always;
    add_header X-XSS-Protection          "1; mode=block"          always;
    add_header Referrer-Policy           "strict-origin-when-cross-origin" always;
    add_header Permissions-Policy        "interest-cohort=()"     always;
    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload" always;

    # Block access to the data directory (DB, backups, temp files)
    location ^~ /data/ {
        deny all;
        return 403;
    }

    # Block sensitive file extensions
    location ~* \.(sqlite|sqlite3|db|sql|bak|gz|tar|zip|sh|json|bundle\.txt)$ {
        deny all;
        return 403;
    }

    # Block SHA256SUMS and other checksum files
    location ~* ^/(SHA256SUMS|CHANGELOG\.md|README\.md)$ {
        deny all;
        return 403;
    }

    # PHP via FPM
    location ~ \.php$ {
        include        fastcgi_params;
        fastcgi_pass   unix:/run/php/php8.2-fpm.sock;  # adjust socket path
        fastcgi_param  SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location / {
        try_files $uri $uri/ =404;
    }
}
```

### Caddy

```caddyfile
ipam.example.com {
    root * /var/www/ipam
    php_fastcgi unix//run/php/php8.2-fpm.sock  # adjust socket path

    # Block access to data directory
    @data path /data/*
    respond @data 403

    # Block sensitive file extensions
    @sensitive {
        path_regexp \.(sqlite|sqlite3|db|sql|bak|gz|tar|zip|sh|json)$
        path /SHA256SUMS
    }
    respond @sensitive 403

    # Security headers
    header {
        X-Frame-Options           "SAMEORIGIN"
        X-Content-Type-Options    "nosniff"
        X-XSS-Protection          "1; mode=block"
        Referrer-Policy           "strict-origin-when-cross-origin"
        Permissions-Policy        "interest-cohort=()"
        Strict-Transport-Security "max-age=63072000; includeSubDomains; preload"
    }

    file_server
}
```

### OpenLiteSpeed

OpenLiteSpeed honours `.htaccess` rewrite rules for the Example vhost out of the box — the `Simple-PHP-IPAM/.htaccess` that ships with the release tarball works unmodified on OLS. All the deny rules (force-HTTPS, block sensitive files, block `/data/`, block `/dialects/`, block `/vendor/`) fire correctly under the OLS 1.8+ rewrite engine.

**One OLS-specific gotcha to be aware of** (handled automatically by the shipped `.htaccess`): OLS's lsphp handler dispatches PHP files **before** a subdirectory-level `.htaccess` rewrite rule fires. A per-directory `dialects/.htaccess` deny rule is therefore insufficient to block direct execution of `.php` files under that path — OLS will execute the file anyway. The v2.11.0 `.htaccess` closes this gap by adding **root-level** `RewriteRule ^dialects(/|$) - [F,L]` and `^vendor(/|$) - [F,L]` entries. Root-level rewrites run before handler dispatch on both Apache and OLS, so the same rule set protects both web servers. You do not need to configure anything in the OLS WebAdmin Console for this to work.

**Two additional OLS-specific settings worth verifying** in the WebAdmin Console when you first deploy:

1. **Auto Index** → **Off**. The `Options -Indexes` directive in the shipped `.htaccess` is Apache-specific — OLS ignores it. Disable directory listing per-vhost in the OLS WebAdmin (Virtual Host → Basic → Index Files → Auto Index → No).
2. **Rewrite Engine** → **Enabled**. This is the default for the stock Example vhost but double-check it on a custom vhost (Virtual Host → Rewrite → Rewrite Control → Enable Rewrite → Yes).

Regression protection: `.github/workflows/playwright.yml` includes a containerized OpenLiteSpeed matrix slot (`htaccess-subset` job, `matrix.webserver = openlitespeed`) that boots a stock `litespeedtech/openlitespeed:latest` image with the Simple-PHP-IPAM tree mounted at the Example vhost docroot and runs the same `tests/htaccess.spec.ts` assertions the Apache slot uses. Both slots must be green on every PR — any rewrite-rule regression that breaks OLS but keeps working on Apache (or vice versa) lights up immediately.

---

## Step 5 — Verify the install

Open `https://ipam.example.com/` in a browser. You should be redirected to the login page. Log in with the bootstrap admin credentials from `config.php` and immediately change the password under **Password** in the navigation.

---

## Step 6 — Register the cron runner

Simple PHP IPAM ships with a unified CLI cron runner that handles all periodic tasks in a single entry: temp file cleanup, audit log pruning, address history pruning, subnet utilisation alerts, database backups, scheduled network scans, and (when demo mode is enabled) the demo database reset.

Add this to the web server user's crontab (for example `www-data`):

```cron
*/15 * * * * php /path/to/Simple-PHP-IPAM/cron.php >> /path/to/Simple-PHP-IPAM/data/cron.log 2>&1
```

The example points the log file at the application's own `data/` directory, which is already writable by the web server user. If you prefer a system log path such as `/var/log/ipam-cron.log`, **pre-create and chown the file before enabling the cron** — most web server users cannot create files under `/var/log` themselves and the job will silently fail to run:

```bash
sudo touch /var/log/ipam-cron.log
sudo chown www-data:www-data /var/log/ipam-cron.log
```

Each task throttles itself internally and is skipped cleanly when not yet due, so a 15-minute cadence is safe. See the **Cron runner** section in [configuration.md](configuration.md) for the full task table, per-task config keys, and troubleshooting.

`cron.php` refuses to run under a web SAPI (returns HTTP 403); it is CLI-only by design.

---

## First login

1. Navigate to your install URL — you will be redirected to the login page.
2. Log in with the credentials set in `config.php` under `bootstrap_admin` (default: `admin` / `ChangeMeNow!12345`).
3. **Immediately change the password** — go to **Password** in the top navigation.
4. Optionally create additional users under **Admin → Users**.

> The bootstrap admin account is only created if no users exist in the database. Once any user account exists, changes to `bootstrap_admin` in `config.php` have no effect.

Starting in **v2.6.0**, most operational settings — branding, timezone, alerting, update checker, OIDC — live in the database and are editable from the admin UI under **⚙ Admin → Settings**. `config.php` is still used for bootstrap values (`db_path`, `session_name`, proxy/HTTPS), and still works as a fallback for the database-backed settings during the v2.6 → v3.0 transition. See [docs/configuration.md](configuration.md) for the full model.

---

## File permissions reference

| Path | Recommended permissions | Notes |
|---|---|---|
| Application files (`*.php`, `*.sql`, etc.) | `0644` | Web server reads; world-readable is fine |
| Directories (except `data/`) | `0755` | Standard web directory permissions |
| `data/` | `0700` | Web server user only — keeps DB out of reach of other users |
| `data/ipam.sqlite` | `0600` | Web server user only |
| `data/ipam.sqlite-wal` / `-shm` | `0600` | Created automatically by SQLite WAL mode |
| `data/tmp/` | `0700` | Created automatically; holds CSV uploads and import plans |
| `config.php` | `0640` | Web server readable, not world-readable |
| `upgrade.sh` | `0755` | Executable; removed from webroot by default after upgrade |
