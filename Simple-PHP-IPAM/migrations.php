<?php
declare(strict_types=1);

function ipam_migrations(): array
{
    return [
        // 0.3: adds subnets.network_bin and backfills it
        '0.3' => function(PDO $db) {
            $cols = $db->query("PRAGMA table_info(subnets)")->fetchAll();
            $names = array_map(fn($c) => $c['name'], $cols);

            if (!in_array('network_bin', $names, true)) {
                $db->exec("ALTER TABLE subnets ADD COLUMN network_bin BLOB");
            }

            $st = $db->prepare("SELECT id, network FROM subnets WHERE network_bin IS NULL OR length(network_bin)=0");
            $st->execute();
            $rows = $st->fetchAll();

            $up = $db->prepare("UPDATE subnets SET network_bin = :b WHERE id = :id");
            foreach ($rows as $r) {
                $bin = @inet_pton((string)$r['network']);
                if ($bin === false) continue;
                $up->execute([':b' => $bin, ':id' => (int)$r['id']]);
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

            $cols = $db->query("PRAGMA table_info(subnets)")->fetchAll();
            $names = array_map(fn($c) => $c['name'], $cols);

            if (!in_array('site_id', $names, true)) {
                $db->exec("ALTER TABLE subnets ADD COLUMN site_id INTEGER");
            }

            $db->exec("CREATE INDEX IF NOT EXISTS idx_subnets_site_id ON subnets(site_id)");
        },

        // 0.12: OIDC subject claim column on users
        '0.12' => function(PDO $db) {
            $cols  = $db->query("PRAGMA table_info(users)")->fetchAll();
            $names = array_map(fn($c) => $c['name'], $cols);

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
    ];
}
