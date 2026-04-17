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

CREATE TABLE IF NOT EXISTS schema_migrations (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  version    TEXT NOT NULL UNIQUE,
  applied_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS settings (
  key        TEXT PRIMARY KEY,
  value      TEXT,
  type       TEXT NOT NULL DEFAULT 'string'
             CHECK(type IN ('string','int','bool','json')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL
);
