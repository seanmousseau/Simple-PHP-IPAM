# Page inventory

> Reference table of every PHP page in the application, its auth requirement, role, and a short description. Linked from CLAUDE.md.
>
> When you add a new page, update this table and the matching nav block.

| File | Auth required | Role | Description |
|------|--------------|------|-------------|
| `login.php` | — | — | Local login form + OIDC button |
| `logout.php` | yes | any | Destroys session |
| `dashboard.php` | yes | any | Utilization summary, recent audit |
| `subnets.php` | yes | any/write | Subnet CRUD, hierarchy view |
| `addresses.php` | yes | any/write | Address CRUD per subnet |
| `search.php` | yes | any | Global IP/hostname/owner search |
| `audit.php` | yes | any | Audit log viewer |
| `unassigned.php` | yes | any | IPv4/IPv6 unassigned host tracker |
| `bulk_update.php` | yes | write | Bulk address update/delete |
| `dhcp_pool.php` | yes | write | DHCP pool reservation tool |
| `import_csv.php` | yes | admin | CSV import wizard |
| `sites.php` | yes | admin | Site management (supports parent site / region hierarchy) |
| `vlans.php` | yes | admin | VLAN management (first-class VLAN objects, linked to subnets via `vlan_fk`); includes VLAN ranges section (v2.4.0) |
| `vrfs.php` | yes | admin | VRF management (Virtual Routing and Forwarding; admin CRUD); includes BGP attributes (ASN, RT import/export, enforce_unique) (v2.4.0) |
| `aggregates.php` | yes | admin | Aggregate/supernet CRUD — RIR-assigned blocks, IPv4 and IPv6 (v2.4.0) |
| `pd_pools.php` | yes | admin | IPv6 Prefix Delegation pool management (RFC 3633); per-pool delegation list with subscriber linking and expiry (v2.4.0) |
| `contacts.php` | yes | admin | Contact management (first-class contact records linked to addresses via `owner_contact_id`) |
| `tags.php` | yes | admin | Tag management (colour-coded tags attached to subnets and addresses) |
| `custom_fields.php` | yes | admin | Custom field definitions: create/edit/delete per-entity key/value metadata fields for subnets and addresses (v3.5.0) |
| `users.php` | yes | admin | User management |
| `api_keys.php` | yes | admin | REST API key management |
| `webhooks.php` | yes | admin | Outbound webhook management: create, edit, toggle, delete, test-fire, delivery log, retry (v3.3.0) |
| `change_password.php` | yes | any | Account page: self-service password change, timezone preference, email change with verification (nav label: "Account") |
| `totp_enroll.php` | yes | any | TOTP 2FA enrollment wizard (3 steps: start → QR scan → backup codes); requires `app_secret` in config.php (v3.6.0) |
| `totp_verify.php` | partial | any | Mid-login TOTP challenge; reached only via `$_SESSION['totp_pending_uid']` set by `login.php`; redirects to login if session key absent (v3.6.0) |
| `email_otp_verify.php` | partial | any | Mid-login Email OTP challenge; reached only via `$_SESSION['email_otp_pending_uid']` set by `login.php`; redirects to login if session key absent (v3.14.0) |
| `passkey_verify.php` | partial | any | Mid-login passkey (WebAuthn) challenge; reached only via `$_SESSION['passkey_pending_uid']` set by `login.php`; redirects to login if session key absent (v3.15.0) |
| `passkey_register.php` | yes | any | AJAX POST endpoint for WebAuthn credential registration ceremony; returns 405 on GET (v3.15.0) |
| `forgot_password.php` | — | — | Email-based password recovery: submit username/email, sends reset link |
| `reset_password.php` | — | — | Consumes password reset token, shows new-password form |
| `devices.php` | yes | admin | Device and interface management (CRUD, filter by type/site, interface sub-section) |
| `address_history.php` | yes | any | Per-address change history |
| `oidc_login.php` | — | — | Initiates OIDC auth flow (PKCE) |
| `oidc_callback.php` | — | — | Handles OIDC redirect callback |
| `api.php` | — | — | Stateless read-only REST API |
| `migrate.php` | CLI | — | Applies pending DB migrations |
| `tmp_cleanup.php` | CLI | — | Deletes stale temp files |
| `demo_reset.php` | CLI | — | Resets demo database to seed data (nightly cron) |
| `demo_seed.php` | CLI | — | Seeds demo data into database |
| `export_addresses.php` | yes | any/write | CSV export: single subnet (any role) or all subnets cross-subnet (write role) |
| `export_dns.php` | yes | any | BIND-format DNS zone file export (A/AAAA + PTR) for a single subnet (v2.4.0) |
| `export_dhcp.php` | yes | write | DHCP config export: ISC `dhcpd.conf` or Kea 2.x JSON; supports `?format=dhcpd|kea`, `?subnets=N,N,N`, `?preview=1`; audited as `export.dhcp`/`export.kea` (v3.4.0) |
| `ping_host.php` | yes | any | AJAX POST: ICMP probe a single address by ID; returns latency or down status (v2.4.0) |
| `cron.php` | CLI | — | Unified housekeeping + scanning cron runner: temp cleanup, audit pruning, history pruning, utilisation alerts, DB backup, network scanning of all due scan_schedules (v2.3.0) |
| `export_address_history.php` | yes | any | CSV export: per-address change history |
| `export_subnet_utilization.php` | yes | any | CSV export: subnet utilization summary across all subnets |
| export_audit.php, export_search.php, export_subnets.php, export_unassigned.php, export_import_report.php | yes | any | Other CSV export endpoints |
| `smtp_test.php` | yes | admin | AJAX POST (CSRF-required): sends a test email via configured SMTP/mail() to the logged-in admin's email; returns JSON `{ok, message, transport}` |
| `index.php` | — | — | Redirects to dashboard (if logged in) or login |
| `status.php` | — | — | Health check JSON endpoint (`{"status":"ok"}`) for load balancers/uptime monitors |
| `user_preference.php` | yes | any | AJAX POST/GET (CSRF-required for POST): per-user preference store (ADR-002). Key allowlist `{theme}`; persists to the `user_preferences` table. Supersedes the former `set_theme.php`. |
| `db_tools.php` | yes | admin | SQL export/import and manual WAL backup (SQLite only). The backup half was migrated to `backup_admin.php` in v3.21.0; this page is no longer in the sidebar but remains reachable by direct URL for the data-export half. |
| `demo_gate.php` | — | — | Demo mode bot challenge gate (pre-login) |
| `backup_admin.php` | yes | admin | **Unified Backup & Restore admin surface (v3.21.0).** Five tabs via `?tab=backup\|restore\|destinations\|notifications\|history`. Replaces six legacy pages (`destinations.php`, `backup_history.php`, `remote_backups.php`, `restore_web.php`, `backups.php`, and the backup half of `db_tools.php`) — those URLs still 301 here for legacy bookmarks. |
| `reports.php` | yes | admin | Utilization history report: time-series of address counts per subnet with subnet filter and CSV export |
| `export_utilization_history.php` | yes | any | CSV export: utilization history snapshots |
| `health.php` | yes | admin | Operational health dashboard: DB metrics, backup status, scanning, webhooks, auth/security, system info; 60s cache; `?nocache=1` bypass (v3.7.0) |
| `destinations.php` | yes | admin | **Retired in v3.21.0** — 301 to `backup_admin.php?tab=destinations` for legacy bookmarks. Original v3.17.0 page is gone. |
| `backup_history.php` | yes | admin | **Retired in v3.21.0** — 301 to `backup_admin.php?tab=history` for legacy bookmarks. |
| `remote_backups.php` | yes | admin | **Retired in v3.21.0** — folded into `backup_admin.php?tab=history` (per-row Download/Verify/Delete actions). |
| `restore_web.php` | yes | admin | **Retired in v3.21.0** — 301 to `backup_admin.php?tab=restore` for legacy bookmarks. |
| `backups.php` | yes | admin | **Retired in v3.21.0** — 301 to `backup_admin.php` (was: 301 to `db_tools.php` since v3.9.0). |
| `download_remote_backup.php` | yes | admin | AJAX/file endpoint: downloads + decrypts a remote backup; signed staged-file token for handoff to the Restore tab (v3.17.0) |
| `test_destination.php` | yes | admin | AJAX endpoint: invokes BackupClient `test()` for a destination id, returns JSON `{ok, message, latency_ms}` (v3.17.0) |
| `run_backup_now.php` | yes | admin | AJAX endpoint behind the **Backup → Run backup now** button. Synchronously runs `ipam_backup_run_for_destination()` for a destination id, returns JSON (v3.17.0; refactored from class to procedural in v3.18.0). |
| `backup.php` | CLI | — | CLI-only database backup runner; returns 403 on web access (v3.7.0) |
| `restore.php` | CLI | — | CLI-only database restore with --dry-run, --force; returns 403 on web access (v3.7.0) |

