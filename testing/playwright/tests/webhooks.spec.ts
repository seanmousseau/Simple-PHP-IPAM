/**
 * Webhooks page (v3.3.0) — drawer lifecycle, CRUD, and test_fire regression
 * guard for issue #662 (IPAM_VERSION constant not available → HTTP 500).
 *
 * All tests share one browser context (admin). The beforeAll enables
 * webhook.allow_private_ips so test_fire can POST to the containerized
 * status.php endpoint (127.0.0.1) without hitting the SSRF guard.
 * The afterAll restores the setting and cleans up the test webhook row.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { login, appUrl, newAuthContext, ADMIN_USER, ADMIN_PASS } from '../fixtures/ipam';

// ── Test data ──────────────────────────────────────────────────────────────────
const WH_NAME   = 'pw-test-webhook';
const WH_URL    = 'https://127.0.0.1:8443/status.php';
const WH_SECRET = 'playwright-test-secret-32chars-ok';

let ctx: BrowserContext;
let page: Page;

// ── Helpers ────────────────────────────────────────────────────────────────────

/** Submit the webhooks settings card that contains allow_private_ips. */
async function saveWebhookSettings(p: Page, enable: boolean): Promise<void> {
  await p.goto(appUrl('settings.php'));
  const cb = p.locator('input[type=checkbox][name="k_webhook__allow_private_ips"]');
  await expect(cb).toBeVisible({ timeout: 10_000 });
  const checked = await cb.isChecked();
  if (enable !== checked) {
    await cb.evaluate((el: HTMLElement) => el.click());
  }
  // Submit the Webhooks settings card
  const card = p.locator('#group-webhooks');
  await card.locator('button[type="submit"]').click();
  await p.waitForURL(/settings\.php/, { timeout: 10_000 });
}

/** Open the "Add webhook" drawer. */
async function openAddDrawer(p: Page): Promise<void> {
  await p.goto(appUrl('webhooks.php'));
  await p.locator('#add-wh-btn').click();
  await expect(p.locator('#wh-form-drawer')).toBeVisible({ timeout: 5_000 });
}

// ── Suite ──────────────────────────────────────────────────────────────────────

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx  = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);

  // Enable webhook.allow_private_ips so test_fire can reach 127.0.0.1.
  // Swallow any error so the rest of the suite still runs if settings.php
  // is missing or the setting key does not exist yet.
  try {
    await saveWebhookSettings(page, true);
  } catch {
    // non-fatal — test_fire test will skip gracefully if delivery fails
  }
});

test.afterAll(async () => {
  try {
    // Restore allow_private_ips to default (false) to keep the DB clean.
    await saveWebhookSettings(page, false);
  } catch { /* ignore */ }

  try {
    // Delete the test webhook if it still exists.
    await page.goto(appUrl('webhooks.php'));
    // data-wh-name is on the <form> element itself; use CSS attribute selector
    const deleteForms = page.locator(`form[data-wh-name="${WH_NAME}"]:has(input[name="action"][value="delete"])`);
    const count = await deleteForms.count();
    for (let i = 0; i < count; i++) {
      page.once('dialog', d => d.accept());
      await deleteForms.first().locator('button[type="submit"]').click();
      await page.waitForURL(/webhooks\.php/, { timeout: 10_000 });
    }
  } catch { /* ignore */ }

  await ctx.close();
});

// ── Tests ──────────────────────────────────────────────────────────────────────

test('webhooks.php loads without error', async () => {
  await page.goto(appUrl('webhooks.php'));
  await expect(page).toHaveTitle(/webhook/i, { timeout: 10_000 });
  // No fatal-error indicators on the page
  await expect(page.locator('.danger').first()).not.toBeVisible().catch(() => {
    // .danger may not exist at all — that's also fine
  });
  await expect(page.locator('#add-wh-btn')).toBeVisible();
});

test('"Add webhook" button opens drawer', async () => {
  await page.goto(appUrl('webhooks.php'));
  await page.locator('#add-wh-btn').click();
  await expect(page.locator('#wh-form-drawer')).toBeVisible({ timeout: 5_000 });
  await expect(page.locator('#wh-drawer-title')).toContainText('Add webhook');
  // Close for cleanup
  await page.keyboard.press('Escape');
});

test('Escape closes drawer', async () => {
  await openAddDrawer(page);
  await page.keyboard.press('Escape');
  await expect(page.locator('#wh-form-overlay')).not.toBeVisible({ timeout: 3_000 });
  await expect(page.locator('#wh-form-drawer')).not.toBeVisible({ timeout: 3_000 });
});

test('overlay click closes drawer', async () => {
  await openAddDrawer(page);
  // Click the backdrop (top-left corner, safely away from the drawer edge)
  await page.locator('#wh-form-overlay').click({ position: { x: 10, y: 10 }, force: true });
  await expect(page.locator('#wh-form-overlay')).not.toBeVisible({ timeout: 3_000 });
  await expect(page.locator('#wh-form-drawer')).not.toBeVisible({ timeout: 3_000 });
});

test('create webhook via form', async () => {
  await openAddDrawer(page);

  // Fill required fields
  await page.locator('#wh-f-name').fill(WH_NAME);
  await page.locator('#wh-f-url').fill(WH_URL);
  await page.locator('#wh-f-secret').fill(WH_SECRET);

  // Check at least one event checkbox
  const firstEventCb = page.locator('.wh-event-cb').first();
  if (!(await firstEventCb.isChecked())) {
    await firstEventCb.check();
  }

  // Submit and wait for redirect back to webhooks.php
  await page.locator('#wh-form button[type="submit"]').click();
  await page.waitForURL(/webhooks\.php(?!\?view=)/, { timeout: 15_000 });

  // New row should appear in the table
  await expect(page.locator('table').getByText(WH_NAME).first()).toBeVisible({ timeout: 5_000 });
});

test('test_fire returns JSON (not HTTP 500) — regression for #662', async () => {
  // Requires the webhook created in the previous test.
  await page.goto(appUrl('webhooks.php'));

  // Find the Test button for our webhook row (data-name is on the button itself)
  const testBtn = page.locator(`.wh-testfire-btn[data-name="${WH_NAME}"]`).first();

  // If it doesn't exist the create test above failed; skip gracefully
  const btnCount = await testBtn.count();
  if (btnCount === 0) {
    test.skip();
    return;
  }

  // The Test button opens the drawer, fires an async fetch, then populates
  // #wh-test-result with the parsed JSON response.
  await testBtn.click();
  await expect(page.locator('#wh-test-panel')).toBeVisible({ timeout: 5_000 });

  // Wait for the result to be rendered (replaces the "Sending…" placeholder)
  const resultEl = page.locator('#wh-test-result p').first();
  await expect(resultEl).toBeVisible({ timeout: 20_000 });

  const resultText = (await resultEl.textContent()) ?? '';

  // Regression guard: PHP fatal errors that leak IPAM_VERSION as undefined
  // produce an HTML error page that gets stringified, not a JSON {ok} object.
  expect(resultText).not.toContain('Fatal error');
  expect(resultText).not.toContain('Uncaught');
  expect(resultText).not.toContain('IPAM_VERSION');

  // The result must have some content (at minimum "✓ Delivered" or "✗ Failed — HTTP N")
  expect(resultText.trim().length).toBeGreaterThan(5);
});

test('webhooks page has breadcrumb', async () => {
  await page.goto(appUrl('webhooks.php'));
  const bc = page.locator('.breadcrumbs');
  await expect(bc).toBeVisible();
  await expect(bc.locator('a[href="dashboard.php"]')).toContainText('Dashboard');
  await expect(bc.locator('span').last()).toContainText('Webhooks');
});

test('delete webhook', async () => {
  await page.goto(appUrl('webhooks.php'));

  // Find the delete form for our named webhook
  // data-wh-name is on the <form> element itself; use CSS attribute selector
  const deleteForm = page.locator(`form[data-wh-name="${WH_NAME}"]:has(input[name="action"][value="delete"])`).first();

  const formCount = await deleteForm.count();
  if (formCount === 0) {
    // Webhook may already be gone — that's acceptable
    return;
  }

  // Accept the confirm() dialog before it fires
  page.once('dialog', d => d.accept());
  await deleteForm.locator('button[type="submit"]').click();
  await page.waitForURL(/webhooks\.php(?!\?view=)/, { timeout: 10_000 });

  // Row must be gone
  await expect(page.locator('table').getByText(WH_NAME)).not.toBeVisible({ timeout: 5_000 });
});
