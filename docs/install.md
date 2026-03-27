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
| **Web server** | Apache or LiteSpeed (`.htaccess` support required); Nginx requires manual translation of `.htaccess` rules |
| **SQLite** | 3.x via PDO SQLite |
| **HTTPS** | Required — the app redirects all HTTP traffic to HTTPS |
| **Writable `data/` dir** | The web server user needs read/write access to `data/` (and `data/tmp/` for CSV import) |

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

### Nginx

Nginx does not process `.htaccess` files. You must replicate the security rules manually. Key rules to translate:

- Deny access to `data/` and `*.sqlite` / `*.db` files
- Deny access to `*.sh`, `*.sql`, `*.json`, `*.tar.gz`, `*.zip`, `*.bundle.txt`, `SHA256SUMS`
- Pass all `.php` requests through PHP-FPM

---

## Step 5 — Verify the install

Open `https://ipam.example.com/` in a browser. You should be redirected to the login page. Log in with the bootstrap admin credentials from `config.php` and immediately change the password under **Password** in the navigation.

---

## First login

1. Navigate to your install URL — you will be redirected to the login page.
2. Log in with the credentials set in `config.php` under `bootstrap_admin` (default: `admin` / `ChangeMeNow!12345`).
3. **Immediately change the password** — go to **Password** in the top navigation.
4. Optionally create additional users under **Admin → Users**.

> The bootstrap admin account is only created if no users exist in the database. Once any user account exists, changes to `bootstrap_admin` in `config.php` have no effect.

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
