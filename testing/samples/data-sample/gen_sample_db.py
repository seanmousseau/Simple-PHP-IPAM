#!/usr/bin/env python3
"""
gen_sample_db.py — generate a complete SQLite SQL dump for Simple PHP IPAM v1.11

Produces sample_dataset.sql, importable via db_tools.php → "Import SQL Dump".

The dump contains:
  • 6 sites  (HQ London, Data Centre Primary, DC DR, Manchester, Edinburgh, Bristol)
  • ~40 subnets with VLAN IDs and site assignments
  • ~4 500 addresses (same dataset as sample_dataset.csv)
  • 2 users: admin (password: changeme) and demo (password: demo)
  • 1 sample API key: sample_api_key_ipam_demo_2026
  • All schema_migrations applied through v1.11

Usage:
    python3 gen_sample_db.py                   # writes sample_dataset.sql
    python3 gen_sample_db.py path/to/out.sql   # custom output path

Address data is sourced by running gen_sample_dataset.py (must be in the same
directory).  The --no-subprocess flag falls back to stub address data if
gen_sample_dataset.py is unavailable.
"""
import csv, hashlib, io, ipaddress, os, socket, sqlite3, subprocess, sys
import warnings; warnings.filterwarnings('ignore')

# ── Constants ────────────────────────────────────────────────────────────────
NOW          = '2026-04-05 12:00:00'
SCRIPT_DIR   = os.path.dirname(os.path.abspath(__file__))
GEN_CSV_PATH = os.path.join(SCRIPT_DIR, 'gen_sample_dataset.py')

# Pre-computed bcrypt-compatible hashes (Python crypt module, $2b$ prefix).
# PHP password_verify() accepts $2b$ identical to $2y$.
#   admin / changeme
HASH_ADMIN  = '$2b$12$o.kkt8T15rWzxJr5exOj3OzaVYaOmc/eRxOM6P88M4MJEcSBZyYt.'
#   demo  / demo
HASH_DEMO   = '$2b$12$D3w9Htjj9pF2/Zvs9HdLg.kY3YkqJjc2/7Q7/tsaO6dgd7Tu2HQEC'
# Sample API key (store its SHA-256 hash in the DB, use the raw key in HTTP header)
SAMPLE_API_KEY      = 'sample_api_key_ipam_demo_2026'
SAMPLE_API_KEY_HASH = hashlib.sha256(SAMPLE_API_KEY.encode()).hexdigest()

# Migration versions that must be recorded as applied
MIGRATION_VERSIONS = [
    '0.3', '0.7', '0.9', '0.11', '0.12', '0.13', '0.14', '1.4', '1.9', '1.11',
]

# ── Schema SQL (verbatim from Simple-PHP-IPAM/schema.sql v1.11) ──────────────
# executescript() handles multiple statements including trigger bodies.
SCHEMA_SQL = """\
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  username      TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  role          TEXT NOT NULL DEFAULT 'admin',
  is_active     INTEGER NOT NULL DEFAULT 1,
  name          TEXT NOT NULL DEFAULT '',
  email         TEXT NOT NULL DEFAULT '',
  oidc_sub             TEXT,
  last_login_at        TEXT,
  password_changed_at  TEXT,
  theme         TEXT NOT NULL DEFAULT 'auto',
  created_at    TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at    TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_users_oidc_sub
  ON users(oidc_sub) WHERE oidc_sub IS NOT NULL;

CREATE TRIGGER IF NOT EXISTS users_updated_at
AFTER UPDATE ON users FOR EACH ROW
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
  vlan_id     INTEGER,
  created_at  TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_subnets_ver_prefix_netbin ON subnets(ip_version, prefix, network_bin);
CREATE INDEX IF NOT EXISTS idx_subnets_site_id ON subnets(site_id);

CREATE TRIGGER IF NOT EXISTS subnets_updated_at
AFTER UPDATE ON subnets FOR EACH ROW
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
  grp        TEXT NOT NULL DEFAULT '',
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
CREATE INDEX IF NOT EXISTS idx_addresses_grp ON addresses(grp);

CREATE TRIGGER IF NOT EXISTS addresses_updated_at
AFTER UPDATE ON addresses FOR EACH ROW
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
"""

# ── Site definitions ─────────────────────────────────────────────────────────
# (id, name, description)
SITES = [
    (1, 'HQ London',           'Headquarters — London, UK'),
    (2, 'Data Centre Primary', 'Primary data centre — Docklands, London'),
    (3, 'DC DR',               'Disaster recovery data centre — Reading, UK'),
    (4, 'Manchester',          'Branch office — Manchester, UK'),
    (5, 'Edinburgh',           'Branch office — Edinburgh, UK'),
    (6, 'Bristol',             'Branch office — Bristol, UK'),
]
SITE_ID = {'hq': 1, 'dc': 2, 'dr': 3, 'mcr': 4, 'edi': 5, 'brs': 6}

# ── Subnet metadata ──────────────────────────────────────────────────────────
# cidr → (description, site_key_or_None, vlan_id_or_None)
SUBNET_META = {
    # HQ London — VLANs 10–301
    '10.0.1.0/24':    ('HQ Management',              'hq',  10),
    '10.0.2.0/25':    ('HQ Linux Servers',           'hq',  20),
    '10.0.2.128/25':  ('HQ Windows Servers',         'hq',  21),
    '10.0.10.0/23':   ('HQ Workstations',            'hq', 100),
    '10.0.20.0/28':   ('HQ Printers',                'hq', 110),
    '10.0.21.0/27':   ('HQ IP Cameras',              'hq', 120),
    '10.0.100.0/24':  ('HQ DMZ',                     'hq', 200),
    '10.0.200.0/24':  ('HQ Guest WiFi',              'hq', 300),
    '10.0.201.0/24':  ('HQ Corporate WiFi',          'hq', 301),
    '10.0.255.0/28':  ('HQ Network Infrastructure',  'hq', None),
    # Data Centre Primary — VLANs 400–498
    '10.10.1.0/24':     ('DC Compute',               'dc', 400),
    '10.10.2.0/24':     ('DC Storage',               'dc', 401),
    '10.10.3.0/24':     ('DC OOB IPMI',              'dc', 402),
    '10.10.4.0/24':     ('DC DMZ',                   'dc', 403),
    '10.10.10.0/24':    ('DC Kubernetes',            'dc', 410),
    '10.10.20.0/24':    ('DC Monitoring',            'dc', 420),
    '10.10.30.0/24':    ('DC CI-CD',                 'dc', 430),
    '10.10.255.248/29': ('DC OOB Management',        'dc', 498),
    # DC DR — VLANs 500–510
    '10.20.1.0/24':  ('DR Compute',                  'dr', 500),
    '10.20.2.0/24':  ('DR Storage',                  'dr', 501),
    '10.20.3.0/24':  ('DR OOB',                      'dr', 502),
    '10.20.10.0/24': ('DR Kubernetes',               'dr', 510),
    # Manchester — VLANs 600–603
    '10.30.0.0/24': ('MCR Staff',                    'mcr', 600),
    '10.30.1.0/24': ('MCR Guest WiFi',               'mcr', 601),
    '10.30.2.0/24': ('MCR VoIP',                     'mcr', 602),
    '10.30.3.0/28': ('MCR Printers',                 'mcr', 603),
    # Edinburgh — VLANs 700–703
    '10.40.0.0/24': ('EDI Staff',                    'edi', 700),
    '10.40.1.0/24': ('EDI Guest WiFi',               'edi', 701),
    '10.40.2.0/25': ('EDI VoIP',                     'edi', 702),
    '10.40.3.0/28': ('EDI Printers',                 'edi', 703),
    # Bristol — VLANs 800–802
    '10.50.0.0/24': ('BRS Staff',                    'brs', 800),
    '10.50.1.0/25': ('BRS Guest WiFi',               'brs', 801),
    '10.50.2.0/28': ('BRS Printers',                 'brs', 802),
    # WAN / Infrastructure (no site, no VLAN)
    '172.16.0.0/22':  ('VPN Client Pool',            None, None),
    '172.16.4.0/30':  ('WAN HQ→DC primary',          None, None),
    '172.16.4.4/30':  ('WAN HQ→MCR',                None, None),
    '172.16.4.8/30':  ('WAN HQ→EDI',                None, None),
    '172.16.4.12/30': ('WAN HQ→BRS',                None, None),
    '172.16.4.16/30': ('WAN DC→DR',                 None, None),
    '172.16.4.20/30': ('WAN DC→MCR',                None, None),
    '172.16.4.24/30': ('WAN DC→EDI',                None, None),
    '172.16.4.28/30': ('WAN DC→BRS',                None, None),
    '172.16.4.32/30': ('WAN HQ→DC secondary',       None, None),
    '172.16.4.36/30': ('WAN HQ→DR',                 None, None),
    '172.16.5.0/31':  ('BGP peer HQ rtr01↔rtr02',   None, None),
    '172.16.5.2/31':  ('BGP peer DC rtr01↔rtr02',   None, None),
    '172.16.5.4/31':  ('BGP peer DR rtr01↔rtr02',   None, None),
    '172.16.5.6/31':  ('BGP peer MCR rtr01↔rtr02',  None, None),
    '172.16.5.8/31':  ('BGP peer EDI rtr01↔rtr02',  None, None),
    '172.16.5.10/31': ('BGP peer BRS rtr01↔rtr02',  None, None),
    '172.16.100.0/28': ('Router Loopbacks /32',      None, None),
    # IPv6
    '2001:db8:1:1::/64':  ('HQ Servers IPv6',        'hq', None),
    '2001:db8:1:2::/64':  ('HQ Workstations IPv6',   'hq', None),
    '2001:db8:10:1::/64': ('DC Compute IPv6',         'dc', None),
    # IPv6 /128 router loopbacks — each a separate subnet
    '2001:db8:ff::1/128': ('HQ router 1 IPv6 loopback',  'hq',  None),
    '2001:db8:ff::2/128': ('HQ router 2 IPv6 loopback',  'hq',  None),
    '2001:db8:ff::3/128': ('DC router 1 IPv6 loopback',  'dc',  None),
    '2001:db8:ff::4/128': ('DC router 2 IPv6 loopback',  'dc',  None),
    '2001:db8:ff::5/128': ('DR router 1 IPv6 loopback',  'dr',  None),
    '2001:db8:ff::6/128': ('MCR router IPv6 loopback',   'mcr', None),
    '2001:db8:ff::7/128': ('EDI router IPv6 loopback',   'edi', None),
    '2001:db8:ff::8/128': ('BRS router IPv6 loopback',   'brs', None),
}

# ── Helpers ───────────────────────────────────────────────────────────────────

def encode_ip(ip_str: str) -> bytes:
    """Return raw binary representation of an IPv4 or IPv6 address."""
    if ':' in ip_str:
        return socket.inet_pton(socket.AF_INET6, ip_str)
    return socket.inet_pton(socket.AF_INET, ip_str)


def cidr_to_parts(cidr: str) -> tuple:
    """Return (ip_version, network_text, network_bin, prefix_len)."""
    net = ipaddress.ip_network(cidr, strict=False)
    version = net.version
    network_text = str(net.network_address)
    network_bin = encode_ip(network_text)
    return version, network_text, network_bin, net.prefixlen


def normalise_cidr(cidr: str) -> str:
    """Return canonical CIDR (host bits zeroed) matching ipaddress module."""
    return str(ipaddress.ip_network(cidr, strict=False))


# ── Database builder ──────────────────────────────────────────────────────────

def build_db(address_rows: list) -> sqlite3.Connection:
    """
    Create an in-memory SQLite database with the v1.11 schema and insert
    all sample data.  Returns the open connection.
    """
    conn = sqlite3.connect(':memory:')
    conn.execute('PRAGMA foreign_keys=OFF')
    conn.executescript(SCHEMA_SQL)

    cur = conn.cursor()

    # schema_migrations
    for ver in MIGRATION_VERSIONS:
        cur.execute(
            "INSERT INTO schema_migrations (version, applied_at) VALUES (?,?)",
            (ver, NOW)
        )

    # sites
    for sid, name, desc in SITES:
        cur.execute(
            "INSERT INTO sites (id,name,description,created_at) VALUES (?,?,?,?)",
            (sid, name, desc, NOW)
        )

    # users
    # admin account (password: changeme)
    cur.execute(
        "INSERT INTO users (id,username,password_hash,role,is_active,name,email,theme,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)",
        (1, 'admin', HASH_ADMIN, 'admin', 1, 'Sample Admin', 'admin@corp.example.com', 'auto', NOW, NOW)
    )
    # demo account (password: demo) — used by login.php in demo_mode
    cur.execute(
        "INSERT INTO users (id,username,password_hash,role,is_active,name,email,theme,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)",
        (2, 'demo', HASH_DEMO, 'readonly', 1, 'Demo User', 'demo@corp.example.com', 'auto', NOW, NOW)
    )

    # api_keys — one sample key
    cur.execute(
        "INSERT INTO api_keys (id,name,key_hash,created_at,is_active,created_by) VALUES (?,?,?,?,?,?)",
        (1, 'Sample Key', SAMPLE_API_KEY_HASH, NOW, 1, 'admin')
    )

    # subnets — build in SUBNET_META order so IDs are stable and predictable
    cidr_to_id: dict[str, int] = {}
    subnet_id = 1
    for cidr, (desc, site_key, vlan_id) in SUBNET_META.items():
        norm = normalise_cidr(cidr)
        version, network, net_bin, prefix = cidr_to_parts(cidr)
        site_id = SITE_ID.get(site_key) if site_key else None
        cur.execute(
            "INSERT INTO subnets (id,cidr,ip_version,network,network_bin,prefix,description,site_id,vlan_id,created_at,updated_at) "
            "VALUES (?,?,?,?,?,?,?,?,?,?,?)",
            (subnet_id, norm, version, network, net_bin, prefix, desc, site_id, vlan_id, NOW, NOW)
        )
        cidr_to_id[norm] = subnet_id
        cidr_to_id[cidr] = subnet_id   # also map the original (may differ by host bits)
        subnet_id += 1

    # addresses — from CSV produced by gen_sample_dataset.py
    addr_id = 1
    skipped = 0
    for row in address_rows:
        ip   = row['ip']
        cidr = row['cidr']
        # normalise the cidr so lookup works even if host bits differ
        norm_c = normalise_cidr(cidr)
        sid = cidr_to_id.get(norm_c) or cidr_to_id.get(cidr)
        if sid is None:
            skipped += 1
            continue
        ip_binary = encode_ip(ip)
        cur.execute(
            "INSERT INTO addresses (id,subnet_id,ip,ip_bin,hostname,owner,note,grp,status,created_at,updated_at) "
            "VALUES (?,?,?,?,?,?,?,?,?,?,?)",
            (
                addr_id, sid, ip, ip_binary,
                row.get('hostname', ''), row.get('owner', ''),
                row.get('note', ''),    row.get('group', ''),
                row.get('status', 'used'),
                NOW, NOW,
            )
        )
        addr_id += 1

    conn.commit()
    if skipped:
        print(f'  Warning: {skipped} address rows had unrecognised CIDRs and were skipped.',
              file=sys.stderr)
    return conn


# ── SQL dump (mirrors ipam_db_dump() in lib.php) ──────────────────────────────

def _sql_val(col_name: str, value, blob_cols: set) -> str:
    """Format a single column value for insertion into the SQL dump."""
    if value is None:
        return 'NULL'
    if isinstance(value, int):
        return str(value)
    if isinstance(value, float):
        return str(value)
    if isinstance(value, bytes) or col_name in blob_cols:
        raw = value if isinstance(value, bytes) else value.encode('latin-1')
        return "X'" + raw.hex() + "'"
    # TEXT — use CAST(X'hex' AS TEXT) to safely handle quotes, semicolons, NULs
    return "CAST(X'" + str(value).encode('utf-8').hex() + "' AS TEXT)"


def dump_db(conn: sqlite3.Connection, out) -> None:
    """
    Write a SQL dump in the exact format produced by ipam_db_dump() (lib.php).
    The output is directly importable via db_tools.php.
    """
    cur = conn.cursor()

    out.write('-- Simple PHP IPAM database dump\n')
    out.write(f'-- Generated: {NOW}\n')
    out.write('-- Sample dataset v1.11 — for testing only\n')
    out.write('-- Users: admin/changeme (admin), demo/demo (readonly)\n')
    out.write(f'-- API key: {SAMPLE_API_KEY}\n')
    out.write('\n')
    out.write('PRAGMA foreign_keys=OFF;\n')
    out.write('BEGIN TRANSACTION;\n\n')

    # Tables (alphabetical, matching sqlite_master ORDER BY name)
    tables = cur.execute(
        "SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
    ).fetchall()

    for tbl_name, tbl_sql in tables:
        quoted = '"' + tbl_name.replace('"', '""') + '"'
        out.write(f'-- Table: {tbl_name}\n')
        out.write(tbl_sql + ';\n')

        # Triggers for this table
        triggers = cur.execute(
            "SELECT sql FROM sqlite_master WHERE type='trigger' AND tbl_name=? AND sql IS NOT NULL ORDER BY name",
            (tbl_name,)
        ).fetchall()
        for (trig_sql,) in triggers:
            out.write(trig_sql + ';\n')

        # Identify BLOB columns
        col_info  = cur.execute(f'PRAGMA table_info({quoted})').fetchall()
        blob_cols = {row[1] for row in col_info if row[2].upper() == 'BLOB'}
        col_names = [row[1] for row in col_info]

        # Rows
        rows = cur.execute(f'SELECT * FROM {quoted}').fetchall()
        for row in rows:
            cols_sql = ','.join('"' + c.replace('"', '""') + '"' for c in col_names)
            vals_sql = ','.join(_sql_val(col_names[i], row[i], blob_cols) for i in range(len(col_names)))
            out.write(f'INSERT INTO {quoted} ({cols_sql}) VALUES ({vals_sql});\n')
        out.write('\n')

    # Indexes
    indexes = cur.execute(
        "SELECT sql FROM sqlite_master WHERE type='index' AND sql IS NOT NULL "
        "AND name NOT LIKE 'sqlite_%' ORDER BY name"
    ).fetchall()
    if indexes:
        out.write('-- Indexes\n')
        for (idx_sql,) in indexes:
            out.write(idx_sql + ';\n')
        out.write('\n')

    out.write('COMMIT;\n')
    out.write('PRAGMA foreign_keys=ON;\n')


# ── Address source ────────────────────────────────────────────────────────────

def load_addresses_from_csv(csv_text: str) -> list:
    """Parse CSV output of gen_sample_dataset.py into a list of row dicts."""
    reader = csv.DictReader(io.StringIO(csv_text))
    return list(reader)


def run_gen_csv() -> list:
    """Run gen_sample_dataset.py as a subprocess and return its rows."""
    if not os.path.isfile(GEN_CSV_PATH):
        print(f'  Warning: {GEN_CSV_PATH} not found; no address data will be inserted.',
              file=sys.stderr)
        return []
    result = subprocess.run(
        [sys.executable, GEN_CSV_PATH],
        capture_output=True, text=True, timeout=120
    )
    if result.returncode != 0:
        print(f'  Warning: gen_sample_dataset.py exited {result.returncode}:\n{result.stderr}',
              file=sys.stderr)
        return []
    return load_addresses_from_csv(result.stdout)


# ── Entry point ───────────────────────────────────────────────────────────────

def main() -> None:
    out_path = sys.argv[1] if len(sys.argv) > 1 else os.path.join(SCRIPT_DIR, 'sample_dataset.sql')

    print('Loading address data from gen_sample_dataset.py …', file=sys.stderr)
    address_rows = run_gen_csv()
    print(f'  {len(address_rows)} address rows loaded.', file=sys.stderr)

    print('Building in-memory database …', file=sys.stderr)
    conn = build_db(address_rows)

    # Summary
    cur = conn.cursor()
    n_subnets = cur.execute('SELECT COUNT(*) FROM subnets').fetchone()[0]
    n_addrs   = cur.execute('SELECT COUNT(*) FROM addresses').fetchone()[0]
    n_sites   = cur.execute('SELECT COUNT(*) FROM sites').fetchone()[0]
    print(f'  {n_sites} sites, {n_subnets} subnets, {n_addrs} addresses.', file=sys.stderr)

    print(f'Writing SQL dump to {out_path} …', file=sys.stderr)
    with open(out_path, 'w', encoding='utf-8') as f:
        dump_db(conn, f)

    size_kb = os.path.getsize(out_path) // 1024
    print(f'Done. {out_path} ({size_kb} KB)', file=sys.stderr)
    print(f'  Import via: db_tools.php → SQL Import', file=sys.stderr)
    print(f'  Credentials: admin / changeme  |  demo / demo', file=sys.stderr)
    print(f'  API key: {SAMPLE_API_KEY}', file=sys.stderr)


if __name__ == '__main__':
    main()
