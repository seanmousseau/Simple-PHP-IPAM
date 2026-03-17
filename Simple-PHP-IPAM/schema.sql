PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'admin',     -- admin|readonly
  is_active INTEGER NOT NULL DEFAULT 1,   -- 1 active, 0 disabled
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TRIGGER IF NOT EXISTS users_updated_at
AFTER UPDATE ON users
FOR EACH ROW
BEGIN
  UPDATE users SET updated_at = datetime('now') WHERE id = OLD.id;
END;

CREATE TABLE IF NOT EXISTS subnets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  cidr TEXT NOT NULL UNIQUE,             -- normalized network/prefix
  ip_version INTEGER NOT NULL,           -- 4 or 6
  network TEXT NOT NULL,                 -- normalized network IP (string)
  network_bin BLOB NOT NULL,             -- inet_pton(network)
  prefix INTEGER NOT NULL,               -- prefix length
  description TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_subnets_ver_prefix_netbin ON subnets(ip_version, prefix, network_bin);

CREATE TRIGGER IF NOT EXISTS subnets_updated_at
AFTER UPDATE ON subnets
FOR EACH ROW
BEGIN
  UPDATE subnets SET updated_at = datetime('now') WHERE id = OLD.id;
END;

CREATE TABLE IF NOT EXISTS addresses (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  subnet_id INTEGER NOT NULL,
  ip TEXT NOT NULL,                      -- normalized textual IP
  ip_bin BLOB NOT NULL,                  -- inet_pton packed bytes
  hostname TEXT NOT NULL DEFAULT '',
  owner TEXT NOT NULL DEFAULT '',
  note TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'used',    -- used|reserved|free
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(subnet_id, ip),
  FOREIGN KEY(subnet_id) REFERENCES subnets(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_addresses_subnet_ipbin ON addresses(subnet_id, ip_bin);

CREATE TRIGGER IF NOT EXISTS addresses_updated_at
AFTER UPDATE ON addresses
FOR EACH ROW
BEGIN
  UPDATE addresses SET updated_at = datetime('now') WHERE id = OLD.id;
END;

CREATE TABLE IF NOT EXISTS audit_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  user_id INTEGER,
  username TEXT,
  action TEXT NOT NULL,
  entity_type TEXT NOT NULL,
  entity_id INTEGER,
  ip TEXT,
  user_agent TEXT,
  details TEXT
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

CREATE TABLE IF NOT EXISTS schema_migrations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  version TEXT NOT NULL UNIQUE,
  applied_at TEXT NOT NULL DEFAULT (datetime('now'))
);
