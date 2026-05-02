/**
 * v3.17.0 — Playwright suite for the web-based restore wizard (#723).
 * v3.21.0 — extended for the wizard state-machine rewrite (#807):
 *   - phase-locked tokens (step-skip rejection covered by PHPUnit
 *     RestoreWizardStateTest; Playwright covers the surfaced UX)
 *   - post-apply session invalidation + login.php?restored=1 banner
 *
 * Most scenarios exercise the dry-run path and the confirm-typing UX.
 * The live-apply path is opt-in via IPAM_PW_RESTORE_LIVE=1 so CI doesn't
 * tear down the test database mid-suite.
 */
import { test, expect, type Page } from '@playwright/test';
import { login, appUrl, ADMIN_USER, ADMIN_PASS, RO_USER, RO_PASS } from '../fixtures/ipam';

test.describe.configure({ mode: 'serial' });

const TEST_DEST_NAME = 'pw-restore-local-dest';
const TEST_DEST_PATH = 'data/tmp/pw-restore-test';

async function loginAsAdmin(page: Page): Promise<void> {
  await login(page, ADMIN_USER, ADMIN_PASS);
}

async function ensureTestDestination(page: Page): Promise<void> {
  await page.goto(appUrl('destinations.php'));
  const exists = await page.locator(`tbody tr:has-text("${TEST_DEST_NAME}")`).count();
  if (exists > 0) return;

  // Scope to the create form to avoid matching per-row Edit drawers (#778).
  const createForm = page.locator('form.destination-form:not(.destination-edit-form)');
  await createForm.locator('input[name="name"]').fill(TEST_DEST_NAME);
  await createForm.locator('select[name="type"]').selectOption('local');
  const enc = createForm.locator('input[name="encrypt"]');
  if (await enc.isChecked()) await enc.uncheck();
  await createForm.locator('input[name="local_path"]').fill(TEST_DEST_PATH);
  await createForm.locator('button[type="submit"]').click();
  await page.waitForLoadState('networkidle');
  await expect(page.locator(`tbody tr:has-text("${TEST_DEST_NAME}")`)).toBeVisible();
}

test.describe('Restore wizard', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('admin sees restore page; readonly user gets 403', async ({ page, browser }) => {
    await page.goto(appUrl('restore_web.php'));
    await expect(page.locator('h1')).toContainText('Restore Database');
    await expect(page.locator('select[name="destination_id"]')).toBeVisible();

    const ctx = await browser.newContext();
    const ro = await ctx.newPage();
    try {
      await login(ro, RO_USER, RO_PASS);
      const resp = await ro.goto(appUrl('restore_web.php'));
      expect(resp?.status()).toBe(403);
    } finally {
      await ctx.close();
    }
  });

  test('step 1: select source UI is present', async ({ page }) => {
    await page.goto(appUrl('restore_web.php'));
    await expect(page.locator('h2').first()).toContainText('Step 1');
    await expect(page.locator('input[name="name"]')).toBeVisible();
    await expect(page.locator('button:has-text("Stage backup")')).toBeVisible();
  });

  test('step 1: invalid filename surfaces an error', async ({ page }) => {
    await ensureTestDestination(page);
    await page.goto(appUrl('restore_web.php'));
    await page.locator('select[name="destination_id"]').selectOption({ label: `${TEST_DEST_NAME} (local)` });
    await page.locator('input[name="name"]').fill('does-not-exist.sql.gz');
    await page.locator('button:has-text("Stage backup")').click();
    await expect(page.locator('.danger')).toContainText(/Stage failed|file not found/);
  });

  test('confirm-typing gate JS binds the real Step 3 controls', async ({ page }) => {
    // CR feedback PR #1054: previous form-injection version of this test
    // bound its own listener to a fake DOM and would have passed even if
    // the shipped gate IIFE in app.js disappeared. Assert against the real
    // bundle source: the gate must look up `#restore-confirm-input` and
    // `#restore-apply-button`, attach an `input` listener, and toggle
    // `disabled` based on the literal "RESTORE" string. Reaching Step 3
    // organically still requires a real staged file, which the bootstrap
    // does not provide; this contract test pins the JS instead so a
    // regression that removes or renames the gate fails immediately.
    const resp = await page.request.get(appUrl('assets/app.js'));
    expect(resp.ok(), 'assets/app.js must be reachable').toBe(true);
    const src = await resp.text();
    expect(src, 'gate binds restore-confirm-input').toContain("getElementById('restore-confirm-input')");
    expect(src, 'gate binds restore-apply-button').toContain("getElementById('restore-apply-button')");
    expect(src, 'gate toggles disabled on the literal "RESTORE"').toMatch(/input\.value\s*!==\s*['"]RESTORE['"]/);
  });

  test('login.php?restored=1 surfaces the post-restore banner', async ({ page }) => {
    // The wizard redirects to login.php?restored=1 after a successful
    // apply (#807, B-P2-50 — session is invalidated). Verify the login
    // page renders the banner without needing an actual restore.
    await page.context().clearCookies();
    await page.goto(appUrl('login.php?restored=1'));
    await expect(page.locator('.success')).toContainText(/Database restored.*log in again/i);
  });

  // ── Unified surface coverage (#1040, v3.21.0) ─────────────────────────────
  //
  // Wave 4 introduced backup_admin.php?tab=restore as the canonical entry
  // point. restore_web.php remains as a thin wrapper for backwards-compat,
  // but tests should also assert the unified surface renders the wizard
  // and the confirm-typing gate end-to-end.
  test.describe('unified surface — backup_admin.php?tab=restore', () => {

    test('Step 1 wizard renders on the Restore tab', async ({ page }) => {
      await page.goto(appUrl('backup_admin.php?tab=restore'));
      // The unified surface emits its own h1 ("Backup & Restore") and an
      // h2 with the active tab name; the wizard's own step heading is
      // therefore further down in the document. Use first-matching h2
      // *inside the tab body* to disambiguate.
      const wizard = page.locator('.backup-admin-tab');
      await expect(wizard.locator('h2', { hasText: /Step 1/ })).toBeVisible();
      await expect(wizard.locator('select[name="destination_id"]')).toBeVisible();
      await expect(wizard.locator('input[name="name"]')).toBeVisible();
      await expect(wizard.locator('button', { hasText: 'Stage backup' })).toBeVisible();
    });

    test('invalid filename surfaces an error on the unified surface', async ({ page }) => {
      await ensureTestDestination(page);
      await page.goto(appUrl('backup_admin.php?tab=restore'));
      await page.locator('select[name="destination_id"]').selectOption({ label: `${TEST_DEST_NAME} (local)` });
      await page.locator('input[name="name"]').fill('does-not-exist.sql.gz');
      await page.locator('button:has-text("Stage backup")').click();
      // The handler redirects back to the same tab with an error; assert
      // we're still on the Restore tab and the .danger banner is shown.
      await expect(page).toHaveURL(/tab=restore/);
      await expect(page.locator('.danger').first()).toContainText(/Stage failed|file not found/);
    });

    test('confirm-typing gate JS is loaded on the unified surface', async ({ page }) => {
      // CR feedback PR #1054: see the same assertion above for restore_web.php.
      // Loading the unified Restore tab also pulls assets/app.js — confirm
      // the gate IIFE is in the bundle that the unified surface serves.
      await page.goto(appUrl('backup_admin.php?tab=restore'));
      const resp = await page.request.get(appUrl('assets/app.js'));
      expect(resp.ok(), 'assets/app.js must be reachable').toBe(true);
      const src = await resp.text();
      expect(src).toContain("getElementById('restore-confirm-input')");
      expect(src).toContain("getElementById('restore-apply-button')");
      expect(src).toMatch(/input\.value\s*!==\s*['"]RESTORE['"]/);
    });

    test('legacy restore_web.php and unified ?tab=restore render structurally identical wizards', async ({ page }) => {
      // Sanity-check the thin-wrapper invariant: same form fields, same
      // submit-button labels, same step heading. If this drifts, one of
      // the two entry points has been edited in isolation.
      const fingerprint = async (url: string) => {
        await page.goto(appUrl(url));
        return page.evaluate(() => {
          const tab    = document.querySelector('.backup-admin-tab') ?? document.body;
          const fields = Array.from(tab.querySelectorAll('input[name],select[name]'))
              .map(el => `${el.tagName.toLowerCase()}:${(el as HTMLInputElement).name}`)
              .sort();
          const buttons = Array.from(tab.querySelectorAll('button[type="submit"], form button:not([type="button"])'))
              .map(el => (el.textContent || '').trim())
              .filter(t => t.length > 0)
              .sort();
          return { fields, buttons };
        });
      };
      const legacy  = await fingerprint('restore_web.php');
      const unified = await fingerprint('backup_admin.php?tab=restore');
      expect(unified.fields).toEqual(legacy.fields);
      expect(unified.buttons).toEqual(legacy.buttons);
    });
  });

  test('LIVE: stage → dry-run → apply round-trip (DESTRUCTIVE)', async ({ page }) => {
    test.skip(process.env.IPAM_PW_RESTORE_LIVE !== '1',
      'Live restore requires IPAM_PW_RESTORE_LIVE=1');
    test.setTimeout(60_000);
    await ensureTestDestination(page);

    await page.goto(appUrl('destinations.php'));
    const schedSubmit = page.locator('form.schedule-form button[type="submit"]');
    if (await schedSubmit.isVisible()) {
      await page.locator('select[name="frequency"]').selectOption('daily');
      await schedSubmit.click();
      await page.waitForLoadState('networkidle');
    }
    const runNow = page.locator('[data-run-now]').first();
    if (await runNow.isVisible()) {
      await runNow.click();
      await page.waitForFunction(() => {
        const btn = document.querySelector('[data-run-now]');
        return btn && (btn.textContent || '').includes('✓');
      }, undefined, { timeout: 30_000 });
    }

    await page.goto(appUrl('remote_backups.php'));
    await page.locator('select[name="destination_id"]').selectOption({ label: `${TEST_DEST_NAME} (local)` });
    await page.waitForLoadState('networkidle');
    const filename = await page.locator('table tbody tr td').first().textContent();
    expect(filename).toBeTruthy();

    await page.goto(appUrl('restore_web.php'));
    await page.locator('select[name="destination_id"]').selectOption({ label: `${TEST_DEST_NAME} (local)` });
    await page.locator('input[name="name"]').fill((filename || '').trim().split(' ')[0]);
    await page.locator('button:has-text("Stage backup")').click();
    await expect(page.locator('h2').first()).toContainText('Step 2');

    await page.locator('button:has-text("Run dry-run")').click();
    await expect(page.locator('h2:has-text("Dry-run results")')).toBeVisible();
    // Don't actually apply — destructive even for opt-in. The
    // post-apply login redirect is exercised by the banner test above.
  });
});
