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
  timezone                 TEXT         DEFAULT NULL,
  pending_email            VARCHAR(255) NULL,
  pending_email_token_hash VARCHAR(64)  COLLATE utf8mb4_bin NULL,
  pending_email_expires_at DATETIME     NULL,
  totp_secret_enc            TEXT,
  totp_enabled               TINYINT NOT NULL DEFAULT 0,
  failed_auth_count          INT NOT NULL DEFAULT 0,
  locked_until               DATETIME,
  lock_reason                TEXT,
  email_otp_enabled          TINYINT NOT NULL DEFAULT 0,
  email_otp_hash             VARCHAR(255) NULL,
  email_otp_expires_at       DATETIME NULL,
  email_otp_attempts         INT NOT NULL DEFAULT 0,
  preferred_mfa_method       VARCHAR(20) NULL,
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
  vrf_id          BIGINT UNSIGNED NULL,
  alerts_enabled  TINYINT(1) NOT NULL DEFAULT 1,
  dhcp_routers     TEXT DEFAULT NULL,                                -- v3.4.0 #402: comma-sep gateway IPs
  dhcp_dns_servers TEXT DEFAULT NULL,                                -- v3.4.0 #402: comma-sep DNS IPs
  dhcp_domain_name TEXT DEFAULT NULL,                                -- v3.4.0 #402: domain name
  dhcp_lease_default INT DEFAULT NULL,                               -- v3.4.0 #402: seconds (default-lease-time)
  dhcp_lease_max   INT DEFAULT NULL,                                 -- v3.4.0 #402: seconds (max-lease-time)
  dhcp_next_server TEXT DEFAULT NULL,                                -- v3.4.0 #402: TFTP server IP (PXE)
  dhcp_boot_filename TEXT DEFAULT NULL,                              -- v3.4.0 #402: boot filename (PXE)
  custom_fields TEXT NOT NULL DEFAULT ('{}'),                        -- v3.5.0 #313/#595: admin-defined key/value metadata (JSON-in-row)
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
  device_id        BIGINT UNSIGNED NULL,
  interface_id     BIGINT UNSIGNED NULL,
  custom_fields    TEXT NOT NULL DEFAULT ('{}'),                      -- v3.5.0 #313/#595: admin-defined key/value metadata (JSON-in-row)
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
  KEY idx_addresses_device_id    (device_id),
  KEY idx_addresses_interface_id (interface_id),
  CONSTRAINT fk_addresses_subnet    FOREIGN KEY (subnet_id)        REFERENCES subnets(id)           ON DELETE CASCADE,
  CONSTRAINT fk_addresses_contact   FOREIGN KEY (owner_contact_id) REFERENCES contacts(id)          ON DELETE SET NULL,
  CONSTRAINT fk_addresses_device    FOREIGN KEY (device_id)        REFERENCES devices(id)           ON DELETE SET NULL,
  CONSTRAINT fk_addresses_interface FOREIGN KEY (interface_id)     REFERENCES device_interfaces(id) ON DELETE SET NULL
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
  action       VARCHAR(32) NOT NULL DEFAULT 'login',
  attempted_at DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
  KEY idx_login_attempts_ip_time (ip, attempted_at),
  KEY idx_login_attempts_username_time (username, attempted_at),
  KEY idx_login_attempts_action_ip_time (action, ip, attempted_at)
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
-- utilization_snapshots (v3.1.0, sparkline history)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS utilization_snapshots (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  subnet_id   BIGINT UNSIGNED NOT NULL,
  snapped_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  used_count  INT NOT NULL,
  free_count  INT NOT NULL,
  total_hosts INT NOT NULL,
  CONSTRAINT fk_util_snap_subnet FOREIGN KEY (subnet_id) REFERENCES subnets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE INDEX idx_util_snap_subnet_time ON utilization_snapshots(subnet_id, snapped_at);

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
-- devices + device_interfaces (v3.2.0, #394)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS devices (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
  type       VARCHAR(64)  NOT NULL DEFAULT 'other',
  site_id    BIGINT UNSIGNED NULL,
  vendor     VARCHAR(191) NOT NULL DEFAULT '',
  model      VARCHAR(191) NOT NULL DEFAULT '',
  serial     VARCHAR(191) NOT NULL DEFAULT '',
  note       VARCHAR(1000) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
  updated_at DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
  UNIQUE KEY uq_devices_name (name),
  CONSTRAINT fk_devices_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TRIGGER IF NOT EXISTS devices_updated_at
  BEFORE UPDATE ON devices FOR EACH ROW
  SET NEW.updated_at = UTC_TIMESTAMP();

CREATE TABLE IF NOT EXISTS device_interfaces (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  device_id   BIGINT UNSIGNED NOT NULL,
  name        VARCHAR(191) NOT NULL,
  description VARCHAR(1000) NOT NULL DEFAULT '',
  created_at  DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
  updated_at  DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
  UNIQUE KEY uq_device_interfaces_dev_name (device_id, name),
  CONSTRAINT fk_device_interfaces_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TRIGGER IF NOT EXISTS device_interfaces_updated_at
  BEFORE UPDATE ON device_interfaces FOR EACH ROW
  SET NEW.updated_at = UTC_TIMESTAMP();

-- ---------------------------------------------------------------------------
-- password_reset_tokens (v3.2.0, #541)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_reset_tokens (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id    BIGINT UNSIGNED NOT NULL,
  token_hash VARCHAR(64) COLLATE utf8mb4_bin NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at    DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
  KEY idx_prt_user (user_id),
  CONSTRAINT fk_prt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- webhooks + webhook_deliveries (v3.3.0, #337)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS webhooks (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name                  VARCHAR(255) NOT NULL,
  url                   TEXT NOT NULL,
  secret                VARCHAR(255) NOT NULL,
  events                TEXT NOT NULL,
  is_active             TINYINT(1) NOT NULL DEFAULT 1,
  created_at            DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
  last_delivery_at      DATETIME NULL,
  last_delivery_status  SMALLINT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_deliveries (
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
  KEY idx_wh_deliveries_wh (webhook_id, created_at),
  KEY idx_wh_deliveries_pending (delivered_at, attempt),
  CONSTRAINT fk_wd_webhook FOREIGN KEY (webhook_id) REFERENCES webhooks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- schema_migrations (pre-seeded below with every historical version)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schema_migrations (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  version    VARCHAR(64) COLLATE utf8mb4_bin NOT NULL UNIQUE,
  applied_at DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP())
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- totp_backup_codes (v3.6.0, #418)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS totp_backup_codes (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NOT NULL,
  code_hash   TEXT NOT NULL,
  used_at     DATETIME,
  PRIMARY KEY (id),
  KEY idx_totp_backup_codes_user (user_id),
  CONSTRAINT fk_totp_backup_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- rate_limit_buckets (v3.6.0, #419)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rate_limit_buckets (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  bucket_key   VARCHAR(255) NOT NULL,
  window_start DATETIME NOT NULL,
  count        INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY idx_rate_limit_key_window (bucket_key, window_start),
  KEY idx_rate_limit_buckets_window_start (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- webauthn_credentials (v3.15.0 #688: WebAuthn/Passkey credentials)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS webauthn_credentials (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id       BIGINT UNSIGNED NOT NULL,
  credential_id VARBINARY(255) NOT NULL, -- PARAM_LOB required on all writes: see ipam_bind_binary()
  public_key    TEXT NOT NULL,
  sign_count    INT UNSIGNED NOT NULL DEFAULT 0,
  name          VARCHAR(255) NOT NULL DEFAULT 'Passkey',
  created_at    DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
  last_used_at  DATETIME,
  UNIQUE KEY uq_wac_cred_id (credential_id),
  CONSTRAINT fk_wac_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_webauthn_credentials_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- settings (v2.6.0, key/value config registry)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  tenant_id  INT NULL,
  `key`      VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
  value      TEXT NULL,
  type       VARCHAR(16) NOT NULL DEFAULT 'string',
  updated_at DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
  updated_by BIGINT UNSIGNED NULL,
  CONSTRAINT settings_type_check CHECK (type IN ('string','int','bool','json')),
  CONSTRAINT fk_settings_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uq_settings_tenant_key (tenant_id, `key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- custom_field_defs (v3.5.0, #313/#595) — admin-defined per-entity metadata
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS custom_field_defs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  entity_type VARCHAR(20)  NOT NULL,                     -- 'subnet' | 'address'
  `key`       VARCHAR(64)  COLLATE utf8mb4_bin NOT NULL, -- slug, ^[a-z][a-z0-9_]{0,62}$
  label       VARCHAR(191) NOT NULL,
  type        VARCHAR(20)  NOT NULL DEFAULT 'text',      -- text|number|date|boolean|select
  options     TEXT         NULL,                         -- JSON array for type='select'
  sort_order  INT          NOT NULL DEFAULT 0,
  is_required TINYINT      NOT NULL DEFAULT 0,
  is_deleted  TINYINT      NOT NULL DEFAULT 0,           -- reserved for future soft-delete
  created_at  DATETIME     NOT NULL DEFAULT (UTC_TIMESTAMP()),
  updated_at  DATETIME     NOT NULL DEFAULT (UTC_TIMESTAMP()),
  UNIQUE KEY uq_cfd_entity_key (entity_type, `key`),
  KEY idx_cfd_entity_order (entity_type, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TRIGGER IF NOT EXISTS custom_field_defs_updated_at
  BEFORE UPDATE ON custom_field_defs FOR EACH ROW
  SET NEW.updated_at = UTC_TIMESTAMP();

-- ---------------------------------------------------------------------------
-- backup_destinations / backup_schedules / backup_runs (v3.17.0 #690 + v3.21.0 #799).
-- The legacy backup_history (v3.7.0 #423) and backup_log (v3.17.0 #690) tables
-- were collapsed into backup_runs in v3.21.0 (§A1 of backup_overhaul.md). The
-- 3.21.0-backup-runs migration creates backup_runs, copies surviving rows in,
-- and drops both legacy tables on upgrade.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS backup_destinations (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name                    VARCHAR(255) NOT NULL,
  type                    VARCHAR(32)  NOT NULL,
  config                  TEXT         NOT NULL,
  encrypt                 TINYINT(1)   NOT NULL DEFAULT 1,
  is_active               TINYINT(1)   NOT NULL DEFAULT 1,
  -- v3.25.0 #846 #848 #1076 #851: retention rehome + default flag + format/mode defaults.
  retention_hourly        SMALLINT     NOT NULL DEFAULT 0,
  retention_daily         SMALLINT     NOT NULL DEFAULT 7,
  retention_weekly        SMALLINT     NOT NULL DEFAULT 4,
  retention_monthly       SMALLINT     NOT NULL DEFAULT 3,
  is_default              TINYINT(1)   NOT NULL DEFAULT 0,
  default_backup_type     VARCHAR(16)  NOT NULL DEFAULT 'logical',
  default_encryption_mode VARCHAR(16)  NOT NULL DEFAULT 'stored',
  created_at              DATETIME     NOT NULL DEFAULT (UTC_TIMESTAMP()),
  updated_at              DATETIME     NOT NULL DEFAULT (UTC_TIMESTAMP()),
  CONSTRAINT chk_bdest_ret_hourly  CHECK (retention_hourly  >= 0),
  CONSTRAINT chk_bdest_ret_daily   CHECK (retention_daily   >= 0),
  CONSTRAINT chk_bdest_ret_weekly  CHECK (retention_weekly  >= 0),
  CONSTRAINT chk_bdest_ret_monthly CHECK (retention_monthly >= 0),
  CONSTRAINT chk_bdest_is_default  CHECK (is_default IN (0,1)),
  CONSTRAINT chk_bdest_btype       CHECK (default_backup_type IN ('database','logical')),
  CONSTRAINT chk_bdest_emode       CHECK (default_encryption_mode IN ('stored','transitory','unencrypted'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER IF NOT EXISTS backup_destinations_updated_at
  BEFORE UPDATE ON backup_destinations FOR EACH ROW
  SET NEW.updated_at = UTC_TIMESTAMP();

CREATE TABLE IF NOT EXISTS backup_schedules (
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
  -- v3.23.0 #825 (F21): per-schedule notification overrides — see schema.sql.
  notify_override     TINYINT(1)   NOT NULL DEFAULT 0,
  notify_on_failure   TINYINT(1)   NULL,
  notify_on_success   TINYINT(1)   NULL,
  notify_recipients   TEXT         NULL,
  created_at          DATETIME     NOT NULL DEFAULT (UTC_TIMESTAMP()),
  CONSTRAINT fk_bsched_dest FOREIGN KEY (destination_id) REFERENCES backup_destinations(id) ON DELETE CASCADE,
  CONSTRAINT chk_bsched_frequency  CHECK (frequency IN ('hourly','daily','weekly','monthly')),
  CONSTRAINT chk_bsched_dow        CHECK (day_of_week IS NULL OR day_of_week BETWEEN 0 AND 6),
  CONSTRAINT chk_bsched_dom        CHECK (day_of_month IS NULL OR day_of_month BETWEEN 1 AND 28),
  CONSTRAINT chk_bsched_ret_hourly  CHECK (retention_hourly  >= 0),
  CONSTRAINT chk_bsched_ret_daily   CHECK (retention_daily   >= 0),
  CONSTRAINT chk_bsched_ret_weekly  CHECK (retention_weekly  >= 0),
  CONSTRAINT chk_bsched_ret_monthly CHECK (retention_monthly >= 0),
  CONSTRAINT chk_bsched_active     CHECK (is_active IN (0,1)),
  CONSTRAINT chk_bsched_notify_override CHECK (notify_override IN (0,1)),
  CONSTRAINT chk_bsched_notify_failure  CHECK (notify_on_failure IS NULL OR notify_on_failure IN (0,1)),
  CONSTRAINT chk_bsched_notify_success  CHECK (notify_on_success IS NULL OR notify_on_success IN (0,1)),
  UNIQUE KEY uq_backup_schedules_destination (destination_id),
  KEY idx_backup_schedules_next_run (next_run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- v3.21.0 #799 (§A1): unified backup_runs replaces backup_history + backup_log.
-- Single enum `triggered_by` (schedule|manual|cli) drops the ambiguous
-- type+triggered_by combo from backup_log (#808 / F38). Single timestamp pair
-- (started_at + completed_at) closes B-P1-31 / #809.
CREATE TABLE IF NOT EXISTS backup_runs (
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
  -- v3.25.0 #856: cancel-in-flight signal.
  cancel_requested TINYINT(1)  NOT NULL DEFAULT 0,
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
  CONSTRAINT chk_brun_cancel          CHECK (cancel_requested IN (0,1)),
  KEY idx_backup_runs_destination (destination_id),
  KEY idx_backup_runs_schedule    (schedule_id),
  KEY idx_backup_runs_started     (started_at),
  KEY idx_backup_runs_protected   (is_protected)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  ('2.12.0-account-lockout'),
  ('3.0.0-config-stub'),
  ('3.0.0-config-stub-rewrite'),
  ('3.0.0-site-contacts'),
  ('3.0.0-subnet-contacts'),
  ('3.1.0-user-timezone'),
  ('3.1.0-subnet-alerts-enabled'),
  ('3.1.0-utilization-snapshots'),
  ('3.2.0-devices'),
  ('3.2.0-password-reset'),
  ('3.3.0-webhooks'),
  ('3.4.0-dhcp-options'),
  ('3.5.0-custom-fields'),
  ('3.6.0-totp'),
  ('3.6.0-rate-limit'),
  ('3.6.0-lockout'),
  ('3.7.0-backup-history'),
  ('3.13.0-settings-cascade'),
  ('3.14.0-mfa-settings'),
  ('3.14.0-email-otp'),
  ('3.15.0-passkeys'),
  ('3.16.0-preferred-mfa-method'),
  ('3.17.0-backup'),
  ('3.21.0-backup-runs'),
  ('3.21.0-schedule-unique'),
  ('3.23.0-notify-overrides'),
  ('3.25.0-backup-destination-evolution');

SET FOREIGN_KEY_CHECKS = 1;
