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
                "INSERT INTO settings (`key`, value, type) VALUES (:k, '[]', 'json') $ignore"
            )->execute([':k' => 'alert.recipient_user_ids']);

            // CodeRabbit M1 (PR #450): re-run safety. If alert.recipient_user_ids
            // is already non-default, the migration (or an admin) has already
            // populated it. Don't overwrite admin changes and don't audit a
            // duplicate auto-migrate row on partial fixture replays.
            $cur = $db->prepare("SELECT value FROM settings WHERE `key` = 'alert.recipient_user_ids'");
            $cur->execute();
            $curRow = $cur->fetch();
            $curVal = is_array($curRow) ? trim(to_str($curRow['value'] ?? '')) : '';
            if ($curVal !== '' && $curVal !== '[]') return;

            // Read the legacy alert.email value (may be missing or blank).
            $legacy = '';
            $st = $db->prepare("SELECT value FROM settings WHERE `key` = 'alert.email'");
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
                $db->prepare("UPDATE settings SET value = :v WHERE `key` = 'alert.recipient_user_ids'")
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

    $check = $db->prepare("SELECT 1 FROM settings WHERE `key` = :k");
    $ins = $db->prepare(
        "INSERT INTO settings (`key`, value, type, updated_at, updated_by)
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
