PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  username      TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  role          TEXT NOT NULL DEFAULT 'admin',     -- admin|readonly
  is_active     INTEGER NOT NULL DEFAULT 1,        -- 1 active, 0 disabled
  name          TEXT NOT NULL DEFAULT '',
  email         TEXT NOT NULL DEFAULT '',
  oidc_sub             TEXT,                        -- IdP subject claim (unique when set)
  last_login_at        TEXT,
  password_changed_at  TEXT,                        -- updated on every local password change; NULL for SSO-only accounts
  theme         TEXT NOT NULL DEFAULT 'auto',       -- persisted UI theme: auto|light|dark
  timezone      TEXT,                                -- per-user display timezone; NULL = use app default
  pending_email            TEXT,                   -- v3.2.0: unverified email change pending confirmation
  pending_email_token_hash TEXT,                   -- v3.2.0: SHA-256 hash of the email-verification token
  pending_email_expires_at TEXT,                   -- v3.2.0: expiry datetime for the pending email token
  totp_secret_enc          TEXT,                   -- v3.6.0: AES-256-CBC encrypted TOTP secret; NULL = not enrolled
  totp_enabled             INTEGER NOT NULL DEFAULT 0,  -- v3.6.0: 1 when 2FA is active
  failed_auth_count        INTEGER NOT NULL DEFAULT 0,  -- v3.6.0: cumulative 2FA failure count
  locked_until             TEXT,                   -- v3.6.0: persistent lockout expiry datetime; NULL = not locked
  lock_reason              TEXT,                   -- v3.6.0: failed_login|failed_2fa|admin|NULL
  email_otp_enabled        INTEGER NOT NULL DEFAULT 0,  -- v3.14.0: 1 when Email OTP 2FA is active
  email_otp_hash           TEXT,                         -- v3.14.0: bcrypt hash of issued OTP code
  email_otp_expires_at     TEXT,                         -- v3.14.0: ISO datetime expiry of current OTP
  email_otp_attempts       INTEGER NOT NULL DEFAULT 0,  -- v3.14.0: failed attempts against current OTP token
  preferred_mfa_method     TEXT,                         -- v3.16.0: NULL | 'totp' | 'email_otp' | 'passkey' — login dispatches to this method first when set
  created_at    TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at    TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Enforce uniqueness of oidc_sub only when it is not NULL
CREATE UNIQUE INDEX IF NOT EXISTS idx_users_oidc_sub
  ON users(oidc_sub) WHERE oidc_sub IS NOT NULL;

CREATE TRIGGER IF NOT EXISTS users_updated_at
AFTER UPDATE ON users
FOR EACH ROW
BEGIN
  UPDATE users SET updated_at = datetime('now') WHERE id = OLD.id;
END;

CREATE TABLE IF NOT EXISTS sites (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  name        TEXT NOT NULL UNIQUE,
  description TEXT NOT NULL DEFAULT '',
  parent_id   INTEGER REFERENCES sites(id) ON DELETE SET NULL,  -- v2.0.0: region/parent hierarchy
  created_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_sites_parent_id ON sites(parent_id);

-- v2.1.0: VRFs (defined before subnets for FK clarity)
CREATE TABLE IF NOT EXISTS vrfs (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  name           TEXT NOT NULL UNIQUE,
  description    TEXT NOT NULL DEFAULT '',
  rd             TEXT NOT NULL DEFAULT '',             -- Route Distinguisher, free-form (e.g. "65000:1")
  asn            TEXT NOT NULL DEFAULT '',             -- v2.4.0 #2.4.0-vrf-bgp: BGP ASN attribute
  rt_import      TEXT NOT NULL DEFAULT '',             -- v2.4.0 #2.4.0-vrf-bgp: Route Target import list
  rt_export      TEXT NOT NULL DEFAULT '',             -- v2.4.0 #2.4.0-vrf-bgp: Route Target export list
  enforce_unique INTEGER NOT NULL DEFAULT 1,           -- v2.4.0 #2.4.0-vrf-bgp: enforce VRF CIDR uniqueness
  created_at     TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at     TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_vrfs_name ON vrfs(name);

CREATE TRIGGER IF NOT EXISTS vrfs_updated_at
AFTER UPDATE ON vrfs
FOR EACH ROW
BEGIN
  UPDATE vrfs SET updated_at = datetime('now') WHERE id = OLD.id;
END;

CREATE TABLE IF NOT EXISTS subnets (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  cidr        TEXT NOT NULL,
  ip_version  INTEGER NOT NULL,
  network     TEXT NOT NULL,
  network_bin BLOB NOT NULL,
  prefix      INTEGER NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  notes       TEXT NOT NULL DEFAULT '',             -- v2.8.0 #316: long-form operational notes (textarea)
  site_id     INTEGER REFERENCES sites(id) ON DELETE SET NULL, -- v2.11.0 #409: FK backfilled; schema.mysql.sql and schema.pgsql.sql have enforced this since v2.10.0
  vlan_id     INTEGER,                              -- 802.1Q VLAN ID (1–4094), legacy integer field
  vlan_fk     INTEGER REFERENCES vlans(id) ON DELETE SET NULL,  -- v2.0.0: FK to vlans table
  vrf_id          INTEGER REFERENCES vrfs(id) ON DELETE RESTRICT,   -- v2.1.0: FK to vrfs table; RESTRICT prevents orphaned subnets moving to global VRF
  alerts_enabled  INTEGER NOT NULL DEFAULT 1,                       -- v3.1.0 #457: 0 disables utilization alerts for this subnet
  dhcp_routers     TEXT,                                             -- v3.4.0 #402: comma-sep gateway IPs → dhcpd option routers
  dhcp_dns_servers TEXT,                                             -- v3.4.0 #402: comma-sep DNS IPs → option domain-name-servers
  dhcp_domain_name TEXT,                                             -- v3.4.0 #402: domain name → option domain-name
  dhcp_lease_default INTEGER,                                        -- v3.4.0 #402: seconds → default-lease-time
  dhcp_lease_max   INTEGER,                                          -- v3.4.0 #402: seconds → max-lease-time
  dhcp_next_server TEXT,                                             -- v3.4.0 #402: TFTP server IP → next-server (PXE)
  dhcp_boot_filename TEXT,                                           -- v3.4.0 #402: boot file → filename (PXE)
  custom_fields TEXT NOT NULL DEFAULT '{}',                          -- v3.5.0 #313/#595: admin-defined key/value metadata (JSON-in-row)
  created_at  TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at  TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(cidr, vrf_id)
);

CREATE INDEX IF NOT EXISTS idx_subnets_ver_prefix_netbin ON subnets(ip_version, prefix, network_bin);
CREATE INDEX IF NOT EXISTS idx_subnets_site_id ON subnets(site_id);
CREATE INDEX IF NOT EXISTS idx_subnets_vrf_id ON subnets(vrf_id);

CREATE TRIGGER IF NOT EXISTS subnets_updated_at
AFTER UPDATE ON subnets
FOR EACH ROW
BEGIN
  UPDATE subnets SET updated_at = datetime('now') WHERE id = OLD.id;
END;

-- v2.1.0: Contacts as first-class objects (defined before addresses for FK clarity)
CREATE TABLE IF NOT EXISTS contacts (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  name       TEXT NOT NULL,
  email      TEXT NOT NULL DEFAULT '',
  phone      TEXT NOT NULL DEFAULT '',
  org        TEXT NOT NULL DEFAULT '',
  note       TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_contacts_name ON contacts(name);
CREATE INDEX IF NOT EXISTS idx_contacts_email ON contacts(email);

CREATE TRIGGER IF NOT EXISTS contacts_updated_at
AFTER UPDATE ON contacts
FOR EACH ROW
BEGIN
  UPDATE contacts SET updated_at = datetime('now') WHERE id = OLD.id;
END;

CREATE TABLE IF NOT EXISTS addresses (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  subnet_id        INTEGER NOT NULL,
  ip               TEXT NOT NULL,
  ip_bin           BLOB NOT NULL,
  hostname         TEXT NOT NULL DEFAULT '',
  owner            TEXT NOT NULL DEFAULT '',
  note             TEXT NOT NULL DEFAULT '',
  grp              TEXT NOT NULL DEFAULT '',              -- group label (group is a SQL reserved word)
  mac              TEXT NOT NULL DEFAULT '',              -- MAC address (optional, free-form)
  expires_at       TEXT,                                  -- optional expiration date (YYYY-MM-DD), NULL = no expiry
  status           TEXT NOT NULL DEFAULT 'used',
  owner_contact_id INTEGER REFERENCES contacts(id) ON DELETE SET NULL,  -- v2.1.0: FK to contacts
  last_seen_at     TEXT,                                  -- v2.3.0: last successful scan response timestamp
  is_stale         INTEGER NOT NULL DEFAULT 0,            -- v2.3.0: 1 = host missed N consecutive scans
  device_id        INTEGER REFERENCES devices(id) ON DELETE SET NULL,         -- v3.2.0: FK to devices
  interface_id     INTEGER REFERENCES device_interfaces(id) ON DELETE SET NULL, -- v3.2.0: FK to device_interfaces
  custom_fields    TEXT NOT NULL DEFAULT '{}',                                  -- v3.5.0 #313/#595: admin-defined key/value metadata (JSON-in-row)
  created_at       TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at       TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(subnet_id, ip),
  FOREIGN KEY(subnet_id) REFERENCES subnets(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_addresses_subnet_ipbin ON addresses(subnet_id, ip_bin);
CREATE INDEX IF NOT EXISTS idx_addresses_hostname ON addresses(hostname);
CREATE INDEX IF NOT EXISTS idx_addresses_owner ON addresses(owner);
CREATE INDEX IF NOT EXISTS idx_addresses_status ON addresses(status);
CREATE INDEX IF NOT EXISTS idx_addresses_grp ON addresses(grp);
CREATE INDEX IF NOT EXISTS idx_addresses_owner_contact_id ON addresses(owner_contact_id);
CREATE INDEX IF NOT EXISTS idx_addresses_is_stale ON addresses(is_stale);
CREATE INDEX IF NOT EXISTS idx_addresses_device_id    ON addresses(device_id);
CREATE INDEX IF NOT EXISTS idx_addresses_interface_id ON addresses(interface_id);

CREATE TRIGGER IF NOT EXISTS addresses_updated_at
AFTER UPDATE ON addresses
FOR EACH ROW
BEGIN
  UPDATE addresses SET updated_at = datetime('now') WHERE id = OLD.id;
END;

CREATE TABLE IF NOT EXISTS address_history (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  created_at  TEXT NOT NULL DEFAULT (datetime('now')),
  address_id  INTEGER,
  subnet_id   INTEGER NOT NULL,
  ip          TEXT NOT NULL,
  action      TEXT NOT NULL,
  user_id     INTEGER,
  username    TEXT,
  client_ip   TEXT,
  user_agent  TEXT,
  before_json TEXT,
  after_json  TEXT
);

CREATE INDEX IF NOT EXISTS idx_address_history_address_id ON address_history(address_id);
CREATE INDEX IF NOT EXISTS idx_address_history_subnet_id ON address_history(subnet_id);

CREATE TABLE IF NOT EXISTS audit_log (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  created_at  TEXT NOT NULL DEFAULT (datetime('now')),
  user_id     INTEGER,
  username    TEXT,
  action      TEXT NOT NULL,
  entity_type TEXT NOT NULL,
  entity_id   INTEGER,
  ip          TEXT,
  user_agent  TEXT,
  details     TEXT
);

CREATE INDEX IF NOT EXISTS idx_audit_log_action ON audit_log(action);
CREATE INDEX IF NOT EXISTS idx_audit_log_created_at ON audit_log(created_at);

CREATE TRIGGER IF NOT EXISTS audit_log_no_update
BEFORE UPDATE ON audit_log
BEGIN
  SELECT RAISE(ABORT, 'audit_log is append-only');
END;

CREATE TRIGGER IF NOT EXISTS audit_log_no_delete
BEFORE DELETE ON audit_log
BEGIN
  SELECT RAISE(ABORT, 'audit_log is append-only');
END;

CREATE TABLE IF NOT EXISTS login_attempts (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  ip           TEXT NOT NULL,
  username     TEXT,
  attempted_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_login_attempts_ip_time ON login_attempts(ip, attempted_at);
CREATE INDEX IF NOT EXISTS idx_login_attempts_username_time ON login_attempts(username, attempted_at);

CREATE TABLE IF NOT EXISTS api_keys (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  name         TEXT NOT NULL,
  key_hash     TEXT NOT NULL UNIQUE,
  created_at   TEXT NOT NULL DEFAULT (datetime('now')),
  last_used_at TEXT,
  is_active    INTEGER NOT NULL DEFAULT 1,
  created_by   TEXT NOT NULL DEFAULT '',
  is_readonly  INTEGER NOT NULL DEFAULT 0,
  description  TEXT    NOT NULL DEFAULT ''
);

-- v2.0.0: VLANs as first-class objects
CREATE TABLE IF NOT EXISTS vlans (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  vlan_id     INTEGER NOT NULL CHECK(vlan_id BETWEEN 1 AND 4094),
  name        TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  site_id     INTEGER REFERENCES sites(id) ON DELETE SET NULL,
  created_at  TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at  TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(vlan_id, site_id)
);

CREATE TRIGGER IF NOT EXISTS vlans_updated_at
AFTER UPDATE ON vlans
FOR EACH ROW
BEGIN
  UPDATE vlans SET updated_at = datetime('now') WHERE id = OLD.id;
END;

-- Clear legacy vlan_id when the backing VLAN row is deleted
CREATE TRIGGER IF NOT EXISTS vlans_before_delete_cleanup_subnets
BEFORE DELETE ON vlans
FOR EACH ROW
BEGIN
  UPDATE subnets SET vlan_id = NULL WHERE vlan_fk = OLD.id;
END;

-- v2.0.0: Tags on subnets and addresses
CREATE TABLE IF NOT EXISTS tags (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  name       TEXT NOT NULL UNIQUE CHECK(length(name) <= 50),
  colour     TEXT NOT NULL DEFAULT '#6c757d',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS subnet_tags (
  subnet_id  INTEGER NOT NULL REFERENCES subnets(id) ON DELETE CASCADE,
  tag_id     INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
  PRIMARY KEY (subnet_id, tag_id)
);

CREATE TABLE IF NOT EXISTS address_tags (
  address_id INTEGER NOT NULL REFERENCES addresses(id) ON DELETE CASCADE,
  tag_id     INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
  PRIMARY KEY (address_id, tag_id)
);

-- v3.0.0: Multi-contact assignments on sites and subnets
CREATE TABLE IF NOT EXISTS site_contacts (
  site_id    INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
  contact_id INTEGER NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
  role       TEXT NOT NULL DEFAULT '',
  PRIMARY KEY (site_id, contact_id)
);

CREATE TABLE IF NOT EXISTS subnet_contacts (
  subnet_id  INTEGER NOT NULL REFERENCES subnets(id) ON DELETE CASCADE,
  contact_id INTEGER NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
  role       TEXT NOT NULL DEFAULT '',
  PRIMARY KEY (subnet_id, contact_id)
);

-- v2.0.0: Utilization alert state tracker
CREATE TABLE IF NOT EXISTS alert_state (
  subnet_id      INTEGER NOT NULL REFERENCES subnets(id) ON DELETE CASCADE,
  level          TEXT NOT NULL CHECK(level IN ('warn','crit')),
  last_alerted_at TEXT NOT NULL,
  PRIMARY KEY (subnet_id, level)
);

-- v3.1.0: Utilization snapshots for sparkline history
CREATE TABLE IF NOT EXISTS utilization_snapshots (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  subnet_id   INTEGER NOT NULL REFERENCES subnets(id) ON DELETE CASCADE,
  snapped_at  TEXT    NOT NULL DEFAULT (datetime('now')),
  used_count  INTEGER NOT NULL,
  free_count  INTEGER NOT NULL,
  total_hosts INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_util_snap_subnet_time ON utilization_snapshots(subnet_id, snapped_at);

-- v2.3.0: Network scanning
CREATE TABLE IF NOT EXISTS scan_schedules (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  subnet_id        INTEGER NOT NULL REFERENCES subnets(id) ON DELETE CASCADE,
  method           TEXT NOT NULL DEFAULT 'icmp' CHECK(method IN ('icmp','tcp','both')),
  tcp_port         INTEGER CHECK(tcp_port IS NULL OR (tcp_port BETWEEN 1 AND 65535)),
  interval_minutes INTEGER NOT NULL DEFAULT 60 CHECK(interval_minutes >= 1),
  is_active        INTEGER NOT NULL DEFAULT 1,
  last_run_at      TEXT,
  created_at       TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at       TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(subnet_id)
);

CREATE INDEX IF NOT EXISTS idx_scan_schedules_active ON scan_schedules(is_active, last_run_at);

CREATE TABLE IF NOT EXISTS scan_results (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  subnet_id  INTEGER NOT NULL REFERENCES subnets(id) ON DELETE CASCADE,
  address_id INTEGER REFERENCES addresses(id) ON DELETE SET NULL,
  ip         TEXT NOT NULL,
  method     TEXT NOT NULL,
  is_up      INTEGER NOT NULL DEFAULT 0,
  latency_ms INTEGER,
  scanned_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_scan_results_subnet_time ON scan_results(subnet_id, scanned_at DESC);
CREATE INDEX IF NOT EXISTS idx_scan_results_address ON scan_results(address_id, scanned_at DESC);

-- v2.4.0 tables — backfilled from migrations into the fresh-install schema
-- in v2.11.0 #409 so fresh SQLite installs match schema.mysql.sql and
-- schema.pgsql.sql. Prior to v2.11.0 these were only created by migrations,
-- but because ipam_db_init() stamps every migration as applied on fresh
-- install, a brand-new SQLite DB shipped without them — leaving the
-- vlan_ranges / aggregates / pd_pools / pd_delegations features latently
-- broken. Column definitions match migrations.php line-for-line.

CREATE TABLE IF NOT EXISTS vlan_ranges (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  name        TEXT NOT NULL,
  vlan_min    INTEGER NOT NULL CHECK(vlan_min >= 1 AND vlan_min <= 4094),
  vlan_max    INTEGER NOT NULL CHECK(vlan_max >= 1 AND vlan_max <= 4094),
  description TEXT NOT NULL DEFAULT '',
  site_id     INTEGER REFERENCES sites(id) ON DELETE SET NULL,
  created_at  TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at  TEXT NOT NULL DEFAULT (datetime('now')),
  CHECK(vlan_min <= vlan_max)
);

CREATE TABLE IF NOT EXISTS aggregates (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  cidr        TEXT NOT NULL UNIQUE,
  ip_version  INTEGER NOT NULL,
  network     TEXT NOT NULL,
  network_bin BLOB NOT NULL,
  prefix      INTEGER NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  rir         TEXT NOT NULL DEFAULT '',
  date_added  TEXT NOT NULL DEFAULT (date('now')),
  notes       TEXT NOT NULL DEFAULT '',
  created_at  TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS pd_pools (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  parent_subnet_id  INTEGER NOT NULL REFERENCES subnets(id) ON DELETE CASCADE,
  delegation_prefix INTEGER NOT NULL CHECK(delegation_prefix BETWEEN 1 AND 128),
  description       TEXT NOT NULL DEFAULT '',
  site_id           INTEGER REFERENCES sites(id) ON DELETE SET NULL,
  created_at        TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at        TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(parent_subnet_id)
);

CREATE TABLE IF NOT EXISTS pd_delegations (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  pool_id       INTEGER NOT NULL REFERENCES pd_pools(id) ON DELETE CASCADE,
  cidr          TEXT NOT NULL,
  subscriber_id INTEGER REFERENCES contacts(id) ON DELETE SET NULL,
  delegated_at  TEXT NOT NULL DEFAULT (datetime('now')),
  expires_at    TEXT,
  notes         TEXT NOT NULL DEFAULT '',
  created_at    TEXT NOT NULL DEFAULT (datetime('now'))
);

-- v3.2.0: Devices
CREATE TABLE IF NOT EXISTS devices (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  name        TEXT NOT NULL,
  type        TEXT NOT NULL DEFAULT 'other',
  site_id     INTEGER REFERENCES sites(id) ON DELETE SET NULL,
  vendor      TEXT NOT NULL DEFAULT '',
  model       TEXT NOT NULL DEFAULT '',
  serial      TEXT NOT NULL DEFAULT '',
  note        TEXT NOT NULL DEFAULT '',
  created_at  TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_devices_name ON devices(name);

CREATE TRIGGER IF NOT EXISTS devices_updated_at
AFTER UPDATE ON devices
FOR EACH ROW
BEGIN
  UPDATE devices SET updated_at = datetime('now') WHERE id = OLD.id;
END;

CREATE TABLE IF NOT EXISTS device_interfaces (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  device_id   INTEGER NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
  name        TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  created_at  TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at  TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(device_id, name)
);

CREATE TRIGGER IF NOT EXISTS device_interfaces_updated_at
AFTER UPDATE ON device_interfaces
FOR EACH ROW
BEGIN
  UPDATE device_interfaces SET updated_at = datetime('now') WHERE id = OLD.id;
END;

-- v3.2.0: Password reset tokens
CREATE TABLE IF NOT EXISTS password_reset_tokens (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  token_hash TEXT NOT NULL UNIQUE,
  expires_at TEXT NOT NULL,
  used_at    TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_prt_user ON password_reset_tokens(user_id);

CREATE TABLE IF NOT EXISTS schema_migrations (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  version    TEXT NOT NULL UNIQUE,
  applied_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS settings (
  tenant_id  INTEGER,
  key        TEXT NOT NULL,
  value      TEXT,
  type       TEXT NOT NULL DEFAULT 'string'
             CHECK(type IN ('string','int','bool','json')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL
);
-- Partial indexes enforce uniqueness correctly for NULL tenant_id (global rows):
-- SQLite treats each NULL as distinct in a composite UNIQUE, so a plain
-- UNIQUE(tenant_id, key) would allow multiple (NULL, 'theme') rows.
CREATE UNIQUE INDEX IF NOT EXISTS uq_settings_global ON settings (key) WHERE tenant_id IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_settings_tenant ON settings (tenant_id, key) WHERE tenant_id IS NOT NULL;

-- v3.3.0: Outbound webhooks
CREATE TABLE IF NOT EXISTS webhooks (
  id                    INTEGER PRIMARY KEY AUTOINCREMENT,
  name                  TEXT NOT NULL,
  url                   TEXT NOT NULL,
  secret                TEXT NOT NULL,
  events                TEXT NOT NULL DEFAULT '[]',
  is_active             INTEGER NOT NULL DEFAULT 1,
  created_at            TEXT NOT NULL DEFAULT (datetime('now')),
  last_delivery_at      TEXT,
  last_delivery_status  INTEGER
);

CREATE TABLE IF NOT EXISTS webhook_deliveries (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  webhook_id    INTEGER NOT NULL REFERENCES webhooks(id) ON DELETE CASCADE,
  event_type    TEXT NOT NULL,
  payload       TEXT NOT NULL,
  signature     TEXT NOT NULL,
  attempt       INTEGER NOT NULL DEFAULT 1,
  http_status   INTEGER,
  response_body TEXT,
  error         TEXT,
  created_at    TEXT NOT NULL DEFAULT (datetime('now')),
  delivered_at  TEXT
);

CREATE INDEX IF NOT EXISTS idx_wh_deliveries_wh
  ON webhook_deliveries(webhook_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_wh_deliveries_pending
  ON webhook_deliveries(delivered_at, attempt);

-- v3.5.0 #313/#595: Custom field definitions (admin-defined per-entity metadata)
CREATE TABLE IF NOT EXISTS custom_field_defs (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  entity_type TEXT    NOT NULL,                         -- 'subnet' | 'address'
  key         TEXT    NOT NULL,                         -- slug, ^[a-z][a-z0-9_]{0,62}$
  label       TEXT    NOT NULL,                         -- human-readable display name
  type        TEXT    NOT NULL DEFAULT 'text',          -- text|number|date|boolean|select
  options     TEXT,                                     -- JSON array of options for type='select'
  sort_order  INTEGER NOT NULL DEFAULT 0,
  is_required INTEGER NOT NULL DEFAULT 0,
  is_deleted  INTEGER NOT NULL DEFAULT 0,               -- reserved for future soft-delete; unused in v3.5.0
  created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
  updated_at  TEXT    NOT NULL DEFAULT (datetime('now')),
  UNIQUE(entity_type, key)
);

CREATE INDEX IF NOT EXISTS idx_cfd_entity_order ON custom_field_defs(entity_type, sort_order);

CREATE TRIGGER IF NOT EXISTS custom_field_defs_updated_at
AFTER UPDATE ON custom_field_defs
FOR EACH ROW
BEGIN
  UPDATE custom_field_defs SET updated_at = datetime('now') WHERE id = OLD.id;
END;

-- v3.6.0 #418: TOTP backup codes (one-time recovery codes, bcrypt-hashed)
CREATE TABLE IF NOT EXISTS totp_backup_codes (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id   INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  code_hash TEXT NOT NULL,
  used_at   TEXT                                          -- NULL = unused
);

CREATE INDEX IF NOT EXISTS idx_totp_backup_codes_user ON totp_backup_codes(user_id);

-- v3.6.0 #419: Sliding-window rate-limit buckets
CREATE TABLE IF NOT EXISTS rate_limit_buckets (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  bucket_key   TEXT    NOT NULL,
  window_start TEXT    NOT NULL,
  count        INTEGER NOT NULL DEFAULT 0,
  UNIQUE(bucket_key, window_start)
);
-- Separate index on window_start alone accelerates the DELETE prune query.
CREATE INDEX IF NOT EXISTS idx_rate_limit_buckets_window_start ON rate_limit_buckets(window_start);

-- v3.15.0 #688: WebAuthn/Passkey credentials
CREATE TABLE IF NOT EXISTS webauthn_credentials (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  credential_id BLOB    NOT NULL UNIQUE, -- PARAM_LOB required on all writes: see ipam_bind_binary()
  public_key    TEXT    NOT NULL,
  sign_count    INTEGER NOT NULL DEFAULT 0,
  name          TEXT    NOT NULL DEFAULT 'Passkey',
  created_at    TEXT    NOT NULL DEFAULT (datetime('now')),
  last_used_at  TEXT
);
CREATE INDEX IF NOT EXISTS idx_webauthn_credentials_user ON webauthn_credentials(user_id);

-- v3.17.0 #690 + v3.21.0 #799: Backup destinations, schedules, and runs.
-- The legacy backup_history (v3.7.0 #423) and backup_log (v3.17.0 #690) tables
-- were collapsed into backup_runs in v3.21.0 (§A1 of backup_overhaul.md). The
-- 3.21.0-backup-runs migration creates backup_runs, copies surviving rows in,
-- and drops both legacy tables on upgrade.
CREATE TABLE IF NOT EXISTS backup_destinations (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  name       TEXT    NOT NULL,
  type       TEXT    NOT NULL,
  config     TEXT    NOT NULL DEFAULT '{}',
  encrypt    INTEGER NOT NULL DEFAULT 1,
  is_active  INTEGER NOT NULL DEFAULT 1,
  created_at TEXT    NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE TRIGGER IF NOT EXISTS backup_destinations_updated_at
AFTER UPDATE ON backup_destinations
FOR EACH ROW
BEGIN
  UPDATE backup_destinations SET updated_at = datetime('now') WHERE id = OLD.id;
END;

CREATE TABLE IF NOT EXISTS backup_schedules (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  destination_id    INTEGER NOT NULL REFERENCES backup_destinations(id) ON DELETE CASCADE,
  frequency         TEXT    NOT NULL DEFAULT 'daily'
                            CHECK (frequency IN ('hourly','daily','weekly','monthly')),
  time_of_day       TEXT    NOT NULL DEFAULT '02:00',
  day_of_week       INTEGER CHECK (day_of_week IS NULL OR day_of_week BETWEEN 0 AND 6),
  day_of_month      INTEGER CHECK (day_of_month IS NULL OR day_of_month BETWEEN 1 AND 28),
  retention_hourly  INTEGER NOT NULL DEFAULT 0  CHECK (retention_hourly  >= 0),
  retention_daily   INTEGER NOT NULL DEFAULT 7  CHECK (retention_daily   >= 0),
  retention_weekly  INTEGER NOT NULL DEFAULT 4  CHECK (retention_weekly  >= 0),
  retention_monthly INTEGER NOT NULL DEFAULT 3  CHECK (retention_monthly >= 0),
  is_active         INTEGER NOT NULL DEFAULT 1  CHECK (is_active IN (0,1)),
  last_run_at       TEXT,
  next_run_at       TEXT,
  -- v3.23.0 #825 (F21): per-schedule notification overrides. When
  -- notify_override = 0, ipam_backup_notify() resolves against the global
  -- backup.notify_* settings (the v3.20.0 behaviour). When = 1, the three
  -- per-schedule columns take precedence. notify_recipients is CSV like
  -- the global setting; NULL means "inherit even when overriding the bools".
  notify_override   INTEGER NOT NULL DEFAULT 0  CHECK (notify_override IN (0,1)),
  notify_on_failure INTEGER          CHECK (notify_on_failure IS NULL OR notify_on_failure IN (0,1)),
  notify_on_success INTEGER          CHECK (notify_on_success IS NULL OR notify_on_success IN (0,1)),
  notify_recipients TEXT,
  created_at        TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_backup_schedules_destination ON backup_schedules(destination_id);
CREATE INDEX IF NOT EXISTS idx_backup_schedules_next_run ON backup_schedules(next_run_at);

-- v3.21.0 #799 (§A1): unified backup_runs replaces backup_history + backup_log.
-- Single enum `triggered_by` (schedule|manual|cli) drops the ambiguous
-- type+triggered_by combo from backup_log (#808 / F38). Single timestamp pair
-- (started_at + completed_at) closes B-P1-31 / #809.
CREATE TABLE IF NOT EXISTS backup_runs (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  destination_id  INTEGER REFERENCES backup_destinations(id) ON DELETE SET NULL,
  schedule_id     INTEGER REFERENCES backup_schedules(id)    ON DELETE SET NULL,
  backup_type     TEXT    NOT NULL DEFAULT 'database'
                          CHECK (backup_type IN ('database','logical')),
  encryption_mode TEXT    NOT NULL DEFAULT 'unencrypted'
                          CHECK (encryption_mode IN ('stored','transitory','unencrypted')),
  triggered_by    TEXT    NOT NULL DEFAULT 'manual'
                          CHECK (triggered_by IN ('schedule','manual','cli')),
  status          TEXT    NOT NULL DEFAULT 'running'
                          CHECK (status IN ('running','success','failed','retention_pruned')),
  filename        TEXT,
  size_bytes      INTEGER,
  checksum        TEXT,
  source_version  TEXT    NOT NULL DEFAULT '0.0.0',
  is_protected    INTEGER NOT NULL DEFAULT 0  CHECK (is_protected IN (0,1)),
  error_message   TEXT,
  started_at      TEXT    NOT NULL DEFAULT (datetime('now')),
  completed_at    TEXT
);
CREATE INDEX IF NOT EXISTS idx_backup_runs_destination ON backup_runs(destination_id);
CREATE INDEX IF NOT EXISTS idx_backup_runs_schedule    ON backup_runs(schedule_id);
CREATE INDEX IF NOT EXISTS idx_backup_runs_started     ON backup_runs(started_at DESC);
CREATE INDEX IF NOT EXISTS idx_backup_runs_protected   ON backup_runs(is_protected) WHERE is_protected = 1;
