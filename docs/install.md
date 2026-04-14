# Installation Guide

## Contents

- [Requirements](#requirements)
- [Step 1 — Download a release](#step-1--download-a-release)
- [Step 2 — Set file permissions](#step-2--set-file-permissions)
- [Step 3 — Configure the application](#step-3--configure-the-application)
- [Step 4 — Configure your web server](#step-4--configure-your-web-server)
- [Step 5 — Verify the install](#step-5--verify-the-install)
- [First login](#first-login)
- [File permissions reference](#file-permissions-reference)

---

## Requirements

| Requirement | Details |
|---|---|
| **PHP** | 8.2 or later (8.3 recommended) |
| **PHP extensions** | `pdo`, `pdo_sqlite`, `openssl` |
| **Web server** | Apache, LiteSpeed, nginx, or Caddy (see [Step 4](#step-4--configure-your-web-server)) |
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
