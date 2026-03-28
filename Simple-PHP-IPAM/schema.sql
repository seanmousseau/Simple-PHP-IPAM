PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  username      TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  role          TEXT NOT NULL DEFAULT 'admin',     -- admin|readonly
  is_active     INTEGER NOT NULL DEFAULT 1,        -- 1 active, 0 disabled
  name          TEXT NOT NULL DEFAULT '',
  email         TEXT NOT NULL DEFAULT '',
  oidc_sub      TEXT,                              -- IdP subject claim (unique when set)
  last_login_at TEXT,
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
  created_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS subnets (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  cidr        TEXT NOT NULL UNIQUE,
  ip_version  INTEGER NOT NULL,
  network     TEXT NOT NULL,
  network_bin BLOB NOT NULL,
  prefix      INTEGER NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  site_id     INTEGER,
  created_at  TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_subnets_ver_prefix_netbin ON subnets(ip_version, prefix, network_bin);
CREATE INDEX IF NOT EXISTS idx_subnets_site_id ON subnets(site_id);

CREATE TRIGGER IF NOT EXISTS subnets_updated_at
AFTER UPDATE ON subnets
FOR EACH ROW
BEGIN
  UPDATE subnets SET updated_at = datetime('now') WHERE id = OLD.id;
END;

CREATE TABLE IF NOT EXISTS addresses (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  subnet_id  INTEGER NOT NULL,
  ip         TEXT NOT NULL,
  ip_bin     BLOB NOT NULL,
  hostname   TEXT NOT NULL DEFAULT '',
  owner      TEXT NOT NULL DEFAULT '',
  note       TEXT NOT NULL DEFAULT '',
  status     TEXT NOT NULL DEFAULT 'used',
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(subnet_id, ip),
  FOREIGN KEY(subnet_id) REFERENCES subnets(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_addresses_subnet_ipbin ON addresses(subnet_id, ip_bin);
CREATE INDEX IF NOT EXISTS idx_addresses_hostname ON addresses(hostname);
CREATE INDEX IF NOT EXISTS idx_addresses_owner ON addresses(owner);
CREATE INDEX IF NOT EXISTS idx_addresses_status ON addresses(status);

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
  attempted_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_login_attempts_ip_time ON login_attempts(ip, attempted_at);

CREATE TABLE IF NOT EXISTS api_keys (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  name         TEXT NOT NULL,
  key_hash     TEXT NOT NULL UNIQUE,
  created_at   TEXT NOT NULL DEFAULT (datetime('now')),
  last_used_at TEXT,
  is_active    INTEGER NOT NULL DEFAULT 1,
  created_by   TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS schema_migrations (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  version    TEXT NOT NULL UNIQUE,
  applied_at TEXT NOT NULL DEFAULT (datetime('now'))
);
