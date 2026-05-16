/**
 * Settings (v2.6.0) — admin page at settings.php backed by the new `settings`
 * table and ipam_setting*() helpers. The shared SQLite Playwright runner means
 * every test must clean up after itself (try/finally restore) and every
 * assertion must be self-sufficient rather than depend on a prior test.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  login, appUrl, fetchGet, ensureRoUser, ADMIN_USER, ADMIN_PASS, RO_USER, RO_PASS,
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
const BRANDING_CARD   = '.card:has(h3:has-text("Branding"))';

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
    // v3.16.0 #749 reorganized into 5 tabs. Branding + Update checker live
    // on General (default tab); the other groups are visible on their
    // owning tabs. Visit each tab to verify the subsection renders there.
    await page.goto(appUrl('settings.php'));
    await expect(page.locator('h1')).toContainText('Settings');
    for (const label of ['Branding', 'Update checker']) {
      await expect(page.locator('.card h3', { hasText: label }).first()).toBeVisible();
    }
    await page.goto(appUrl('settings.php?tab=authentication'));
    for (const label of ['Security', 'OIDC / SSO']) {
      await expect(page.locator('.card h3', { hasText: label }).first()).toBeVisible();
    }
    await page.goto(appUrl('settings.php?tab=notifications'));
    await expect(page.locator('.card h3', { hasText: 'Alerting' }).first()).toBeVisible();
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
    await page.goto(appUrl('settings.php?tab=authentication'));
    const secret = page.locator('input[name="k_oidc__client_secret"]');
    const toggle = page.locator('button.pw-toggle[data-pw-toggle-for="f-k_oidc__client_secret"]');
    await expect(secret).toHaveAttribute('type', 'password');
    await expect(toggle).toHaveAttribute('aria-pressed', 'false');
    await secret.fill('round-trip-check');
    // Use evaluate to bypass sticky-header pointer-event interception (#v3.8.0 layout)
    await toggle.evaluate((el: HTMLElement) => el.click());
    await expect(secret).toHaveAttribute('type', 'text');
    await expect(secret).toHaveValue('round-trip-check');
    await expect(toggle).toHaveAttribute('aria-pressed', 'true');
    await toggle.evaluate((el: HTMLElement) => el.click());
    await expect(secret).toHaveAttribute('type', 'password');
    await expect(toggle).toHaveAttribute('aria-pressed', 'false');
    // Do not submit — leave sensitive field blank so we don't rewrite the
    // stored secret on dev. The sensitive POST path ignores blank values.
    await secret.fill('');
  });

  test('#441 bool rows render checkbox inline with label (same row)', async () => {
    await page.goto(appUrl('settings.php?tab=authentication'));
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
    await page.goto(appUrl('settings.php?tab=authentication'));
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
    await page.goto(appUrl('settings.php?tab=authentication'));
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
    await page.goto(appUrl('settings.php?tab=authentication'));
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
    // Task 5.2c routed enum validation through ipam_setting_validate(), whose
    // enum branch emits "Must be one of: <comma-list>." — assert on the stable
    // prefix so the test survives changes to the option set.
    expect(body).toContain('Must be one of:');

    // Guarantee nothing persisted: reload the page and the select value must
    // still be one of the known-good entries, never the forged string.
    await page.goto(appUrl('settings.php?tab=authentication'));
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

// ── v3.13.0 #714 — nav visibility, readonly gate, console cleanliness ─────────

test.describe('Settings page — v3.13.0 (#714)', () => {
  let ctx714: BrowserContext;
  let page714: Page;

  test.beforeAll(async ({ browser }: { browser: Browser }) => {
    ctx714 = await newAuthContext(browser);
    page714 = await ctx714.newPage();
    await login(page714, ADMIN_USER, ADMIN_PASS);
  });

  test.afterAll(async () => {
    await ctx714.close();
  });

  test('Settings nav link is visible in the admin sidebar', async () => {
    await page714.goto(appUrl('dashboard.php'));
    const link = page714.locator('nav a[href="settings.php"], .sidebar-link[href="settings.php"]');
    await expect(link.first()).toBeVisible();
  });

  test('readonly user receives 403 on settings.php', async () => {
    // Ensure readonly user exists before trying to log in as them.
    await ensureRoUser(page714);

    const roCtx = await newAuthContext(page714.context().browser()!);
    const roPage = await roCtx.newPage();
    try {
      await login(roPage, RO_USER, RO_PASS);
      const result = await fetchGet(roPage, appUrl('settings.php'));
      expect(result.status).toBe(403);
    } finally {
      await roCtx.close();
    }
  });

  test('settings.php loads with no console errors', async () => {
    const errors: string[] = [];
    page714.on('console', msg => {
      if (msg.type() === 'error') errors.push(msg.text());
    });
    await page714.goto(appUrl('settings.php'));
    await page714.waitForLoadState('networkidle');
    const realErrors = errors.filter(e => !e.includes('favicon'));
    expect(realErrors).toHaveLength(0);
  });
});

// ── v3.16.0 #749 — vertical tab navigation ───────────────────────────────────
test.describe('Settings page — v3.16.0 (#749) tab navigation', () => {
  let ctx749: BrowserContext;
  let page749: Page;

  test.beforeAll(async ({ browser }: { browser: Browser }) => {
    ctx749 = await newAuthContext(browser);
    page749 = await ctx749.newPage();
    await login(page749, ADMIN_USER, ADMIN_PASS);
  });

  test.afterAll(async () => {
    await ctx749.close();
  });

  test('default tab is General; rail is visible with five tabs', async () => {
    await page749.goto(appUrl('settings.php'));
    const rail = page749.locator('[data-settings-rail]');
    await expect(rail).toBeVisible();
    const links = rail.locator('.settings-rail__link');
    await expect(links).toHaveCount(5);
    for (const label of ['General', 'Authentication', 'Notifications', 'Data & Maintenance', 'Integrations']) {
      await expect(rail.locator(`.settings-rail__link:has-text("${label}")`)).toHaveCount(1);
    }
    const general = rail.locator('.settings-rail__link:has-text("General")');
    await expect(general).toHaveAttribute('aria-current', 'page');
  });

  test('?tab=authentication renders only Authentication subsections', async () => {
    await page749.goto(appUrl('settings.php?tab=authentication'));
    const auth = page749.locator('.settings-rail__link:has-text("Authentication")');
    await expect(auth).toHaveAttribute('aria-current', 'page');

    // Auth-tab subsections must render.
    for (const label of ['Security', 'Multi-Factor Authentication', 'Password policy', 'OIDC / SSO']) {
      await expect(page749.locator('.card h3', { hasText: label }).first()).toBeVisible();
    }
    // Non-auth tab subsections must NOT render.
    await expect(page749.locator('.card h3', { hasText: 'Branding' })).toHaveCount(0);
    await expect(page749.locator('.card h3', { hasText: 'SMTP / Email Delivery' })).toHaveCount(0);
    await expect(page749.locator('.card h3', { hasText: 'Webhooks' })).toHaveCount(0);
  });

  test('?tab=invalid falls back to General', async () => {
    await page749.goto(appUrl('settings.php?tab=invalid'));
    const general = page749.locator('.settings-rail__link:has-text("General")');
    await expect(general).toHaveAttribute('aria-current', 'page');
    await expect(page749.locator('.card h3', { hasText: 'Branding' }).first()).toBeVisible();
  });

  test('per-subsection POST still works and redirects back to owning tab', async () => {
    // The MFA subsection lives under the Authentication tab. Saving a no-op
    // change must redirect back to ?tab=authentication and land on
    // #group-mfa, NOT lose the tab parameter.
    await page749.goto(appUrl('settings.php?tab=authentication'));

    // Snapshot current MFA toggle bools so we can restore them in finally.
    // POSTing group=mfa with no boolean keys treats them all as "false" and
    // wipes the global toggle, which cascades into totp/email_otp/passkey
    // specs that run after this one (see #756).
    const readToggle = async (key: string): Promise<boolean> => {
      const cb = page749.locator(`input[type="checkbox"][name="${key}"]`).first();
      if (!(await cb.count())) return false;
      return await cb.isChecked();
    };
    const before = {
      'k_mfa__totp_enabled':      await readToggle('k_mfa__totp_enabled'),
      'k_mfa__email_otp_enabled': await readToggle('k_mfa__email_otp_enabled'),
      'k_mfa__passkeys_enabled':  await readToggle('k_mfa__passkeys_enabled'),
      'k_mfa__require':           await readToggle('k_mfa__require'),
    };

    const csrf = await page749
      .locator('form:has(input[name="group"][value="mfa"]) input[name="csrf"]')
      .first()
      .getAttribute('value');
    expect(csrf).toBeTruthy();

    try {
      const response = await page749.request.post(appUrl('settings.php'), {
        form: {
          csrf:                  csrf ?? '',
          group:                 'mfa',
          // Only post the bool fields with their current state. Empty/missing
          // values are treated as "false" by the bool path so this is a no-op
          // unless the seeded state differs. The redirect target is what we
          // care about.
        },
        maxRedirects: 0,
      });
      expect(response.status()).toBe(302);
      const loc = response.headers()['location'] || '';
      expect(loc).toContain('settings.php?tab=authentication');
      expect(loc).toContain('#group-mfa');
    } finally {
      // Restore the bools we just overwrote so we don't poison subsequent specs.
      const restoreCsrf = await page749.request.get(appUrl('settings.php?tab=authentication'));
      const restoreHtml = await restoreCsrf.text();
      const restoreMatch = restoreHtml.match(/<form[^>]*>[\s\S]*?name="group"\s+value="mfa"[\s\S]*?name="csrf"\s+value="([^"]+)"/);
      const newCsrf = restoreMatch ? restoreMatch[1] : (csrf ?? '');
      const form: Record<string, string> = { csrf: newCsrf, group: 'mfa' };
      for (const [k, v] of Object.entries(before)) {
        if (v) form[k] = '1';
      }
      await page749.request.post(appUrl('settings.php'), { form, maxRedirects: 0 });
    }
  });

  test('arrow-key keyboard nav moves focus between rail items', async () => {
    await page749.goto(appUrl('settings.php'));
    const general = page749.locator('.settings-rail__link:has-text("General")');
    await general.focus();
    await page749.keyboard.press('ArrowDown');
    const focused = await page749.evaluate(() => document.activeElement?.textContent?.trim() ?? '');
    expect(focused).toBe('Authentication');

    await page749.keyboard.press('End');
    const focusedEnd = await page749.evaluate(() => document.activeElement?.textContent?.trim() ?? '');
    expect(focusedEnd).toBe('Integrations');

    await page749.keyboard.press('Home');
    const focusedHome = await page749.evaluate(() => document.activeElement?.textContent?.trim() ?? '');
    expect(focusedHome).toBe('General');
  });

  test('legacy #group-<key> bookmark redirects to owning tab (#749 follow-up)', async () => {
    // A pre-#749 bookmark like settings.php#group-mfa lands on the General
    // tab (default) but the MFA form lives under Authentication. The JS
    // shim should read the hash, look up the owning tab in the map exposed
    // via data-group-tab-map on the rail, and redirect to
    // ?tab=authentication#group-mfa preserving the anchor.
    // Navigate away first so the next goto is a real page load (and runs the
    // inline shim). If the previous test left us on settings.php, going to
    // settings.php#group-mfa is treated as a same-document hash change and
    // the script never re-executes.
    await page749.goto(appUrl('dashboard.php'));
    await page749.goto(appUrl('settings.php#group-mfa'));
    // The shim calls location.replace synchronously after parse; Playwright
    // may or may not have observed the second navigation by the time goto()
    // returns. Poll the URL until the redirect has landed, then assert.
    await expect.poll(() => page749.url(), { timeout: 5000 })
      .toMatch(/[?&]tab=authentication/);
    expect(page749.url()).toContain('settings.php?tab=authentication');
    expect(page749.url()).toContain('#group-mfa');
    // The MFA form must actually be present after the redirect.
    const mfaForm = page749.locator('form:has(input[name="group"][value="mfa"])');
    await expect(mfaForm).toBeVisible();
  });

  test('mobile <768px hides rail and shows <select> dropdown', async () => {
    await page749.setViewportSize({ width: 360, height: 720 });
    try {
      await page749.goto(appUrl('settings.php'));
      const rail = page749.locator('[data-settings-rail]');
      await expect(rail).toBeHidden();
      const select = page749.locator('select[data-settings-mobile-nav]');
      await expect(select).toBeVisible();
      // Has all five options.
      for (const value of ['general', 'authentication', 'notifications', 'data', 'integrations']) {
        await expect(select.locator(`option[value="${value}"]`)).toHaveCount(1);
      }
    } finally {
      // Reset viewport so later specs that share this page get desktop layout.
      await page749.setViewportSize({ width: 1280, height: 800 });
    }
  });
});
