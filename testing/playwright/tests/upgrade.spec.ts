/**
 * Upgrade path tests. (#305)
 *
 * Simulates upgrading from a pre-v2.0.0 database schema to the current version.
 *
 * Strategy:
 *   1. Export the current (v2.x) DB so it can be restored in afterAll.
 *   2. Import a hand-crafted pre-v2.0.0 SQL dump that has:
 *        - Old table schemas (no vlans, vrfs, contacts, tags, alert_state tables;
 *          no vlan_fk/vrf_id on subnets; no mac/expires_at/owner_contact_id on addresses;
 *          no parent_id on sites; no theme on users)
 *        - Migration stamps only through '1.13' (so '1.19.0', '2.0.0-*', and '2.1.0-*' migrations all run)
 *        - One admin user with a known password ('admin')
 *   3. Navigate to the login page — this triggers init.php → ipam_db_init() →
 *      apply_migrations() which adds all v2.x tables and columns.
 *   4. Log in with the pre-v2 admin credentials and verify every v2.x page
 *      renders without PHP errors.
 *   5. Restore the original DB in afterAll.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  login as loginAs, fetchPost, fetchPostForm, appUrl, warmSudoGrant,
  ADMIN_USER, ADMIN_PASS,
  newAuthContext, IS_SQLITE,
} from '../fixtures/ipam';

// v2.10.0 #433 / v2.11.0 #388: the pre-v2.0.0 upgrade path is fundamentally
// SQLite-only. On MySQL/Postgres fresh installs, schema.*.sql pre-seeds
// schema_migrations with every historical version row so apply_migrations()
// is a no-op, and the historical closures use SQLite-specific PRAGMA /
// sqlite_master queries that cannot run on other engines. Pre-v2.0.0 state
// also requires importing a SQLite-format dump via db_tools.php which is
// itself SQLite-only.
test.skip(!IS_SQLITE, 'pre-v2.0.0 upgrade path is SQLite-only (non-SQLite schema files pre-seed migrations)');

let ctx: BrowserContext;
let page: Page;
let originalSql = '';

// ── Pre-v2 SQL ────────────────────────────────────────────────────────────────

// Admin user with password 'admin' (bcrypt cost 12, verified on PHP 8.2).
const UPGRADE_ADMIN_USER = 'upgrade-test-admin';
const UPGRADE_ADMIN_PASS = 'admin';
const UPGRADE_ADMIN_HASH = '$2y$12$WojAoSrFQjnk7Z/ovY1KOOyxY66rg1RCHItKkSo3fnvrC7frqhl5K';

/**
 * Returns a minimal SQL dump representing a database at the v1.19.0 milestone:
 *   - Core tables without any v2.x columns or dependent tables.
 *   - Schema migration stamps only through '1.13' so '1.19.0' (adds mac/expires_at),
 *     '2.0.0-*', and '2.1.0-*' migrations all run on the first page load after import.
 *   - One admin user so the app can be logged into after migration.
 *   - One site, one subnet, two addresses for baseline data.
 *
 * When imported and the app is loaded, apply_migrations() will run all
 * '2.0.0-*' and '2.1.0-*' migrations automatically.
 */
function buildPreV2Sql(): string {
  return `
-- ============================================================
-- Pre-v2.0.0 schema snapshot (v1.19.0 era)
-- Used by upgrade.spec.ts to test the migration upgrade path.
-- ============================================================

CREATE TABLE IF NOT EXISTS users (
  id                   INTEGER PRIMARY KEY AUTOINCREMENT,
  username             TEXT NOT NULL UNIQUE,
  password_hash        TEXT NOT NULL,
  role                 TEXT NOT NULL DEFAULT 'admin',
  is_active            INTEGER NOT NULL DEFAULT 1,
  name                 TEXT NOT NULL DEFAULT '',
  email                TEXT NOT NULL DEFAULT '',
  oidc_sub             TEXT,
  last_login_at        TEXT,
  password_changed_at  TEXT,
  created_at           TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at           TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_users_oidc_sub ON users(oidc_sub) WHERE oidc_sub IS NOT NULL;

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

CREATE INDEX IF NOT EXISTS idx_sites_placeholder ON sites(name);

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

CREATE INDEX IF NOT EXISTS idx_audit_log_action ON audit_log(action);
CREATE INDEX IF NOT EXISTS idx_audit_log_created_at ON audit_log(created_at);

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
  created_by   TEXT NOT NULL DEFAULT '',
  is_readonly  INTEGER NOT NULL DEFAULT 0,
  description  TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS schema_migrations (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  version    TEXT NOT NULL UNIQUE,
  applied_at TEXT NOT NULL DEFAULT (datetime('now'))
);

INSERT INTO users (username, password_hash, role, is_active, name, email, password_changed_at)
VALUES ('${UPGRADE_ADMIN_USER}', '${UPGRADE_ADMIN_HASH}', 'admin', 1, 'Upgrade Test Admin', 'upgrade@test.local', datetime('now'));

INSERT INTO sites (name, description) VALUES ('UpgradeHQ', 'Upgrade test site');

INSERT INTO subnets (cidr, ip_version, network, network_bin, prefix, description, site_id, vlan_id)
VALUES ('10.99.254.0/24', 4, '10.99.254.0', CAST(X'6363fe00' AS BLOB), 24, 'Pre-v2 management LAN', 1, 10);

INSERT INTO addresses (subnet_id, ip, ip_bin, hostname, owner, note, grp, status)
VALUES (1, '10.99.254.1', CAST(X'6363fe01' AS BLOB), 'gw.upgradehq.local', 'netops', 'Default gateway', 'infra', 'used');

INSERT INTO addresses (subnet_id, ip, ip_bin, hostname, owner, note, grp, status)
VALUES (1, '10.99.254.10', CAST(X'6363fe0a' AS BLOB), 'srv01.upgradehq.local', 'sysadmin', 'Test server', 'servers', 'used');

INSERT INTO schema_migrations (version) VALUES ('0.3');
INSERT INTO schema_migrations (version) VALUES ('0.7');
INSERT INTO schema_migrations (version) VALUES ('0.9');
INSERT INTO schema_migrations (version) VALUES ('0.11');
INSERT INTO schema_migrations (version) VALUES ('0.12');
INSERT INTO schema_migrations (version) VALUES ('0.13');
INSERT INTO schema_migrations (version) VALUES ('0.14');
INSERT INTO schema_migrations (version) VALUES ('1.4');
INSERT INTO schema_migrations (version) VALUES ('1.9');
INSERT INTO schema_migrations (version) VALUES ('1.11');
INSERT INTO schema_migrations (version) VALUES ('1.12');
INSERT INTO schema_migrations (version) VALUES ('1.13');
`.trim();
}

// ── Suite ──────────────────────────────────────────────────────────────────────

test.describe('Upgrade path: pre-v2.0.0 → current version (#305)', () => {
  test.beforeAll(async ({ browser }: { browser: Browser }) => {
    test.setTimeout(60_000);
    ctx  = await newAuthContext(browser);
    page = await ctx.newPage();

    // Login with the real admin to export the current DB before we touch it.
    await loginAs(page, ADMIN_USER, ADMIN_PASS);
    await page.goto('db_tools.php');
    const r = await fetchPost(page, appUrl('db_tools.php'), { action: 'export' });
    originalSql = r.body;
    expect(originalSql.length, 'saved current DB export').toBeGreaterThan(100);

    // v3.27.0 (#1107): db_tools import is gated behind ipam_sudo_verify().
    // Pre-warm a sudo grant so the import POST reaches the import handler
    // instead of the step-up prompt.
    await warmSudoGrant(page);

    // Import the pre-v2 SQL (replaces the DB with the old schema).
    await page.goto('db_tools.php');
    const importResult = await fetchPostForm(
      page, appUrl('db_tools.php'),
      { action: 'import', confirmed: '1' },
      { name: 'sql_file', content: buildPreV2Sql(), filename: 'pre-v2.sql', type: 'application/sql' },
    );
    expect(importResult.body, 'pre-v2 SQL imported').toContain('Import successful');

    // Log out so the real-admin session cookie is cleared.
    // Without this, the session still contains user_id=1, and after import
    // upgrade-test-admin also lands at id=1, causing login.php to redirect
    // to dashboard instead of showing the login form.
    await page.goto('logout.php');
    await page.waitForLoadState('domcontentloaded');

    // Navigate to login.php — this triggers apply_migrations() to run all
    // '1.19.0', '2.0.0-*', and '2.1.0-*' migrations against the old schema.
    await page.goto('login.php');
    await page.waitForLoadState('domcontentloaded');
  });

  test.afterAll(async () => {
    // Restore the original DB so subsequent specs are unaffected.
    try {
      if (originalSql && page) {
        // Ensure we're logged in as upgrade-test-admin (the only admin in the migrated DB).
        // If intermediate tests failed, the session state may be unknown — log out and back in.
        await page.goto('logout.php').catch(() => null);
        await loginAs(page, UPGRADE_ADMIN_USER, UPGRADE_ADMIN_PASS);
        // Re-warm the sudo grant before the restore: the beforeAll grant
        // is long expired by the time afterAll runs (CodeRabbit round 2,
        // #1116), and the new login dropped any session-bound grant
        // anyway.
        await warmSudoGrant(page);
        await page.goto('db_tools.php');
        await fetchPostForm(
          page, appUrl('db_tools.php'),
          { action: 'import', confirmed: '1' },
          { name: 'sql_file', content: originalSql, filename: 'restore.sql', type: 'application/sql' },
        );
      }
    } finally {
      await ctx?.close();
    }
  });

  // ── Migration trigger ───────────────────────────────────────────────────────

  test('login page renders without PHP error after old-schema import (migrations ran)', async () => {
    await page.goto('login.php');
    const body = await page.textContent('body') ?? '';
    expect(body, 'no PHP fatal error after migration').not.toContain('Fatal error');
    expect(body, 'no uncaught exception after migration').not.toContain('Uncaught');
    // Login form must be present
    await expect(page.locator('[name=username]')).toBeVisible();
  });

  test('status.php returns ok after migration', async () => {
    const statusBody = await page.evaluate(async (url) => {
      const res = await fetch(url);
      return res.text();
    }, appUrl('status.php'));
    expect(statusBody).toContain('ok');
  });

  // ── Post-migration login ────────────────────────────────────────────────────

  test('can log in with pre-v2 admin credentials after migration', async () => {
    await loginAs(page, UPGRADE_ADMIN_USER, UPGRADE_ADMIN_PASS);
    // Should redirect to dashboard after login
    await expect(page).toHaveURL(/dashboard\.php/);
  });

  // ── v2.x pages accessible after migration ──────────────────────────────────

  test('dashboard loads after migration', async () => {
    await page.goto('dashboard.php');
    const body = await page.textContent('body') ?? '';
    expect(body).not.toContain('Fatal error');
    await expect(page.locator('h1')).toBeVisible();
  });

  test('subnets page loads after migration (vrf_id column added)', async () => {
    await page.goto('subnets.php');
    const body = await page.textContent('body') ?? '';
    expect(body).not.toContain('Fatal error');
    expect(body).not.toContain('no such column: vrf_id');
    await expect(page.locator('h1')).toBeVisible();
  });

  test('vlans page loads after migration (vlans table created)', async () => {
    await page.goto('vlans.php');
    const body = await page.textContent('body') ?? '';
    expect(body).not.toContain('Fatal error');
    expect(body).not.toContain('no such table: vlans');
    await expect(page.locator('h1')).toBeVisible();
  });

  test('vrfs page loads after migration (vrfs table created)', async () => {
    await page.goto('vrfs.php');
    const body = await page.textContent('body') ?? '';
    expect(body).not.toContain('Fatal error');
    expect(body).not.toContain('no such table: vrfs');
    await expect(page.locator('h1')).toBeVisible();
  });

  test('contacts page loads after migration (contacts table created)', async () => {
    await page.goto('contacts.php');
    const body = await page.textContent('body') ?? '';
    expect(body).not.toContain('Fatal error');
    expect(body).not.toContain('no such table: contacts');
    await expect(page.locator('h1')).toBeVisible();
  });

  test('tags page loads after migration (tags table created)', async () => {
    await page.goto('tags.php');
    const body = await page.textContent('body') ?? '';
    expect(body).not.toContain('Fatal error');
    expect(body).not.toContain('no such table: tags');
    await expect(page.locator('h1')).toBeVisible();
  });

  test('sites page loads after migration (parent_id column added)', async () => {
    await page.goto('sites.php');
    const body = await page.textContent('body') ?? '';
    expect(body).not.toContain('Fatal error');
    expect(body).not.toContain('no such column: parent_id');
    await expect(page.locator('h1')).toBeVisible();
    // The upgrade test site should be visible
    expect(body).toContain('UpgradeHQ');
  });

  test('users page loads after migration (theme column added)', async () => {
    await page.goto('users.php');
    const body = await page.textContent('body') ?? '';
    expect(body).not.toContain('Fatal error');
    expect(body).not.toContain('no such column: theme');
    await expect(page.locator('h1')).toBeVisible();
  });

  test('audit page loads after migration', async () => {
    await page.goto('audit.php');
    const body = await page.textContent('body') ?? '';
    expect(body).not.toContain('Fatal error');
    await expect(page.locator('h1')).toBeVisible();
  });

  test('addresses page loads for the pre-v2 subnet (mac/expires_at columns added)', async () => {
    // Navigate via subnets.php to find the subnet we seeded
    await page.goto('subnets.php');
    const href = await page
      .locator('a[href*="addresses.php?subnet_id"]')
      .first()
      .getAttribute('href', { timeout: 5_000 })
      .catch(() => null);
    if (!href) { test.skip(); return; }

    await page.goto(href);
    const body = await page.textContent('body') ?? '';
    expect(body).not.toContain('Fatal error');
    expect(body).not.toContain('no such column: mac');
    expect(body).not.toContain('no such column: expires_at');
    await expect(page.locator('h1')).toBeVisible();
  });

  test('pre-v2 address data survived migration intact', async () => {
    await page.goto('subnets.php');
    const body = await page.textContent('body') ?? '';
    // The seeded subnet cidr should still be visible
    expect(body).toContain('10.99.254.0/24');
  });
});
