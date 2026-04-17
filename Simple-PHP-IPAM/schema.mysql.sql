-- schema.mysql.sql (v2.10.0 #383)
--
-- MySQL 8.0+ authoritative schema for fresh installs. Equivalent to the
-- fully-migrated state of schema.sql (SQLite) + every v1.x/v2.x migration
-- through v2.9.0-blob-affinity. Migration replay on top of this file is a
-- no-op because schema_migrations is pre-populated with every historical
-- version row at the bottom of this script.
--
-- Engine / charset conventions:
--   - InnoDB on every table (explicit, not default-inherited)
--   - utf8mb4 / utf8mb4_general_ci by default; individual columns use
--     utf8mb4_bin for case-sensitive byte-wise comparison (usernames,
--     CIDRs, hostnames, IPs) to match SQLite's default BINARY collation
--   - VARBINARY(16) for ip_bin / network_bin, native length (4 bytes for
--     IPv4, 16 bytes for IPv6, never left-padded). Locked in #410.
--   - BIGINT UNSIGNED NOT NULL AUTO_INCREMENT for surrogate primary keys;
--     FK columns referencing those use the matching BIGINT UNSIGNED type
--   - DATETIME columns default to (UTC_TIMESTAMP()) so stored values are
--     UTC regardless of session timezone, matching SQLite's datetime('now')
--   - Append-only tables (audit_log) enforced via BEFORE UPDATE/DELETE
--     triggers using SIGNAL SQLSTATE '45000' (MySQL 8.0.29+)
--   - *_updated_at triggers use BEFORE UPDATE FOR EACH ROW SET NEW.updated_at
--     which is the idiomatic MySQL form; SQLite's AFTER UPDATE + recursive
--     UPDATE is a workaround that is not needed here.
--   - CHECK constraints honoured starting MySQL 8.0.16; v2.10.0 effective
--     minimum is 8.0.29 so all CHECKs are active
--   - oidc_sub uniqueness: MySQL UNIQUE treats NULLs as distinct so a
--     plain UNIQUE KEY allows multiple NULLs without the partial-index
--     trick SQLite uses

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- users
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username            VARCHAR(191) COLLATE utf8mb4_bin NOT NULL UNIQUE,
  password_hash       VARCHAR(255) NOT NULL,
  role                VARCHAR(20)  NOT NULL DEFAULT 'admin',
  is_active           TINYINT      NOT NULL DEFAULT 1,
  name                VARCHAR(255) NOT NULL DEFAULT '',
  email               VARCHAR(255) NOT NULL DEFAULT '',
  oidc_sub            VARCHAR(191) COLLATE utf8mb4_bin NULL,
  last_login_at       DATETIME NULL,
  password_changed_at DATETIME NULL,
  theme               VARCHAR(10)  NOT NULL DEFAULT 'auto',
  created_at          DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
  updated_at          DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
  UNIQUE KEY idx_users_oidc_sub (oidc_sub)
  -- No CHECK on role or theme: schema.sql (SQLite) has none, and
  -- demo_seed_data() inserts a display-only 'netops' user. Enum
  -- enforcement lives at the application layer (settings.php,
  -- users.php) where it belongs.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TRIGGER IF NOT EXISTS users_updated_at
  BEFORE UPDATE ON users FOR EACH ROW
  SET NEW.updated_at = UTC_TIMESTAMP();

-- ---------------------------------------------------------------------------
-- sites (hierarchy supported via parent_id self-FK)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sites (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(191) COLLATE utf8mb4_bin NOT NULL UNIQUE,
  description TEXT NOT NULL DEFAULT (''),
  parent_id   BIGINT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
  KEY idx_sites_parent_id (parent_id),
  CONSTRAINT fk_sites_parent FOREIGN KEY (parent_id) REFERENCES sites(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- vrfs (Virtual Routing and Forwarding)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS vrfs (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(191) COLLATE utf8mb4_bin NOT NULL UNIQUE,
  description    TEXT NOT NULL DEFAULT (''),
  rd             VARCHAR(191) NOT NULL DEFAULT '',
  asn            VARCHAR(64)  NOT NULL DEFAULT '',
  rt_import      VARCHAR(191) NOT NULL DEFAULT '',
  rt_export      VARCHAR(191) NOT NULL DEFAULT '',
  enforce_unique TINYINT      NOT NULL DEFAULT 1,
  created_at     DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
  updated_at     DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
  KEY idx_vrfs_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TRIGGER IF NOT EXISTS vrfs_updated_at
  BEFORE UPDATE ON vrfs FOR EACH ROW
  SET NEW.updated_at = UTC_TIMESTAMP();

-- ---------------------------------------------------------------------------
-- vlans (first-class 802.1Q VLAN objects)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS vlans (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  vlan_id     INT             NOT NULL,
  name        VARCHAR(191)    NOT NULL,
  description TEXT            NOT NULL DEFAULT (''),
  site_id     BIGINT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
  updated_at  DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
  UNIQUE KEY uq_vlans_vlan_id_site_id (vlan_id, site_id),
  CONSTRAINT vlans_vlan_id_range CHECK (vlan_id BETWEEN 1 AND 4094),
  CONSTRAINT fk_vlans_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TRIGGER IF NOT EXISTS vlans_updated_at
  BEFORE UPDATE ON vlans FOR EACH ROW
  SET NEW.updated_at = UTC_TIMESTAMP();

-- Clear legacy subnets.vlan_id when the backing VLAN row is deleted.
CREATE TRIGGER IF NOT EXISTS vlans_before_delete_cleanup_subnets
  BEFORE DELETE ON vlans FOR EACH ROW
  UPDATE subnets SET vlan_id = NULL WHERE vlan_fk = OLD.id;

-- ---------------------------------------------------------------------------
-- vlan_ranges (v2.4.0)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS vlan_ranges (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(191)    NOT NULL,
  vlan_min    INT             NOT NULL,
  vlan_max    INT             NOT NULL,
  description TEXT            NOT NULL DEFAULT (''),
  site_id     BIGINT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
  updated_at  DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
  CONSTRAINT vlan_ranges_min_range CHECK (vlan_min BETWEEN 1 AND 4094),
  CONSTRAINT vlan_ranges_max_range CHECK (vlan_max BETWEEN 1 AND 4094),
  CONSTRAINT vlan_ranges_order     CHECK (vlan_min <= vlan_max),
  CONSTRAINT fk_vlan_ranges_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- subnets
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS subnets (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cidr        VARCHAR(64)  COLLATE utf8mb4_bin NOT NULL,
  ip_version  TINYINT      NOT NULL,
  network     VARCHAR(45)  COLLATE utf8mb4_bin NOT NULL,
  network_bin VARBINARY(16) NOT NULL,
  prefix      SMALLINT     NOT NULL,
  description TEXT         NOT NULL DEFAULT (''),
  notes       TEXT         NOT NULL DEFAULT (''),
  site_id     BIGINT UNSIGNED NULL,
  vlan_id     INT          NULL,
  vlan_fk     BIGINT UNSIGNED NULL,
  vrf_id      BIGINT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
  updated_at  DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
  UNIQUE KEY uq_subnets_cidr_vrf (cidr, vrf_id),
  KEY idx_subnets_ver_prefix_netbin (ip_version, prefix, network_bin),
  KEY idx_subnets_site_id (site_id),
  KEY idx_subnets_vrf_id (vrf_id),
  KEY idx_subnets_vlan_fk (vlan_fk),
  CONSTRAINT fk_subnets_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL,
  CONSTRAINT fk_subnets_vlan FOREIGN KEY (vlan_fk) REFERENCES vlans(id) ON DELETE SET NULL,
  CONSTRAINT fk_subnets_vrf  FOREIGN KEY (vrf_id)  REFERENCES vrfs(id)  ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TRIGGER IF NOT EXISTS subnets_updated_at
  BEFORE UPDATE ON subnets FOR EACH ROW
  SET NEW.updated_at = UTC_TIMESTAMP();

-- ---------------------------------------------------------------------------
-- contacts (v2.1.0, first-class contact objects)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS contacts (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(191) NOT NULL,
  email      VARCHAR(255) NOT NULL DEFAULT '',
  phone      VARCHAR(64)  NOT NULL DEFAULT '',
  org        VARCHAR(191) NOT NULL DEFAULT '',
  note       TEXT         NOT NULL DEFAULT (''),
  created_at DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
  updated_at DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
  KEY idx_contacts_name  (name),
  KEY idx_contacts_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TRIGGER IF NOT EXISTS contacts_updated_at
  BEFORE UPDATE ON contacts FOR EACH ROW
  SET NEW.updated_at = UTC_TIMESTAMP();

-- ---------------------------------------------------------------------------
-- addresses (individual IPs)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS addresses (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  subnet_id        BIGINT UNSIGNED NOT NULL,
  ip               VARCHAR(45)  COLLATE utf8mb4_bin NOT NULL,
  ip_bin           VARBINARY(16) NOT NULL,
  hostname         VARCHAR(255) COLLATE utf8mb4_bin NOT NULL DEFAULT '',
  owner            VARCHAR(191) NOT NULL DEFAULT '',
  note             TEXT         NOT NULL DEFAULT (''),
  grp              VARCHAR(191) NOT NULL DEFAULT '',
  mac              VARCHAR(32)  NOT NULL DEFAULT '',
  expires_at       DATE         NULL,
  status           VARCHAR(20)  NOT NULL DEFAULT 'used',
  owner_contact_id BIGINT UNSIGNED NULL,
  last_seen_at     DATETIME     NULL,
  is_stale         TINYINT      NOT NULL DEFAULT 0,
  created_at       DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
  updated_at       DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
  UNIQUE KEY uq_addresses_subnet_ip (subnet_id, ip),
  KEY idx_addresses_subnet_ipbin (subnet_id, ip_bin),
  KEY idx_addresses_hostname (hostname),
  KEY idx_addresses_owner (owner),
  KEY idx_addresses_status (status),
  KEY idx_addresses_grp (grp),
  KEY idx_addresses_owner_contact_id (owner_contact_id),
  KEY idx_addresses_is_stale (is_stale),
  CONSTRAINT fk_addresses_subnet  FOREIGN KEY (subnet_id)        REFERENCES subnets(id)  ON DELETE CASCADE,
  CONSTRAINT fk_addresses_contact FOREIGN KEY (owner_contact_id) REFERENCES contacts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TRIGGER IF NOT EXISTS addresses_updated_at
  BEFORE UPDATE ON addresses FOR EACH ROW
  SET NEW.updated_at = UTC_TIMESTAMP();

-- ---------------------------------------------------------------------------
-- address_history (per-address change log)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS address_history (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  created_at  DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
  address_id  BIGINT UNSIGNED NULL,
  subnet_id   BIGINT UNSIGNED NOT NULL,
  ip          VARCHAR(45)  NOT NULL,
  action      VARCHAR(64)  NOT NULL,
  user_id     BIGINT UNSIGNED NULL,
  username    VARCHAR(191) NULL,
  client_ip   VARCHAR(45)  NULL,
  user_agent  VARCHAR(512) NULL,
  before_json TEXT         NULL,
  after_json  TEXT         NULL,
  KEY idx_address_history_address_id (address_id),
  KEY idx_address_history_subnet_id  (subnet_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- audit_log (append-only, enforced by SIGNAL triggers)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  created_at  DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
  user_id     BIGINT UNSIGNED NULL,
  username    VARCHAR(191) NULL,
  action      VARCHAR(191) NOT NULL,
  entity_type VARCHAR(64)  NOT NULL,
  entity_id   BIGINT UNSIGNED NULL,
  ip          VARCHAR(45)  NULL,
  user_agent  VARCHAR(512) NULL,
  details     TEXT         NULL,
  KEY idx_audit_log_action     (action),
  KEY idx_audit_log_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- v2.10.0 #502 post-review: the trigger body wraps SIGNAL in an IF block
-- gated on the session variable @ipam_bypass_append_only. Housekeeping
-- routines (e.g. prune_audit_log in lib.php) set the variable to 1 for the
-- duration of their work, DELETE, then unset it. Other connections keep the
-- variable as NULL/0 and continue to be blocked — session variables are
-- per-connection so the bypass never leaks. See MysqlDialect::append_only_trigger().
CREATE TRIGGER IF NOT EXISTS audit_log_no_update
  BEFORE UPDATE ON audit_log FOR EACH ROW
  BEGIN
    IF @ipam_bypass_append_only IS NULL OR @ipam_bypass_append_only <> 1 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only', MYSQL_ERRNO = 1644;
    END IF;
  END;

CREATE TRIGGER IF NOT EXISTS audit_log_no_delete
  BEFORE DELETE ON audit_log FOR EACH ROW
  BEGIN
    IF @ipam_bypass_append_only IS NULL OR @ipam_bypass_append_only <> 1 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only', MYSQL_ERRNO = 1644;
    END IF;
  END;

-- ---------------------------------------------------------------------------
-- login_attempts (rate limiter)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ip           VARCHAR(45) NOT NULL,
  username     VARCHAR(191) COLLATE utf8mb4_bin DEFAULT NULL,
  attempted_at DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
  KEY idx_login_attempts_ip_time (ip, attempted_at),
  KEY idx_login_attempts_username_time (username, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- api_keys
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS api_keys (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(191) NOT NULL,
  key_hash     VARCHAR(191) COLLATE utf8mb4_bin NOT NULL UNIQUE,
  created_at   DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
  last_used_at DATETIME NULL,
  is_active    TINYINT      NOT NULL DEFAULT 1,
  created_by   VARCHAR(191) NOT NULL DEFAULT '',
  is_readonly  TINYINT      NOT NULL DEFAULT 0,
  description  TEXT         NOT NULL DEFAULT ('')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- tags (v2.0.0)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tags (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(50) COLLATE utf8mb4_bin NOT NULL UNIQUE,
  colour     VARCHAR(20) NOT NULL DEFAULT '#6c757d',
  created_at DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP())
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS subnet_tags (
  subnet_id BIGINT UNSIGNED NOT NULL,
  tag_id    BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (subnet_id, tag_id),
  KEY idx_subnet_tags_tag (tag_id),
  CONSTRAINT fk_subnet_tags_subnet FOREIGN KEY (subnet_id) REFERENCES subnets(id) ON DELETE CASCADE,
  CONSTRAINT fk_subnet_tags_tag    FOREIGN KEY (tag_id)    REFERENCES tags(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS address_tags (
  address_id BIGINT UNSIGNED NOT NULL,
  tag_id     BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (address_id, tag_id),
  KEY idx_address_tags_tag (tag_id),
  CONSTRAINT fk_address_tags_address FOREIGN KEY (address_id) REFERENCES addresses(id) ON DELETE CASCADE,
  CONSTRAINT fk_address_tags_tag     FOREIGN KEY (tag_id)     REFERENCES tags(id)      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- site_contacts / subnet_contacts (multi-contact assignments, v3.0.0)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_contacts (
  site_id    BIGINT UNSIGNED NOT NULL,
  contact_id BIGINT UNSIGNED NOT NULL,
  role       VARCHAR(191) NOT NULL DEFAULT '',
  PRIMARY KEY (site_id, contact_id),
  CONSTRAINT fk_site_contacts_site    FOREIGN KEY (site_id)    REFERENCES sites(id)    ON DELETE CASCADE,
  CONSTRAINT fk_site_contacts_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS subnet_contacts (
  subnet_id  BIGINT UNSIGNED NOT NULL,
  contact_id BIGINT UNSIGNED NOT NULL,
  role       VARCHAR(191) NOT NULL DEFAULT '',
  PRIMARY KEY (subnet_id, contact_id),
  CONSTRAINT fk_subnet_contacts_subnet  FOREIGN KEY (subnet_id)  REFERENCES subnets(id)  ON DELETE CASCADE,
  CONSTRAINT fk_subnet_contacts_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- alert_state (utilization alert tracker, v2.0.0)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS alert_state (
  subnet_id       BIGINT UNSIGNED NOT NULL,
  level           VARCHAR(10) NOT NULL,
  last_alerted_at DATETIME    NOT NULL,
  PRIMARY KEY (subnet_id, level),
  CONSTRAINT alert_state_level_check CHECK (level IN ('warn','crit')),
  CONSTRAINT fk_alert_state_subnet FOREIGN KEY (subnet_id) REFERENCES subnets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- aggregates (v2.4.0, RIR-assigned supernet tracking)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS aggregates (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cidr        VARCHAR(64) COLLATE utf8mb4_bin NOT NULL UNIQUE,
  ip_version  TINYINT     NOT NULL,
  network     VARCHAR(45) COLLATE utf8mb4_bin NOT NULL,
  network_bin VARBINARY(16) NOT NULL,
  prefix      SMALLINT    NOT NULL,
  description TEXT        NOT NULL DEFAULT (''),
  rir         VARCHAR(32) NOT NULL DEFAULT '',
  date_added  DATE        NOT NULL DEFAULT (CURRENT_DATE()),
  notes       TEXT        NOT NULL DEFAULT (''),
  created_at  DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
  updated_at  DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP())
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- pd_pools + pd_delegations (v2.4.0, IPv6 Prefix Delegation)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pd_pools (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  parent_subnet_id  BIGINT UNSIGNED NOT NULL,
  delegation_prefix SMALLINT NOT NULL,
  description       TEXT NOT NULL DEFAULT (''),
  site_id           BIGINT UNSIGNED NULL,
  created_at        DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
  updated_at        DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
  UNIQUE KEY uq_pd_pools_parent_subnet (parent_subnet_id),
  CONSTRAINT pd_pools_prefix_range CHECK (delegation_prefix BETWEEN 1 AND 128),
  CONSTRAINT fk_pd_pools_parent FOREIGN KEY (parent_subnet_id) REFERENCES subnets(id) ON DELETE CASCADE,
  CONSTRAINT fk_pd_pools_site   FOREIGN KEY (site_id)          REFERENCES sites(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS pd_delegations (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  pool_id       BIGINT UNSIGNED NOT NULL,
  cidr          VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
  subscriber_id BIGINT UNSIGNED NULL,
  delegated_at  DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
  expires_at    DATETIME NULL,
  notes         TEXT     NOT NULL DEFAULT (''),
  created_at    DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
  KEY idx_pd_delegations_pool       (pool_id),
  KEY idx_pd_delegations_subscriber (subscriber_id),
  CONSTRAINT fk_pd_delegations_pool FOREIGN KEY (pool_id)       REFERENCES pd_pools(id) ON DELETE CASCADE,
  CONSTRAINT fk_pd_delegations_sub  FOREIGN KEY (subscriber_id) REFERENCES contacts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- scan_schedules + scan_results (v2.3.0, network scanning)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS scan_schedules (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  subnet_id        BIGINT UNSIGNED NOT NULL,
  method           VARCHAR(10) NOT NULL DEFAULT 'icmp',
  tcp_port         INT     NULL,
  interval_minutes INT     NOT NULL DEFAULT 60,
  is_active        TINYINT NOT NULL DEFAULT 1,
  last_run_at      DATETIME NULL,
  created_at       DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
  updated_at       DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
  UNIQUE KEY uq_scan_schedules_subnet (subnet_id),
  KEY idx_scan_schedules_active (is_active, last_run_at),
  CONSTRAINT scan_schedules_method   CHECK (method IN ('icmp','tcp','both')),
  CONSTRAINT scan_schedules_tcp_port CHECK (tcp_port IS NULL OR (tcp_port BETWEEN 1 AND 65535)),
  CONSTRAINT scan_schedules_interval CHECK (interval_minutes >= 1),
  CONSTRAINT fk_scan_schedules_subnet FOREIGN KEY (subnet_id) REFERENCES subnets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS scan_results (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  subnet_id  BIGINT UNSIGNED NOT NULL,
  address_id BIGINT UNSIGNED NULL,
  ip         VARCHAR(45) NOT NULL,
  method     VARCHAR(10) NOT NULL,
  is_up      TINYINT     NOT NULL DEFAULT 0,
  latency_ms INT         NULL,
  scanned_at DATETIME    NOT NULL DEFAULT (UTC_TIMESTAMP()),
  KEY idx_scan_results_subnet_time (subnet_id, scanned_at),
  KEY idx_scan_results_address     (address_id, scanned_at),
  CONSTRAINT fk_scan_results_subnet  FOREIGN KEY (subnet_id)  REFERENCES subnets(id)   ON DELETE CASCADE,
  CONSTRAINT fk_scan_results_address FOREIGN KEY (address_id) REFERENCES addresses(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- schema_migrations (pre-seeded below with every historical version)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schema_migrations (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  version    VARCHAR(64) COLLATE utf8mb4_bin NOT NULL UNIQUE,
  applied_at DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP())
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- settings (v2.6.0, key/value config registry)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  `key`      VARCHAR(191) COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
  value      TEXT NULL,
  type       VARCHAR(16) NOT NULL DEFAULT 'string',
  updated_at DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
  updated_by BIGINT UNSIGNED NULL,
  CONSTRAINT settings_type_check CHECK (type IN ('string','int','bool','json')),
  CONSTRAINT fk_settings_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- Pre-seed schema_migrations with every historical version so apply_migrations
-- is a no-op on fresh MySQL installs. New migrations added in v2.10.0+ must
-- be idempotent and safe to run on MySQL, since they WILL execute here.
--
-- Locked in #484 scope decision (2026-04-15): historical SQLite-only
-- migration closures are not refactored for multi-engine; this pre-seed is
-- how we keep them out of the MySQL execution path.
-- ---------------------------------------------------------------------------
INSERT INTO schema_migrations (version) VALUES
  ('0.3'),
  ('0.7'),
  ('0.9'),
  ('0.11'),
  ('0.12'),
  ('0.13'),
  ('0.14'),
  ('1.4'),
  ('1.9'),
  ('1.11'),
  ('1.12'),
  ('1.13'),
  ('1.19.0'),
  ('2.0.0-vlans'),
  ('2.0.0-site-hierarchy'),
  ('2.0.0-tags'),
  ('2.0.0-alert-state'),
  ('2.1.0-vrfs'),
  ('2.1.0-contacts'),
  ('2.3.0-scanning'),
  ('2.4.0-vrf-bgp'),
  ('2.4.0-vlan-ranges'),
  ('2.4.0-aggregates'),
  ('2.4.0-pd-pools'),
  ('2.6.0-settings'),
  ('2.8.0-subnet-notes'),
  ('2.8.0-alert-recipients'),
  ('2.9.0-blob-affinity'),
  ('2.12.0-account-lockout');

SET FOREIGN_KEY_CHECKS = 1;
