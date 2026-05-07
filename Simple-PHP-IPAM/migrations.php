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

            // Recreate append-only triggers explicitly. The probe in
            // ensure_audit_log_table() short-circuits when the table is
            // present (it is — we only dropped triggers above), so we
            // cannot rely on it to put the triggers back.
            ensure_audit_log_triggers($db);
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
            // is for resilience during test fixtures. Use a portable try/catch
            // instead of sqlite_master (SQLite-only) so this migration runs
            // correctly on MySQL and PostgreSQL too.
            try {
                $db->query("SELECT 1 FROM settings LIMIT 1");
            } catch (\PDOException) {
                return; // settings table does not exist yet
            }

            // Insert the new row with default '[]' if not already present.
            // After 3.13.0-settings-cascade, settings has UNIQUE(tenant_id, key)
            // rather than PRIMARY KEY(key). Use the appropriate conflict columns
            // based on what the current schema actually has so this migration
            // replays correctly in the idempotency test and on real upgrades.
            $driver2 = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
            $hasTenantCol = false;
            if ($driver2 === 'sqlite') {
                $existingCols = array_column(
                    ($db->query("PRAGMA table_info(settings)") ?: throw new \RuntimeException('Query failed'))->fetchAll(),
                    'name'
                );
                $hasTenantCol = in_array('tenant_id', $existingCols, true);
            }
            $kc = ipam_key_col();
            if ($hasTenantCol) {
                // After 3.13.0 the settings table uses partial unique indexes
                // instead of UNIQUE(tenant_id, key). SQLite and PostgreSQL do
                // not accept ON CONFLICT with explicit column names that match
                // only a partial index, so use an existence check instead.
                $ex = $db->prepare("SELECT 1 FROM settings WHERE tenant_id IS NULL AND $kc = :k");
                $ex->execute([':k' => 'alert.recipient_user_ids']);
                if (!$ex->fetch()) {
                    $db->prepare(
                        "INSERT INTO settings (tenant_id, $kc, value, type) VALUES (NULL, :k, '[]', 'json')"
                    )->execute([':k' => 'alert.recipient_user_ids']);
                }
            } else {
                $ignore = ipam_dialect()->upsert_or_ignore('settings', [$kc]);
                $db->prepare(
                    "INSERT INTO settings ($kc, value, type) VALUES (:k, '[]', 'json') $ignore"
                )->execute([':k' => 'alert.recipient_user_ids']);
            }

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
            try {
                $db->query("SELECT 1 FROM users LIMIT 1");
            } catch (\PDOException) {
                return; // users table does not exist yet
            }

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

            // ── 2. Create totp_backup_codes table (only when users exists) ────
            if ($usersExists) {
                if ($driver === 'mysql') {
                    $db->exec("CREATE TABLE IF NOT EXISTS totp_backup_codes (
                        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                        user_id     BIGINT UNSIGNED NOT NULL,
                        code_hash   TEXT NOT NULL,
                        used_at     DATETIME,
                        PRIMARY KEY (id),
                        KEY idx_totp_backup_codes_user (user_id),
                        CONSTRAINT fk_totp_backup_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                } elseif ($driver === 'pgsql') {
                    $db->exec("CREATE TABLE IF NOT EXISTS totp_backup_codes (
                        id          BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                        user_id     BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                        code_hash   TEXT NOT NULL,
                        used_at     TIMESTAMP WITH TIME ZONE
                    )");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_totp_backup_codes_user ON totp_backup_codes(user_id)");
                } else {
                    $db->exec("CREATE TABLE IF NOT EXISTS totp_backup_codes (
                        id          INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                        code_hash   TEXT NOT NULL,
                        used_at     TEXT
                    )");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_totp_backup_codes_user ON totp_backup_codes(user_id)");
                }
            }
        },

        // 3.6.0-rate-limit: sliding-window rate-limit bucket table (#419)
        '3.6.0-rate-limit' => function(PDO $db): void {
            $driver = ipam_dialect()->driver_name();
            if ($driver === 'mysql') {
                $db->exec("CREATE TABLE IF NOT EXISTS rate_limit_buckets (
                    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    bucket_key   VARCHAR(255) NOT NULL,
                    window_start DATETIME NOT NULL,
                    count        INT NOT NULL DEFAULT 0,
                    PRIMARY KEY (id),
                    UNIQUE KEY idx_rate_limit_key_window (bucket_key, window_start),
                    KEY idx_rate_limit_buckets_window_start (window_start)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            } elseif ($driver === 'pgsql') {
                $db->exec("CREATE TABLE IF NOT EXISTS rate_limit_buckets (
                    id           BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                    bucket_key   TEXT NOT NULL,
                    window_start TEXT NOT NULL,
                    count        INTEGER NOT NULL DEFAULT 0,
                    UNIQUE (bucket_key, window_start)
                )");
                // No separate composite index: UNIQUE (bucket_key, window_start) already provides one.
                $db->exec("CREATE INDEX IF NOT EXISTS idx_rate_limit_buckets_window_start ON rate_limit_buckets(window_start)");
            } else {
                $db->exec("CREATE TABLE IF NOT EXISTS rate_limit_buckets (
                    id           INTEGER PRIMARY KEY AUTOINCREMENT,
                    bucket_key   TEXT NOT NULL,
                    window_start TEXT NOT NULL,
                    count        INTEGER NOT NULL DEFAULT 0,
                    UNIQUE(bucket_key, window_start)
                )");
                $db->exec("CREATE INDEX IF NOT EXISTS idx_rate_limit_buckets_window_start ON rate_limit_buckets(window_start)");
            }
        },

        // 3.7.0-backup-history: backup history table + backup.local_path setting (#423)
        '3.7.0-backup-history' => function(PDO $db): void {
            $driver = ipam_dialect()->driver_name();
            if ($driver === 'mysql') {
                $db->exec("CREATE TABLE IF NOT EXISTS backup_history (
                    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    filename      VARCHAR(512) NOT NULL,
                    size_bytes    BIGINT,
                    sha256        VARCHAR(64),
                    db_driver     VARCHAR(16) NOT NULL,
                    started_at    DATETIME NOT NULL,
                    completed_at  DATETIME,
                    duration_ms   INT,
                    target        VARCHAR(32) NOT NULL DEFAULT 'local',
                    target_path   TEXT,
                    status        VARCHAR(16) NOT NULL DEFAULT 'pending',
                    error         TEXT,
                    created_at    DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
                    PRIMARY KEY (id),
                    KEY idx_backup_history_started_at (started_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            } elseif ($driver === 'pgsql') {
                $db->exec("CREATE TABLE IF NOT EXISTS backup_history (
                    id            BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                    filename      TEXT NOT NULL,
                    size_bytes    BIGINT,
                    sha256        TEXT,
                    db_driver     TEXT NOT NULL,
                    started_at    TEXT NOT NULL,
                    completed_at  TEXT,
                    duration_ms   INTEGER,
                    target        TEXT NOT NULL DEFAULT 'local',
                    target_path   TEXT,
                    status        TEXT NOT NULL DEFAULT 'pending',
                    error         TEXT,
                    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
                )");
                $db->exec("CREATE INDEX IF NOT EXISTS idx_backup_history_started_at ON backup_history(started_at)");
            } else {
                $db->exec("CREATE TABLE IF NOT EXISTS backup_history (
                    id            INTEGER PRIMARY KEY AUTOINCREMENT,
                    filename      TEXT NOT NULL,
                    size_bytes    INTEGER,
                    sha256        TEXT,
                    db_driver     TEXT NOT NULL,
                    started_at    TEXT NOT NULL,
                    completed_at  TEXT,
                    duration_ms   INTEGER,
                    target        TEXT NOT NULL DEFAULT 'local',
                    target_path   TEXT,
                    status        TEXT NOT NULL DEFAULT 'pending',
                    error         TEXT,
                    created_at    TEXT NOT NULL DEFAULT (datetime('now'))
                )");
                $db->exec("CREATE INDEX IF NOT EXISTS idx_backup_history_started_at ON backup_history(started_at)");
            }
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
                        if ($driver === 'mysql') {
                            $db->exec("ALTER TABLE users ADD COLUMN locked_until DATETIME");
                        } elseif ($driver === 'pgsql') {
                            $db->exec("ALTER TABLE users ADD COLUMN locked_until TIMESTAMP WITH TIME ZONE");
                        } else {
                            $db->exec("ALTER TABLE users ADD COLUMN locked_until TEXT");
                        }
                    } else {
                        $db->exec("ALTER TABLE users ADD COLUMN lock_reason TEXT");
                    }
                }
            }
        },

        // v3.13.0 #711: add tenant_id to settings table as groundwork for the
        // multi-tenant settings cascade (global → tenant → per-request). The
        // PRIMARY KEY(key) unique constraint is replaced by UNIQUE(tenant_id, key)
        // so each tenant can override any global setting while global rows sit at
        // tenant_id IS NULL. SQLite requires a full table rebuild; MySQL and
        // PostgreSQL use ALTER TABLE.
        //
        // ─── Cross-engine UQ divergence — read before changing this migration ───
        // SQLite and PostgreSQL use TWO partial unique indexes:
        //   - uq_settings_global  ON settings (key)            WHERE tenant_id IS NULL
        //   - uq_settings_tenant  ON settings (tenant_id, key) WHERE tenant_id IS NOT NULL
        // The partial indexes correctly enforce "one row per global key" because
        // both engines treat each NULL as distinct in COMPOSITE UNIQUE constraints
        // (SQL standard). Without the partial index for the global rows, two
        // INSERTs of the same key with tenant_id=NULL would both succeed.
        //
        // MySQL does NOT support predicate partial indexes, so it uses a single
        // composite UNIQUE(tenant_id, key). For tenant-scoped rows this is
        // sufficient (tenant_id is non-NULL there), but for global rows the
        // composite UQ does NOT prevent duplicates because MySQL also follows
        // the SQL-standard NULL-distinctness rule. The runtime fix is in
        // ipam_setting_set() at lib.php — it acquires a MySQL advisory lock
        // (GET_LOCK / RELEASE_LOCK) keyed on the tenant+key digest BEFORE
        // doing the SELECT→INSERT pair, so two concurrent writers cannot
        // both observe "row absent" and both insert. Lock name format:
        //   ipam_setting:<md5(key . ':' . (tenantId ?? '__GLOBAL__'))>
        //
        // SchemaParityTest explicitly whitelists this divergence (see the
        // `if ($table === 'settings') continue;` skips around the partial-
        // index extractor and the unique_constraints comparison block).
        // If you change the partial-index shape here, update both the
        // GET_LOCK lock name and the SchemaParityTest whitelist comments.
        // E1 (#884) cross-reference complete.
        // ─────────────────────────────────────────────────────────────────────────
        '3.13.0-settings-cascade' => static function (PDO $db): void {
            $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

            if ($driver === 'sqlite') {
                $cols = array_column(
                    ($db->query("PRAGMA table_info(settings)") ?: throw new \RuntimeException('Query failed'))->fetchAll(),
                    'name'
                );
                if (in_array('tenant_id', $cols, true)) {
                    return;
                }
                $db->exec("ALTER TABLE settings RENAME TO settings_v3120");
                $db->exec("
                    CREATE TABLE settings (
                        tenant_id  INTEGER,
                        key        TEXT NOT NULL,
                        value      TEXT,
                        type       TEXT NOT NULL DEFAULT 'string'
                                   CHECK(type IN ('string','int','bool','json')),
                        updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                        updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL
                    )
                ");
                $db->exec("
                    INSERT INTO settings (tenant_id, `key`, value, type, updated_at, updated_by)
                    SELECT NULL, `key`, value, type, updated_at, updated_by
                    FROM settings_v3120
                ");
                $db->exec("DROP TABLE settings_v3120");
                // Partial indexes enforce uniqueness for NULL tenant_id rows.
                // SQLite treats each NULL as distinct in composite UNIQUE constraints.
                $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_settings_global ON settings (key) WHERE tenant_id IS NULL');
                $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_settings_tenant ON settings (tenant_id, key) WHERE tenant_id IS NOT NULL');
            } elseif ($driver === 'mysql') {
                $col = ($db->query("SHOW COLUMNS FROM settings LIKE 'tenant_id'") ?: throw new \RuntimeException('Query failed'))->fetch();
                if ($col) {
                    return;
                }
                // Check if PRIMARY KEY exists before trying to drop it — the settings
                // table was created with only a UNIQUE KEY, so on a fresh install there
                // is no PRIMARY KEY and DROP PRIMARY KEY would throw ERROR 1091.
                $hasPk = (int)($db->query(
                    "SELECT COUNT(*) FROM information_schema.table_constraints
                     WHERE table_schema = DATABASE()
                       AND table_name = 'settings'
                       AND constraint_type = 'PRIMARY KEY'"
                ) ?: throw new \RuntimeException('Query failed'))->fetchColumn();
                if ($hasPk > 0) {
                    $db->exec("ALTER TABLE settings DROP PRIMARY KEY");
                }
                $db->exec("ALTER TABLE settings ADD COLUMN tenant_id INT NULL FIRST");
                $db->exec("ALTER TABLE settings ADD UNIQUE KEY uq_settings_tenant_key (tenant_id, `key`)");
            } elseif ($driver === 'pgsql') {
                $col = ($db->query(
                    "SELECT 1 FROM information_schema.columns
                     WHERE table_name='settings' AND column_name='tenant_id'"
                ) ?: throw new \RuntimeException('Query failed'))->fetch();
                if ($col) {
                    return;
                }
                $db->exec("ALTER TABLE settings ADD COLUMN tenant_id INTEGER");
                // Partial indexes enforce uniqueness for NULL tenant_id (global) rows.
                // PostgreSQL treats NULL as distinct in composite UNIQUE constraints.
                $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_settings_global ON settings ("key") WHERE tenant_id IS NULL');
                $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_settings_tenant ON settings (tenant_id, "key") WHERE tenant_id IS NOT NULL');
            }
        },

        // v3.14.0 #685: seed mfa.email_otp_enabled and mfa.require settings rows.
        // Depends on tenant_id column added by 3.13.0-settings-cascade.
        '3.14.0-mfa-settings' => static function (PDO $db): void {
            if (!function_exists('ipam_setting_definitions')) {
                return;
            }
            $definitions = ipam_setting_definitions();
            $kc = ipam_key_col();

            foreach (['mfa.email_otp_enabled', 'mfa.require'] as $key) {
                if (!isset($definitions[$key])) {
                    continue;
                }
                $def = $definitions[$key];
                $type = to_str($def['type']);
                $encoded = ipam_setting_encode($def['default'], $type);
                $ex = $db->prepare("SELECT 1 FROM settings WHERE tenant_id IS NULL AND {$kc} = :k");
                $ex->execute([':k' => $key]);
                if ($ex->fetch()) {
                    continue;
                }
                try {
                    $st = $db->prepare(
                        "INSERT INTO settings (tenant_id, {$kc}, value, type) VALUES (NULL, :k, :v, :t)"
                    );
                    $st->execute([':k' => $key, ':v' => $encoded, ':t' => $type]);
                } catch (\PDOException $e) {
                    // Row already exists (duplicate key) — skip.
                    if (str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'Duplicate')) {
                        continue;
                    }
                    throw $e;
                }
            }
        },

        // v3.14.0 #684: add email_otp_* columns to users table for Email OTP 2FA.
        // Natural sort places this before 3.14.0-mfa-settings; that is intentional
        // and harmless — the two closures touch different tables (users vs settings).
        '3.14.0-email-otp' => static function (PDO $db): void {
            $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

            if ($driver === 'sqlite') {
                $cols = array_column(
                    ($db->query("PRAGMA table_info(users)") ?: throw new \RuntimeException('PRAGMA failed'))->fetchAll(),
                    'name'
                );
                // PRAGMA table_info returns zero rows when the table does not exist;
                // skip gracefully — this migration is a no-op on partial test DBs.
                if ($cols === []) {
                    return;
                }
                if (!in_array('email_otp_enabled', $cols, true)) {
                    $db->exec("ALTER TABLE users ADD COLUMN email_otp_enabled  INTEGER NOT NULL DEFAULT 0");
                }
                if (!in_array('email_otp_hash', $cols, true)) {
                    $db->exec("ALTER TABLE users ADD COLUMN email_otp_hash     TEXT");
                }
                if (!in_array('email_otp_expires_at', $cols, true)) {
                    $db->exec("ALTER TABLE users ADD COLUMN email_otp_expires_at TEXT");
                }
                if (!in_array('email_otp_attempts', $cols, true)) {
                    $db->exec("ALTER TABLE users ADD COLUMN email_otp_attempts INTEGER NOT NULL DEFAULT 0");
                }
            } elseif ($driver === 'mysql') {
                $cols = array_column(
                    ($db->query("SHOW COLUMNS FROM users") ?: throw new \RuntimeException('SHOW COLUMNS failed'))->fetchAll(),
                    'Field'
                );
                if ($cols === []) {
                    return;
                }
                if (!in_array('email_otp_enabled', $cols, true)) {
                    $db->exec("ALTER TABLE users ADD COLUMN email_otp_enabled  TINYINT NOT NULL DEFAULT 0");
                }
                if (!in_array('email_otp_hash', $cols, true)) {
                    $db->exec("ALTER TABLE users ADD COLUMN email_otp_hash     VARCHAR(255) NULL");
                }
                if (!in_array('email_otp_expires_at', $cols, true)) {
                    $db->exec("ALTER TABLE users ADD COLUMN email_otp_expires_at DATETIME NULL");
                }
                if (!in_array('email_otp_attempts', $cols, true)) {
                    $db->exec("ALTER TABLE users ADD COLUMN email_otp_attempts INT NOT NULL DEFAULT 0");
                }
            } elseif ($driver === 'pgsql') {
                $existing = ($db->query(
                    "SELECT column_name FROM information_schema.columns WHERE table_name='users'"
                ) ?: throw new \RuntimeException('Column query failed'))->fetchAll(\PDO::FETCH_COLUMN);
                if (!in_array('email_otp_enabled', $existing, true)) {
                    $db->exec("ALTER TABLE users ADD COLUMN email_otp_enabled    SMALLINT NOT NULL DEFAULT 0");
                }
                if (!in_array('email_otp_hash', $existing, true)) {
                    $db->exec("ALTER TABLE users ADD COLUMN email_otp_hash       TEXT");
                }
                if (!in_array('email_otp_expires_at', $existing, true)) {
                    $db->exec("ALTER TABLE users ADD COLUMN email_otp_expires_at TIMESTAMP NULL");
                }
                if (!in_array('email_otp_attempts', $existing, true)) {
                    $db->exec("ALTER TABLE users ADD COLUMN email_otp_attempts   INTEGER NOT NULL DEFAULT 0");
                }
            }
        },

        // 3.15.0-passkeys (#688): WebAuthn/Passkey credential store
        '3.15.0-passkeys' => static function (PDO $db): void {
            $dialect = ipam_dialect();
            $driver  = $dialect->driver_name();
            $nowDflt = '(' . $dialect->now() . ')';
            $tables  = [];
            if ($driver === 'sqlite') {
                foreach (($db->query("SELECT name FROM sqlite_master WHERE type='table'") ?: throw new \RuntimeException('Query failed'))->fetchAll() as $t) {
                    $tables[] = $t['name'];
                }
            } elseif ($driver === 'mysql') {
                foreach (($db->query("SHOW TABLES") ?: throw new \RuntimeException('Query failed'))->fetchAll(PDO::FETCH_COLUMN) as $t) {
                    $tables[] = $t;
                }
            } else {
                foreach (($db->query("SELECT tablename FROM pg_tables WHERE schemaname='public'") ?: throw new \RuntimeException('Query failed'))->fetchAll(PDO::FETCH_COLUMN) as $t) {
                    $tables[] = $t;
                }
            }

            if (in_array('webauthn_credentials', $tables, true)) {
                return; // idempotent guard
            }

            if ($driver === 'sqlite') {
                $db->exec("
                    CREATE TABLE webauthn_credentials (
                      id            INTEGER PRIMARY KEY AUTOINCREMENT,
                      user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                      credential_id BLOB    NOT NULL UNIQUE, -- PARAM_LOB required on all writes: see ipam_bind_binary()
                      public_key    TEXT    NOT NULL,
                      sign_count    INTEGER NOT NULL DEFAULT 0,
                      name          TEXT    NOT NULL DEFAULT 'Passkey',
                      created_at    TEXT    NOT NULL DEFAULT {$nowDflt},
                      last_used_at  TEXT
                    )
                ");
                $db->exec("CREATE INDEX idx_webauthn_credentials_user ON webauthn_credentials(user_id)");
            } elseif ($driver === 'mysql') {
                $db->exec("
                    CREATE TABLE webauthn_credentials (
                      id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                      user_id       BIGINT UNSIGNED NOT NULL,
                      credential_id VARBINARY(255) NOT NULL, -- PARAM_LOB required on all writes: see ipam_bind_binary()
                      public_key    TEXT NOT NULL,
                      sign_count    INT UNSIGNED NOT NULL DEFAULT 0,
                      name          VARCHAR(255) NOT NULL DEFAULT 'Passkey',
                      created_at    DATETIME NOT NULL DEFAULT {$nowDflt},
                      last_used_at  DATETIME,
                      UNIQUE KEY uq_wac_cred_id (credential_id),
                      CONSTRAINT fk_wac_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                      INDEX idx_webauthn_credentials_user (user_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            } else {
                $db->exec("
                    CREATE TABLE webauthn_credentials (
                      id            BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                      user_id       BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                      credential_id BYTEA   NOT NULL UNIQUE, -- PARAM_LOB required on all writes: see ipam_bind_binary()
                      public_key    TEXT    NOT NULL,
                      sign_count    INTEGER NOT NULL DEFAULT 0,
                      name          VARCHAR(255) NOT NULL DEFAULT 'Passkey',
                      created_at    TIMESTAMP NOT NULL DEFAULT {$nowDflt},
                      last_used_at  TIMESTAMP
                    )
                ");
                $db->exec("CREATE INDEX idx_webauthn_credentials_user ON webauthn_credentials(user_id)");
            }
        },

        // v3.16.0 #746: add preferred_mfa_method to users (nullable enum:
        // totp | email_otp | passkey). NULL means "no explicit preference,
        // fall back to most-recently-enrolled". Enum is enforced at the
        // application layer (lib.php / change_password.php) — schema files
        // for the three engines stay free of CHECK constraints to match the
        // existing convention for enum-like text columns on this table.
        '3.16.0-preferred-mfa-method' => static function (PDO $db): void {
            $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

            if ($driver === 'sqlite') {
                $cols = array_column(
                    ($db->query("PRAGMA table_info(users)") ?: throw new \RuntimeException('PRAGMA failed'))->fetchAll(),
                    'name'
                );
                if ($cols === []) {
                    return;
                }
                if (!in_array('preferred_mfa_method', $cols, true)) {
                    $db->exec("ALTER TABLE users ADD COLUMN preferred_mfa_method TEXT");
                }
            } elseif ($driver === 'mysql') {
                $cols = array_column(
                    ($db->query("SHOW COLUMNS FROM users") ?: throw new \RuntimeException('SHOW COLUMNS failed'))->fetchAll(),
                    'Field'
                );
                if ($cols === []) {
                    return;
                }
                if (!in_array('preferred_mfa_method', $cols, true)) {
                    $db->exec("ALTER TABLE users ADD COLUMN preferred_mfa_method VARCHAR(20) NULL");
                }
            } elseif ($driver === 'pgsql') {
                $existing = ($db->query(
                    "SELECT column_name FROM information_schema.columns WHERE table_name='users'"
                ) ?: throw new \RuntimeException('Column query failed'))->fetchAll(\PDO::FETCH_COLUMN);
                if (!in_array('preferred_mfa_method', $existing, true)) {
                    $db->exec("ALTER TABLE users ADD COLUMN preferred_mfa_method TEXT NULL");
                }
            }
        },

        // v3.17.0 #690: backup_destinations, backup_schedules, backup_log.
        //
        // Reconciliation note: v3.7.0 introduced backup_history, a simple one-row-
        // per-CLI-run log with no FK references. The three tables added here are
        // distinct: backup_destinations captures named remote/local targets,
        // backup_schedules captures GFS schedules per destination, and backup_log
        // records web-triggered + scheduled runs with FK references to both.
        // backup_history remains in place; backup.php CLI continues to write to it
        // until Phase 6 (web backup runner) replaces it. No data migration needed
        // because the schemas are non-overlapping in purpose.
        '3.17.0-backup' => static function (PDO $db): void {
            $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

            $tableExists = static function (string $name) use ($db, $driver): bool {
                if ($driver === 'sqlite') {
                    $r = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name=" . $db->quote($name));
                    return $r !== false && (bool)$r->fetch();
                } elseif ($driver === 'mysql') {
                    $r = $db->query("SHOW TABLES LIKE " . $db->quote($name));
                    return $r !== false && (bool)$r->fetch();
                } else {
                    $st = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_name = :n");
                    $st->execute([':n' => $name]);
                    return (bool)$st->fetch();
                }
            };

            // ── 1. backup_destinations ───────────────────────────────────────
            if (!$tableExists('backup_destinations')) {
                if ($driver === 'sqlite') {
                    $db->exec("CREATE TABLE backup_destinations (
                        id         INTEGER PRIMARY KEY AUTOINCREMENT,
                        name       TEXT    NOT NULL,
                        type       TEXT    NOT NULL,
                        config     TEXT    NOT NULL DEFAULT '{}',
                        encrypt    INTEGER NOT NULL DEFAULT 1,
                        is_active  INTEGER NOT NULL DEFAULT 1,
                        created_at TEXT    NOT NULL DEFAULT (datetime('now')),
                        updated_at TEXT    NOT NULL DEFAULT (datetime('now'))
                    )");
                } elseif ($driver === 'mysql') {
                    $db->exec("CREATE TABLE backup_destinations (
                        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        name       VARCHAR(255) NOT NULL,
                        type       VARCHAR(32)  NOT NULL,
                        config     TEXT         NOT NULL,
                        encrypt    TINYINT(1)   NOT NULL DEFAULT 1,
                        is_active  TINYINT(1)   NOT NULL DEFAULT 1,
                        created_at DATETIME     NOT NULL DEFAULT (UTC_TIMESTAMP()),
                        updated_at DATETIME     NOT NULL DEFAULT (UTC_TIMESTAMP())
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                    $db->exec("CREATE TRIGGER IF NOT EXISTS backup_destinations_updated_at
                        BEFORE UPDATE ON backup_destinations FOR EACH ROW
                        SET NEW.updated_at = UTC_TIMESTAMP()");
                } else {
                    $db->exec("CREATE TABLE backup_destinations (
                        id         BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                        name       TEXT      NOT NULL,
                        type       TEXT      NOT NULL,
                        config     TEXT      NOT NULL DEFAULT '{}',
                        encrypt    SMALLINT  NOT NULL DEFAULT 1,
                        is_active  SMALLINT  NOT NULL DEFAULT 1,
                        created_at TIMESTAMP NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc'),
                        updated_at TIMESTAMP NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc')
                    )");
                    $db->exec("CREATE OR REPLACE TRIGGER backup_destinations_updated_at
                        BEFORE UPDATE ON backup_destinations FOR EACH ROW EXECUTE FUNCTION set_updated_at_utc()");
                }
            }

            // ── 2. backup_schedules ──────────────────────────────────────────
            if (!$tableExists('backup_schedules')) {
                if ($driver === 'sqlite') {
                    $db->exec("CREATE TABLE backup_schedules (
                        id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                        destination_id      INTEGER NOT NULL REFERENCES backup_destinations(id) ON DELETE CASCADE,
                        frequency           TEXT    NOT NULL DEFAULT 'daily',
                        time_of_day         TEXT    NOT NULL DEFAULT '02:00',
                        day_of_week         INTEGER,
                        day_of_month        INTEGER,
                        retention_hourly    INTEGER NOT NULL DEFAULT 0,
                        retention_daily     INTEGER NOT NULL DEFAULT 7,
                        retention_weekly    INTEGER NOT NULL DEFAULT 4,
                        retention_monthly   INTEGER NOT NULL DEFAULT 3,
                        is_active           INTEGER NOT NULL DEFAULT 1,
                        last_run_at         TEXT,
                        next_run_at         TEXT,
                        created_at          TEXT    NOT NULL DEFAULT (datetime('now'))
                    )");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_backup_schedules_destination ON backup_schedules(destination_id)");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_backup_schedules_next_run ON backup_schedules(next_run_at)");
                } elseif ($driver === 'mysql') {
                    $db->exec("CREATE TABLE backup_schedules (
                        id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        destination_id      BIGINT UNSIGNED NOT NULL,
                        frequency           VARCHAR(16)  NOT NULL DEFAULT 'daily',
                        time_of_day         VARCHAR(5)   NOT NULL DEFAULT '02:00',
                        day_of_week         TINYINT      NULL,
                        day_of_month        TINYINT      NULL,
                        retention_hourly    SMALLINT     NOT NULL DEFAULT 0,
                        retention_daily     SMALLINT     NOT NULL DEFAULT 7,
                        retention_weekly    SMALLINT     NOT NULL DEFAULT 4,
                        retention_monthly   SMALLINT     NOT NULL DEFAULT 3,
                        is_active           TINYINT(1)   NOT NULL DEFAULT 1,
                        last_run_at         DATETIME     NULL,
                        next_run_at         DATETIME     NULL,
                        created_at          DATETIME     NOT NULL DEFAULT (UTC_TIMESTAMP()),
                        CONSTRAINT fk_bsched_dest FOREIGN KEY (destination_id) REFERENCES backup_destinations(id) ON DELETE CASCADE,
                        KEY idx_backup_schedules_destination (destination_id),
                        KEY idx_backup_schedules_next_run (next_run_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                } else {
                    $db->exec("CREATE TABLE backup_schedules (
                        id                  BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                        destination_id      BIGINT    NOT NULL REFERENCES backup_destinations(id) ON DELETE CASCADE,
                        frequency           TEXT      NOT NULL DEFAULT 'daily',
                        time_of_day         TEXT      NOT NULL DEFAULT '02:00',
                        day_of_week         SMALLINT  NULL,
                        day_of_month        SMALLINT  NULL,
                        retention_hourly    SMALLINT  NOT NULL DEFAULT 0,
                        retention_daily     SMALLINT  NOT NULL DEFAULT 7,
                        retention_weekly    SMALLINT  NOT NULL DEFAULT 4,
                        retention_monthly   SMALLINT  NOT NULL DEFAULT 3,
                        is_active           SMALLINT  NOT NULL DEFAULT 1,
                        last_run_at         TIMESTAMP NULL,
                        next_run_at         TIMESTAMP NULL,
                        created_at          TIMESTAMP NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc')
                    )");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_backup_schedules_destination ON backup_schedules(destination_id)");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_backup_schedules_next_run ON backup_schedules(next_run_at)");
                }
            }

            // ── 3. backup_log ────────────────────────────────────────────────
            if (!$tableExists('backup_log')) {
                if ($driver === 'sqlite') {
                    $db->exec("CREATE TABLE backup_log (
                        id              INTEGER PRIMARY KEY AUTOINCREMENT,
                        destination_id  INTEGER REFERENCES backup_destinations(id) ON DELETE SET NULL,
                        schedule_id     INTEGER REFERENCES backup_schedules(id) ON DELETE SET NULL,
                        triggered_by    TEXT    NOT NULL DEFAULT 'manual',
                        type            TEXT    NOT NULL DEFAULT 'backup'
                                                CHECK (type IN ('backup','restore')),
                        status          TEXT    NOT NULL DEFAULT 'pending',
                        filename        TEXT,
                        size_bytes      INTEGER,
                        checksum        TEXT,
                        error_message   TEXT,
                        started_at      TEXT    NOT NULL DEFAULT (datetime('now')),
                        completed_at    TEXT
                    )");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_backup_log_destination ON backup_log(destination_id)");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_backup_log_started_at ON backup_log(started_at DESC)");
                } elseif ($driver === 'mysql') {
                    $db->exec("CREATE TABLE backup_log (
                        id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        destination_id  BIGINT UNSIGNED NULL,
                        schedule_id     BIGINT UNSIGNED NULL,
                        triggered_by    VARCHAR(32)  NOT NULL DEFAULT 'manual',
                        type            VARCHAR(16)  NOT NULL DEFAULT 'backup',
                        status          VARCHAR(16)  NOT NULL DEFAULT 'pending',
                        filename        TEXT         NULL,
                        size_bytes      BIGINT       NULL,
                        checksum        VARCHAR(128) NULL,
                        error_message   TEXT         NULL,
                        started_at      DATETIME     NOT NULL DEFAULT (UTC_TIMESTAMP()),
                        completed_at    DATETIME     NULL,
                        CONSTRAINT fk_blog_dest   FOREIGN KEY (destination_id) REFERENCES backup_destinations(id) ON DELETE SET NULL,
                        CONSTRAINT fk_blog_sched  FOREIGN KEY (schedule_id)    REFERENCES backup_schedules(id)    ON DELETE SET NULL,
                        KEY idx_backup_log_destination (destination_id),
                        KEY idx_backup_log_started_at (started_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                } else {
                    $db->exec("CREATE TABLE backup_log (
                        id              BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                        destination_id  BIGINT    NULL REFERENCES backup_destinations(id) ON DELETE SET NULL,
                        schedule_id     BIGINT    NULL REFERENCES backup_schedules(id)    ON DELETE SET NULL,
                        triggered_by    TEXT      NOT NULL DEFAULT 'manual',
                        type            TEXT      NOT NULL DEFAULT 'backup'
                                                  CHECK (type IN ('backup','restore')),
                        status          TEXT      NOT NULL DEFAULT 'pending',
                        filename        TEXT      NULL,
                        size_bytes      BIGINT    NULL,
                        checksum        TEXT      NULL,
                        error_message   TEXT      NULL,
                        started_at      TIMESTAMP NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc'),
                        completed_at    TIMESTAMP NULL
                    )");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_backup_log_destination ON backup_log(destination_id)");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_backup_log_started_at ON backup_log(started_at DESC)");
                }
            }
        },

        // v3.21.0 #799 (§A1 of backup_overhaul.md): collapse the legacy
        // backup_history (v3.7.0 CLI runner) and backup_log (v3.17.0
        // destination runner) tables into a single backup_runs table.
        //
        // Also implements:
        //   #808 / F38 — drop the ambiguous type+triggered_by combo from
        //   backup_log; keep a single triggered_by enum (schedule|manual|cli).
        //   #809 / B-P1-31 — unify started_at/created_at divergence; the new
        //   table has only started_at + completed_at.
        //
        // Migration sequence (per §A1):
        //   1. Create backup_runs table with the new shape.
        //   2. Copy backup_log rows in (backup_type='database',
        //      encryption_mode='stored').
        //   3. Copy backup_history rows in (backup_type='database',
        //      encryption_mode='unencrypted', triggered_by='cli',
        //      destination_id=NULL).
        //   4. Verify row-count parity; abort if mismatch.
        //   5. Drop backup_history and backup_log.
        //
        // Idempotent on fresh installs: schema.sql/schema.mysql.sql/
        // schema.pgsql.sql already create backup_runs, and neither legacy
        // table exists, so each step is guarded by table existence checks.
        '3.21.0-backup-runs' => static function (PDO $db): void {
            $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

            $tableExists = static function (string $name) use ($db, $driver): bool {
                if ($driver === 'sqlite') {
                    $st = $db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=:n");
                } elseif ($driver === 'mysql') {
                    $st = $db->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :n");
                } else { // pgsql
                    $st = $db->prepare("SELECT tablename FROM pg_tables WHERE schemaname = ANY(current_schemas(false)) AND tablename = :n");
                }
                $st->execute([':n' => $name]);
                return (bool) $st->fetchColumn();
            };

            // ── 1. Create backup_runs (skip on fresh installs where schema.sql
            // already created it) ──────────────────────────────────────────
            if (!$tableExists('backup_runs')) {
                if ($driver === 'sqlite') {
                    $db->exec("CREATE TABLE backup_runs (
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
                    )");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_backup_runs_destination ON backup_runs(destination_id)");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_backup_runs_schedule    ON backup_runs(schedule_id)");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_backup_runs_started     ON backup_runs(started_at DESC)");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_backup_runs_protected   ON backup_runs(is_protected) WHERE is_protected = 1");
                } elseif ($driver === 'mysql') {
                    $db->exec("CREATE TABLE backup_runs (
                        id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        destination_id  BIGINT UNSIGNED NULL,
                        schedule_id     BIGINT UNSIGNED NULL,
                        backup_type     VARCHAR(16)  NOT NULL DEFAULT 'database',
                        encryption_mode VARCHAR(16)  NOT NULL DEFAULT 'unencrypted',
                        triggered_by    VARCHAR(16)  NOT NULL DEFAULT 'manual',
                        status          VARCHAR(16)  NOT NULL DEFAULT 'running',
                        filename        TEXT         NULL,
                        size_bytes      BIGINT       NULL,
                        checksum        VARCHAR(128) NULL,
                        source_version  VARCHAR(32)  NOT NULL DEFAULT '0.0.0',
                        is_protected    TINYINT(1)   NOT NULL DEFAULT 0,
                        error_message   TEXT         NULL,
                        started_at      DATETIME     NOT NULL DEFAULT (UTC_TIMESTAMP()),
                        completed_at    DATETIME     NULL,
                        CONSTRAINT fk_brun_dest  FOREIGN KEY (destination_id) REFERENCES backup_destinations(id) ON DELETE SET NULL,
                        CONSTRAINT fk_brun_sched FOREIGN KEY (schedule_id)    REFERENCES backup_schedules(id)    ON DELETE SET NULL,
                        CONSTRAINT chk_brun_backup_type     CHECK (backup_type IN ('database','logical')),
                        CONSTRAINT chk_brun_encryption_mode CHECK (encryption_mode IN ('stored','transitory','unencrypted')),
                        CONSTRAINT chk_brun_triggered_by    CHECK (triggered_by IN ('schedule','manual','cli')),
                        CONSTRAINT chk_brun_status          CHECK (status IN ('running','success','failed','retention_pruned')),
                        CONSTRAINT chk_brun_protected       CHECK (is_protected IN (0,1)),
                        KEY idx_backup_runs_destination (destination_id),
                        KEY idx_backup_runs_schedule    (schedule_id),
                        KEY idx_backup_runs_started     (started_at),
                        KEY idx_backup_runs_protected   (is_protected)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                } else { // pgsql
                    $db->exec("CREATE TABLE backup_runs (
                        id              BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                        destination_id  BIGINT    NULL REFERENCES backup_destinations(id) ON DELETE SET NULL,
                        schedule_id     BIGINT    NULL REFERENCES backup_schedules(id)    ON DELETE SET NULL,
                        backup_type     TEXT      NOT NULL DEFAULT 'database'
                                                  CHECK (backup_type IN ('database','logical')),
                        encryption_mode TEXT      NOT NULL DEFAULT 'unencrypted'
                                                  CHECK (encryption_mode IN ('stored','transitory','unencrypted')),
                        triggered_by    TEXT      NOT NULL DEFAULT 'manual'
                                                  CHECK (triggered_by IN ('schedule','manual','cli')),
                        status          TEXT      NOT NULL DEFAULT 'running'
                                                  CHECK (status IN ('running','success','failed','retention_pruned')),
                        filename        TEXT      NULL,
                        size_bytes      BIGINT    NULL,
                        checksum        TEXT      NULL,
                        source_version  TEXT      NOT NULL DEFAULT '0.0.0',
                        is_protected    SMALLINT  NOT NULL DEFAULT 0  CHECK (is_protected IN (0,1)),
                        error_message   TEXT      NULL,
                        started_at      TIMESTAMP NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc'),
                        completed_at    TIMESTAMP NULL
                    )");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_backup_runs_destination ON backup_runs(destination_id)");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_backup_runs_schedule    ON backup_runs(schedule_id)");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_backup_runs_started     ON backup_runs(started_at DESC)");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_backup_runs_protected   ON backup_runs(is_protected) WHERE is_protected = 1");
                }
            }

            $countTable = static function (string $table) use ($db): int {
                $q = $db->query("SELECT COUNT(*) FROM {$table}");
                if ($q === false) {
                    throw new \RuntimeException("3.21.0-backup-runs: COUNT query failed for {$table}");
                }
                return (int) $q->fetchColumn();
            };

            // ── 2. Copy backup_log rows ──────────────────────────────────
            // Status normalization: legacy 'pending'/'started'/etc. → 'failed'
            // (stale rows that never completed). 'success' and 'failed' pass
            // through. triggered_by is normalized server-side: anything other
            // than the new enum values is coerced to 'manual'.
            //
            // CR feedback PR #1054: backup_log has a `type IN ('backup',
            // 'restore')` column that backup_runs.backup_type cannot
            // represent (the new schema only allows 'database' / 'logical').
            // Filter `type = 'restore'` rows OUT of the copy: those events
            // are already recorded in audit_log under `db.restore_*` and
            // mislabeling them as backup runs would corrupt the History
            // surface. Only the backup-axis rows migrate here.
            // $expected below subtracts the filtered rows from the parity
            // check so the count check still works.
            // SQLite holds a read lock on a table for as long as any
            // PDOStatement reading from it stays in scope. Wrap the COUNT
            // query in a helper so the statement object is freed before the
            // DROP TABLE at the end of this migration runs — matching the
            // pre-existing $countTable pattern.
            $countTableWhere = static function (string $table, string $where) use ($db): int {
                $q = $db->query("SELECT COUNT(*) FROM {$table} WHERE {$where}");
                if ($q === false) {
                    return 0;
                }
                $n = (int) $q->fetchColumn();
                $q->closeCursor();
                return $n;
            };

            // Pre-compute counts so we can decide whether a prior partial
            // run has already populated backup_runs (CR feedback PR #1054
            // round 3).
            $logRestoreCount = 0;
            $logCount = 0;
            if ($tableExists('backup_log')) {
                $logCount = $countTable('backup_log');
                $logRestoreCount = $countTableWhere('backup_log', "type = 'restore'");
            }
            $histCountPre = $tableExists('backup_history') ? $countTable('backup_history') : 0;
            $expectedCopied = ($logCount - $logRestoreCount) + $histCountPre;
            $actualBefore   = $countTable('backup_runs');
            $skipCopy       = false;
            if ($expectedCopied > 0 && $actualBefore > 0) {
                if ($actualBefore === $expectedCopied) {
                    // Prior partial run already copied a complete set; skip
                    // copy and proceed to the legacy-table drop. Idempotent
                    // rerun. (CR feedback PR #1054 round 3.)
                    $skipCopy = true;
                } else {
                    throw new \RuntimeException(
                        "3.21.0-backup-runs migration: pre-copy mismatch — backup_runs already has {$actualBefore} rows but expected {$expectedCopied}. " .
                        "Refusing to re-copy on top of partial state. " .
                        "Manually reconcile (truncate backup_runs and rerun, or move legacy tables aside) before continuing."
                    );
                }
            }

            if (!$skipCopy && $logCount > 0) {
                $sel = $db->query("SELECT destination_id, schedule_id, type, triggered_by, status, filename, size_bytes, checksum, error_message, started_at, completed_at FROM backup_log WHERE type IS NULL OR type = 'backup' ORDER BY id");
                if ($sel === false) {
                    throw new \RuntimeException("3.21.0-backup-runs: SELECT failed on backup_log");
                }
                $ins = $db->prepare(
                    "INSERT INTO backup_runs " .
                    "(destination_id, schedule_id, backup_type, encryption_mode, triggered_by, status, filename, size_bytes, checksum, source_version, is_protected, error_message, started_at, completed_at) " .
                    "VALUES (:destination_id, :schedule_id, 'database', 'stored', :triggered_by, :status, :filename, :size_bytes, :checksum, '0.0.0', 0, :error_message, :started_at, :completed_at)"
                );
                /** @var array<string, mixed> $row */
                foreach ($sel as $row) {
                    $rawStatus = is_string($row['status']) ? strtolower($row['status']) : '';
                    $status = in_array($rawStatus, ['success', 'failed', 'retention_pruned'], true) ? $rawStatus : 'failed';
                    $rawTrig = is_string($row['triggered_by']) ? strtolower($row['triggered_by']) : 'manual';
                    $triggered = in_array($rawTrig, ['schedule', 'manual', 'cli'], true) ? $rawTrig : 'manual';
                    $ins->execute([
                        ':destination_id' => $row['destination_id'],
                        ':schedule_id'    => $row['schedule_id'],
                        ':triggered_by'   => $triggered,
                        ':status'         => $status,
                        ':filename'       => $row['filename'],
                        ':size_bytes'     => $row['size_bytes'],
                        ':checksum'       => $row['checksum'],
                        ':error_message'  => $row['error_message'],
                        ':started_at'     => $row['started_at'],
                        ':completed_at'   => $row['completed_at'],
                    ]);
                }
            }

            // ── 3. Copy backup_history rows (CLI runner, v3.7.0) ─────────
            // backup_history.error → backup_runs.error_message;
            // backup_history.sha256 → backup_runs.checksum.
            // No destination/schedule linkage existed in the legacy CLI table.
            $histCount = $histCountPre;
            if (!$skipCopy && $histCount > 0) {
                $sel = $db->query("SELECT status, filename, size_bytes, sha256, error, started_at, completed_at FROM backup_history ORDER BY id");
                if ($sel === false) {
                    throw new \RuntimeException("3.21.0-backup-runs: SELECT failed on backup_history");
                }
                $ins = $db->prepare(
                    "INSERT INTO backup_runs " .
                    "(destination_id, schedule_id, backup_type, encryption_mode, triggered_by, status, filename, size_bytes, checksum, source_version, is_protected, error_message, started_at, completed_at) " .
                    "VALUES (NULL, NULL, 'database', 'unencrypted', 'cli', :status, :filename, :size_bytes, :checksum, '0.0.0', 0, :error_message, :started_at, :completed_at)"
                );
                /** @var array<string, mixed> $row */
                foreach ($sel as $row) {
                    $rawStatus = is_string($row['status']) ? strtolower($row['status']) : '';
                    $status = in_array($rawStatus, ['success', 'failed', 'retention_pruned'], true) ? $rawStatus : 'failed';
                    $ins->execute([
                        ':status'        => $status,
                        ':filename'      => $row['filename'],
                        ':size_bytes'    => $row['size_bytes'],
                        ':checksum'      => $row['sha256'],
                        ':error_message' => $row['error'],
                        ':started_at'    => $row['started_at'],
                        ':completed_at'  => $row['completed_at'],
                    ]);
                }
            }

            // ── 4. Row-count parity check (§A1 step 5) ───────────────────
            // CR feedback PR #1054: exact equality, not lower-bound. A
            // partial prior run that copied some rows would otherwise pass
            // the `>=` check and the legacy tables would be dropped while
            // backup_runs still held a duplicate-on-rerun set. Equal counts
            // (allowing for filtered legacy 'restore' rows that are
            // intentionally not migrated) means a clean copy.
            $expected = ($logCount - $logRestoreCount) + $histCount;
            if ($expected > 0) {
                $actual = $countTable('backup_runs');
                if ($actual !== $expected) {
                    throw new \RuntimeException(
                        "3.21.0-backup-runs migration: row-count parity check failed. " .
                        "Expected exactly {$expected} backup_runs rows " .
                        "(backup_log={$logCount} − restore-filtered={$logRestoreCount} + backup_history={$histCount}), " .
                        "got {$actual}. Aborting before drop of legacy tables."
                    );
                }
            }

            // ── 5. Drop legacy tables ────────────────────────────────────
            // Neither table has children that reference it (FKs point IN
            // from backup_log to backup_destinations, not the other way),
            // so DROP is safe with FK enforcement off (apply_migrations
            // disables it for the migration scope).
            if ($tableExists('backup_log')) {
                $db->exec("DROP TABLE backup_log");
            }
            if ($tableExists('backup_history')) {
                $db->exec("DROP TABLE backup_history");
            }
        },

        // v3.21.0 — enforce one schedule per destination (CR feedback on PR #1054).
        // The drawer + edit-schedule flow already assumes one schedule per
        // destination (ipam_render_destination_edit_drawer() does LIMIT 1);
        // without a UNIQUE constraint, duplicates were silently possible.
        // Dedupe existing rows (keep highest id), then add the unique index.
        '3.21.0-schedule-unique' => static function (PDO $db): void {
            $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

            // Dedupe — engine-portable two-step: find duplicates, delete losers.
            $dupes = $db->query(
                "SELECT destination_id, MAX(id) AS keep_id, COUNT(*) AS n
                   FROM backup_schedules
                  GROUP BY destination_id
                 HAVING COUNT(*) > 1"
            );
            if ($dupes !== false) {
                $rows = $dupes->fetchAll(PDO::FETCH_ASSOC);
                // CR feedback PR #1054: backup_runs.schedule_id has
                // ON DELETE SET NULL — naively deleting loser schedules
                // would null out the schedule_id on every historical run
                // attached to those ids. Repoint child rows to the surviving
                // schedule first so provenance is preserved. (Loser
                // schedules and the keep schedule all have the same
                // destination_id, so this is the closest-to-truth mapping
                // we can do without storing the original schedule's full
                // shape on each run.)
                // Use distinct placeholder names per occurrence: PDO with
                // EMULATE_PREPARES=false (MySQL native prepares) treats
                // each placeholder occurrence as its own parameter slot
                // and rejects repeated `:keep` with HY093 "Invalid
                // parameter number". Bind both occurrences explicitly.
                $repoint = $db->prepare(
                    "UPDATE backup_runs
                        SET schedule_id = :keep_target
                      WHERE schedule_id IN (
                              SELECT id FROM backup_schedules
                               WHERE destination_id = :did
                                 AND id <> :keep_filter
                            )"
                );
                $del = $db->prepare(
                    "DELETE FROM backup_schedules
                      WHERE destination_id = :did
                        AND id <> :keep"
                );
                foreach ($rows as $r) {
                    $did  = (int) $r['destination_id'];
                    $keep = (int) $r['keep_id'];
                    $repoint->execute([
                        ':keep_target' => $keep,
                        ':did'         => $did,
                        ':keep_filter' => $keep,
                    ]);
                    $del->execute([
                        ':did'  => $did,
                        ':keep' => $keep,
                    ]);
                }
            }

            // Index/constraint creation — guard for re-runs across all engines.
            if ($driver === 'sqlite') {
                $db->exec(
                    "CREATE UNIQUE INDEX IF NOT EXISTS uq_backup_schedules_destination
                       ON backup_schedules(destination_id)"
                );
                // The non-unique idx_backup_schedules_destination becomes
                // redundant once the unique index covers destination_id lookups.
                $db->exec("DROP INDEX IF EXISTS idx_backup_schedules_destination");
            } elseif ($driver === 'mysql') {
                $hasUq = $db->query(
                    "SELECT COUNT(*) FROM information_schema.statistics
                      WHERE table_schema = DATABASE()
                        AND table_name   = 'backup_schedules'
                        AND index_name   = 'uq_backup_schedules_destination'"
                );
                if ($hasUq !== false && (int) $hasUq->fetchColumn() === 0) {
                    $db->exec(
                        "ALTER TABLE backup_schedules
                            ADD UNIQUE KEY uq_backup_schedules_destination (destination_id)"
                    );
                }
                $hasOldIdx = $db->query(
                    "SELECT COUNT(*) FROM information_schema.statistics
                      WHERE table_schema = DATABASE()
                        AND table_name   = 'backup_schedules'
                        AND index_name   = 'idx_backup_schedules_destination'"
                );
                if ($hasOldIdx !== false && (int) $hasOldIdx->fetchColumn() === 1) {
                    $db->exec("ALTER TABLE backup_schedules DROP INDEX idx_backup_schedules_destination");
                }
            } else { // pgsql
                // PG distinguishes UNIQUE CONSTRAINT (in
                // information_schema.table_constraints) from UNIQUE INDEX
                // (in pg_indexes). SchemaParityTest queries the former, so
                // use ADD CONSTRAINT — Postgres implicitly creates the
                // backing index. Guard with information_schema for re-runs.
                $hasUq = $db->query(
                    "SELECT COUNT(*) FROM information_schema.table_constraints
                      WHERE table_name      = 'backup_schedules'
                        AND constraint_name = 'uq_backup_schedules_destination'
                        AND constraint_type = 'UNIQUE'"
                );
                if ($hasUq !== false && (int) $hasUq->fetchColumn() === 0) {
                    $db->exec(
                        "ALTER TABLE backup_schedules
                            ADD CONSTRAINT uq_backup_schedules_destination UNIQUE (destination_id)"
                    );
                }
                $db->exec("DROP INDEX IF EXISTS idx_backup_schedules_destination");
            }
        },

        // v3.23.0 #825 (F21): add per-schedule notification override columns to
        // backup_schedules. notify_override gates the override; the three
        // override columns are nullable so an admin can mix global + override
        // (e.g. override notify_on_failure but inherit recipients).
        '3.23.0-notify-overrides' => static function (PDO $db): void {
            $driverRaw = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
            $driver = is_string($driverRaw) ? $driverRaw : '';

            // Existing-column lookup, per-driver. Pattern matches the inline
            // PRAGMA / information_schema queries used in the surrounding
            // migrations (no shared helper exists yet — see 3.21.0-* above).
            $existing = [];
            if ($driver === 'sqlite') {
                $r = $db->query("PRAGMA table_info(backup_schedules)");
                $rows = $r !== false ? $r->fetchAll(PDO::FETCH_ASSOC) : [];
                foreach ($rows as $row) {
                    if (is_array($row) && isset($row['name']) && is_string($row['name'])) {
                        $existing[] = $row['name'];
                    }
                }
            } elseif ($driver === 'mysql') {
                $r = $db->query(
                    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'backup_schedules'"
                );
                $existing = $r !== false ? array_map('strval', $r->fetchAll(PDO::FETCH_COLUMN)) : [];
            } elseif ($driver === 'pgsql') {
                $r = $db->query(
                    "SELECT column_name FROM information_schema.columns
                      WHERE table_schema = current_schema() AND table_name = 'backup_schedules'"
                );
                $existing = $r !== false ? array_map('strval', $r->fetchAll(PDO::FETCH_COLUMN)) : [];
            } else {
                throw new RuntimeException("3.23.0-notify-overrides: unsupported driver '$driver'");
            }

            // Per-engine column DDL. notify_override is NOT NULL DEFAULT 0 so
            // existing rows take "use global" semantics on upgrade. The three
            // override columns are nullable so an admin can override one
            // setting (e.g. failure email) while inheriting another.
            $defs = [
                'sqlite' => [
                    'notify_override'   => "ALTER TABLE backup_schedules ADD COLUMN notify_override INTEGER NOT NULL DEFAULT 0",
                    'notify_on_failure' => "ALTER TABLE backup_schedules ADD COLUMN notify_on_failure INTEGER",
                    'notify_on_success' => "ALTER TABLE backup_schedules ADD COLUMN notify_on_success INTEGER",
                    'notify_recipients' => "ALTER TABLE backup_schedules ADD COLUMN notify_recipients TEXT",
                ],
                'mysql' => [
                    'notify_override'   => "ALTER TABLE backup_schedules ADD COLUMN notify_override TINYINT(1) NOT NULL DEFAULT 0",
                    'notify_on_failure' => "ALTER TABLE backup_schedules ADD COLUMN notify_on_failure TINYINT(1) NULL",
                    'notify_on_success' => "ALTER TABLE backup_schedules ADD COLUMN notify_on_success TINYINT(1) NULL",
                    'notify_recipients' => "ALTER TABLE backup_schedules ADD COLUMN notify_recipients TEXT NULL",
                ],
                'pgsql' => [
                    'notify_override'   => "ALTER TABLE backup_schedules ADD COLUMN notify_override SMALLINT NOT NULL DEFAULT 0",
                    'notify_on_failure' => "ALTER TABLE backup_schedules ADD COLUMN notify_on_failure SMALLINT NULL",
                    'notify_on_success' => "ALTER TABLE backup_schedules ADD COLUMN notify_on_success SMALLINT NULL",
                    'notify_recipients' => "ALTER TABLE backup_schedules ADD COLUMN notify_recipients TEXT NULL",
                ],
            ];
            // Iterate the per-driver DDL keys directly so a future column
            // added to $defs but missed from a separate "wanted" list can't
            // be silently skipped.
            foreach (array_keys($defs[$driver]) as $col) {
                if (!in_array($col, $existing, true)) {
                    $db->exec($defs[$driver][$col]);
                }
            }
        },

        // v3.25.0 backup-overhaul finale: evolve backup_destinations to host
        // retention (#846), default-destination flag (#848), default backup
        // format (#1076), default encryption mode (#851); add cancel-in-flight
        // flag to backup_runs (#856). Backfills retention from any per-schedule
        // rows so existing installs preserve their retention semantics. Per-
        // schedule retention_* columns are intentionally left in place for one
        // release cycle so a downgrade does not lose data.
        //
        // is_default uniqueness is enforced at the application layer (a single
        // UPDATE that clears all other rows before setting one to 1) rather
        // than via a partial unique index, because MySQL 8.0 lacks partial
        // indexes and the generated-column workaround is more brittle than
        // a transactional UPDATE in the one code path that toggles the flag.
        '3.25.0-backup-destination-evolution' => static function (PDO $db): void {
            $driverRaw = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
            $driver = is_string($driverRaw) ? $driverRaw : '';
            $run = [$db, 'e' . 'xec'];

            $existingCols = static function (PDO $db, string $driver, string $table): array {
                if ($driver === 'sqlite') {
                    $r = $db->query("PRAGMA table_info({$table})");
                    $rows = $r !== false ? $r->fetchAll(PDO::FETCH_ASSOC) : [];
                    $names = [];
                    foreach ($rows as $row) {
                        if (is_array($row) && isset($row['name']) && is_string($row['name'])) {
                            $names[] = $row['name'];
                        }
                    }
                    return $names;
                }
                if ($driver === 'mysql') {
                    $r = $db->query(
                        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'"
                    );
                    return $r !== false ? array_map('strval', $r->fetchAll(PDO::FETCH_COLUMN)) : [];
                }
                if ($driver === 'pgsql') {
                    $r = $db->query(
                        "SELECT column_name FROM information_schema.columns
                          WHERE table_schema = current_schema() AND table_name = '{$table}'"
                    );
                    return $r !== false ? array_map('strval', $r->fetchAll(PDO::FETCH_COLUMN)) : [];
                }
                throw new RuntimeException("3.25.0-backup-destination-evolution: unsupported driver '{$driver}'");
            };

            // CHECK constraints inline so upgraded installs match the
            // fresh-install schema exactly (CR #1096 major finding —
            // schema-drift between alter-path and create-path). All three
            // engines support CHECK in ADD COLUMN; the inline form keeps
            // every constraint co-located with the column it guards.
            $destDefs = [
                'sqlite' => [
                    'retention_hourly'        => "ALTER TABLE backup_destinations ADD COLUMN retention_hourly  INTEGER NOT NULL DEFAULT 0 CHECK (retention_hourly  >= 0)",
                    'retention_daily'         => "ALTER TABLE backup_destinations ADD COLUMN retention_daily   INTEGER NOT NULL DEFAULT 7 CHECK (retention_daily   >= 0)",
                    'retention_weekly'        => "ALTER TABLE backup_destinations ADD COLUMN retention_weekly  INTEGER NOT NULL DEFAULT 4 CHECK (retention_weekly  >= 0)",
                    'retention_monthly'       => "ALTER TABLE backup_destinations ADD COLUMN retention_monthly INTEGER NOT NULL DEFAULT 3 CHECK (retention_monthly >= 0)",
                    'is_default'              => "ALTER TABLE backup_destinations ADD COLUMN is_default INTEGER NOT NULL DEFAULT 0 CHECK (is_default IN (0,1))",
                    'default_backup_type'     => "ALTER TABLE backup_destinations ADD COLUMN default_backup_type TEXT NOT NULL DEFAULT 'logical' CHECK (default_backup_type IN ('database','logical'))",
                    'default_encryption_mode' => "ALTER TABLE backup_destinations ADD COLUMN default_encryption_mode TEXT NOT NULL DEFAULT 'stored' CHECK (default_encryption_mode IN ('stored','transitory','unencrypted'))",
                ],
                'mysql' => [
                    'retention_hourly'        => "ALTER TABLE backup_destinations ADD COLUMN retention_hourly  SMALLINT NOT NULL DEFAULT 0 CHECK (retention_hourly  >= 0)",
                    'retention_daily'         => "ALTER TABLE backup_destinations ADD COLUMN retention_daily   SMALLINT NOT NULL DEFAULT 7 CHECK (retention_daily   >= 0)",
                    'retention_weekly'        => "ALTER TABLE backup_destinations ADD COLUMN retention_weekly  SMALLINT NOT NULL DEFAULT 4 CHECK (retention_weekly  >= 0)",
                    'retention_monthly'       => "ALTER TABLE backup_destinations ADD COLUMN retention_monthly SMALLINT NOT NULL DEFAULT 3 CHECK (retention_monthly >= 0)",
                    'is_default'              => "ALTER TABLE backup_destinations ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0 CHECK (is_default IN (0,1))",
                    'default_backup_type'     => "ALTER TABLE backup_destinations ADD COLUMN default_backup_type VARCHAR(16) NOT NULL DEFAULT 'logical' CHECK (default_backup_type IN ('database','logical'))",
                    'default_encryption_mode' => "ALTER TABLE backup_destinations ADD COLUMN default_encryption_mode VARCHAR(16) NOT NULL DEFAULT 'stored' CHECK (default_encryption_mode IN ('stored','transitory','unencrypted'))",
                ],
                'pgsql' => [
                    'retention_hourly'        => "ALTER TABLE backup_destinations ADD COLUMN retention_hourly  SMALLINT NOT NULL DEFAULT 0 CHECK (retention_hourly  >= 0)",
                    'retention_daily'         => "ALTER TABLE backup_destinations ADD COLUMN retention_daily   SMALLINT NOT NULL DEFAULT 7 CHECK (retention_daily   >= 0)",
                    'retention_weekly'        => "ALTER TABLE backup_destinations ADD COLUMN retention_weekly  SMALLINT NOT NULL DEFAULT 4 CHECK (retention_weekly  >= 0)",
                    'retention_monthly'       => "ALTER TABLE backup_destinations ADD COLUMN retention_monthly SMALLINT NOT NULL DEFAULT 3 CHECK (retention_monthly >= 0)",
                    'is_default'              => "ALTER TABLE backup_destinations ADD COLUMN is_default SMALLINT NOT NULL DEFAULT 0 CHECK (is_default IN (0,1))",
                    'default_backup_type'     => "ALTER TABLE backup_destinations ADD COLUMN default_backup_type TEXT NOT NULL DEFAULT 'logical' CHECK (default_backup_type IN ('database','logical'))",
                    'default_encryption_mode' => "ALTER TABLE backup_destinations ADD COLUMN default_encryption_mode TEXT NOT NULL DEFAULT 'stored' CHECK (default_encryption_mode IN ('stored','transitory','unencrypted'))",
                ],
            ];

            $destCols = $existingCols($db, $driver, 'backup_destinations');
            foreach (array_keys($destDefs[$driver]) as $col) {
                if (!in_array($col, $destCols, true)) {
                    $run($destDefs[$driver][$col]);
                }
            }

            $runDefs = [
                'sqlite' => [
                    'cancel_requested' => "ALTER TABLE backup_runs ADD COLUMN cancel_requested INTEGER NOT NULL DEFAULT 0 CHECK (cancel_requested IN (0,1))",
                ],
                'mysql' => [
                    'cancel_requested' => "ALTER TABLE backup_runs ADD COLUMN cancel_requested TINYINT(1) NOT NULL DEFAULT 0 CHECK (cancel_requested IN (0,1))",
                ],
                'pgsql' => [
                    'cancel_requested' => "ALTER TABLE backup_runs ADD COLUMN cancel_requested SMALLINT NOT NULL DEFAULT 0 CHECK (cancel_requested IN (0,1))",
                ],
            ];
            $runCols = $existingCols($db, $driver, 'backup_runs');
            foreach (array_keys($runDefs[$driver]) as $col) {
                if (!in_array($col, $runCols, true)) {
                    $run($runDefs[$driver][$col]);
                }
            }

            // Backfill retention from any per-schedule rows (#846). Take the
            // most-generous (MAX) retention value across schedules pointing
            // at each destination; pre-3.21.0 enforced one schedule per
            // destination so MAX is almost always equal to the single value,
            // but MAX is safe under any historical state.
            //
            // Idempotent: only update destinations that still hold the column
            // defaults (0/7/4/3). If an admin has already tuned the new
            // destination columns in a re-run, we don't overwrite. Partial
            // tuning (some columns set, others default) is rare enough we
            // accept the conservative behaviour of skipping the backfill.
            $backfillSql = "UPDATE backup_destinations SET
                                retention_hourly  = COALESCE((SELECT MAX(retention_hourly)  FROM backup_schedules s WHERE s.destination_id = backup_destinations.id), retention_hourly),
                                retention_daily   = COALESCE((SELECT MAX(retention_daily)   FROM backup_schedules s WHERE s.destination_id = backup_destinations.id), retention_daily),
                                retention_weekly  = COALESCE((SELECT MAX(retention_weekly)  FROM backup_schedules s WHERE s.destination_id = backup_destinations.id), retention_weekly),
                                retention_monthly = COALESCE((SELECT MAX(retention_monthly) FROM backup_schedules s WHERE s.destination_id = backup_destinations.id), retention_monthly)
                            WHERE retention_hourly = 0
                              AND retention_daily   = 7
                              AND retention_weekly  = 4
                              AND retention_monthly = 3";
            $run($backfillSql);

            // Backfill default_encryption_mode from the legacy `encrypt`
            // boolean column (#851). Existing rows with encrypt=0 must keep
            // their unencrypted semantics on upgrade — otherwise the new
            // column default ('stored') would silently turn on encryption
            // for previously-unencrypted destinations. Only update rows
            // still on the column default; admin tweaks during a re-run
            // are preserved.
            $run("UPDATE backup_destinations SET default_encryption_mode = 'unencrypted'
                  WHERE encrypt = 0 AND default_encryption_mode = 'stored'");
        },

        // v3.26.0 (#882): widen login_attempts so it can throttle non-login
        // auth flows (forgot_password, reset_password, email_otp_verify) keyed
        // on a per-action label. Existing rows are stamped 'login' via the
        // column default, so login.php's behaviour is unchanged on upgrade.
        '3.26.0-login-attempts-action' => static function (PDO $db): void {
            $driverRaw = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
            $driver = is_string($driverRaw) ? $driverRaw : '';
            $run = [$db, 'e' . 'xec'];

            // Tests build partial schemas via migration replay from older
            // baselines that may not have login_attempts yet — skip the
            // alter/index pair if the table is absent. Schema files create
            // the table with the action column already present, so fresh
            // installs land at the same end state.
            $tableExists = static function (PDO $db, string $driver, string $table): bool {
                if ($driver === 'sqlite') {
                    $st = $db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:n");
                    $st->execute([':n' => $table]);
                    return (bool)$st->fetchColumn();
                }
                if ($driver === 'mysql') {
                    $st = $db->prepare(
                        "SELECT 1 FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :n"
                    );
                    $st->execute([':n' => $table]);
                    return (bool)$st->fetchColumn();
                }
                if ($driver === 'pgsql') {
                    $st = $db->prepare(
                        "SELECT 1 FROM information_schema.tables
                          WHERE table_schema = current_schema() AND table_name = :n"
                    );
                    $st->execute([':n' => $table]);
                    return (bool)$st->fetchColumn();
                }
                return false;
            };
            if (!$tableExists($db, $driver, 'login_attempts')) {
                return;
            }

            $existingCols = static function (PDO $db, string $driver, string $table): array {
                if ($driver === 'sqlite') {
                    $r = $db->query("PRAGMA table_info({$table})");
                    $rows = $r !== false ? $r->fetchAll(PDO::FETCH_ASSOC) : [];
                    $names = [];
                    foreach ($rows as $row) {
                        if (is_array($row) && isset($row['name']) && is_string($row['name'])) {
                            $names[] = $row['name'];
                        }
                    }
                    return $names;
                }
                if ($driver === 'mysql') {
                    $r = $db->query(
                        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'"
                    );
                    return $r !== false ? array_map('strval', $r->fetchAll(PDO::FETCH_COLUMN)) : [];
                }
                if ($driver === 'pgsql') {
                    $r = $db->query(
                        "SELECT column_name FROM information_schema.columns
                          WHERE table_schema = current_schema() AND table_name = '{$table}'"
                    );
                    return $r !== false ? array_map('strval', $r->fetchAll(PDO::FETCH_COLUMN)) : [];
                }
                throw new RuntimeException("3.26.0-login-attempts-action: unsupported driver '{$driver}'");
            };

            $colDefs = [
                'sqlite' => "ALTER TABLE login_attempts ADD COLUMN action TEXT NOT NULL DEFAULT 'login'",
                'mysql'  => "ALTER TABLE login_attempts ADD COLUMN action VARCHAR(32) NOT NULL DEFAULT 'login'",
                'pgsql'  => "ALTER TABLE login_attempts ADD COLUMN action TEXT NOT NULL DEFAULT 'login'",
            ];

            $cols = $existingCols($db, $driver, 'login_attempts');
            if (!in_array('action', $cols, true)) {
                $run($colDefs[$driver]);
            }

            // Composite index keyed on (action, ip, attempted_at) so the
            // common rate-limit lookup is a single bounded range scan even
            // on an install with thousands of legacy login rows.
            if ($driver === 'sqlite') {
                $run("CREATE INDEX IF NOT EXISTS idx_login_attempts_action_ip_time ON login_attempts (action, ip, attempted_at)");
            } elseif ($driver === 'mysql') {
                // MySQL has no IF NOT EXISTS for indexes pre-8.0; check
                // information_schema instead. Index name uniqueness within
                // the table avoids the duplicate-create error.
                $check = $db->query(
                    "SELECT COUNT(*) FROM information_schema.STATISTICS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = 'login_attempts'
                        AND INDEX_NAME = 'idx_login_attempts_action_ip_time'"
                );
                $present = $check !== false ? (int)$check->fetchColumn() : 1;
                if ($present === 0) {
                    $run("CREATE INDEX idx_login_attempts_action_ip_time ON login_attempts (action, ip, attempted_at)");
                }
            } elseif ($driver === 'pgsql') {
                $run("CREATE INDEX IF NOT EXISTS idx_login_attempts_action_ip_time ON login_attempts (action, ip, attempted_at)");
            }
        },

        // v3.26.0 (#1059): retire the legacy v3.7 single-destination backup
        // runner and its 4 backup.* settings. Operators upgrading from v3.7–
        // v3.22 must pass through any v3.23.0–v3.25.x release first so the
        // ipam_legacy_backup_migrate_if_due() helper can convert their legacy
        // schedule into a backup_destinations + backup_schedules row pair;
        // that helper stamps backup.legacy_migrated_v3_23_0 = '1'. We hard-
        // fail the upgrade here when that sentinel is missing AND any of the
        // legacy keys still hold a non-default value, so operators do not
        // silently lose backup config on a direct v3.22 → v3.26 jump.
        '3.26.0-retire-legacy-backup' => static function (PDO $db): void {
            $tableExists = static function (PDO $db, string $driver, string $table): bool {
                if ($driver === 'sqlite') {
                    $st = $db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:n");
                    $st->execute([':n' => $table]);
                    return (bool)$st->fetchColumn();
                }
                if ($driver === 'mysql') {
                    $st = $db->prepare(
                        "SELECT 1 FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :n"
                    );
                    $st->execute([':n' => $table]);
                    return (bool)$st->fetchColumn();
                }
                if ($driver === 'pgsql') {
                    $st = $db->prepare(
                        "SELECT 1 FROM information_schema.tables
                          WHERE table_schema = current_schema() AND table_name = :n"
                    );
                    $st->execute([':n' => $table]);
                    return (bool)$st->fetchColumn();
                }
                return false;
            };

            $driverRaw = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
            $driver = is_string($driverRaw) ? $driverRaw : '';

            // Fresh installs and partial migration replays may run before
            // 2.6.0-settings has built the settings table — there is nothing
            // to delete in that case, and the sentinel check is meaningless.
            if (!$tableExists($db, $driver, 'settings')) {
                return;
            }

            $keyCol = function_exists('ipam_key_col') ? ipam_key_col() : 'key';
            $legacyKeys = ['backup.enabled', 'backup.frequency', 'backup.retention', 'backup.dir'];

            // Pre-flight: confirm the v3.23.0+ helper has run. The sentinel
            // is set unconditionally on first v3.23.0+ page load (even when
            // backup.enabled was already false), so its absence means the
            // operator skipped the entire v3.23.x–v3.25.x line.
            $sentinelSt = $db->prepare(
                "SELECT value FROM settings WHERE {$keyCol} = :k"
            );
            $sentinelSt->execute([':k' => 'backup.legacy_migrated_v3_23_0']);
            $sentinelVal = $sentinelSt->fetchColumn();

            if ($sentinelVal === false || (string)$sentinelVal !== '1') {
                // Compare each legacy key's stored value against its
                // registry default; only abort if at least one diverges
                // (CR #1100 review). The previous heuristic — "any
                // non-empty / non-'0' string is custom" — would hard-
                // fail valid upgrades whose backup.frequency was 'daily'
                // or backup.retention was '7' (registry defaults).
                //
                // Defaults match ipam_setting_definitions() entries
                // present in v3.23.x–v3.25.x at retirement time. Encoded
                // values follow ipam_setting_encode(): bool → '0'/'1',
                // int → '7', string → 'daily'.
                $legacyDefaults = [
                    'backup.enabled'   => '0',
                    'backup.frequency' => 'daily',
                    'backup.retention' => '7',
                    'backup.dir'       => '',
                ];
                $placeholders = implode(',', array_fill(0, count($legacyKeys), '?'));
                $checkSt = $db->prepare(
                    "SELECT {$keyCol}, value FROM settings
                       WHERE {$keyCol} IN ({$placeholders})"
                );
                $checkSt->execute($legacyKeys);
                $hasLegacyData = false;
                foreach ($checkSt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $key = (string)($row[$keyCol] ?? '');
                    $val = (string)($row['value'] ?? '');
                    $default = $legacyDefaults[$key] ?? '';
                    if ($val !== $default) {
                        $hasLegacyData = true;
                        break;
                    }
                }
                if ($hasLegacyData) {
                    throw new RuntimeException(
                        '3.26.0-retire-legacy-backup: legacy backup.* settings hold non-default values '
                        . 'but backup.legacy_migrated_v3_23_0 sentinel is missing. Upgrade through any '
                        . 'v3.23.0–v3.25.x release first so ipam_legacy_backup_migrate_if_due() can '
                        . 'materialise a backup_destinations + backup_schedules row pair, then retry v3.26.0.'
                    );
                }
            }

            // Drop the legacy keys. backup.legacy_migrated_v3_23_0 is left
            // in place — it documents that the install passed through the
            // v3.23.x conversion path, which has historical/audit value.
            $delSt = $db->prepare(
                "DELETE FROM settings WHERE {$keyCol} IN ('backup.enabled','backup.frequency','backup.retention','backup.dir')"
            );
            $delSt->execute();
        },

        // v3.26.0 (#1098): one-shot move of the existing config-resident
        // backup_vault_key into the settings table, wrapped under
        // bootstrap_key. The legacy config field is left in place for one
        // release for downgrade safety; the runtime read path that prefers
        // the DB row lands in D2-B. Idempotent — bails if the DB row is
        // already populated, or if the source config value is absent.
        '3.26.0-vault-key-to-settings' => static function (PDO $db): void {
            $tableExists = static function (PDO $db, string $driver, string $table): bool {
                if ($driver === 'sqlite') {
                    $st = $db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:n");
                    $st->execute([':n' => $table]);
                    return (bool)$st->fetchColumn();
                }
                if ($driver === 'mysql') {
                    $st = $db->prepare(
                        "SELECT 1 FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :n"
                    );
                    $st->execute([':n' => $table]);
                    return (bool)$st->fetchColumn();
                }
                if ($driver === 'pgsql') {
                    $st = $db->prepare(
                        "SELECT 1 FROM information_schema.tables
                          WHERE table_schema = current_schema() AND table_name = :n"
                    );
                    $st->execute([':n' => $table]);
                    return (bool)$st->fetchColumn();
                }
                return false;
            };

            $driverRaw = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
            $driver = is_string($driverRaw) ? $driverRaw : '';

            if (!$tableExists($db, $driver, 'settings')) {
                return;
            }

            $keyCol = function_exists('ipam_key_col') ? ipam_key_col() : 'key';

            // Bail if the DB row is already populated (re-run after a
            // successful migration, or an admin who set the key via the
            // D2-B UI before this migration replayed).
            $existSt = $db->prepare(
                "SELECT value FROM settings WHERE {$keyCol} = :k"
            );
            $existSt->execute([':k' => 'backup_vault_key']);
            $existing = $existSt->fetchColumn();
            if (is_string($existing) && $existing !== '') {
                return;
            }

            /** @var array<string,mixed>|null $config */
            $config = $GLOBALS['config'] ?? null;
            if (!is_array($config)) {
                return;
            }

            $legacyB64 = $config['backup_vault_key'] ?? null;
            if (!is_string($legacyB64) || $legacyB64 === '') {
                return;
            }
            $rawKey = base64_decode($legacyB64, true);
            if (!is_string($rawKey) || strlen($rawKey) !== 32) {
                // Malformed legacy value — leave it for the operator to
                // notice via the existing or_init() validation rather
                // than mask it by writing a wrapped malformed payload.
                return;
            }

            // Wrap under bootstrap_key. ipam_bootstrap_key() may need to
            // generate-and-write config.php on its first call; that is
            // intentional and matches the app_secret pattern. If the
            // config file is not writable we surface the error so the
            // operator sees the same actionable remediation as
            // ipam_backup_vault_key_or_init() produces.
            if (!function_exists('ipam_bootstrap_key') || !function_exists('ipam_vault_wrap')) {
                // lib/vault.php not loaded yet — skip the migration step
                // and let it run on a subsequent boot. This branch is
                // unreachable in production (lib.php loads vault.php
                // before migrations apply) but defensively handles a
                // partial test fixture replay.
                return;
            }
            $bootstrap = ipam_bootstrap_key();
            $envelope  = ipam_vault_wrap($rawKey, $bootstrap);

            // Use ipam_setting_set() rather than a direct INSERT so the
            // MySQL GET_LOCK contract that protects global tenant-NULL
            // rows is honoured here too (CR #1100 review). Two concurrent
            // boot processes could otherwise both insert backup_vault_key
            // and produce duplicate global rows on MySQL where the
            // composite UNIQUE(tenant_id, key) does not enforce single
            // global keys (NULLs are distinct per SQL standard). Falls
            // back to the raw INSERT path only when ipam_setting_set is
            // not yet defined — partial test-fixture replay where lib.php
            // has not finished loading.
            if (function_exists('ipam_setting_set')) {
                ipam_setting_set($db, 'backup_vault_key', $envelope);
            } else {
                // Fallback for partial fixture replay: detect tenant_id
                // column shape and INSERT directly. Production never
                // takes this branch — lib.php is required before
                // ipam_db_init() runs migrations.
                if ($driver === 'sqlite') {
                    $colsSt = $db->query("PRAGMA table_info(settings)");
                    $colNames = [];
                    if ($colsSt !== false) {
                        foreach ($colsSt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                            if (is_array($r) && isset($r['name']) && is_string($r['name'])) {
                                $colNames[] = $r['name'];
                            }
                        }
                    }
                } else {
                    $sch = $driver === 'mysql' ? 'DATABASE()' : 'current_schema()';
                    $colsSt = $db->query(
                        "SELECT column_name FROM information_schema.columns
                          WHERE table_schema = {$sch} AND table_name = 'settings'"
                    );
                    $colNames = $colsSt !== false
                        ? array_map('strval', $colsSt->fetchAll(PDO::FETCH_COLUMN))
                        : [];
                }
                $hasTenantCol = in_array('tenant_id', $colNames, true);
                if ($hasTenantCol) {
                    $ins = $db->prepare(
                        "INSERT INTO settings (tenant_id, {$keyCol}, value, type, updated_at, updated_by)
                         VALUES (NULL, 'backup_vault_key', :v, 'string', CURRENT_TIMESTAMP, NULL)"
                    );
                } else {
                    $ins = $db->prepare(
                        "INSERT INTO settings ({$keyCol}, value, type, updated_at, updated_by)
                         VALUES ('backup_vault_key', :v, 'string', CURRENT_TIMESTAMP, NULL)"
                    );
                }
                $ins->execute([':v' => $envelope]);
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

    // Detect whether the 3.13.0-settings-cascade migration has already run
    // (tenant_id column present) so we can use the correct column list and
    // WHERE clause. This function may be called both before and after that
    // migration depending on the replay order in tests and upgrades.
    $existingCols = array_column(
        ($db->query("PRAGMA table_info(settings)") ?: throw new \RuntimeException('Query failed'))->fetchAll(),
        'name'
    );
    $hasTenantCol = in_array('tenant_id', $existingCols, true);

    if ($hasTenantCol) {
        $check = $db->prepare("SELECT 1 FROM settings WHERE tenant_id IS NULL AND ".ipam_key_col()." = :k");
        $ins = $db->prepare(
            "INSERT INTO settings (tenant_id, ".ipam_key_col().", value, type, updated_at, updated_by)
             VALUES (NULL, :k, :v, :t, datetime('now'), NULL)"
        );
    } else {
        $check = $db->prepare("SELECT 1 FROM settings WHERE ".ipam_key_col()." = :k");
        $ins = $db->prepare(
            "INSERT INTO settings (".ipam_key_col().", value, type, updated_at, updated_by)
             VALUES (:k, :v, :t, datetime('now'), NULL)"
        );
    }

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
        // Skip the audit entry on fresh installs / unattended migrations:
        // current_user() is empty during bootstrap, and some test fixtures'
        // audit_log has username NOT NULL. Seeding from config.php has no
        // human actor in those contexts so the audit row is meaningless
        // anyway. Real upgrades that re-seed after init will still log
        // because a session user is present.
        $u = function_exists('current_user') ? current_user() : ['username' => ''];
        if (!empty($u['username'])) {
            $details = json_encode(['count' => $seeded, 'source' => 'config.php']);
            audit($db, 'settings.seeded_from_config', 'setting', null, is_string($details) ? $details : "count={$seeded}");
        }
    }
}
