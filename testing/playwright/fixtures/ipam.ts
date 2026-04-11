/**
 * Shared fixtures, helpers, and constants for the Simple PHP IPAM Playwright suite.
 */
import { test as base, expect, type Page, type Browser, type BrowserContext } from '@playwright/test';
import { APP_BASE } from '../playwright.config';

// ── App credentials ────────────────────────────────────────────────────────────
export const ADMIN_USER = process.env.IPAM_ADMIN_USER || 'admin';
export const ADMIN_PASS = process.env.IPAM_ADMIN_PASS || 'admin';

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
  await page.waitForSelector('[name=username]', { timeout: 10_000 });
  await page.locator('[name=username]').fill(username);
  await page.locator('[name=password]').fill(password);
  await page.locator('button[type=submit]').click();
  // Wait for navigation away from login.php (successful login redirects to dashboard)
  await page.waitForURL(url => !url.pathname.endsWith('login.php'), { timeout: 15_000 });
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
 * Delete a subnet by CIDR via a POST to subnets.php.
 * Must be called while subnets.php is the active page.
 */
export async function deleteSubnet(page: Page, cidr: string): Promise<void> {
  const subId = await page.evaluate((cidr) => {
    for (const f of document.querySelectorAll<HTMLFormElement>('form')) {
      const act = f.querySelector<HTMLInputElement>('[name=action]');
      const id  = f.querySelector<HTMLInputElement>('[name=id]');
      if (act?.value === 'delete' && id) {
        const node = f.closest<HTMLElement>('.subnet-node');
        if (node?.innerText.includes(cidr)) return id.value;
      }
    }
    return null;
  }, cidr);
  if (subId) {
    await fetchPost(page, appUrl('subnets.php'), { action: 'delete', id: subId });
  }
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
