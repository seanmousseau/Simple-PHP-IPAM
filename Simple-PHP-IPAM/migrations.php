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

            $up = $db->prepare("UPDATE subnets SET network_bin = :b WHERE id = :id");
            foreach ($rows as $r) {
                $bin = @inet_pton(to_str($r['network']));
                if ($bin === false) continue;
                $up->execute([':b' => $bin, ':id' => to_int($r['id'])]);
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

            // Note: PRAGMA foreign_keys is a no-op inside a transaction, but DROP TABLE
            // in SQLite does not check FK constraints on DDL — only DML triggers FK checks.
            // All child tables (addresses, subnet_tags, alert_state) use ON DELETE CASCADE,
            // so the rename-based rebuild is safe without disabling FK enforcement.
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
    ];
}
