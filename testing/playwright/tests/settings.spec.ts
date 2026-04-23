/**
 * Settings (v2.6.0) — admin page at settings.php backed by the new `settings`
 * table and ipam_setting*() helpers. The shared SQLite Playwright runner means
 * every test must clean up after itself (try/finally restore) and every
 * assertion must be self-sufficient rather than depend on a prior test.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  login, appUrl, ADMIN_USER, ADMIN_PASS,
  newAuthContext,
} from '../fixtures/ipam';

/**
 * Delete a settings row from the containerized test database so a known key
 * falls back to config.php and appears in the #376 deprecation banner.
 * Uses docker exec + php -r via execFileSync (no shell) because the
 * container is the source of truth for test state and there is no HTTP
 * endpoint that can surgically drop a row. A no-op (swallowed) when the
 * container is not running so the rest of the spec still skips cleanly
 * under dev-direct.
 */
let ctx: BrowserContext;
let page: Page;

const SITE_NAME_FIELD = 'input[name="k_branding__site_name"]';
const BRANDING_CARD   = '.card:has(h2:has-text("Branding"))';

async function brandingSubmit(p: Page): Promise<void> {
  await p.locator(`${BRANDING_CARD} button[type="submit"]`).click();
  // Wait for the redirect back to settings.php to complete.
  await p.waitForURL(/settings\.php/);
}

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);
});

test.afterAll(async () => {
  await ctx.close();
});

test.describe('Settings page', () => {
  test('loads under Admin dropdown for admin users', async () => {
    await page.goto(appUrl('settings.php'));
    await expect(page.locator('h1')).toContainText('Settings');
    for (const label of ['Branding', 'Security', 'Alerting', 'Update checker', 'OIDC']) {
      await expect(page.locator('.card h2', { hasText: label }).first()).toBeVisible();
    }
  });

  test('admin can save a branding change and the source badge flips to Database', async () => {
    await page.goto(appUrl('settings.php'));
    const field = page.locator(SITE_NAME_FIELD);
    await expect(field).toBeVisible();

    const original = (await field.inputValue()) ?? '';
    const newValue = `IPAM Test ${Date.now()}`;

    try {
      await field.fill(newValue);
      await brandingSubmit(page);

      await expect(page.locator(SITE_NAME_FIELD)).toHaveValue(newValue);
      // v2.7.0: label+badge+key live in a .setting-head wrapper (no longer
      // one big <label>). Assert the source badge inside that wrapper.
      const head = page.locator('.setting-head', { hasText: 'Application name' }).first();
      await expect(head).toContainText('Database');
    } finally {
      // Always put the field back so later specs see the seeded value.
      await page.goto(appUrl('settings.php'));
      await page.locator(SITE_NAME_FIELD).fill(original);
      await brandingSubmit(page);
    }
  });

  test('#449 password show eye-button flips sensitive input type', async () => {
    // Regression for the v2.6.0 inline-onclick and v2.7.0 nested-<label>
    // checkbox patterns. v2.8.0 replaces both with a sibling <button> that
    // flips the input type and toggles aria-pressed.
    await page.goto(appUrl('settings.php'));
    const secret = page.locator('input[name="k_oidc__client_secret"]');
    const toggle = page.locator('button.pw-toggle[data-pw-toggle-for="f-k_oidc__client_secret"]');
    await expect(secret).toHaveAttribute('type', 'password');
    await expect(toggle).toHaveAttribute('aria-pressed', 'false');
    await secret.fill('round-trip-check');
    await toggle.click({ force: true });
    await expect(secret).toHaveAttribute('type', 'text');
    await expect(secret).toHaveValue('round-trip-check');
    await expect(toggle).toHaveAttribute('aria-pressed', 'true');
    await toggle.click();
    await expect(secret).toHaveAttribute('type', 'password');
    await expect(toggle).toHaveAttribute('aria-pressed', 'false');
    // Do not submit — leave sensitive field blank so we don't rewrite the
    // stored secret on dev. The sensitive POST path ignores blank values.
    await secret.fill('');
  });

  test('#441 bool rows render checkbox inline with label (same row)', async () => {
    await page.goto(appUrl('settings.php'));
    const cb = page.locator('input[type=checkbox][name="k_oidc__enabled"]');
    const label = page.locator('label[for="f-k_oidc__enabled"] strong', { hasText: 'OIDC enabled' });
    await expect(cb).toBeVisible();
    await expect(label).toBeVisible();

    const cbBox    = await cb.boundingBox();
    const labelBox = await label.boundingBox();
    expect(cbBox).not.toBeNull();
    expect(labelBox).not.toBeNull();
    if (cbBox && labelBox) {
      const cbCenter    = cbBox.y + cbBox.height / 2;
      const labelCenter = labelBox.y + labelBox.height / 2;
      // Centres must be within 10px vertically — same row, not stacked.
      expect(Math.abs(cbCenter - labelCenter)).toBeLessThan(10);
    }
  });

  test('#442 login_protection.method renders as a validated <select>', async () => {
    await page.goto(appUrl('settings.php'));
    const select = page.locator('select[name="k_login_protection__method"]');
    await expect(select).toBeVisible();
    // At minimum the seven registry entries must be selectable.
    for (const value of ['', 'honeypot', 'time_check', 'turnstile', 'hcaptcha', 'recaptcha', 'friendly_captcha']) {
      await expect(select.locator(`option[value="${value}"]`)).toHaveCount(1);
    }
  });

  test('#501 oidc.default_role offers readonly, netops, and admin', async () => {
    // v2.11.0 #501: the dropdown had been missing `netops` since v2.9.0
    // even though the users.role column and demo seed already carried
    // the role. Regression guard — this test fails if anyone narrows
    // the enum again.
    await page.goto(appUrl('settings.php'));
    const select = page.locator('select[name="k_oidc__default_role"]');
    await expect(select).toBeVisible();
    for (const value of ['readonly', 'netops', 'admin']) {
      await expect(select.locator(`option[value="${value}"]`)).toHaveCount(1);
    }
  });

  test('#442 branding.timezone renders as a dropdown seeded with PHP zoneinfo', async () => {
    await page.goto(appUrl('settings.php'));
    const select = page.locator('select[name="k_branding__timezone"]');
    await expect(select).toBeVisible();
    for (const value of ['UTC', 'America/Toronto', 'Europe/London']) {
      await expect(select.locator(`option[value="${value}"]`)).toHaveCount(1);
    }
  });

  test('v3.0.0: no deprecation banner on clean install (config.php is a stub)', async () => {
    await page.goto(appUrl('settings.php'));
    const oldBanner = page.locator('#deprecated-banner');
    await expect(oldBanner).toHaveCount(0);
  });

  test('v3.0.0: setting source badges show Database or Default only', async () => {
    await page.goto(appUrl('settings.php'));
    const configBadges = page.locator('.badge', { hasText: 'config.php' });
    await expect(configBadges).toHaveCount(0);
  });

  test('#442 out-of-set login_protection.method is rejected server-side', async () => {
    // Go through the form to grab a fresh CSRF token, then POST a forged value.
    await page.goto(appUrl('settings.php'));
    const csrf = await page
      .locator('form:has(select[name="k_login_protection__method"]) input[name="csrf"]')
      .first()
      .getAttribute('value');
    expect(csrf).toBeTruthy();

    const response = await page.request.post(appUrl('settings.php'), {
      form: {
        csrf: csrf ?? '',
        group: 'login_protection',
        k_login_protection__method: 'not-a-real-method',
        k_login_protection__site_key: '',
        k_login_protection__min_seconds: '3',
        k_login_protection__version: '2',
      },
      maxRedirects: 0,
    });
    // Validation error path re-renders the page (HTTP 200), not a redirect.
    expect(response.status()).toBe(200);
    const body = await response.text();
    expect(body).toContain('Must be one of the listed values.');

    // Guarantee nothing persisted: reload the page and the select value must
    // still be one of the known-good entries, never the forged string.
    await page.goto(appUrl('settings.php'));
    const selected = await page
      .locator('select[name="k_login_protection__method"]')
      .inputValue();
    expect(selected).not.toBe('not-a-real-method');
  });

  test('saving a setting produces a setting.update audit entry', async () => {
    // Self-sufficient: create a fresh setting.update here rather than relying
    // on the prior test's side effect.
    await page.goto(appUrl('settings.php'));
    const field = page.locator(SITE_NAME_FIELD);
    const original = (await field.inputValue()) ?? '';
    const marker = `audit-check-${Date.now()}`;

    try {
      await field.fill(marker);
      await brandingSubmit(page);

      await page.goto(appUrl('audit.php'));
      await expect(page.locator('body')).toContainText('setting.update');
    } finally {
      await page.goto(appUrl('settings.php'));
      await page.locator(SITE_NAME_FIELD).fill(original);
      await brandingSubmit(page);
    }
  });
});
