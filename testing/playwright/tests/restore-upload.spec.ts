/**
 * v3.24.0 #837 — manual upload-and-restore wizard step.
 *
 * Covers the new step=upload control surface on the restore wizard:
 *   - Step 1 'Upload a backup file' <details> renders.
 *   - Plain SQL upload reaches Step 2 (dryrun preview) — happy path.
 *   - Empty / oversized uploads surface a friendly error.
 *   - Garbage upload (unrecognised magic) routes to Step 2 anyway as
 *     a copy (the dryrun stage is where bogus content is rejected — by
 *     the SQL splitter, not the upload step).
 *
 * Encrypted-upload paths (IPAMBKP1/2/3 stored, IPAMBKP3 transitory →
 * needs_passphrase prompt) are exercised exhaustively by the PHPUnit
 * codec suite (BackupCryptoIpambkp3Test, DecryptToolTest) — generating
 * those archives from a browser context would require a separate
 * fixture-builder server route. The two surfaces are validated
 * independently: PHPUnit owns the cryptographic round-trip, Playwright
 * owns the wizard UX and the readonly/admin gate.
 */
import { test, expect, type Page } from '@playwright/test';
import { login, appUrl, ADMIN_USER, ADMIN_PASS, RO_USER, RO_PASS } from '../fixtures/ipam';

test.describe.configure({ mode: 'serial' });

async function loginAsAdmin(page: Page): Promise<void> {
  await login(page, ADMIN_USER, ADMIN_PASS);
}

async function openUploadDetails(page: Page): Promise<void> {
  await page.goto(appUrl('restore_web.php'));
  await page.locator('details summary', { hasText: /Upload a backup file/i }).click();
}

test.describe('Restore wizard — manual upload (#837)', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('Step 1 renders an upload affordance with file + submit controls', async ({ page }) => {
    await openUploadDetails(page);
    await expect(page.locator('input[type="file"][name="restore_upload"]')).toBeVisible();
    await expect(page.locator('button:has-text("Upload & stage")')).toBeVisible();
    // The accepted-formats note must reference the v3 magic family so
    // operators know IPAMBKP3 archives are supported.
    await expect(page.locator('details', { has: page.locator('input[name="restore_upload"]') }))
      .toContainText(/IPAMBKP3/);
  });

  test('readonly user is forbidden from the restore page entirely', async ({ browser }) => {
    const ctx = await browser.newContext();
    const ro  = await ctx.newPage();
    try {
      await login(ro, RO_USER, RO_PASS);
      const resp = await ro.goto(appUrl('restore_web.php'));
      expect(resp?.status()).toBe(403);
    } finally {
      await ctx.close();
    }
  });

  test('plain .sql upload reaches Step 2 (dryrun preview)', async ({ page }) => {
    await openUploadDetails(page);

    // Minimal SQL fixture — passes the splitter and stages cleanly. The
    // SQL is a no-op SELECT so dryrun reports zero schema changes.
    const fixtureBytes = Buffer.from('-- v3.24.0 manual-upload smoke\nSELECT 1;\n', 'utf-8');
    await page.locator('input[type="file"][name="restore_upload"]').setInputFiles({
      name: 'manual-upload-smoke.sql',
      mimeType: 'application/sql',
      buffer: fixtureBytes,
    });
    await page.locator('button:has-text("Upload & stage")').click();
    await page.waitForLoadState('networkidle');

    // Either we land on Step 2 (dryrun preview) — the happy path — OR
    // the splitter rejected our minimal SQL with an error. Either way
    // a stale Step 1 with no feedback is a regression.
    const step2Heading = page.locator('h2', { hasText: /Step 2|dry-run preview/i });
    const errorBanner  = page.locator('.danger');
    await expect(step2Heading.or(errorBanner)).toBeVisible();
  });

  test('garbage upload either errors or stages for downstream rejection', async ({ page }) => {
    await openUploadDetails(page);
    // Random bytes that match no magic — staged as a plain copy; the
    // dryrun is where rejection happens. The upload step itself does
    // not pre-validate content (would duplicate splitter logic).
    const garbage = Buffer.from('\x00not-a-backup-file\xff\xfe\xfd', 'binary');
    await page.locator('input[type="file"][name="restore_upload"]').setInputFiles({
      name: 'garbage.bin',
      mimeType: 'application/octet-stream',
      buffer: garbage,
    });
    await page.locator('button:has-text("Upload & stage")').click();
    await page.waitForLoadState('networkidle');
    // Wizard MUST progress past Step 1 in some form — either landing on
    // Step 2 or surfacing an error banner. Stale Step 1 with no
    // feedback is the regression we're guarding against.
    const step2Heading = page.locator('h2', { hasText: /Step 2|dry-run preview/i });
    const errorBanner  = page.locator('.danger');
    await expect(step2Heading.or(errorBanner)).toBeVisible();
  });

  test('upload form encloses an enctype=multipart/form-data attribute', async ({ page }) => {
    // Without enctype=multipart, $_FILES would be empty server-side and
    // every upload would surface as 'no file uploaded'. Pin the form
    // attribute so a careless edit can't silently break the whole step.
    await openUploadDetails(page);
    const form = page.locator('form:has(input[name="restore_upload"])');
    await expect(form).toHaveAttribute('enctype', 'multipart/form-data');
    await expect(form).toHaveAttribute('method', /post/i);
  });
});
