/**
 * Shared fixtures, helpers, and constants for the Simple PHP IPAM Playwright suite.
 */
import { test as base, expect, type Page, type Browser, type BrowserContext } from '@playwright/test';
import { APP_BASE } from '../playwright.config';

// ── App credentials ────────────────────────────────────────────────────────────
export const ADMIN_USER = process.env.IPAM_ADMIN_USER || 'admin';
export const ADMIN_PASS = process.env.IPAM_ADMIN_PASS || 'admin';

// ── Active database driver (v2.10.0 #433) ─────────────────────────────────────
// Set by the Playwright CI workflow when a non-SQLite matrix slot runs.
// Unset or 'sqlite' means the SQLite driver is active. Tests that exercise
// SQLite-specific behaviour (ipam_db_dump_stream SQL format, pre-v2.0.0
// upgrade path, etc.) use IS_SQLITE to skip on MySQL and Postgres.
// IS_MYSQL is retained for MySQL-specific assertions (e.g. the SQL-only
// notice on db_tools.php).
export const IPAM_DRIVER = (process.env.IPAM_DRIVER || 'sqlite').toLowerCase();
export const IS_SQLITE   = IPAM_DRIVER === 'sqlite';
export const IS_MYSQL    = IPAM_DRIVER === 'mysql';

// HTTP Basic Auth protecting the /claude/ gateway.
const basicUser = process.env.IPAM_BASIC_USER || '';
const basicPass = process.env.IPAM_BASIC_PASS || '';

// HTTP Basic Auth header for fetch() calls inside page.evaluate() (gateway auth).
export const BASIC_AUTH_HEADER = basicUser
  ? `Basic ${Buffer.from(`${basicUser}:${basicPass}`).toString('base64')}`
  : '';

/** HTTP credentials object for newContext() calls (mirrors playwright.config.ts). */
export const HTTP_CREDENTIALS = basicUser
  ? { username: basicUser, password: basicPass, send: 'always' as const }
  : undefined;

/**
 * Create a new browser context with the correct settings for the dev server:
 * ignores self-signed TLS errors and sends HTTP Basic Auth on every request.
 * Use this in beforeAll hooks instead of bare browser.newContext().
 */
export async function newAuthContext(browser: Browser): Promise<BrowserContext> {
  return browser.newContext({
    ignoreHTTPSErrors: true,
    httpCredentials: HTTP_CREDENTIALS,
    baseURL: APP_BASE + '/',
  });
}

// ── Test data constants ────────────────────────────────────────────────────────
export const TEST_VLAN_ID   = 99;
export const TEST_VLAN_NAME = 'pw-test-vlan';
export const TEST_VLAN_DESC = 'Playwright test VLAN';
export const TEST_VLAN_CIDR = '10.77.99.0/24';

export const TEST_TAG_NAME   = 'pw-test-tag';
export const TEST_TAG_COLOUR = '#ff0000';

export const TEST_CONTACT_NAME  = 'pw-test-contact';
export const TEST_CONTACT_EMAIL = 'pw-test@example.com';
export const TEST_CONTACT_ORG   = 'PW Test Org';

export const TEST_VRF_NAME = 'pw-test-vrf';
export const TEST_VRF_DESC = 'Playwright test VRF';
export const TEST_VRF_RD   = '65000:999';
export const TEST_VRF_CIDR = '10.66.0.0/24';

export const TEST_DHCP_CIDR = '10.55.0.0/24';

export const TEST_SCAN_CIDR = '10.44.0.0/28'; // /28 = 16 IPs; satisfies scan_run API limit

export const TEST_CIDR1     = '10.99.0.0/24';
export const TEST_CIDR2     = '10.88.0.0/24';
export const TEST_CIDR_V6   = '2001:db8:1::/120';
export const TEST_IP        = '10.88.0.10';
export const TEST_HOST      = 'pw-test-host';
export const TEST_MAC       = 'AA:BB:CC:DD:EE:FF';
export const TEST_EXPIRES   = '2099-12-31';
export const EXPIRED_IP     = '10.88.0.11';
export const EXPIRED_DATE   = '2020-01-01';
export const RO_USER        = 'pw-readonly';
export const RO_PASS        = 'TestPass!pw99';

// ── URL helper ─────────────────────────────────────────────────────────────────
/** Returns the absolute URL for an app page (e.g. appUrl('login.php')). */
export function appUrl(path: string): string {
  return `${APP_BASE}/${path.replace(/^\//, '')}`;
}

// ── Auth helpers ───────────────────────────────────────────────────────────────
export async function login(page: Page, username: string, password: string): Promise<void> {
  await page.goto('login.php');
  await page.waitForSelector('[name=username]', { timeout: 30_000 });
  await page.locator('[name=username]').fill(username);
  await page.locator('[name=password]').fill(password);
  await page.locator('button[type=submit]').click();
  // Wait for navigation away from login.php (successful login redirects to dashboard)
  await page.waitForURL(url => !url.pathname.endsWith('login.php'), { timeout: 30_000 });
}

export async function logout(page: Page): Promise<void> {
  await page.goto('logout.php');
  await page.waitForURL(url => url.pathname.endsWith('login.php'), { timeout: 10_000 });
}

// ── Form helpers ───────────────────────────────────────────────────────────────
/**
 * Read the CSRF token from the current page. Returns '' if no token element is found.
 */
export async function getCsrf(page: Page): Promise<string> {
  return page.locator('[name=csrf]').first().inputValue().catch(() => '');
}

/**
 * POST form data to a URL using the browser's fetch (session cookies included).
 * Adds the current page's CSRF token and the gateway Basic Auth header automatically.
 */
export async function fetchPost(
  page: Page,
  url: string,
  data: Record<string, string>,
): Promise<{ ok: boolean; status: number; body: string }> {
  const csrf = await getCsrf(page);
  const basicAuth = BASIC_AUTH_HEADER;
  return page.evaluate(
    async ({ url, data, csrf, basicAuth }) => {
      const params = new URLSearchParams({ ...data, csrf });
      const headers: Record<string, string> = {
        'Content-Type': 'application/x-www-form-urlencoded',
      };
      if (basicAuth) headers['Authorization'] = basicAuth;
      const r = await fetch(url, {
        method: 'POST',
        headers,
        body: params.toString(),
        credentials: 'same-origin',
      });
      return { ok: r.ok, status: r.status, body: await r.text() };
    },
    { url, data, csrf, basicAuth },
  );
}

/**
 * POST multipart/form-data via the browser's fetch.
 */
export async function fetchPostForm(
  page: Page,
  url: string,
  fields: Record<string, string>,
  fileField?: { name: string; content: string; filename: string; type: string },
): Promise<{ ok: boolean; status: number; body: string }> {
  const csrf = await getCsrf(page);
  const basicAuth = BASIC_AUTH_HEADER;
  return page.evaluate(
    async ({ url, fields, fileField, csrf, basicAuth }) => {
      const fd = new FormData();
      for (const [k, v] of Object.entries(fields)) fd.append(k, v);
      fd.append('csrf', csrf);
      if (fileField) {
        const blob = new Blob([fileField.content], { type: fileField.type });
        fd.append(fileField.name, blob, fileField.filename);
      }
      const headers: Record<string, string> = {};
      if (basicAuth) headers['Authorization'] = basicAuth;
      const r = await fetch(url, {
        method: 'POST',
        headers,
        body: fd,
        credentials: 'same-origin',
      });
      return { ok: r.ok, status: r.status, body: await r.text() };
    },
    { url, fields, fileField, csrf, basicAuth },
  );
}

/**
 * Perform a GET fetch from within the browser context (session + Basic Auth included).
 */
export async function fetchGet(
  page: Page,
  url: string,
): Promise<{ status: number; contentType: string; body: string }> {
  const basicAuth = BASIC_AUTH_HEADER;
  return page.evaluate(
    async ({ url, basicAuth }) => {
      const headers: Record<string, string> = {};
      if (basicAuth) headers['Authorization'] = basicAuth;
      const r = await fetch(url, { headers, credentials: 'same-origin' });
      return {
        status: r.status,
        contentType: r.headers.get('content-type') ?? '',
        body: await r.text(),
      };
    },
    { url, basicAuth },
  );
}

/**
 * Ensure the pw-readonly test user exists. Creates it via users.php if not present.
 * Must be called from an admin-authenticated page context.
 * The db-tools import test wipes the DB, so this must be called before any readonly login.
 */
export async function ensureRoUser(page: Page): Promise<void> {
  await page.goto('users.php');
  const exists = await page.evaluate((u) => {
    for (const td of document.querySelectorAll<HTMLElement>('table td')) {
      if (td.textContent?.trim() === u) return true;
    }
    return false;
  }, RO_USER);
  if (!exists) {
    const res = await fetchPost(page, appUrl('users.php'), {
      action: 'create',
      username: RO_USER,
      password: RO_PASS,
      role: 'readonly',
    });
    if (!res.ok) throw new Error(`ensureRoUser: create failed (HTTP ${res.status}): ${res.body}`);
  }
}

// ── Subnet helpers ─────────────────────────────────────────────────────────────
/**
 * Returns the subnet_id for a subnet with the given CIDR from subnets.php.
 * Must be called while subnets.php is the active page.
 */
export async function subnetIdFor(page: Page, cidr: string): Promise<number | null> {
  return page.evaluate((cidr) => {
    for (const node of document.querySelectorAll<HTMLElement>('.subnet-node')) {
      if (node.innerText.includes(cidr)) {
        const a = node.querySelector<HTMLAnchorElement>('a[href*="subnet_id"]');
        if (a) {
          const m = a.href.match(/subnet_id=([0-9]+)/);
          if (m) return parseInt(m[1], 10);
        }
      }
    }
    return null;
  }, cidr);
}

/**
 * Delete every subnet matching the given CIDR via POST to subnets.php.
 * Must be called while subnets.php is the active page.
 *
 * Loops until subnetIdFor returns null. The schema's UNIQUE(cidr, vrf_id)
 * treats NULL vrf_id as distinct (SQL standard) on every supported engine
 * (SQLite, MySQL, Postgres), so a CIDR with no VRF can legitimately appear
 * more than once if a prior spec's afterAll left a row behind, or if a
 * later spec re-created the CIDR with confirm_overlap=1. A single-shot
 * delete leaves orphans, and the *next* spec's subnetIdFor() can then
 * return the orphan ID — its address creates land in the orphan, while
 * the test then queries the freshly-created subnet (or vice versa),
 * causing assertions like unassigned.spec.ts:76 (#760) to flake by
 * showing the assigned IP as still unassigned. Re-iterating + reloading
 * the subnets list page guarantees the CIDR slot is empty before the
 * caller proceeds, so the next subnet creation is the only row.
 */
export async function deleteSubnet(page: Page, cidr: string): Promise<void> {
  // Bound the loop to defend against pathological cases (broken delete handler,
  // mass-orphaned rows). 10 is far above the realistic worst case (1–2 stale).
  for (let i = 0; i < 10; i++) {
    const subId = await subnetIdFor(page, cidr);
    if (!subId) return;
    await fetchPost(page, appUrl('subnets.php'), { action: 'delete', id: String(subId) });
    // Reload so subnetIdFor() sees the post-delete state on the next iteration.
    await page.goto('subnets.php');
  }
}

// ── VLAN helpers ───────────────────────────────────────────────────────────────
/**
 * Create a VLAN via form POST to vlans.php.
 * Requires an authenticated page already on (or able to navigate to) vlans.php.
 */
export async function createVlan(
  page: Page,
  vlanId: number,
  name: string,
  description = '',
): Promise<void> {
  await page.goto('vlans.php');
  await page.locator('input[name=vlan_id]').fill(String(vlanId));
  await page.locator('input[name=name]').fill(name);
  if (description) await page.locator('input[name=description]').fill(description);
  await page.locator('button[type=submit]').first().click();
  await page.waitForURL(/vlans\.php/);
}

/**
 * Delete a VLAN by name via the delete button on vlans.php.
 * No-ops silently if the VLAN does not exist.
 */
export async function deleteVlan(page: Page, name: string): Promise<void> {
  await page.goto('vlans.php');
  const rows = await page.locator('table tbody tr').all();
  for (const row of rows) {
    const text = await row.innerText();
    if (!text.includes(name)) continue;
    const details = row.locator('details');
    await details.click();
    page.once('dialog', d => d.accept());
    await details.locator('button.button-danger').click();
    await page.waitForURL(/vlans\.php/);
    break;
  }
}

// ── Tag helpers ────────────────────────────────────────────────────────────────
/**
 * Create a tag via form POST to tags.php.
 */
export async function createTag(
  page: Page,
  name: string,
  colour = '#6c757d',
): Promise<void> {
  await page.goto('tags.php');
  await page.locator('input[name=name]').fill(name);
  await page.locator('input[name=colour]').evaluate(
    (el: HTMLInputElement, c: string) => { el.value = c; },
    colour,
  );
  await page.locator('button[type=submit]').first().click();
  await page.waitForURL(/tags\.php/);
}

/**
 * Delete a tag by name via the delete button on tags.php.
 * No-ops silently if the tag does not exist.
 */
export async function deleteTag(page: Page, name: string): Promise<void> {
  await page.goto('tags.php');
  const rows = await page.locator('table tbody tr').all();
  for (const row of rows) {
    const text = await row.innerText();
    if (!text.includes(name)) continue;
    const details = row.locator('details');
    await details.click();
    page.once('dialog', d => d.accept());
    await details.locator('button.button-danger').click();
    await page.waitForURL(/tags\.php/);
    break;
  }
}

/**
 * Reset a user's password directly in the database by executing
 * reset_test_password.php inside the test container via docker exec.
 * Bypasses change_password.php policy enforcement (needed when the original
 * password is shorter than the enforced min_length, e.g. 'demo' = 4 chars).
 */
export async function resetTestPassword(username: string, password: string): Promise<void> {
    const container = process.env.DOCKER_CONTAINER ?? 'ipam-pw-test';
    const { execFileSync } = await import('child_process');
    execFileSync('docker', [
        'exec', container,
        'php', '/var/www/html/testing/scripts/reset_test_password.php',
        username, password,
    ], { stdio: 'pipe' });
}

/**
 * Inject a known 6-digit OTP for the given username by executing
 * inject_test_otp.php inside the test container via docker exec.
 * Returns the 6-digit code string used.
 */
export async function injectTestOtp(username: string, code = '123456'): Promise<string> {
    const container = process.env.DOCKER_CONTAINER ?? 'ipam-pw-test';
    const { execFileSync } = await import('child_process');
    execFileSync('docker', [
        'exec', container,
        'php', '/var/www/html/testing/scripts/inject_test_otp.php',
        username, code,
    ], { stdio: 'pipe' });
    return code;
}

export async function resetEmailOtpEnrollment(username: string): Promise<void> {
    const container = process.env.DOCKER_CONTAINER ?? 'ipam-pw-test';
    const { execFileSync } = await import('child_process');
    execFileSync('docker', [
        'exec', container,
        'php', '/var/www/html/testing/scripts/reset_email_otp_enrollment.php',
        username,
    ], { stdio: 'pipe' });
}

/**
 * Re-seed a user's TOTP enrolment back to the canonical test state:
 * totp_enabled=1, totp_secret_enc set to the RFC 6238 test vector,
 * and the eight known-plaintext backup codes refreshed. Used by
 * totp.spec.ts beforeAll so the spec is robust to any upstream test
 * that may have flipped totp_enabled during a full-suite run.
 */
export async function reset2faEnrollment(username: string): Promise<void> {
    const container = process.env.DOCKER_CONTAINER ?? 'ipam-pw-test';
    const { execFileSync } = await import('child_process');
    execFileSync('docker', [
        'exec', container,
        'php', '/var/www/html/testing/scripts/reset_2fa_enrollment.php',
        username,
    ], { stdio: 'pipe' });
}

export async function ensureEmailOtpEnrolled(username: string): Promise<void> {
    const container = process.env.DOCKER_CONTAINER ?? 'ipam-pw-test';
    const { execFileSync } = await import('child_process');
    execFileSync('docker', [
        'exec', container,
        'php', '/var/www/html/testing/scripts/ensure_email_otp_enrolled.php',
        username,
    ], { stdio: 'pipe' });
}

/**
 * Pass a step-up authentication prompt if it's currently rendered on the page.
 *
 * v3.27.0 (#1107) gated several admin actions behind ipam_sudo_verify(): vault
 * key set/reveal/replace, settings_reveal, db_tools import, api_keys create,
 * disable_totp / disable_email_otp / passkey_delete in change_password.php.
 * Existing specs that exercise those handlers now land on the shared step-up
 * prompt (`views/_step_up_prompt.php`) before the original action runs.
 *
 * Call this immediately after submitting the form for a gated action. If the
 * prompt isn't there (no gate, or grant already warm), it's a no-op. If the
 * prompt is there, it submits the password proof using the supplied (or
 * default ADMIN_PASS) credentials and waits for the next page.
 *
 * Returns true if a prompt was passed, false if no prompt was visible.
 */
export async function passStepUpIfPresent(
    page: Page,
    password: string = ADMIN_PASS,
): Promise<boolean> {
    const prompt = page.locator('[data-step-up-prompt]');
    if (await prompt.count() === 0) return false;
    if (!(await prompt.first().isVisible().catch(() => false))) return false;

    const methodSel = page.locator('select[name="_sudo_method"]');
    if (await methodSel.count()) {
        await methodSel.selectOption('password').catch(() => undefined);
    }
    await page.locator('input[name="_sudo_password"]').fill(password);
    // The shared prompt partial renders ALL available method sections on the
    // same page and toggles visibility via `hidden`, so a bare
    // `#step-up-form button[type=submit]` selector matches multiple buttons
    // (TOTP / Email-OTP send / Email-OTP verify / Password) and trips
    // Playwright's strict-mode violation on multi-method users. Scope the
    // click to the password section we just populated.
    await Promise.all([
        page.waitForLoadState('domcontentloaded'),
        page.locator('[data-step-up-section="password"] button[type=submit]').click(),
    ]);
    // Verify the prompt actually cleared. If the proof was rejected the
    // partial re-renders on the same URL with a 'danger' error banner; we
    // surface that to the caller as `false` rather than silently returning
    // `true` while the action upstream wasn't actually authorised. (CodeRabbit
    // round 2, #1116.)
    const stillPrompting = await page.locator('[data-step-up-prompt]').count();
    if (stillPrompting > 0) {
        return false;
    }
    return true;
}

/**
 * Pre-warm a sudo grant on the current session by completing one step-up
 * round-trip. Useful in `beforeEach` for specs that hit multiple gated
 * handlers in sequence — under default policy (TTL=300s) the warm grant
 * satisfies all subsequent sensitive actions in the same test.
 *
 * Implementation note: we use the api_keys create gate as the warm-up driver
 * because it leaves no destructive side effect (we deactivate+delete the key
 * we created). The grant lives on the session, not on the action.
 */
export async function warmSudoGrant(page: Page, password: string = ADMIN_PASS): Promise<void> {
    const keyName = `pw-warm-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
    await page.goto(appUrl('api_keys.php'));
    await page.locator('input[name="name"]').fill(keyName);
    await page.locator('button[name="action"][value="create"], button:has-text("Generate key")')
        .first()
        .click();
    const passed = await passStepUpIfPresent(page, password);
    // CR round-3 #1116: callers (vault_set, db_tools import, restore paths)
    // assume sudo is warm after this returns. If the proof was rejected
    // (rate-limit, wrong password, missing method) passStepUpIfPresent
    // returns false. Fail loudly here so the caller doesn't sail past a
    // silent rejection and emit cryptic downstream timeouts.
    const stillPrompting = await page.locator('[data-step-up-prompt]').count();
    if (!passed || stillPrompting > 0) {
        throw new Error(
            `warmSudoGrant: step-up prompt was not cleared (passed=${passed}, ` +
            `prompt_remaining=${stillPrompting}). Likely a rejected proof or ` +
            `sudo rate-limit. Check session state, mfa.* settings, and the ` +
            `auth.sudo_failed audit row.`,
        );
    }
    // Best-effort cleanup of the warm-up key. Failures here don't break the test.
    await page.goto(appUrl('api_keys.php')).catch(() => undefined);
    const row = page.locator('tr', { hasText: keyName });
    const deactivate = row.locator('button[name="action"][value="deactivate"]');
    if (await deactivate.count().catch(() => 0)) {
        await deactivate.first().click().catch(() => undefined);
        await page.waitForLoadState('networkidle').catch(() => undefined);
    }
    const deleteBtn = page.locator('tr', { hasText: keyName })
        .locator('button[name="action"][value="delete"]');
    if (await deleteBtn.count().catch(() => 0)) {
        page.once('dialog', (d) => d.accept());
        await deleteBtn.first().click().catch(() => undefined);
        await page.waitForLoadState('networkidle').catch(() => undefined);
    }
}

/**
 * Seed (or refresh) an OIDC-only admin (oidc_sub set, password_hash='!disabled')
 * for v3.27.0 step-up regression tests. Returns the user's numeric id.
 *
 * mode = 'with-totp' enrols the user in TOTP using the JBSWY3DPEHPK3PXP test
 * vector and flips mfa.totp_enabled on globally; 'no-mfa' explicitly clears
 * any MFA enrollment so the user has no method available under default policy
 * minus provider re-auth.
 */
export async function seedOidcOnlyAdmin(
    username: string,
    mode: 'with-totp' | 'no-mfa' | 'deactivate',
): Promise<number> {
    const container = process.env.DOCKER_CONTAINER ?? 'ipam-pw-test';
    const { execFileSync } = await import('child_process');
    const flag = mode === 'with-totp' ? '--with-totp'
               : mode === 'deactivate' ? '--deactivate'
               : '--no-mfa';
    const out = execFileSync('docker', [
        'exec', '--user', 'www-data', container,
        'php', '/var/www/html/testing/scripts/seed_oidc_only_admin.php',
        username, flag,
    ], { encoding: 'utf-8' });
    // --deactivate prints "deactivated\n"; the seed paths print the uid.
    if (mode === 'deactivate') return 0;
    return parseInt(out.trim(), 10);
}

/**
 * Mint a logged-in PHP session for the given user and return both the
 * session-cookie name (which is install-dir-derived in init.php) and the
 * session id. Used by step-up-oidc-only.spec.ts to drive a session as an
 * OIDC-only admin without round-tripping through an OIDC IdP.
 *
 * The caller sets the cookie on the browser context via context.addCookies().
 */
export async function mintTestSession(username: string): Promise<{ cookieName: string; sid: string }> {
    const container = process.env.DOCKER_CONTAINER ?? 'ipam-pw-test';
    const { execFileSync } = await import('child_process');
    const out = execFileSync('docker', [
        'exec', '--user', 'www-data', container,
        'php', '/var/www/html/testing/scripts/mint_test_session.php',
        username,
    ], { encoding: 'utf-8' });
    const lines: Record<string, string> = {};
    for (const line of out.split('\n')) {
        const m = line.match(/^([^=]+)=(.*)$/);
        if (m) lines[m[1]] = m[2];
    }
    if (!lines.cookie_name || !lines.sid) {
        throw new Error(`mintTestSession: malformed output: ${out}`);
    }
    return { cookieName: lines.cookie_name, sid: lines.sid };
}

/**
 * Write a single allow-listed setting value (currently auth.step_up.*) directly
 * via ipam_setting_set(), bypassing the UI's lock-out guard. Used by
 * step-up-oidc-only.spec.ts to force the install into a stranded state the UI
 * would normally refuse to commit.
 */
export async function setTestSetting(key: string, value: string): Promise<void> {
    const container = process.env.DOCKER_CONTAINER ?? 'ipam-pw-test';
    const { execFileSync } = await import('child_process');
    execFileSync('docker', [
        'exec', '--user', 'www-data', container,
        'php', '/var/www/html/testing/scripts/set_test_setting.php',
        key, value,
    ], { stdio: 'pipe' });
}

/**
 * Delete every `backup_runs` row whose `encryption_mode != 'unencrypted'`.
 * Used by step-up-vault-flow + step-up-oidc-only specs to clear encrypted
 * runs that earlier specs (backup-integration etc.) leave behind, so the
 * `vault_set + generate` path is not blocked by the CR #1100 guard
 * (lib/backup_admin_destinations.php — "Cannot generate a new vault key
 * while encrypted backups exist").
 */
export async function purgeEncryptedBackupRuns(): Promise<void> {
    const container = process.env.DOCKER_CONTAINER ?? 'ipam-pw-test';
    const { execFileSync } = await import('child_process');
    execFileSync('docker', [
        'exec', '--user', 'www-data', container,
        'php', '/var/www/html/testing/scripts/purge_encrypted_backup_runs.php',
    ], { stdio: 'pipe' });
}

export async function setSmtpMailhog(): Promise<void> {
    const container = process.env.DOCKER_CONTAINER ?? 'ipam-pw-test';
    const { execFileSync } = await import('child_process');
    execFileSync('docker', [
        'exec', container,
        'php', '/var/www/html/testing/scripts/set_smtp_mailhog.php',
    ], { stdio: 'pipe' });
}

// ── adminTest fixture ──────────────────────────────────────────────────────────
/**
 * A test fixture that logs in as admin before each test and logs out after.
 * Import this instead of bare `test` in any spec that needs an authenticated session.
 */
type IpamFixtures = { adminPage: Page };

export const adminTest = base.extend<IpamFixtures>({
  adminPage: async ({ page }, use) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await use(page);
    await logout(page).catch(() => undefined);
  },
});

export { expect };
