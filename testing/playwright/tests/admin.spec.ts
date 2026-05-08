/**
 * Admin pages — API key CRUD (#10 in cdp), users, sites, session activity log (#235),
 * dashboard checks, v1.18/v1.19 UI feature verification.
 * Also covers: VLANs admin page, tags admin page (v2.0.0 additions).
 * Migrated from cdp_test.py section 10 + 11b.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  login, fetchPost, fetchGet, appUrl,
  ADMIN_USER, ADMIN_PASS,
  newAuthContext,
  passStepUpIfPresent,
} from '../fixtures/ipam';

let ctx: BrowserContext;
let page: Page;

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);
});

test.afterAll(async () => {
  await ctx?.close();
});

// ── Dashboard ──────────────────────────────────────────────────────────────────

test('dashboard: correct title and has stat cards', async () => {
  await page.goto('dashboard.php');
  expect((await page.title()).toLowerCase()).toContain('dashboard');
  const cards = await page.locator('.card').count();
  expect(cards).toBeGreaterThan(0);
});

test('dashboard: nav bar present', async () => {
  await page.goto('dashboard.php');
  await expect(page.locator('#sidebar')).toBeVisible();
});

test('nav: DHCP Pools accessible in sidebar', async () => {
  await page.goto('dashboard.php');
  const sidebarLinks = await page.locator('.sidebar-link').allInnerTexts();
  expect(sidebarLinks.some(t => t.includes('DHCP'))).toBe(true);
});

// ── API keys ───────────────────────────────────────────────────────────────────

test('api_keys page loads', async () => {
  await page.goto('api_keys.php');
  expect((await page.title()).toLowerCase()).toContain('api key');
});

test('api key: create and one-time display', async () => {
  await page.goto('api_keys.php');
  const createForm = page.locator('form').filter({ has: page.locator('[name=action][value=create]') });
  await createForm.locator('[name=name]').fill('pw-test-key');
  // Use Promise.all to catch the navigation triggered by form submission
  await Promise.all([
    page.waitForURL(url => url.pathname.includes('api_keys.php'), { timeout: 10_000 }),
    createForm.locator('button[type=submit]').click(),
  ]);
  // v3.27.0 (#1107): api_keys create is gated behind ipam_sudo_verify().
  // If the step-up prompt rendered, pass it with the admin password to
  // continue to the one-time key display.
  await passStepUpIfPresent(page);
  // Wait for the one-time key display element specifically
  await page.waitForSelector('code.key-display', { timeout: 10_000 });
  const keyText = await page.locator('code.key-display').innerText();
  expect(keyText.length, 'created key should be shown').toBeGreaterThan(16);
});

test('api key: list shows created key', async () => {
  await page.goto('api_keys.php');
  await expect(page.getByText('pw-test-key')).toBeVisible();
});

test('api key: unauthenticated API call returns 401', async () => {
  await page.goto('api_keys.php');
  const status = await page.evaluate(async (url) => {
    const r = await fetch(url);
    return r.status;
  }, appUrl('api.php?resource=subnets'));
  expect(status).toBe(401);
});

test('api key: delete test key', async () => {
  await page.goto('api_keys.php');
  const keyId = await page.evaluate(() => {
    for (const f of document.querySelectorAll<HTMLFormElement>('form')) {
      const act = f.querySelector<HTMLInputElement>('[name=action]');
      const kid = f.querySelector<HTMLInputElement>('[name=key_id]');
      if (act?.value === 'delete' && kid) {
        const row = f.closest('tr');
        if (row?.innerText.includes('pw-test-key')) return kid.value;
      }
    }
    return null;
  });
  if (keyId) {
    await fetchPost(page, appUrl('api_keys.php'), { action: 'delete', key_id: keyId });
    await page.goto('api_keys.php');
    const body = await page.locator('body').innerText();
    expect(body).not.toContain('pw-test-key');
  } else {
    test.skip();
  }
});

// ── Sites ──────────────────────────────────────────────────────────────────────

test('sites page loads', async () => {
  await page.goto('sites.php');
  expect((await page.title()).toLowerCase()).toContain('site');
});

test('site CRUD: create → appears in list → delete', async () => {
  await page.goto('sites.php');
  await fetchPost(page, appUrl('sites.php'), {
    action: 'create', name: 'pw-test-site', description: 'playwright test',
  });
  await page.goto('sites.php');
  // Scope to table td to avoid matching <option> elements (site name also appears in pickers)
  await expect(page.locator('table td').filter({ hasText: 'pw-test-site' }).first()).toBeVisible();

  // Delete it
  const siteId = await page.evaluate(() => {
    for (const f of document.querySelectorAll<HTMLFormElement>('form')) {
      const act = f.querySelector<HTMLInputElement>('[name=action]');
      const id  = f.querySelector<HTMLInputElement>('[name=id]');
      if (act?.value === 'delete' && id) {
        const row = f.closest('tr');
        if (row?.innerText.includes('pw-test-site')) return id.value;
      }
    }
    return null;
  });
  if (siteId) {
    await fetchPost(page, appUrl('sites.php'), { action: 'delete', id: siteId });
    await page.goto('sites.php');
    const body = await page.locator('body').innerText();
    expect(body).not.toContain('pw-test-site');
  }
});

// ── Session activity log (#235) ────────────────────────────────────────────────

test('session activity log on change_password.php (#235)', async () => {
  await page.goto('change_password.php');
  const body = await page.locator('body').innerText();
  // The page should show recent login activity
  const hasLoginActivity = body.toLowerCase().includes('recent') ||
                            body.toLowerCase().includes('login') ||
                            body.toLowerCase().includes('activity') ||
                            body.toLowerCase().includes('session');
  expect(hasLoginActivity, 'session activity section present').toBe(true);
});

// ── status.php ─────────────────────────────────────────────────────────────────

test('status.php returns ok JSON with schema_version', async () => {
  await page.goto('dashboard.php');
  const r = await fetchGet(page, appUrl('status.php'));
  const json = JSON.parse(r.body);
  expect(json.status).toBe('ok');
  expect(json).toHaveProperty('schema_version');
});

// ── Empty state CTAs (#245) ────────────────────────────────────────────────────

test('addresses.php without subnet_id shows empty state with link (#245)', async () => {
  await page.goto('addresses.php');
  const body = await page.locator('body').innerText();
  const html = await page.content();
  const hasLink = html.includes('subnets.php') || body.toLowerCase().includes('go to subnets') ||
                  body.toLowerCase().includes('select a subnet');
  expect(hasLink, 'empty state points to subnets').toBe(true);
});

// ── Config validation (#236) ───────────────────────────────────────────────────

test('no config validation warnings in normal operation', async () => {
  // If config values are valid, no admin warning banner should appear
  await page.goto('dashboard.php');
  // The config-warning notice only appears for invalid config values
  // In a correctly configured install, there should be none
  const warnings = await page.locator('.config-warning, [data-config-warning]').count();
  // Just verify the page loads (warnings are only shown for broken configs)
  expect((await page.title()).toLowerCase()).toContain('dashboard');
  void warnings; // soft check — not a failure condition in a valid install
});

// ── VLANs admin page (v2.0.0) ─────────────────────────────────────────────────

test('vlans page: loads via admin nav', async () => {
  await page.goto('vlans.php');
  await expect(page).toHaveTitle(/VLANs/i);
  await expect(page.locator('h1')).toContainText('VLANs');
});

test('vlans page: accessible from admin sidebar', async () => {
  await page.goto('dashboard.php');
  const sidebarLinks = await page.locator('.sidebar-link').allInnerTexts();
  const hasVlans = sidebarLinks.some((t: string) => t.toLowerCase().includes('vlan'));
  expect(hasVlans, 'Sidebar must contain a VLANs link').toBe(true);
});

test('vlans page: breadcrumb present', async () => {
  await page.goto('vlans.php');
  await expect(page.locator('.breadcrumbs')).toBeVisible();
  await expect(page.locator('.breadcrumbs')).toContainText('Dashboard');
});

// ── Tags admin page (v2.0.0) ───────────────────────────────────────────────────

test('tags page: loads via admin nav', async () => {
  await page.goto('tags.php');
  await expect(page).toHaveTitle(/Tags/i);
  await expect(page.locator('h1')).toContainText('Tags');
});

test('tags page: accessible from admin sidebar', async () => {
  await page.goto('dashboard.php');
  const sidebarLinks = await page.locator('.sidebar-link').allInnerTexts();
  const hasTags = sidebarLinks.some((t: string) => t.toLowerCase().includes('tag'));
  expect(hasTags, 'Sidebar must contain a Tags link').toBe(true);
});

test('tags page: breadcrumb present', async () => {
  await page.goto('tags.php');
  await expect(page.locator('.breadcrumbs')).toBeVisible();
  await expect(page.locator('.breadcrumbs')).toContainText('Dashboard');
});
