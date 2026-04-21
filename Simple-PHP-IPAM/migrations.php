<?php
declare(strict_types=1);

/** @return array<string, \Closure> */
function ipam_migrations(): array
{
    return [
        // 0.3: adds subnets.network_bin and backfills it
        '0.3' => function(PDO $db) {
            $cols = ($db->query("PRAGMA table_info(subnets)") ?: throw new \RuntimeException('Query failed'))->fetchAll();
            $names = array_map(fn($c) => to_str($c['name']), $cols);

            if (!in_array('network_bin', $names, true)) {
                $db->exec("ALTER TABLE subnets ADD COLUMN network_bin BLOB");
            }

            $st = $db->prepare("SELECT id, network FROM subnets WHERE network_bin IS NULL OR length(network_bin)=0");
            $st->execute();
            $rows = $st->fetchAll();

            // #380/#410: bind network_bin via ipam_bind_binary() (PARAM_LOB) so
            // the backfilled rows are BLOB affinity from the start. The
            // 2.9.0-blob-affinity migration would otherwise need to re-rewrite
            // every row that this migration inserted.
            $up = $db->prepare("UPDATE subnets SET network_bin = :b WHERE id = :id");
            foreach ($rows as $r) {
                $bin = @inet_pton(to_str($r['network']));
                if ($bin === false) continue;
                ipam_bind_binary($up, ':b', $bin);
                $up->bindValue(':id', to_int($r['id']), PDO::PARAM_INT);
                $up->execute();
            }

            $db->exec("CREATE INDEX IF NOT EXISTS idx_subnets_ver_prefix_netbin ON subnets(ip_version, prefix, network_bin)");
        },

        // 0.7: address history + search indexes
        '0.7' => function(PDO $db) {
            $db->exec("
                CREATE TABLE IF NOT EXISTS address_history (
                  id INTEGER PRIMARY KEY AUTOINCREMENT,
                  created_at TEXT NOT NULL DEFAULT (datetime('now')),
                  address_id INTEGER,
                  subnet_id INTEGER NOT NULL,
                  ip TEXT NOT NULL,
                  action TEXT NOT NULL,
                  user_id INTEGER,
                  username TEXT,
                  client_ip TEXT,
                  user_agent TEXT,
                  before_json TEXT,
                  after_json TEXT
                )
            ");

            $db->exec("CREATE INDEX IF NOT EXISTS idx_address_history_address_id ON address_history(address_id)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_address_history_subnet_id ON address_history(subnet_id)");

            $db->exec("CREATE INDEX IF NOT EXISTS idx_addresses_hostname ON addresses(hostname)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_addresses_owner ON addresses(owner)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_addresses_status ON addresses(status)");
        },

        // 0.9: sites grouping
        '0.9'  => function(PDO $db) {
            $db->exec("
                CREATE TABLE IF NOT EXISTS sites (
                  id INTEGER PRIMARY KEY AUTOINCREMENT,
                  name TEXT NOT NULL UNIQUE,
                  description TEXT NOT NULL DEFAULT '',
                  created_at TEXT NOT NULL DEFAULT (datetime('now'))
                )
            ");

            $cols = ($db->query("PRAGMA table_info(subnets)") ?: throw new \RuntimeException('Query failed'))->fetchAll();
            $names = array_map(fn($c) => to_str($c['name']), $cols);

            if (!in_array('site_id', $names, true)) {
                $db->exec("ALTER TABLE subnets ADD COLUMN site_id INTEGER");
            }

            $db->exec("CREATE INDEX IF NOT EXISTS idx_subnets_site_id ON subnets(site_id)");
        },

        // 1.4: password_changed_at timestamp on users (for password rotation policy)
        '1.4' => function(PDO $db) {
            $cols  = ($db->query("PRAGMA table_info(users)") ?: throw new \RuntimeException('Query failed'))->fetchAll();
            $names = array_map(fn($c) => to_str($c['name']), $cols);
            if (!in_array('password_changed_at', $names, true)) {
                $db->exec("ALTER TABLE users ADD COLUMN password_changed_at TEXT");
                // Backfill existing local accounts so they aren't immediately expired.
                // SSO-only accounts (unusable hash starting with '!') are left NULL
                // since expiry doesn't apply to them.
                $db->exec("UPDATE users SET password_changed_at = datetime('now')
                           WHERE password_hash NOT LIKE '!%'");
            }
        },

        // 0.14: last_login_at timestamp on users
        '0.14' => function(PDO $db) {
            $cols  = ($db->query("PRAGMA table_info(users)") ?: throw new \RuntimeException('Query failed'))->fetchAll();
            $names = array_map(fn($c) => to_str($c['name']), $cols);
            if (!in_array('last_login_at', $names, true)) {
                $db->exec("ALTER TABLE users ADD COLUMN last_login_at TEXT");
            }
        },

        // 0.13: name + email fields on users
        '0.13' => function(PDO $db) {
            $cols  = ($db->query("PRAGMA table_info(users)") ?: throw new \RuntimeException('Query failed'))->fetchAll();
            $names = array_map(fn($c) => to_str($c['name']), $cols);

            if (!in_array('name', $names, true)) {
                $db->exec("ALTER TABLE users ADD COLUMN name TEXT NOT NULL DEFAULT ''");
            }
            if (!in_array('email', $names, true)) {
                $db->exec("ALTER TABLE users ADD COLUMN email TEXT NOT NULL DEFAULT ''");
            }
        },

        // 0.12: OIDC subject claim column on users
        '0.12' => function(PDO $db) {
            $cols  = ($db->query("PRAGMA table_info(users)") ?: throw new \RuntimeException('Query failed'))->fetchAll();
            $names = array_map(fn($c) => to_str($c['name']), $cols);

            if (!in_array('oidc_sub', $names, true)) {
                $db->exec("ALTER TABLE users ADD COLUMN oidc_sub TEXT");
            }

            // Partial unique index: only enforce uniqueness when oidc_sub is not NULL
            $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_users_oidc_sub
                       ON users(oidc_sub) WHERE oidc_sub IS NOT NULL");
        },

        // 0.11: login rate-limiting + REST API keys
        '0.11' => function(PDO $db) {
            $db->exec("
                CREATE TABLE IF NOT EXISTS login_attempts (
                    id           INTEGER PRIMARY KEY AUTOINCREMENT,
                    ip           TEXT NOT NULL,
                    attempted_at TEXT NOT NULL DEFAULT (datetime('now'))
                )
            ");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_login_attempts_ip_time
                       ON login_attempts(ip, attempted_at)");

            $db->exec("
                CREATE TABLE IF NOT EXISTS api_keys (
                    id           INTEGER PRIMARY KEY AUTOINCREMENT,
                    name         TEXT NOT NULL,
                    key_hash     TEXT NOT NULL UNIQUE,
                    created_at   TEXT NOT NULL DEFAULT (datetime('now')),
                    last_used_at TEXT,
                    is_active    INTEGER NOT NULL DEFAULT 1,
                    created_by   TEXT NOT NULL DEFAULT ''
                )
            ");
        },

        // 1.11: addresses.grp (group field), subnets.vlan_id, users.theme
        '1.11' => function(PDO $db) {
            // addresses.grp — SQL reserved word, stored as grp, exposed as group in UI/API/CSV
            $cols = array_column(($db->query("PRAGMA table_info(addresses)") ?: throw new \RuntimeException('Query failed'))->fetchAll(), 'name');
            if (!in_array('grp', $cols, true)) {
                $db->exec("ALTER TABLE addresses ADD COLUMN grp TEXT NOT NULL DEFAULT ''");
            }
            $db->exec("CREATE INDEX IF NOT EXISTS idx_addresses_grp ON addresses(grp)");

            // subnets.vlan_id — nullable integer, 1–4094, NULL means unassigned
            $cols = array_column(($db->query("PRAGMA table_info(subnets)") ?: throw new \RuntimeException('Query failed'))->fetchAll(), 'name');
            if (!in_array('vlan_id', $cols, true)) {
                $db->exec("ALTER TABLE subnets ADD COLUMN vlan_id INTEGER");
            }

            // users.theme — persisted theme preference: 'auto'|'light'|'dark'
            $cols = array_column(($db->query("PRAGMA table_info(users)") ?: throw new \RuntimeException('Query failed'))->fetchAll(), 'name');
            if (!in_array('theme', $cols, true)) {
                $db->exec("ALTER TABLE users ADD COLUMN theme TEXT NOT NULL DEFAULT 'auto'");
            }
        },

        // 1.9: ensure audit_log exists — it was only in schema.sql, not a migration,
        // so a botched demo reset that dropped it would leave it permanently missing.
        // Using CREATE TABLE IF NOT EXISTS makes this safe to run on any existing install.
        // 1.12: add indexes on audit_log + normalize audit action names
        '1.12' => function(PDO $db) {
            // Indexes for audit_log queries
            $db->exec("CREATE INDEX IF NOT EXISTS idx_audit_log_action ON audit_log(action)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_audit_log_created_at ON audit_log(created_at)");

            // Normalize audit action names: api_key.* → apikey.*, user.password_change → user.change_password
            // Must temporarily drop append-only triggers to allow UPDATE
            $db->exec("DROP TRIGGER IF EXISTS audit_log_no_update");
            $db->exec("DROP TRIGGER IF EXISTS audit_log_no_delete");

            $db->exec("UPDATE audit_log SET action = REPLACE(action, 'api_key.', 'apikey.') WHERE action LIKE 'api\_key.%' ESCAPE '\'");
            $db->exec("UPDATE audit_log SET action = 'user.change_password' WHERE action = 'user.password_change'");

            // Recreate append-only triggers
            ensure_audit_log_table($db);
        },

        '1.9' => function(PDO $db) {
            ensure_audit_log_table($db);
        },

        // 1.13: api_keys.is_readonly + api_keys.description
        '1.13' => function(PDO $db): void {
            $cols = array_column(($db->query("PRAGMA table_info(api_keys)") ?: throw new \RuntimeException('Query failed'))->fetchAll(), 'name');
            if (!in_array('is_readonly', $cols, true)) {
                $db->exec("ALTER TABLE api_keys ADD COLUMN is_readonly INTEGER NOT NULL DEFAULT 0");
            }
            if (!in_array('description', $cols, true)) {
                $db->exec("ALTER TABLE api_keys ADD COLUMN description TEXT NOT NULL DEFAULT ''");
            }
        },

        // 1.19.0: addresses.mac + addresses.expires_at
        '1.19.0' => function(PDO $db): void {
            $cols = array_column(
                ($db->query("PRAGMA table_info(addresses)") ?: throw new \RuntimeException('Query failed'))->fetchAll(),
                'name'
            );
            if (!in_array('mac', $cols, true)) {
                $db->exec("ALTER TABLE addresses ADD COLUMN mac TEXT NOT NULL DEFAULT ''");
            }
            if (!in_array('expires_at', $cols, true)) {
                $db->exec("ALTER TABLE addresses ADD COLUMN expires_at TEXT");
            }
        },

        // 2.0.0-vlans: VLANs as first-class managed objects
        '2.0.0-vlans' => function(PDO $db): void {
            $tables = array_column(
                ($db->query("SELECT name FROM sqlite_master WHERE type='table'") ?: throw new \RuntimeException('Query failed'))->fetchAll(),
                'name'
            );
            if (!in_array('vlans', $tables, true)) {
                $db->exec("
                    CREATE TABLE vlans (
                        id          INTEGER PRIMARY KEY AUTOINCREMENT,
                        vlan_id     INTEGER NOT NULL CHECK(vlan_id BETWEEN 1 AND 4094),
                        name        TEXT NOT NULL,
                        description TEXT NOT NULL DEFAULT '',
                        site_id     INTEGER REFERENCES sites(id) ON DELETE SET NULL,
                        created_at  TEXT NOT NULL DEFAULT (datetime('now')),
                        updated_at  TEXT NOT NULL DEFAULT (datetime('now')),
                        UNIQUE(vlan_id, site_id)
                    )
                ");
                $db->exec("CREATE INDEX IF NOT EXISTS idx_vlans_vlan_id ON vlans(vlan_id)");
                $db->exec("CREATE INDEX IF NOT EXISTS idx_vlans_site_id ON vlans(site_id)");
            }
            $subnetCols = array_column(
                ($db->query("PRAGMA table_info(subnets)") ?: throw new \RuntimeException('Query failed'))->fetchAll(),
                'name'
            );
            if (!in_array('vlan_fk', $subnetCols, true)) {
                $db->exec("ALTER TABLE subnets ADD COLUMN vlan_fk INTEGER REFERENCES vlans(id) ON DELETE SET NULL");
            }
            // Clear legacy vlan_id when the backing VLAN row is deleted
            $db->exec("
                CREATE TRIGGER IF NOT EXISTS vlans_before_delete_cleanup_subnets
                BEFORE DELETE ON vlans
                FOR EACH ROW
                BEGIN
                  UPDATE subnets SET vlan_id = NULL WHERE vlan_fk = OLD.id;
                END
            ");
        },

        // 2.0.0-site-hierarchy: parent site / region support
        '2.0.0-site-hierarchy' => function(PDO $db): void {
            $cols = array_column(
                ($db->query("PRAGMA table_info(sites)") ?: throw new \RuntimeException('Query failed'))->fetchAll(),
                'name'
            );
            if (!in_array('parent_id', $cols, true)) {
                $db->exec("ALTER TABLE sites ADD COLUMN parent_id INTEGER REFERENCES sites(id) ON DELETE SET NULL");
            }
            $db->exec("CREATE INDEX IF NOT EXISTS idx_sites_parent_id ON sites(parent_id)");
        },

        // 2.0.0-tags: tags on subnets and addresses
        '2.0.0-tags' => function(PDO $db): void {
            $tables = array_column(
                ($db->query("SELECT name FROM sqlite_master WHERE type='table'") ?: throw new \RuntimeException('Query failed'))->fetchAll(),
                'name'
            );
            if (!in_array('tags', $tables, true)) {
                $db->exec("
                    CREATE TABLE tags (
                        id         INTEGER PRIMARY KEY AUTOINCREMENT,
                        name       TEXT NOT NULL UNIQUE CHECK(length(name) <= 50),
                        colour     TEXT NOT NULL DEFAULT '#6c757d',
                        created_at TEXT NOT NULL DEFAULT (datetime('now'))
                    )
                ");
            }
            if (!in_array('subnet_tags', $tables, true)) {
                $db->exec("
                    CREATE TABLE subnet_tags (
                        subnet_id INTEGER NOT NULL REFERENCES subnets(id) ON DELETE CASCADE,
                        tag_id    INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
                        PRIMARY KEY (subnet_id, tag_id)
                    )
                ");
                $db->exec("CREATE INDEX IF NOT EXISTS idx_subnet_tags_tag_id ON subnet_tags(tag_id)");
            }
            if (!in_array('address_tags', $tables, true)) {
                $db->exec("
                    CREATE TABLE address_tags (
                        address_id INTEGER NOT NULL REFERENCES addresses(id) ON DELETE CASCADE,
                        tag_id     INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
                        PRIMARY KEY (address_id, tag_id)
                    )
                ");
                $db->exec("CREATE INDEX IF NOT EXISTS idx_address_tags_tag_id ON address_tags(tag_id)");
            }
        },

        // 2.0.0-alert-state: email utilization alert dedup state
        '2.0.0-alert-state' => function(PDO $db): void {
            $tables = array_column(
                ($db->query("SELECT name FROM sqlite_master WHERE type='table'") ?: throw new \RuntimeException('Query failed'))->fetchAll(),
                'name'
            );
            if (!in_array('alert_state', $tables, true)) {
                $db->exec("
                    CREATE TABLE alert_state (
                        subnet_id       INTEGER NOT NULL REFERENCES subnets(id) ON DELETE CASCADE,
                        level           TEXT NOT NULL CHECK(level IN ('warn','crit')),
                        last_alerted_at TEXT NOT NULL,
                        PRIMARY KEY (subnet_id, level)
                    )
                ");
            }
        },

        // 2.1.0-vrfs: VRF support — overlapping address spaces
        // SQLite cannot DROP CONSTRAINT, so we rebuild the subnets table to change
        // UNIQUE(cidr) → UNIQUE(cidr, vrf_id).  All existing subnets get vrf_id = NULL
        // (= global/default VRF).  Idempotent: guarded by vrf_id column presence.
        '2.1.0-vrfs' => function(PDO $db): void {
            $tables = array_column(
                ($db->query("SELECT name FROM sqlite_master WHERE type='table'") ?: throw new \RuntimeException('Query failed'))->fetchAll(),
                'name'
            );

            // Create vrfs table if absent
            if (!in_array('vrfs', $tables, true)) {
                $db->exec("
                    CREATE TABLE vrfs (
                        id          INTEGER PRIMARY KEY AUTOINCREMENT,
                        name        TEXT NOT NULL UNIQUE,
                        description TEXT NOT NULL DEFAULT '',
                        rd          TEXT NOT NULL DEFAULT '',
                        created_at  TEXT NOT NULL DEFAULT (datetime('now')),
                        updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
                    )
                ");
                $db->exec("CREATE INDEX IF NOT EXISTS idx_vrfs_name ON vrfs(name)");
                $db->exec("
                    CREATE TRIGGER IF NOT EXISTS vrfs_updated_at
                    AFTER UPDATE ON vrfs FOR EACH ROW
                    BEGIN
                      UPDATE vrfs SET updated_at = datetime('now') WHERE id = OLD.id;
                    END
                ");
            }

            // Rebuild subnets only if vrf_id column is absent
            $subnetCols = array_column(
                ($db->query("PRAGMA table_info(subnets)") ?: throw new \RuntimeException('Query failed'))->fetchAll(),
                'name'
            );
            if (in_array('vrf_id', $subnetCols, true)) {
                return;
            }

            // FK enforcement is disabled by apply_migrations() before this transaction
            // so that DROP TABLE subnets below does not cascade-delete child rows
            // (addresses, subnet_tags, alert_state all have ON DELETE CASCADE on subnet_id).
            $db->exec("
                CREATE TABLE subnets_new (
                    id          INTEGER PRIMARY KEY AUTOINCREMENT,
                    cidr        TEXT NOT NULL,
                    ip_version  INTEGER NOT NULL,
                    network     TEXT NOT NULL,
                    network_bin BLOB NOT NULL,
                    prefix      INTEGER NOT NULL,
                    description TEXT NOT NULL DEFAULT '',
                    site_id     INTEGER,
                    vlan_id     INTEGER,
                    vlan_fk     INTEGER REFERENCES vlans(id) ON DELETE SET NULL,
                    vrf_id      INTEGER REFERENCES vrfs(id) ON DELETE RESTRICT,
                    created_at  TEXT NOT NULL DEFAULT (datetime('now')),
                    updated_at  TEXT NOT NULL DEFAULT (datetime('now')),
                    UNIQUE(cidr, vrf_id)
                )
            ");
                $db->exec("
                    INSERT INTO subnets_new
                        (id, cidr, ip_version, network, network_bin, prefix, description,
                         site_id, vlan_id, vlan_fk, vrf_id, created_at, updated_at)
                    SELECT id, cidr, ip_version, network, network_bin, prefix, description,
                           site_id, vlan_id, vlan_fk, NULL, created_at, updated_at
                    FROM subnets
                ");
                // vlans_before_delete_cleanup_subnets fires ON vlans (not subnets), so
                // it is NOT auto-dropped when subnets is dropped. SQLite validates trigger
                // bodies during ALTER TABLE RENAME and will error if subnets doesn't exist
                // at that point. Drop it explicitly here; it is recreated below.
                $db->exec("DROP TRIGGER IF EXISTS vlans_before_delete_cleanup_subnets");

                $db->exec("DROP TABLE subnets");
                $db->exec("ALTER TABLE subnets_new RENAME TO subnets");

                // Recreate indexes
                $db->exec("CREATE INDEX IF NOT EXISTS idx_subnets_ver_prefix_netbin ON subnets(ip_version, prefix, network_bin)");
                $db->exec("CREATE INDEX IF NOT EXISTS idx_subnets_site_id ON subnets(site_id)");
                $db->exec("CREATE INDEX IF NOT EXISTS idx_subnets_vrf_id ON subnets(vrf_id)");

                // Recreate subnets_updated_at trigger (dropped with the old table)
                $db->exec("
                    CREATE TRIGGER IF NOT EXISTS subnets_updated_at
                    AFTER UPDATE ON subnets FOR EACH ROW
                    BEGIN
                      UPDATE subnets SET updated_at = datetime('now') WHERE id = OLD.id;
                    END
                ");

                // Recreate vlans cleanup trigger
                $db->exec("
                    CREATE TRIGGER IF NOT EXISTS vlans_before_delete_cleanup_subnets
                    BEFORE DELETE ON vlans FOR EACH ROW
                    BEGIN
                      UPDATE subnets SET vlan_id = NULL WHERE vlan_fk = OLD.id;
                    END
                ");
        },

        // 2.3.0-scanning: network discovery & scanning tables
        '2.3.0-scanning' => function(PDO $db): void {
            $tables = array_column(
                ($db->query("SELECT name FROM sqlite_master WHERE type='table'") ?: throw new \RuntimeException('Query failed'))->fetchAll(),
                'name'
            );

            // scan_schedules: per-subnet scan configuration
            if (!in_array('scan_schedules', $tables, true)) {
                $db->exec("CREATE TABLE scan_schedules (
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
                )");
            }
            // Indexes created unconditionally so they are repaired on re-run
            $db->exec("CREATE INDEX IF NOT EXISTS idx_scan_schedules_active ON scan_schedules(is_active, last_run_at)");

            // scan_results: one row per IP per scan run
            if (!in_array('scan_results', $tables, true)) {
                $db->exec("CREATE TABLE scan_results (
                    id         INTEGER PRIMARY KEY AUTOINCREMENT,
                    subnet_id  INTEGER NOT NULL REFERENCES subnets(id) ON DELETE CASCADE,
                    address_id INTEGER REFERENCES addresses(id) ON DELETE SET NULL,
                    ip         TEXT NOT NULL,
                    method     TEXT NOT NULL,
                    is_up      INTEGER NOT NULL DEFAULT 0,
                    latency_ms INTEGER,
                    scanned_at TEXT NOT NULL DEFAULT (datetime('now'))
                )");
            }
            $db->exec("CREATE INDEX IF NOT EXISTS idx_scan_results_subnet_time ON scan_results(subnet_id, scanned_at DESC)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_scan_results_address ON scan_results(address_id, scanned_at DESC)");

            // addresses.last_seen_at — timestamp of last successful scan response
            $addrCols = array_column(
                ($db->query("PRAGMA table_info(addresses)") ?: throw new \RuntimeException('Query failed'))->fetchAll(),
                'name'
            );
            if (!in_array('last_seen_at', $addrCols, true)) {
                $db->exec("ALTER TABLE addresses ADD COLUMN last_seen_at TEXT");
            }
            // addresses.is_stale — auto-stale flag set by scanner after N missed scans
            if (!in_array('is_stale', $addrCols, true)) {
                $db->exec("ALTER TABLE addresses ADD COLUMN is_stale INTEGER NOT NULL DEFAULT 0");
                $db->exec("CREATE INDEX IF NOT EXISTS idx_addresses_is_stale ON addresses(is_stale)");
            }
        },

        // 2.1.0-contacts: contacts as first-class objects
        '2.1.0-contacts' => function(PDO $db): void {
            $tables = array_column(
                ($db->query("SELECT name FROM sqlite_master WHERE type='table'") ?: throw new \RuntimeException('Query failed'))->fetchAll(),
                'name'
            );
            if (!in_array('contacts', $tables, true)) {
                $db->exec("
                    CREATE TABLE contacts (
                        id         INTEGER PRIMARY KEY AUTOINCREMENT,
                        name       TEXT NOT NULL,
                        email      TEXT NOT NULL DEFAULT '',
                        phone      TEXT NOT NULL DEFAULT '',
                        org        TEXT NOT NULL DEFAULT '',
                        note       TEXT NOT NULL DEFAULT '',
                        created_at TEXT NOT NULL DEFAULT (datetime('now')),
                        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
                    )
                ");
                $db->exec("CREATE INDEX IF NOT EXISTS idx_contacts_name ON contacts(name)");
                $db->exec("CREATE INDEX IF NOT EXISTS idx_contacts_email ON contacts(email)");
                $db->exec("
                    CREATE TRIGGER IF NOT EXISTS contacts_updated_at
                    AFTER UPDATE ON contacts FOR EACH ROW
                    BEGIN
                      UPDATE contacts SET updated_at = datetime('now') WHERE id = OLD.id;
                    END
                ");
            }
            $addrCols = array_column(
                ($db->query("PRAGMA table_info(addresses)") ?: throw new \RuntimeException('Query failed'))->fetchAll(),
                'name'
            );
            if (!in_array('owner_contact_id', $addrCols, true)) {
                $db->exec("ALTER TABLE addresses ADD COLUMN owner_contact_id INTEGER REFERENCES contacts(id) ON DELETE SET NULL");
                $db->exec("CREATE INDEX IF NOT EXISTS idx_addresses_owner_contact_id ON addresses(owner_contact_id)");
            }
        },

        // 2.4.0-vrf-bgp: BGP context fields on VRFs
        '2.4.0-vrf-bgp' => function(PDO $db): void {
            $cols = array_column(
                ($db->query("PRAGMA table_info(vrfs)") ?: throw new \RuntimeException('Query failed'))->fetchAll(),
                'name'
            );
            if (!in_array('asn', $cols, true)) {
                $db->exec("ALTER TABLE vrfs ADD COLUMN asn TEXT NOT NULL DEFAULT ''");
            }
            if (!in_array('rt_import', $cols, true)) {
                $db->exec("ALTER TABLE vrfs ADD COLUMN rt_import TEXT NOT NULL DEFAULT ''");
            }
            if (!in_array('rt_export', $cols, true)) {
                $db->exec("ALTER TABLE vrfs ADD COLUMN rt_export TEXT NOT NULL DEFAULT ''");
            }
            if (!in_array('enforce_unique', $cols, true)) {
                $db->exec("ALTER TABLE vrfs ADD COLUMN enforce_unique INTEGER NOT NULL DEFAULT 1");
            }
        },

        // 2.4.0-vlan-ranges: 802.1Q VLAN ID range model
        '2.4.0-vlan-ranges' => function(PDO $db): void {
            $tables = array_column(
                ($db->query("SELECT name FROM sqlite_master WHERE type='table'") ?: throw new \RuntimeException('Query failed'))->fetchAll(),
                'name'
            );
            if (!in_array('vlan_ranges', $tables, true)) {
                $db->exec("
                    CREATE TABLE vlan_ranges (
                        id          INTEGER PRIMARY KEY AUTOINCREMENT,
                        name        TEXT NOT NULL,
                        vlan_min    INTEGER NOT NULL CHECK(vlan_min >= 1 AND vlan_min <= 4094),
                        vlan_max    INTEGER NOT NULL CHECK(vlan_max >= 1 AND vlan_max <= 4094),
                        description TEXT NOT NULL DEFAULT '',
                        site_id     INTEGER REFERENCES sites(id) ON DELETE SET NULL,
                        created_at  TEXT NOT NULL DEFAULT (datetime('now')),
                        updated_at  TEXT NOT NULL DEFAULT (datetime('now')),
                        CHECK(vlan_min <= vlan_max)
                    )
                ");
            }
        },

        // 2.4.0-aggregates: supernet/aggregate tracking
        '2.4.0-aggregates' => function(PDO $db): void {
            $tables = array_column(
                ($db->query("SELECT name FROM sqlite_master WHERE type='table'") ?: throw new \RuntimeException('Query failed'))->fetchAll(),
                'name'
            );
            if (!in_array('aggregates', $tables, true)) {
                $db->exec("
                    CREATE TABLE aggregates (
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
                    )
                ");
            }
        },

        // 2.4.0-pd-pools: IPv6 prefix delegation (RFC 3633)
        '2.4.0-pd-pools' => function(PDO $db): void {
            $tables = array_column(
                ($db->query("SELECT name FROM sqlite_master WHERE type='table'") ?: throw new \RuntimeException('Query failed'))->fetchAll(),
                'name'
            );
            if (!in_array('pd_pools', $tables, true)) {
                $db->exec("
                    CREATE TABLE pd_pools (
                        id                INTEGER PRIMARY KEY AUTOINCREMENT,
                        parent_subnet_id  INTEGER NOT NULL REFERENCES subnets(id) ON DELETE CASCADE,
                        delegation_prefix INTEGER NOT NULL CHECK(delegation_prefix BETWEEN 1 AND 128),
                        description       TEXT NOT NULL DEFAULT '',
                        site_id           INTEGER REFERENCES sites(id) ON DELETE SET NULL,
                        created_at        TEXT NOT NULL DEFAULT (datetime('now')),
                        updated_at        TEXT NOT NULL DEFAULT (datetime('now')),
                        UNIQUE(parent_subnet_id)
                    )
                ");
            }
            if (!in_array('pd_delegations', $tables, true)) {
                $db->exec("
                    CREATE TABLE pd_delegations (
                        id            INTEGER PRIMARY KEY AUTOINCREMENT,
                        pool_id       INTEGER NOT NULL REFERENCES pd_pools(id) ON DELETE CASCADE,
                        cidr          TEXT NOT NULL,
                        subscriber_id INTEGER REFERENCES contacts(id) ON DELETE SET NULL,
                        delegated_at  TEXT NOT NULL DEFAULT (datetime('now')),
                        expires_at    TEXT,
                        notes         TEXT NOT NULL DEFAULT '',
                        created_at    TEXT NOT NULL DEFAULT (datetime('now'))
                    )
                ");
            }
        },

        '2.6.0-settings' => \Closure::fromCallable('ipam_migrate_2_6_0_settings'),

        // v2.8.0 #316: long-form operational notes on subnets, separate from
        // the short-form description column used in table listings.
        '2.8.0-subnet-notes' => function(PDO $db): void {
            $cols = ($db->query("PRAGMA table_info(subnets)") ?: throw new \RuntimeException('Query failed'))->fetchAll();
            $names = array_map(fn($c) => to_str($c['name']), $cols);
            if (!in_array('notes', $names, true)) {
                $db->exec("ALTER TABLE subnets ADD COLUMN notes TEXT NOT NULL DEFAULT ''");
            }
        },

        // v2.8.0 #443: alert.email -> alert.recipient_user_ids. Seed the new
        // registry row, try to map the legacy free-text address to a single
        // active user via case-insensitive email match, and audit either the
        // automatic migration or the unmigratable value so the admin can
        // re-pick recipients on the settings page.
        '2.8.0-alert-recipients' => function(PDO $db): void {
            // settings table only exists on installs that ran 2.6.0-settings.
            // Fresh installs older than v2.6.0 are not supported; this guard
            // is for resilience during test fixtures.
            $tables = array_column(
                ($db->query("SELECT name FROM sqlite_master WHERE type='table'") ?: throw new \RuntimeException('Query failed'))->fetchAll(),
                'name'
            );
            if (!in_array('settings', $tables, true)) return;

            // Insert the new row with default '[]' if not already present.
            $ignore = ipam_dialect()->upsert_or_ignore('settings', ['key']);
            $db->prepare(
                "INSERT INTO settings (".ipam_key_col().", value, type) VALUES (:k, '[]', 'json') $ignore"
            )->execute([':k' => 'alert.recipient_user_ids']);

            // CodeRabbit M1 (PR #450): re-run safety. If alert.recipient_user_ids
            // is already non-default, the migration (or an admin) has already
            // populated it. Don't overwrite admin changes and don't audit a
            // duplicate auto-migrate row on partial fixture replays.
            $cur = $db->prepare("SELECT value FROM settings WHERE ".ipam_key_col()." = 'alert.recipient_user_ids'");
            $cur->execute();
            $curRow = $cur->fetch();
            $curVal = is_array($curRow) ? trim(to_str($curRow['value'] ?? '')) : '';
            if ($curVal !== '' && $curVal !== '[]') return;

            // Read the legacy alert.email value (may be missing or blank).
            $legacy = '';
            $st = $db->prepare("SELECT value FROM settings WHERE ".ipam_key_col()." = 'alert.email'");
            $st->execute();
            $row = $st->fetch();
            if (is_array($row)) $legacy = trim(to_str($row['value'] ?? ''));
            if ($legacy === '') return;

            // Look up exactly one active user with this email (case-insensitive).
            $users = ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'") ?: throw new \RuntimeException('Query failed'))->fetchAll();
            if (!$users) return;

            $u = $db->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(:e) AND is_active = 1");
            $u->execute([':e' => $legacy]);
            $matches = $u->fetchAll();

            if (count($matches) === 1) {
                $uid = to_int($matches[0]['id']);
                $payload = json_encode([$uid], JSON_UNESCAPED_SLASHES);
                $db->prepare("UPDATE settings SET value = :v WHERE ".ipam_key_col()." = 'alert.recipient_user_ids'")
                   ->execute([':v' => is_string($payload) ? $payload : '[]']);
                // Audit the auto-migration so it appears in audit.php and the
                // admin sees what happened.
                $db->prepare(
                    "INSERT INTO audit_log (action, entity_type, entity_id, user_id, username, ip, user_agent, details, created_at)
                     VALUES ('settings.auto_migrate_alert_email', 'setting', NULL, NULL, 'system', '', '', :d, datetime('now'))"
                )->execute([':d' => "from={$legacy} matched_user_id={$uid}"]);
            } else {
                // Either zero matches or ambiguous (>1 active user with the
                // same email). Don't drop the value silently — emit an audit
                // row so the admin can re-pick recipients on settings.php.
                $db->prepare(
                    "INSERT INTO audit_log (action, entity_type, entity_id, user_id, username, ip, user_agent, details, created_at)
                     VALUES ('settings.alert_email_unmigrated', 'setting', NULL, NULL, 'system', '', '', :d, datetime('now'))"
                )->execute([':d' => "from={$legacy} matched_count=" . count($matches)]);
            }
        },

        // v2.9.0 #410 (CRITICAL): normalize ip_bin / network_bin storage on
        // SQLite from TEXT affinity to BLOB affinity. Pre-v2.9.0 the project
        // used PDO::PARAM_STR (the default) for binary IP binding. SQLite's
        // loose typing honors the binding's affinity at insert time, not the
        // column's declared type, so 100% of existing data on every install
        // is stored with TEXT affinity. v2.9.0 switches to PDO::PARAM_LOB via
        // ipam_bind_binary(); without normalizing existing rows first, every
        // ORDER BY ip_bin and every range query breaks immediately, because
        // SQLite's comparison rules say any BLOB sorts greater than any TEXT
        // regardless of byte content.
        //
        // The migration rewrites every binary IP column row using explicit
        // PARAM_LOB binding. Bytes are preserved exactly. Idempotent: if all
        // rows in a target table already have BLOB affinity, the rewrite
        // loop is skipped. Re-running the migration on a fresh install (or
        // on a v2.9.0+ install that already has BLOB-affinity data) is a
        // no-op.
        '2.9.0-blob-affinity' => function(PDO $db): void {
            // SQLite-only: TEXT-vs-BLOB affinity is a quirk of SQLite's loose
            // typing. MySQL VARBINARY(16) and Postgres BYTEA do not have the
            // same problem — PARAM_LOB binding on those engines is correct
            // from v2.10.0 / v2.11.0 onward without a data rewrite. No-op
            // cleanly on non-SQLite drivers so the migration framework can
            // replay the full chain on any engine.
            if (ipam_dialect()->driver_name() !== 'sqlite') {
                return;
            }

            // Tables with binary IP columns. address_history.ip and
            // scan_results.ip are TEXT — only these two columns store
            // raw bytes from inet_pton().
            $targets = [
                ['table' => 'subnets',   'col' => 'network_bin'],
                ['table' => 'addresses', 'col' => 'ip_bin'],
            ];

            $totals = [];

            foreach ($targets as $t) {
                $table = $t['table'];
                $col   = $t['col'];

                // Skip tables that don't exist (defensive — fresh-install
                // schemas always have them, but partial test fixtures may not).
                $exists = $db->query(
                    "SELECT name FROM sqlite_master WHERE type='table' AND name=" . $db->quote($table)
                );
                if (!$exists || !$exists->fetch()) continue;

                // Idempotency check: how many rows are NOT already BLOB
                // affinity? typeof() returns 'blob' / 'text' / 'null' /
                // 'integer' / 'real'. We rewrite anything that isn't 'blob'.
                $countStmt = $db->query("SELECT COUNT(*) FROM {$table} WHERE typeof({$col}) != 'blob'");
                if (!$countStmt) continue;
                $needsRewrite = (int)$countStmt->fetchColumn();
                if ($needsRewrite === 0) {
                    $totals[$table] = 0;
                    continue;
                }

                // Stream every row that needs rewriting and rebind via
                // ipam_bind_binary() (PARAM_LOB). We select id + value rather
                // than UPDATE-in-place because the affinity flip happens at
                // bind time — there is no SQLite syntax for "force this
                // column to BLOB affinity" other than re-binding the value.
                $select = $db->query("SELECT id, {$col} AS bin FROM {$table} WHERE typeof({$col}) != 'blob'");
                if (!$select) continue;

                $update = $db->prepare("UPDATE {$table} SET {$col} = :bin WHERE id = :id");
                $rewritten = 0;
                while ($row = $select->fetch()) {
                    if (!is_array($row)) continue;
                    $bin = is_string($row['bin'] ?? null) ? $row['bin'] : '';
                    $id  = is_int($row['id'] ?? null) ? $row['id'] : (int)to_str($row['id'] ?? 0);
                    ipam_bind_binary($update, ':bin', $bin);
                    $update->bindValue(':id',  $id,  PDO::PARAM_INT);
                    $update->execute();
                    $rewritten++;
                }
                $totals[$table] = $rewritten;
            }

            // Audit a single row summarising the migration so admins can see
            // it ran in audit.php. Skip if no table needed rewriting (fresh
            // installs and re-runs both produce 0 audit noise). Guarded on
            // audit_log existence because ipam_db_init() recreates that
            // table after apply_migrations() — on a partial fixture where
            // the table has been dropped, a raw INSERT would abort the
            // transaction and the BLOB normalization would never commit.
            $totalRewritten = array_sum($totals);
            if ($totalRewritten > 0) {
                $auditExists = $db->query(
                    "SELECT name FROM sqlite_master WHERE type='table' AND name='audit_log'"
                );
                if ($auditExists && $auditExists->fetch()) {
                    $detailParts = [];
                    foreach ($totals as $tbl => $n) {
                        if ($n > 0) $detailParts[] = "{$tbl}={$n}";
                    }
                    $details = 'normalized ' . implode(', ', $detailParts);
                    $db->prepare(
                        "INSERT INTO audit_log (action, entity_type, entity_id, user_id, username, ip, user_agent, details, created_at)
                         VALUES ('migration.blob_affinity_normalized', 'migration', NULL, NULL, 'system', '', '', :d, " . ipam_dialect()->now() . ")"
                    )->execute([':d' => $details]);
                }
            }
        },

        '2.12.0-account-lockout' => function(PDO $db): void {
            $driver = ipam_dialect()->driver_name();

            // Guard: table may not exist in partial test fixtures
            if ($driver === 'sqlite') {
                $tblCheck = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='login_attempts'");
                if ($tblCheck === false || !$tblCheck->fetch()) return;
            }

            $hasCol = false;
            if ($driver === 'sqlite') {
                $pragmaResult = $db->query("PRAGMA table_info(login_attempts)");
                if ($pragmaResult !== false) {
                    /** @var array<string, mixed> $col */
                    foreach ($pragmaResult as $col) {
                        if (($col['name'] ?? '') === 'username') { $hasCol = true; break; }
                    }
                }
            } elseif ($driver === 'mysql') {
                $st = $db->prepare("SHOW COLUMNS FROM login_attempts LIKE 'username'");
                $st->execute();
                $hasCol = (bool)$st->fetch();
            } else {
                $st = $db->prepare(
                    "SELECT column_name FROM information_schema.columns
                     WHERE table_name = 'login_attempts' AND column_name = 'username'"
                );
                $st->execute();
                $hasCol = (bool)$st->fetch();
            }
            if (!$hasCol) {
                $colType = ($driver === 'mysql') ? 'VARCHAR(191) DEFAULT NULL' : 'TEXT DEFAULT NULL';
                $db->exec("ALTER TABLE login_attempts ADD COLUMN username {$colType}");
            }
            if ($driver === 'mysql') {
                $idx = $db->prepare("SHOW INDEX FROM login_attempts WHERE Key_name = 'idx_login_attempts_username_time'");
                $idx->execute();
                if (!$idx->fetch()) {
                    $db->exec("CREATE INDEX idx_login_attempts_username_time ON login_attempts(username, attempted_at)");
                }
            } else {
                $db->exec("CREATE INDEX IF NOT EXISTS idx_login_attempts_username_time ON login_attempts(username, attempted_at)");
            }
        },
        '3.0.0-config-stub' => function(PDO $db): void {
            $configPath = __DIR__ . '/config.php';
            if (!is_file($configPath)) return;

            $config = (array)(require $configPath);

            $definitions = ipam_setting_definitions();
            $existing = [];
            $kc = ipam_key_col();
            $allRows = $db->query("SELECT {$kc} AS k FROM settings");
            if ($allRows !== false) {
                /** @var array<string, mixed> $r */
                foreach ($allRows as $r) {
                    $existing[to_str($r['k'] ?? '')] = true;
                }
            }

            $imported = 0;
            foreach ($definitions as $key => $def) {
                if (isset($existing[$key])) continue;
                /** @var string|array<mixed>|null $configKey */
                $configKey = $def['config_key'] ?? null;
                if ($configKey === null) continue;
                $cfgVal = ipam_setting_config_fallback($config, $configKey);
                if ($cfgVal === null) continue;

                $default = $def['default'] ?? null;
                /** @var string $type */
                $type = $def['type'] ?? 'string';
                /** @var mixed $cfgVal */
                /** @var mixed $default */
                $same = match ($type) {
                    'bool'   => (bool)$cfgVal === (bool)$default,
                    'int'    => (int)(is_numeric($cfgVal) ? $cfgVal : 0) === (int)(is_numeric($default) ? $default : 0),
                    'json'   => $cfgVal === $default,
                    default  => (is_scalar($cfgVal) ? (string)$cfgVal : '') === (is_scalar($default) ? (string)$default : ''),
                };
                if ($same) continue;

                ipam_setting_set($db, $key, $cfgVal, null);
                $imported++;
            }

            $GLOBALS['_ipam_v3_config_stub_pending'] = [
                'config_path' => $configPath,
                'config'      => $config,
                'imported'    => $imported,
            ];
        },

        '3.0.0-config-stub-rewrite' => function(PDO $db): void {
            $pending = $GLOBALS['_ipam_v3_config_stub_pending'] ?? null;
            if (!is_array($pending)) return;
            unset($GLOBALS['_ipam_v3_config_stub_pending']);

            $configPath = to_str($pending['config_path']);
            $config     = (array)$pending['config'];
            $imported   = to_int($pending['imported']);

            $bakPath = $configPath . '.bak-v3upgrade';
            if (!is_file($bakPath)) {
                @copy($configPath, $bakPath);
            }

            $driver = to_str($config['db_driver'] ?? 'sqlite');
            $stub = "<?php\ndeclare(strict_types=1);\n\nreturn [\n"
                . "    'db_driver'    => " . var_export($driver, true) . ",\n";
            if ($driver === 'sqlite') {
                $dbPath = to_str($config['db_path'] ?? (__DIR__ . '/data/ipam.sqlite'));
                $configDir = dirname($configPath);
                if (str_starts_with($dbPath, $configDir . '/')) {
                    $relPath = substr($dbPath, strlen($configDir));
                    $stub .= "    'db_path'      => __DIR__ . " . var_export($relPath, true) . ",\n";
                } else {
                    $stub .= "    'db_path'      => " . var_export($dbPath, true) . ",\n";
                }
            } else {
                $stub .= "    'db_dsn'       => " . var_export(to_str($config['db_dsn'] ?? ''), true) . ",\n"
                    . "    'db_user'      => " . var_export(to_str($config['db_user'] ?? ''), true) . ",\n"
                    . "    'db_pass'      => " . var_export(to_str($config['db_pass'] ?? ''), true) . ",\n";
            }
            $stub .= "    'session_name' => " . var_export(to_str($config['session_name'] ?? 'IPAMSESSID'), true) . ",\n"
                . "    'force_https'  => " . var_export((bool)($config['force_https'] ?? true), true) . ",\n";

            if (!empty($config['proxy_trust'])) {
                $stub .= "    'proxy_trust'  => true,\n";
            }
            if (!empty($config['base_url'])) {
                $stub .= "    'base_url'     => " . var_export(to_str($config['base_url']), true) . ",\n";
            }
            if (!empty($config['session_cookie_path'])) {
                $stub .= "    'session_cookie_path' => " . var_export(to_str($config['session_cookie_path']), true) . ",\n";
            }
            if (!empty($config['recovery_mode'])) {
                $stub .= "    'recovery_mode' => true,\n";
            }
            if (isset($config['bootstrap_admin'])) {
                $ba = (array)$config['bootstrap_admin'];
                $stub .= "    'bootstrap_admin' => [\n"
                    . "        'username' => " . var_export(to_str($ba['username'] ?? 'admin'), true) . ",\n"
                    . "        'password' => " . var_export(to_str($ba['password'] ?? 'ChangeMeNow!12345'), true) . ",\n"
                    . "    ],\n";
            }
            if (isset($config['demo_mode'])) {
                $dm = (array)$config['demo_mode'];
                $stub .= "    'demo_mode' => [\n"
                    . "        'enabled'    => " . var_export((bool)($dm['enabled'] ?? false), true) . ",\n"
                    . "        'gate'       => " . var_export($dm['gate'] ?? null, true) . ",\n"
                    . "        'site_key'   => " . var_export(to_str($dm['site_key'] ?? ''), true) . ",\n"
                    . "        'secret_key' => " . var_export(to_str($dm['secret_key'] ?? ''), true) . ",\n"
                    . "    ],\n";
            }
            $stub .= "];\n";

            @file_put_contents($configPath, $stub, LOCK_EX);

            if ($imported > 0) {
                try {
                    audit($db, 'config.migrate_to_stub', 'system', null,
                        "Migrated {$imported} setting(s) from config.php to settings table.");
                } catch (\Throwable $e) {
                    error_log('config.migrate_to_stub audit failed: ' . $e->getMessage());
                }
            }
        },

        '3.0.0-site-contacts' => function(PDO $db): void {
            $driver = ipam_dialect()->driver_name();
            if ($driver === 'sqlite') {
                $tbl = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='site_contacts'");
                if ($tbl !== false && $tbl->fetch()) return;
            } elseif ($driver === 'mysql') {
                $tbl = $db->query("SHOW TABLES LIKE 'site_contacts'");
                if ($tbl !== false && $tbl->fetch()) return;
            } else {
                $tbl = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_name='site_contacts'");
                $tbl->execute();
                if ($tbl->fetch()) return;
            }
            $intType = ($driver === 'mysql') ? 'BIGINT UNSIGNED' : ($driver === 'pgsql' ? 'BIGINT' : 'INTEGER');
            $roleType = ($driver === 'mysql') ? "VARCHAR(191) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''";
            $fk = ($driver === 'mysql')
                ? ", CONSTRAINT fk_site_contacts_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE, CONSTRAINT fk_site_contacts_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE"
                : '';
            $inlineRef = ($driver === 'mysql') ? '' : ' REFERENCES sites(id) ON DELETE CASCADE';
            $inlineRef2 = ($driver === 'mysql') ? '' : ' REFERENCES contacts(id) ON DELETE CASCADE';
            $db->exec("CREATE TABLE site_contacts (
                site_id    {$intType} NOT NULL{$inlineRef},
                contact_id {$intType} NOT NULL{$inlineRef2},
                role       {$roleType},
                PRIMARY KEY (site_id, contact_id){$fk}
            )" . ($driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : ''));
        },

        '3.1.0-user-timezone' => function(PDO $db): void {
            $driver = ipam_dialect()->driver_name();
            $hasCol = false;
            if ($driver === 'sqlite') {
                $tblSt = $db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='users'");
                $tbl = $tblSt ? $tblSt->fetch() : false;
                if (!$tbl) return;
                foreach (($db->query("PRAGMA table_info(users)") ?: throw new \RuntimeException('Query failed'))->fetchAll() as $col) {
                    if (to_str($col['name']) === 'timezone') { $hasCol = true; break; }
                }
            } elseif ($driver === 'mysql') {
                $st = $db->prepare("SHOW COLUMNS FROM users LIKE 'timezone'");
                $st->execute();
                $hasCol = $st->fetch() !== false;
            } else {
                $st = $db->prepare(
                    "SELECT 1 FROM information_schema.columns
                     WHERE table_name='users' AND column_name='timezone'"
                );
                $st->execute();
                $hasCol = $st->fetch() !== false;
            }
            if (!$hasCol) {
                $db->exec("ALTER TABLE users ADD COLUMN timezone TEXT");
            }
        },

        '3.1.0-subnet-alerts-enabled' => function(PDO $db): void {
            $driver = ipam_dialect()->driver_name();
            $hasCol = false;
            if ($driver === 'sqlite') {
                $res = $db->query("PRAGMA table_info(subnets)");
                if ($res !== false) {
                    /** @var array<string, mixed> $col */
                    foreach ($res as $col) {
                        if (($col['name'] ?? '') === 'alerts_enabled') { $hasCol = true; break; }
                    }
                }
            } elseif ($driver === 'mysql') {
                $st = $db->prepare("SHOW COLUMNS FROM subnets LIKE 'alerts_enabled'");
                $st->execute();
                $hasCol = (bool)$st->fetch();
            } else {
                $st = $db->prepare(
                    "SELECT column_name FROM information_schema.columns
                     WHERE table_name = 'subnets' AND column_name = 'alerts_enabled'"
                );
                $st->execute();
                $hasCol = (bool)$st->fetch();
            }
            if (!$hasCol) {
                $colType = ($driver === 'mysql') ? 'TINYINT(1) NOT NULL DEFAULT 1' : 'INTEGER NOT NULL DEFAULT 1';
                $db->exec("ALTER TABLE subnets ADD COLUMN alerts_enabled {$colType}");
            }
        },

        '3.1.0-utilization-snapshots' => function(PDO $db): void {
            $driver = ipam_dialect()->driver_name();
            if ($driver === 'sqlite') {
                $tbl = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='utilization_snapshots'");
                if ($tbl !== false && $tbl->fetch()) return;
                $db->exec("CREATE TABLE IF NOT EXISTS utilization_snapshots (
                    id          INTEGER PRIMARY KEY AUTOINCREMENT,
                    subnet_id   INTEGER NOT NULL REFERENCES subnets(id) ON DELETE CASCADE,
                    snapped_at  TEXT    NOT NULL DEFAULT (datetime('now')),
                    used_count  INTEGER NOT NULL,
                    free_count  INTEGER NOT NULL,
                    total_hosts INTEGER NOT NULL
                )");
                $db->exec("CREATE INDEX IF NOT EXISTS idx_util_snap_subnet_time ON utilization_snapshots(subnet_id, snapped_at)");
            } elseif ($driver === 'mysql') {
                $tbl = $db->query("SHOW TABLES LIKE 'utilization_snapshots'");
                if ($tbl !== false && $tbl->fetch()) return;
                $db->exec("CREATE TABLE IF NOT EXISTS utilization_snapshots (
                    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    subnet_id   BIGINT UNSIGNED NOT NULL,
                    snapped_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    used_count  INT NOT NULL,
                    free_count  INT NOT NULL,
                    total_hosts INT NOT NULL,
                    CONSTRAINT fk_util_snap_subnet FOREIGN KEY (subnet_id) REFERENCES subnets(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
                $db->exec("CREATE INDEX idx_util_snap_subnet_time ON utilization_snapshots(subnet_id, snapped_at)");
            } else {
                // pgsql
                $tbl = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_name='utilization_snapshots'");
                $tbl->execute();
                if ($tbl->fetch()) return;
                $db->exec("CREATE TABLE IF NOT EXISTS utilization_snapshots (
                    id          BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                    subnet_id   BIGINT NOT NULL REFERENCES subnets(id) ON DELETE CASCADE,
                    snapped_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                    used_count  INTEGER NOT NULL,
                    free_count  INTEGER NOT NULL,
                    total_hosts INTEGER NOT NULL
                )");
                $db->exec("CREATE INDEX IF NOT EXISTS idx_util_snap_subnet_time ON utilization_snapshots(subnet_id, snapped_at)");
            }
        },

        '3.0.0-subnet-contacts' => function(PDO $db): void {
            $driver = ipam_dialect()->driver_name();
            if ($driver === 'sqlite') {
                $tbl = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='subnet_contacts'");
                if ($tbl !== false && $tbl->fetch()) return;
            } elseif ($driver === 'mysql') {
                $tbl = $db->query("SHOW TABLES LIKE 'subnet_contacts'");
                if ($tbl !== false && $tbl->fetch()) return;
            } else {
                $tbl = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_name='subnet_contacts'");
                $tbl->execute();
                if ($tbl->fetch()) return;
            }
            $intType = ($driver === 'mysql') ? 'BIGINT UNSIGNED' : ($driver === 'pgsql' ? 'BIGINT' : 'INTEGER');
            $roleType = ($driver === 'mysql') ? "VARCHAR(191) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''";
            $fk = ($driver === 'mysql')
                ? ", CONSTRAINT fk_subnet_contacts_subnet FOREIGN KEY (subnet_id) REFERENCES subnets(id) ON DELETE CASCADE, CONSTRAINT fk_subnet_contacts_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE"
                : '';
            $inlineRef = ($driver === 'mysql') ? '' : ' REFERENCES subnets(id) ON DELETE CASCADE';
            $inlineRef2 = ($driver === 'mysql') ? '' : ' REFERENCES contacts(id) ON DELETE CASCADE';
            $db->exec("CREATE TABLE subnet_contacts (
                subnet_id  {$intType} NOT NULL{$inlineRef},
                contact_id {$intType} NOT NULL{$inlineRef2},
                role       {$roleType},
                PRIMARY KEY (subnet_id, contact_id){$fk}
            )" . ($driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : ''));
        },

        // 3.2.0-devices: device and device_interface tables, plus FK columns on addresses
        '3.2.0-devices' => function(PDO $db): void {
            $driver = ipam_dialect()->driver_name();

            // ── 1. Create devices table ──────────────────────────────────────
            $devExists = false;
            if ($driver === 'sqlite') {
                $r = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='devices'");
                $devExists = $r !== false && (bool)$r->fetch();
            } elseif ($driver === 'mysql') {
                $r = $db->query("SHOW TABLES LIKE 'devices'");
                $devExists = $r !== false && (bool)$r->fetch();
            } else {
                $r = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_name='devices'");
                $r->execute();
                $devExists = (bool)$r->fetch();
            }
            if (!$devExists) {
                if ($driver === 'sqlite') {
                    $db->exec("CREATE TABLE IF NOT EXISTS devices (
                        id         INTEGER PRIMARY KEY AUTOINCREMENT,
                        name       TEXT NOT NULL,
                        type       TEXT NOT NULL DEFAULT 'other',
                        site_id    INTEGER REFERENCES sites(id) ON DELETE SET NULL,
                        vendor     TEXT NOT NULL DEFAULT '',
                        model      TEXT NOT NULL DEFAULT '',
                        serial     TEXT NOT NULL DEFAULT '',
                        note       TEXT NOT NULL DEFAULT '',
                        created_at TEXT NOT NULL DEFAULT (datetime('now')),
                        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
                    )");
                    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_devices_name ON devices(name)");
                    $db->exec("CREATE TRIGGER IF NOT EXISTS devices_updated_at
                        AFTER UPDATE ON devices FOR EACH ROW
                        BEGIN UPDATE devices SET updated_at = datetime('now') WHERE id = OLD.id; END");
                } elseif ($driver === 'mysql') {
                    $db->exec("CREATE TABLE IF NOT EXISTS devices (
                        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        name       VARCHAR(191) NOT NULL,
                        type       VARCHAR(50)  NOT NULL DEFAULT 'other',
                        site_id    BIGINT UNSIGNED NULL,
                        vendor     VARCHAR(191) NOT NULL DEFAULT '',
                        model      VARCHAR(191) NOT NULL DEFAULT '',
                        serial     VARCHAR(191) NOT NULL DEFAULT '',
                        note       VARCHAR(1000) NOT NULL DEFAULT '',
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        UNIQUE KEY uq_devices_name (name),
                        CONSTRAINT fk_devices_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
                } else {
                    $db->exec("CREATE TABLE IF NOT EXISTS devices (
                        id         BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                        name       TEXT NOT NULL UNIQUE,
                        type       TEXT NOT NULL DEFAULT 'other',
                        site_id    BIGINT REFERENCES sites(id) ON DELETE SET NULL,
                        vendor     TEXT NOT NULL DEFAULT '',
                        model      TEXT NOT NULL DEFAULT '',
                        serial     TEXT NOT NULL DEFAULT '',
                        note       TEXT NOT NULL DEFAULT '',
                        created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                        updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                    )");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_devices_site ON devices(site_id)");
                }
            }

            // ── 2. Create device_interfaces table ───────────────────────────
            $ifExists = false;
            if ($driver === 'sqlite') {
                $r = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='device_interfaces'");
                $ifExists = $r !== false && (bool)$r->fetch();
            } elseif ($driver === 'mysql') {
                $r = $db->query("SHOW TABLES LIKE 'device_interfaces'");
                $ifExists = $r !== false && (bool)$r->fetch();
            } else {
                $r = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_name='device_interfaces'");
                $r->execute();
                $ifExists = (bool)$r->fetch();
            }
            if (!$ifExists) {
                if ($driver === 'sqlite') {
                    $db->exec("CREATE TABLE IF NOT EXISTS device_interfaces (
                        id          INTEGER PRIMARY KEY AUTOINCREMENT,
                        device_id   INTEGER NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
                        name        TEXT NOT NULL,
                        description TEXT NOT NULL DEFAULT '',
                        created_at  TEXT NOT NULL DEFAULT (datetime('now')),
                        updated_at  TEXT NOT NULL DEFAULT (datetime('now')),
                        UNIQUE(device_id, name)
                    )");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_di_device ON device_interfaces(device_id)");
                    $db->exec("CREATE TRIGGER IF NOT EXISTS device_interfaces_updated_at
                        AFTER UPDATE ON device_interfaces FOR EACH ROW
                        BEGIN UPDATE device_interfaces SET updated_at = datetime('now') WHERE id = OLD.id; END");
                } elseif ($driver === 'mysql') {
                    $db->exec("CREATE TABLE IF NOT EXISTS device_interfaces (
                        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        device_id   BIGINT UNSIGNED NOT NULL,
                        name        VARCHAR(191) NOT NULL,
                        description VARCHAR(1000) NOT NULL DEFAULT '',
                        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        UNIQUE KEY uq_di_device_name (device_id, name),
                        CONSTRAINT fk_di_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
                } else {
                    $db->exec("CREATE TABLE IF NOT EXISTS device_interfaces (
                        id          BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                        device_id   BIGINT NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
                        name        TEXT NOT NULL,
                        description TEXT NOT NULL DEFAULT '',
                        created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                        updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                        UNIQUE(device_id, name)
                    )");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_di_device ON device_interfaces(device_id)");
                }
            }

            // ── 3. Add device_id + interface_id columns to addresses ─────────
            $hasDeviceId    = false;
            $hasInterfaceId = false;
            if ($driver === 'sqlite') {
                $cols = array_column(
                    ($db->query("PRAGMA table_info(addresses)") ?: throw new \RuntimeException('Query failed'))->fetchAll(),
                    'name'
                );
                $hasDeviceId    = in_array('device_id',    $cols, true);
                $hasInterfaceId = in_array('interface_id', $cols, true);
            } elseif ($driver === 'mysql') {
                $st = $db->prepare("SHOW COLUMNS FROM addresses LIKE 'device_id'");
                $st->execute();
                $hasDeviceId = (bool)$st->fetch();
                $st = $db->prepare("SHOW COLUMNS FROM addresses LIKE 'interface_id'");
                $st->execute();
                $hasInterfaceId = (bool)$st->fetch();
            } else {
                $st = $db->prepare(
                    "SELECT column_name FROM information_schema.columns
                     WHERE table_name = 'addresses' AND column_name = 'device_id'"
                );
                $st->execute();
                $hasDeviceId = (bool)$st->fetch();
                $st = $db->prepare(
                    "SELECT column_name FROM information_schema.columns
                     WHERE table_name = 'addresses' AND column_name = 'interface_id'"
                );
                $st->execute();
                $hasInterfaceId = (bool)$st->fetch();
            }
            if (!$hasDeviceId) {
                if ($driver === 'mysql') {
                    $db->exec(
                        "ALTER TABLE addresses
                         ADD COLUMN device_id BIGINT UNSIGNED NULL,
                         ADD CONSTRAINT fk_addresses_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE SET NULL,
                         ADD INDEX idx_addresses_device_id (device_id)"
                    );
                } elseif ($driver === 'pgsql') {
                    $db->exec("ALTER TABLE addresses ADD COLUMN device_id BIGINT REFERENCES devices(id) ON DELETE SET NULL");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_addresses_device_id ON addresses(device_id)");
                } else {
                    $db->exec("ALTER TABLE addresses ADD COLUMN device_id INTEGER REFERENCES devices(id) ON DELETE SET NULL");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_addresses_device_id ON addresses(device_id)");
                }
            }
            if (!$hasInterfaceId) {
                if ($driver === 'mysql') {
                    $db->exec(
                        "ALTER TABLE addresses
                         ADD COLUMN interface_id BIGINT UNSIGNED NULL,
                         ADD CONSTRAINT fk_addresses_interface FOREIGN KEY (interface_id) REFERENCES device_interfaces(id) ON DELETE SET NULL,
                         ADD INDEX idx_addresses_interface_id (interface_id)"
                    );
                } elseif ($driver === 'pgsql') {
                    $db->exec("ALTER TABLE addresses ADD COLUMN interface_id BIGINT REFERENCES device_interfaces(id) ON DELETE SET NULL");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_addresses_interface_id ON addresses(interface_id)");
                } else {
                    $db->exec("ALTER TABLE addresses ADD COLUMN interface_id INTEGER REFERENCES device_interfaces(id) ON DELETE SET NULL");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_addresses_interface_id ON addresses(interface_id)");
                }
            }
        },

        // 3.2.0-password-reset: password_reset_tokens table + pending email columns on users
        '3.2.0-password-reset' => function(PDO $db): void {
            $driver = ipam_dialect()->driver_name();

            // ── 1. Create password_reset_tokens table ────────────────────────
            $tblExists = false;
            if ($driver === 'sqlite') {
                $r = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='password_reset_tokens'");
                $tblExists = $r !== false && (bool)$r->fetch();
            } elseif ($driver === 'mysql') {
                $r = $db->query("SHOW TABLES LIKE 'password_reset_tokens'");
                $tblExists = $r !== false && (bool)$r->fetch();
            } else {
                $r = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_name='password_reset_tokens'");
                $r->execute();
                $tblExists = (bool)$r->fetch();
            }
            if (!$tblExists) {
                if ($driver === 'sqlite') {
                    $db->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
                        id         INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                        token_hash TEXT NOT NULL UNIQUE,
                        expires_at TEXT NOT NULL,
                        used_at    TEXT,
                        created_at TEXT NOT NULL DEFAULT (datetime('now'))
                    )");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_prt_user ON password_reset_tokens(user_id)");
                } elseif ($driver === 'mysql') {
                    $db->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
                        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        user_id    BIGINT UNSIGNED NOT NULL,
                        token_hash VARCHAR(64) COLLATE utf8mb4_bin NOT NULL UNIQUE,
                        expires_at DATETIME NOT NULL,
                        used_at    DATETIME NULL,
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        CONSTRAINT fk_prt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                        KEY idx_prt_user (user_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                } else {
                    $db->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
                        id         BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                        user_id    BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                        token_hash TEXT NOT NULL UNIQUE,
                        expires_at TIMESTAMPTZ NOT NULL,
                        used_at    TIMESTAMPTZ,
                        created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                    )");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_prt_user ON password_reset_tokens(user_id)");
                }
            }

            // ── 2. Add pending email columns to users ────────────────────────
            // Guard: users table is created by an early migration. On a pre-2.0
            // snapshot DB (unit tests only) it may not exist — skip gracefully.
            if ($driver === 'sqlite') {
                $usersExistSt = $db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='users'");
                if ($usersExistSt === false || !$usersExistSt->fetch()) {
                    return; // users table absent — nothing to alter
                }
                $userCols = array_column(
                    ($db->query("PRAGMA table_info(users)") ?: throw new \RuntimeException('Query failed'))->fetchAll(),
                    'name'
                );
                if (!in_array('pending_email', $userCols, true)) {
                    $db->exec("ALTER TABLE users ADD COLUMN pending_email TEXT");
                }
                if (!in_array('pending_email_token_hash', $userCols, true)) {
                    $db->exec("ALTER TABLE users ADD COLUMN pending_email_token_hash TEXT");
                }
                if (!in_array('pending_email_expires_at', $userCols, true)) {
                    $db->exec("ALTER TABLE users ADD COLUMN pending_email_expires_at TEXT");
                }
            } elseif ($driver === 'mysql') {
                $st = $db->prepare("SHOW COLUMNS FROM users LIKE 'pending_email'");
                $st->execute();
                if (!$st->fetch()) {
                    $db->exec("ALTER TABLE users ADD COLUMN pending_email VARCHAR(255)");
                }
                $st = $db->prepare("SHOW COLUMNS FROM users LIKE 'pending_email_token_hash'");
                $st->execute();
                if (!$st->fetch()) {
                    $db->exec("ALTER TABLE users ADD COLUMN pending_email_token_hash VARCHAR(64) COLLATE utf8mb4_bin");
                }
                $st = $db->prepare("SHOW COLUMNS FROM users LIKE 'pending_email_expires_at'");
                $st->execute();
                if (!$st->fetch()) {
                    $db->exec("ALTER TABLE users ADD COLUMN pending_email_expires_at DATETIME");
                }
            } else {
                $checkCol = function(string $col) use ($db): bool {
                    $st = $db->prepare(
                        "SELECT 1 FROM information_schema.columns
                         WHERE table_name = 'users' AND column_name = :col"
                    );
                    $st->execute([':col' => $col]);
                    return (bool)$st->fetch();
                };
                if (!$checkCol('pending_email')) {
                    $db->exec("ALTER TABLE users ADD COLUMN pending_email TEXT");
                }
                if (!$checkCol('pending_email_token_hash')) {
                    $db->exec("ALTER TABLE users ADD COLUMN pending_email_token_hash TEXT");
                }
                if (!$checkCol('pending_email_expires_at')) {
                    $db->exec("ALTER TABLE users ADD COLUMN pending_email_expires_at TIMESTAMPTZ");
                }
            }
        },

        // 3.3.0-webhooks: webhooks + webhook_deliveries tables
        '3.3.0-webhooks' => function(PDO $db): void {
            $driver = ipam_dialect()->driver_name();

            // Helper: table existence check
            $tableExists = function(string $name) use ($db, $driver): bool {
                if ($driver === 'sqlite') {
                    $r = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name=" . $db->quote($name));
                    return $r !== false && (bool)$r->fetch();
                } elseif ($driver === 'mysql') {
                    $r = $db->query("SHOW TABLES LIKE " . $db->quote($name));
                    return $r !== false && (bool)$r->fetch();
                } else {
                    $st = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_name=:n");
                    $st->execute([':n' => $name]);
                    return (bool)$st->fetch();
                }
            };

            // ── 1. webhooks table ────────────────────────────────────────────
            if (!$tableExists('webhooks')) {
                if ($driver === 'sqlite') {
                    $db->exec("CREATE TABLE webhooks (
                        id                    INTEGER PRIMARY KEY AUTOINCREMENT,
                        name                  TEXT NOT NULL,
                        url                   TEXT NOT NULL,
                        secret                TEXT NOT NULL,
                        events                TEXT NOT NULL DEFAULT '[]',
                        is_active             INTEGER NOT NULL DEFAULT 1,
                        created_at            TEXT NOT NULL DEFAULT (datetime('now')),
                        last_delivery_at      TEXT,
                        last_delivery_status  INTEGER
                    )");
                } elseif ($driver === 'mysql') {
                    $db->exec("CREATE TABLE webhooks (
                        id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        name                  VARCHAR(255) NOT NULL,
                        url                   TEXT NOT NULL,
                        secret                VARCHAR(255) NOT NULL,
                        events                TEXT NOT NULL,
                        is_active             TINYINT(1) NOT NULL DEFAULT 1,
                        created_at            DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
                        last_delivery_at      DATETIME NULL,
                        last_delivery_status  SMALLINT NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                } else {
                    $db->exec("CREATE TABLE webhooks (
                        id                    BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                        name                  TEXT NOT NULL,
                        url                   TEXT NOT NULL,
                        secret                TEXT NOT NULL,
                        events                TEXT NOT NULL DEFAULT '[]',
                        is_active             SMALLINT NOT NULL DEFAULT 1,
                        created_at            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                        last_delivery_at      TIMESTAMPTZ,
                        last_delivery_status  SMALLINT
                    )");
                }
            }

            // ── 2. webhook_deliveries table ──────────────────────────────────
            if (!$tableExists('webhook_deliveries')) {
                if ($driver === 'sqlite') {
                    $db->exec("CREATE TABLE webhook_deliveries (
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
                    )");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_wh_deliveries_wh
                               ON webhook_deliveries(webhook_id, created_at DESC)");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_wh_deliveries_pending
                               ON webhook_deliveries(delivered_at, attempt)");
                } elseif ($driver === 'mysql') {
                    $db->exec("CREATE TABLE webhook_deliveries (
                        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        webhook_id    BIGINT UNSIGNED NOT NULL,
                        event_type    VARCHAR(100) NOT NULL,
                        payload       MEDIUMTEXT NOT NULL,
                        signature     VARCHAR(100) NOT NULL,
                        attempt       TINYINT UNSIGNED NOT NULL DEFAULT 1,
                        http_status   SMALLINT NULL,
                        response_body TEXT NULL,
                        error         TEXT NULL,
                        created_at    DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
                        delivered_at  DATETIME NULL,
                        CONSTRAINT fk_whd_webhook FOREIGN KEY (webhook_id) REFERENCES webhooks(id) ON DELETE CASCADE,
                        KEY idx_wh_deliveries_wh (webhook_id, created_at),
                        KEY idx_wh_deliveries_pending (delivered_at, attempt)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                } else {
                    $db->exec("CREATE TABLE webhook_deliveries (
                        id            BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                        webhook_id    BIGINT NOT NULL REFERENCES webhooks(id) ON DELETE CASCADE,
                        event_type    TEXT NOT NULL,
                        payload       TEXT NOT NULL,
                        signature     TEXT NOT NULL,
                        attempt       SMALLINT NOT NULL DEFAULT 1,
                        http_status   SMALLINT,
                        response_body TEXT,
                        error         TEXT,
                        created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                        delivered_at  TIMESTAMPTZ
                    )");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_wh_deliveries_wh
                               ON webhook_deliveries(webhook_id, created_at DESC)");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_wh_deliveries_pending
                               ON webhook_deliveries(delivered_at, attempt)");
                }
            }
        },

        // 3.4.0-dhcp-options: add 7 nullable DHCP option columns to subnets (#402)
        '3.4.0-dhcp-options' => function(PDO $db): void {
            $driver = ipam_dialect()->driver_name();
            $cols = [
                'dhcp_routers'      => ($driver === 'mysql') ? 'TEXT DEFAULT NULL' : 'TEXT',
                'dhcp_dns_servers'  => ($driver === 'mysql') ? 'TEXT DEFAULT NULL' : 'TEXT',
                'dhcp_domain_name'  => ($driver === 'mysql') ? 'TEXT DEFAULT NULL' : 'TEXT',
                'dhcp_lease_default'=> ($driver === 'mysql') ? 'INT DEFAULT NULL'  : 'INTEGER',
                'dhcp_lease_max'    => ($driver === 'mysql') ? 'INT DEFAULT NULL'  : 'INTEGER',
                'dhcp_next_server'  => ($driver === 'mysql') ? 'TEXT DEFAULT NULL' : 'TEXT',
                'dhcp_boot_filename'=> ($driver === 'mysql') ? 'TEXT DEFAULT NULL' : 'TEXT',
            ];
            foreach ($cols as $col => $colType) {
                $exists = false;
                if ($driver === 'sqlite') {
                    $res = $db->query("PRAGMA table_info(subnets)");
                    if ($res !== false) {
                        /** @var array<string, mixed> $row */
                        foreach ($res as $row) {
                            if (($row['name'] ?? '') === $col) { $exists = true; break; }
                        }
                    }
                } elseif ($driver === 'mysql') {
                    $st = $db->prepare(
                        "SELECT column_name FROM information_schema.columns
                         WHERE table_schema = DATABASE() AND table_name = 'subnets' AND column_name = :col"
                    );
                    $st->execute([':col' => $col]);
                    $exists = (bool)$st->fetch();
                } else {
                    $st = $db->prepare(
                        "SELECT column_name FROM information_schema.columns
                         WHERE table_name = 'subnets' AND column_name = :col"
                    );
                    $st->execute([':col' => $col]);
                    $exists = (bool)$st->fetch();
                }
                if (!$exists) {
                    $db->exec("ALTER TABLE subnets ADD COLUMN {$col} {$colType}");
                }
            }
        },

        // 3.5.0-custom-fields: admin-defined key/value metadata (#313, #595)
        //  - creates custom_field_defs table (definitions per entity type)
        //  - adds subnets.custom_fields  TEXT NOT NULL DEFAULT '{}' (JSON-in-row)
        //  - adds addresses.custom_fields TEXT NOT NULL DEFAULT '{}' (JSON-in-row)
        // JSON is stored as plain TEXT on all three engines so SchemaParityTest
        // sees the same type_class ('text') everywhere; json_extract / json_remove
        // work on SQLite 3.38+, MySQL 8.0+, and Postgres 14+.
        '3.5.0-custom-fields' => function(PDO $db): void {
            $driver = ipam_dialect()->driver_name();

            // ── 1. Create custom_field_defs table ────────────────────────────
            $defsExists = false;
            if ($driver === 'sqlite') {
                $r = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='custom_field_defs'");
                $defsExists = $r !== false && (bool)$r->fetch();
            } elseif ($driver === 'mysql') {
                $r = $db->query("SHOW TABLES LIKE 'custom_field_defs'");
                $defsExists = $r !== false && (bool)$r->fetch();
            } else {
                $r = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_name='custom_field_defs'");
                $r->execute();
                $defsExists = (bool)$r->fetch();
            }
            if (!$defsExists) {
                if ($driver === 'sqlite') {
                    $db->exec("CREATE TABLE IF NOT EXISTS custom_field_defs (
                        id          INTEGER PRIMARY KEY AUTOINCREMENT,
                        entity_type TEXT    NOT NULL,
                        key         TEXT    NOT NULL,
                        label       TEXT    NOT NULL,
                        type        TEXT    NOT NULL DEFAULT 'text',
                        options     TEXT,
                        sort_order  INTEGER NOT NULL DEFAULT 0,
                        is_required INTEGER NOT NULL DEFAULT 0,
                        is_deleted  INTEGER NOT NULL DEFAULT 0,
                        created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
                        updated_at  TEXT    NOT NULL DEFAULT (datetime('now')),
                        UNIQUE(entity_type, key)
                    )");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_cfd_entity_order ON custom_field_defs(entity_type, sort_order)");
                    $db->exec("CREATE TRIGGER IF NOT EXISTS custom_field_defs_updated_at
                        AFTER UPDATE ON custom_field_defs FOR EACH ROW
                        BEGIN UPDATE custom_field_defs SET updated_at = datetime('now') WHERE id = OLD.id; END");
                } elseif ($driver === 'mysql') {
                    $db->exec("CREATE TABLE IF NOT EXISTS custom_field_defs (
                        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        entity_type VARCHAR(20)  NOT NULL,
                        `key`       VARCHAR(64)  COLLATE utf8mb4_bin NOT NULL,
                        label       VARCHAR(191) NOT NULL,
                        type        VARCHAR(20)  NOT NULL DEFAULT 'text',
                        options     TEXT         NULL,
                        sort_order  INT          NOT NULL DEFAULT 0,
                        is_required TINYINT      NOT NULL DEFAULT 0,
                        is_deleted  TINYINT      NOT NULL DEFAULT 0,
                        created_at  DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
                        updated_at  DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
                        UNIQUE KEY uq_cfd_entity_key (entity_type, `key`),
                        KEY idx_cfd_entity_order (entity_type, sort_order)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
                } else {
                    $db->exec("CREATE TABLE IF NOT EXISTS custom_field_defs (
                        id          BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                        entity_type TEXT     NOT NULL,
                        \"key\"     TEXT     COLLATE \"C\" NOT NULL,
                        label       TEXT     NOT NULL,
                        type        TEXT     NOT NULL DEFAULT 'text',
                        options     TEXT     NULL,
                        sort_order  INTEGER  NOT NULL DEFAULT 0,
                        is_required SMALLINT NOT NULL DEFAULT 0,
                        is_deleted  SMALLINT NOT NULL DEFAULT 0,
                        created_at  TIMESTAMP NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc'),
                        updated_at  TIMESTAMP NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc'),
                        CONSTRAINT uq_cfd_entity_key UNIQUE (entity_type, \"key\")
                    )");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_cfd_entity_order ON custom_field_defs(entity_type, sort_order)");
                }
            }

            // ── 2. Add custom_fields TEXT column to subnets + addresses ──────
            foreach (['subnets', 'addresses'] as $tbl) {
                $has = false;
                if ($driver === 'sqlite') {
                    $res = $db->query("PRAGMA table_info({$tbl})");
                    if ($res !== false) {
                        /** @var array<string, mixed> $row */
                        foreach ($res as $row) {
                            if (($row['name'] ?? '') === 'custom_fields') { $has = true; break; }
                        }
                    }
                } elseif ($driver === 'mysql') {
                    $st = $db->prepare(
                        "SELECT column_name FROM information_schema.columns
                         WHERE table_schema = DATABASE() AND table_name = :t AND column_name = 'custom_fields'"
                    );
                    $st->execute([':t' => $tbl]);
                    $has = (bool)$st->fetch();
                } else {
                    $st = $db->prepare(
                        "SELECT column_name FROM information_schema.columns
                         WHERE table_name = :t AND column_name = 'custom_fields'"
                    );
                    $st->execute([':t' => $tbl]);
                    $has = (bool)$st->fetch();
                }
                if (!$has) {
                    if ($driver === 'mysql') {
                        // MySQL 8.0.13+ requires expression-form DEFAULT for TEXT columns.
                        $db->exec("ALTER TABLE {$tbl} ADD COLUMN custom_fields TEXT NOT NULL DEFAULT ('{}')");
                    } else {
                        $db->exec("ALTER TABLE {$tbl} ADD COLUMN custom_fields TEXT NOT NULL DEFAULT '{}'");
                    }
                }
            }
        },

        // 3.6.0-totp: TOTP 2FA enrollment columns + backup codes table (#418)
        '3.6.0-totp' => function(PDO $db): void {
            $driver = ipam_dialect()->driver_name();

            // ── 1. Add totp_secret_enc + totp_enabled columns to users ────────
            // Guard: users table may not exist in test DBs that pre-date it.
            $usersExists = false;
            if ($driver === 'sqlite') {
                $r = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'");
                $usersExists = $r !== false && (bool)$r->fetch();
            } elseif ($driver === 'mysql') {
                $r = $db->query("SHOW TABLES LIKE 'users'");
                $usersExists = $r !== false && (bool)$r->fetch();
            } else {
                $r = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_name='users'");
                $r->execute();
                $usersExists = (bool)$r->fetch();
            }

            if ($usersExists) {
                foreach (['totp_secret_enc', 'totp_enabled'] as $col) {
                    $has = false;
                    if ($driver === 'sqlite') {
                        $res = $db->query("PRAGMA table_info(users)");
                        if ($res !== false) {
                            /** @var array<string, mixed> $row */
                            foreach ($res as $row) {
                                if (($row['name'] ?? '') === $col) { $has = true; break; }
                            }
                        }
                    } elseif ($driver === 'mysql') {
                        $st = $db->prepare(
                            "SELECT column_name FROM information_schema.columns
                             WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = :c"
                        );
                        $st->execute([':c' => $col]);
                        $has = (bool)$st->fetch();
                    } else {
                        $st = $db->prepare(
                            "SELECT column_name FROM information_schema.columns
                             WHERE table_name = 'users' AND column_name = :c"
                        );
                        $st->execute([':c' => $col]);
                        $has = (bool)$st->fetch();
                    }
                    if (!$has) {
                        if ($col === 'totp_secret_enc') {
                            $db->exec("ALTER TABLE users ADD COLUMN totp_secret_enc TEXT");
                        } else {
                            if ($driver === 'sqlite') {
                                $db->exec("ALTER TABLE users ADD COLUMN totp_enabled INTEGER NOT NULL DEFAULT 0");
                            } elseif ($driver === 'mysql') {
                                $db->exec("ALTER TABLE users ADD COLUMN totp_enabled TINYINT NOT NULL DEFAULT 0");
                            } else {
                                $db->exec("ALTER TABLE users ADD COLUMN totp_enabled SMALLINT NOT NULL DEFAULT 0");
                            }
                        }
                    }
                }
            }

            // ── 2. Create totp_backup_codes table ─────────────────────────────
            $db->exec("CREATE TABLE IF NOT EXISTS totp_backup_codes (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                code_hash   TEXT NOT NULL,
                used_at     TEXT
            )");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_totp_backup_codes_user ON totp_backup_codes(user_id)");
        },

        // 3.6.0-rate-limit: sliding-window rate-limit bucket table (#419)
        '3.6.0-rate-limit' => function(PDO $db): void {
            // CREATE TABLE IF NOT EXISTS is portable across SQLite, MySQL, and PostgreSQL.
            // No column guards needed (new table only).
            $db->exec("CREATE TABLE IF NOT EXISTS rate_limit_buckets (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                bucket_key   TEXT NOT NULL,
                window_start TEXT NOT NULL,
                count        INTEGER NOT NULL DEFAULT 0,
                UNIQUE(bucket_key, window_start)
            )");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_rate_limit_key_window ON rate_limit_buckets(bucket_key, window_start)");
        },

        // 3.6.0-lockout: persistent account lockout columns (#421)
        '3.6.0-lockout' => function(PDO $db): void {
            $driver = ipam_dialect()->driver_name();

            // Guard: users table may not exist in test DBs that pre-date it.
            $usersExists = false;
            if ($driver === 'sqlite') {
                $r = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'");
                $usersExists = $r !== false && (bool)$r->fetch();
            } elseif ($driver === 'mysql') {
                $r = $db->query("SHOW TABLES LIKE 'users'");
                $usersExists = $r !== false && (bool)$r->fetch();
            } else {
                $r = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_name='users'");
                $r->execute();
                $usersExists = (bool)$r->fetch();
            }

            if (!$usersExists) {
                return;
            }

            foreach (['failed_auth_count', 'locked_until', 'lock_reason'] as $col) {
                $has = false;
                if ($driver === 'sqlite') {
                    $res = $db->query("PRAGMA table_info(users)");
                    if ($res !== false) {
                        /** @var array<string, mixed> $row */
                        foreach ($res as $row) {
                            if (($row['name'] ?? '') === $col) { $has = true; break; }
                        }
                    }
                } elseif ($driver === 'mysql') {
                    $st = $db->prepare(
                        "SELECT column_name FROM information_schema.columns
                         WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = :c"
                    );
                    $st->execute([':c' => $col]);
                    $has = (bool)$st->fetch();
                } else {
                    $st = $db->prepare(
                        "SELECT column_name FROM information_schema.columns
                         WHERE table_name = 'users' AND column_name = :c"
                    );
                    $st->execute([':c' => $col]);
                    $has = (bool)$st->fetch();
                }
                if (!$has) {
                    if ($col === 'failed_auth_count') {
                        if ($driver === 'mysql') {
                            $db->exec("ALTER TABLE users ADD COLUMN failed_auth_count INT NOT NULL DEFAULT 0");
                        } else {
                            $db->exec("ALTER TABLE users ADD COLUMN failed_auth_count INTEGER NOT NULL DEFAULT 0");
                        }
                    } elseif ($col === 'locked_until') {
                        $db->exec("ALTER TABLE users ADD COLUMN locked_until TEXT");
                    } else {
                        $db->exec("ALTER TABLE users ADD COLUMN lock_reason TEXT");
                    }
                }
            }
        },
    ];
}

/**
 * 2.6.0-settings migration: create the settings table and seed every registry
 * key from the live $config.php. Lives as a named function (rather than a
 * closure literal inside ipam_migrations()) so the migration body is easier
 * to read and unit-test.
 */
function ipam_migrate_2_6_0_settings(PDO $db): void
{
    $tables = array_column(
        ($db->query("SELECT name FROM sqlite_master WHERE type='table'") ?: throw new \RuntimeException('Query failed'))->fetchAll(),
        'name'
    );

    if (!in_array('settings', $tables, true)) {
        $db->exec("
            CREATE TABLE settings (
                key        TEXT PRIMARY KEY,
                value      TEXT,
                type       TEXT NOT NULL DEFAULT 'string'
                           CHECK(type IN ('string','int','bool','json')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL
            )
        ");
    }

    // Seed rows from the live $config for every registry entry that does not
    // already exist. Relies on ipam_setting_definitions() being available from
    // lib.php, which init.php loads before migrations run.
    if (!function_exists('ipam_setting_definitions')) {
        return;
    }

    /** @var array<string, mixed>|null $config */
    $config = $GLOBALS['config'] ?? null;
    $definitions = ipam_setting_definitions();

    $check = $db->prepare("SELECT 1 FROM settings WHERE ".ipam_key_col()." = :k");
    $ins = $db->prepare(
        "INSERT INTO settings (".ipam_key_col().", value, type, updated_at, updated_by)
         VALUES (:k, :v, :t, datetime('now'), NULL)"
    );

    $seeded = 0;
    foreach ($definitions as $key => $def) {
        $check->execute([':k' => $key]);
        if ($check->fetchColumn() !== false) continue;

        $type = is_string($def['type'] ?? null) ? $def['type'] : 'string';
        $value = $def['default'] ?? null;
        if (is_array($config)) {
            $cfgKey = $def['config_key'] ?? null;
            if ($cfgKey !== null && (is_string($cfgKey) || is_array($cfgKey))) {
                $cfgVal = ipam_setting_config_fallback($config, $cfgKey);
                if ($cfgVal !== null) $value = $cfgVal;
            }
        }

        $ins->execute([
            ':k' => $key,
            ':v' => ipam_setting_encode($value, $type),
            ':t' => $type,
        ]);
        $seeded++;
    }

    if ($seeded > 0 && function_exists('audit')) {
        // Audit is best-effort: tests use a stricter audit_log schema than production,
        // and we never want a seeding log entry to abort a migration.
        try {
            $details = json_encode(['count' => $seeded, 'source' => 'config.php']);
            audit($db, 'settings.seeded_from_config', 'setting', null, is_string($details) ? $details : "count={$seeded}");
        } catch (\Throwable) {
            // swallow
        }
    }
}
