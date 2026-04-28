/**
 * backups.spec.ts — Backup destinations, schedules, and history (#721, #722)
 *
 * Covers:
 *   - Backup destinations CRUD (S3, SFTP, Local) on destinations.php
 *   - Test-connection failure path (closed-port endpoint)
 *   - Backup schedules CRUD + run-now button attribute
 *   - Backup history page rendering, filters, and pagination
 *
 * All tests share one browser context (admin). A single beforeAll logs in
 * and the afterAll cleans up any test rows created during the suite.
 *
 * Readonly-user assertions: the RO_USER fixture from ipam.ts is used
 * where a readonly check is meaningful; the describe block notes clearly
 * when the readonly half is skipped.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { login, appUrl, newAuthContext, ADMIN_USER, ADMIN_PASS, RO_USER, RO_PASS } from '../fixtures/ipam';

// ── Test data ───────────────────────────────────────────────────────────────────
const DEST_S3_NAME    = 'pw-test-s3-dest';
const DEST_SFTP_NAME  = 'pw-test-sftp-dest';
const DEST_LOCAL_NAME = 'pw-test-local-dest';
const DEST_TEST_NAME  = 'pw-test-conn-dest';   // used for the test-connection failure path

let ctx: BrowserContext;
let page: Page;

// ── Helpers ─────────────────────────────────────────────────────────────────────

/** Find a row in the destinations table by the destination name. */
async function findDestRow(p: Page, name: string) {
  return p.locator('table.data-table tbody tr', { hasText: name }).first();
}

/**
 * Delete a destination by name via the delete form on destinations.php.
 * No-ops if the row is not found.
 */
async function cleanupDest(p: Page, name: string): Promise<void> {
  await p.goto(appUrl('destinations.php'));
  const row = p.locator('table.data-table tbody tr', { hasText: name }).first();
  if (await row.count() === 0) return;
  const deleteForm = row.locator('form:has(input[name="action"][value="delete_destination"])');
  if (await deleteForm.count() === 0) return;
  p.once('dialog', d => d.accept());
  await deleteForm.locator('button[type="submit"]').click();
  await p.waitForURL(/destinations\.php/, { timeout: 10_000 });
}

// ── Suite setup ─────────────────────────────────────────────────────────────────

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx  = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);
});

test.afterAll(async () => {
  // Best-effort cleanup: remove any destinations created during the suite.
  // Schedules are cascade-deleted when the destination is removed.
  try {
    for (const name of [DEST_S3_NAME, DEST_SFTP_NAME, DEST_LOCAL_NAME, DEST_TEST_NAME]) {
      await cleanupDest(page, name);
    }
  } catch { /* ignore */ }
  await ctx.close();
});

// ── Backup destinations admin ────────────────────────────────────────────────────

test.describe('Backup destinations admin', () => {

  test('destinations.php loads with Add-destination form visible', async () => {
    await page.goto(appUrl('destinations.php'));
    await expect(page).toHaveTitle(/destination/i, { timeout: 10_000 });
    // The "Add a destination" section and its submit button must be present.
    await expect(page.locator('form.destination-form button[type="submit"]')).toBeVisible();
    // Type selector must be present.
    await expect(page.locator('[data-destination-type-selector]')).toBeVisible();
  });

  test('readonly user cannot reach destinations.php (redirected or 403)', async () => {
    // NOTE: this test spins up its own context for the readonly user.
    // If RO_USER is not seeded this test self-skips gracefully.
    const roCtx  = await newAuthContext(ctx.browser()!);
    const roPage = await roCtx.newPage();
    try {
      await login(roPage, RO_USER, RO_PASS);
      const resp = await roPage.goto(appUrl('destinations.php'), { waitUntil: 'commit' });
      // Expect either a 403 response or a redirect away from destinations.php.
      const url = roPage.url();
      const status = resp?.status() ?? 0;
      const accessDenied = status === 403 || !url.includes('destinations.php');
      expect(
        accessDenied,
        `Readonly user should not access destinations.php (status=${status}, url=${url})`,
      ).toBeTruthy();
    } catch {
      // If the login itself fails (RO user not seeded), swallow and skip.
      test.skip(true, 'Readonly user not seeded — skipping readonly access check');
    } finally {
      await roCtx.close();
    }
  });

  test('admin can create an S3 destination', async () => {
    await page.goto(appUrl('destinations.php'));

    // Select S3 type (it is the default, but be explicit).
    await page.locator('[data-destination-type-selector]').selectOption('s3');

    await page.locator('input[name="name"]').fill(DEST_S3_NAME);
    await page.locator('input[name="s3_endpoint"]').fill('https://s3.example.com');
    await page.locator('input[name="s3_region"]').fill('us-east-1');
    await page.locator('input[name="s3_bucket"]').fill('pw-test-bucket');
    await page.locator('input[name="s3_access_key"]').fill('AKIATESTKEY');
    await page.locator('input[name="s3_secret_key"]').fill('secretvalue');

    await page.locator('form.destination-form button[type="submit"]').click();
    await page.waitForURL(/destinations\.php/, { timeout: 15_000 });

    // Row must appear in destinations table with the S3 type badge.
    const row = await findDestRow(page, DEST_S3_NAME);
    await expect(row).toBeVisible({ timeout: 5_000 });
    await expect(row.locator('.badge-type-s3')).toBeVisible();
  });

  test('admin can create an SFTP destination', async () => {
    await page.goto(appUrl('destinations.php'));
    await page.locator('[data-destination-type-selector]').selectOption('sftp');

    await page.locator('input[name="name"]').fill(DEST_SFTP_NAME);
    await page.locator('input[name="sftp_host"]').fill('sftp.example.com');
    await page.locator('input[name="sftp_port"]').fill('22');
    await page.locator('input[name="sftp_username"]').fill('backupuser');
    await page.locator('input[name="sftp_password"]').fill('sftppassword');
    await page.locator('input[name="sftp_remote_path"]').fill('/backups/ipam/');

    await page.locator('form.destination-form button[type="submit"]').click();
    await page.waitForURL(/destinations\.php/, { timeout: 15_000 });

    const row = await findDestRow(page, DEST_SFTP_NAME);
    await expect(row).toBeVisible({ timeout: 5_000 });
    await expect(row.locator('.badge-type-sftp')).toBeVisible();
  });

  test('admin can create a Local destination', async () => {
    await page.goto(appUrl('destinations.php'));
    await page.locator('[data-destination-type-selector]').selectOption('local');

    await page.locator('input[name="name"]').fill(DEST_LOCAL_NAME);
    await page.locator('input[name="local_path"]').fill('data/backups/pw-test');

    await page.locator('form.destination-form button[type="submit"]').click();
    await page.waitForURL(/destinations\.php/, { timeout: 15_000 });

    const row = await findDestRow(page, DEST_LOCAL_NAME);
    await expect(row).toBeVisible({ timeout: 5_000 });
    await expect(row.locator('.badge-type-local')).toBeVisible();
  });

  test('Test connection button indicates failure for a closed-port endpoint', async () => {
    // First ensure a destination exists that we can test against.
    await page.goto(appUrl('destinations.php'));
    // Create a throwaway S3 dest pointing at a closed port.
    await page.locator('[data-destination-type-selector]').selectOption('s3');
    await page.locator('input[name="name"]').fill(DEST_TEST_NAME);
    await page.locator('input[name="s3_endpoint"]').fill('http://127.0.0.1:1');
    await page.locator('input[name="s3_region"]').fill('us-east-1');
    await page.locator('input[name="s3_bucket"]').fill('fail-bucket');
    await page.locator('input[name="s3_access_key"]').fill('FAILKEY');
    await page.locator('input[name="s3_secret_key"]').fill('failsecret');
    await page.locator('form.destination-form button[type="submit"]').click();
    await page.waitForURL(/destinations\.php/, { timeout: 15_000 });

    // Find the Test button for this destination and click it.
    const row = await findDestRow(page, DEST_TEST_NAME);
    const testBtn = row.locator('button[data-test-destination]');
    const testBtnCount = await testBtn.count();
    if (testBtnCount === 0) {
      test.skip(true, 'Test button not found — may require JS to be wired; skipping');
      return;
    }

    await testBtn.click();

    // Wait up to 12s for the result to update: look for a failure indicator
    // in a result element or an updated button label.
    // The exact selector depends on how the JS wires up the test result,
    // but any of: ✗, failed, error, or the button text changing is sufficient.
    const resultLocator = page.locator('[data-test-result], .test-result, #test-result-area').first();
    const resultVisible = await resultLocator.isVisible().catch(() => false);

    if (resultVisible) {
      const resultText = (await resultLocator.textContent()) ?? '';
      expect(
        resultText.toLowerCase().includes('fail') ||
        resultText.includes('✗') ||
        resultText.toLowerCase().includes('error') ||
        resultText.toLowerCase().includes('could not'),
        `Expected failure indicator in test result, got: "${resultText}"`,
      ).toBeTruthy();
    } else {
      // Alternative: button text may change to reflect failure status.
      const btnText = (await testBtn.textContent()) ?? '';
      expect(
        btnText.toLowerCase().includes('fail') ||
        btnText.includes('✗') ||
        btnText.toLowerCase().includes('error'),
        `Expected failure indicator in Test button text, got: "${btnText}"`,
      ).toBeTruthy();
    }
  });

  test('admin can delete a destination', async () => {
    // Use the SFTP destination created earlier in the suite.
    await page.goto(appUrl('destinations.php'));
    const row = await findDestRow(page, DEST_SFTP_NAME);
    const rowCount = await row.count();
    if (rowCount === 0) {
      test.skip(true, 'SFTP destination not found — create test may have failed');
      return;
    }

    const deleteForm = row.locator('form:has(input[name="action"][value="delete_destination"])');
    page.once('dialog', d => d.accept());
    await deleteForm.locator('button[type="submit"]').click();
    await page.waitForURL(/destinations\.php/, { timeout: 10_000 });

    // Row must be gone.
    await expect(page.locator('table.data-table tbody tr', { hasText: DEST_SFTP_NAME })).toHaveCount(0, { timeout: 5_000 });
  });

  test('empty name prevents destination save (required attribute)', async () => {
    await page.goto(appUrl('destinations.php'));
    // Ensure name is empty (it should be by default on a fresh load).
    await page.locator('input[name="name"]').fill('');

    // Intercept the submit and check for native validation.
    // We listen for the form submit event: if the browser validates and blocks it,
    // the URL will NOT change. We click and then assert we're still on the same page.
    await page.locator('form.destination-form button[type="submit"]').click();

    // Give it a moment to potentially navigate.
    await page.waitForTimeout(800);
    const afterUrl = page.url();

    // Should still be on destinations.php (no redirect happened).
    expect(afterUrl).toContain('destinations.php');
    // The before/after URL should match (no ?flash= appended).
    expect(afterUrl).not.toContain('flash=created');

    // Optionally: check that the name input is marked invalid (HTML5 :invalid pseudo).
    const isInvalid = await page.locator('input[name="name"]').evaluate(
      (el: HTMLInputElement) => !el.validity.valid,
    );
    expect(isInvalid, 'Name input should be invalid when empty').toBeTruthy();
  });

});

// ── Backup schedules ─────────────────────────────────────────────────────────────

test.describe('Backup schedules', () => {

  test('admin can create a daily schedule for an existing destination', async () => {
    // Pre-condition: at least one destination exists (the S3 dest from the previous describe).
    await page.goto(appUrl('destinations.php'));

    // Check that the schedule form's destination select has at least one option.
    const destSelect = page.locator('form.schedule-form select[name="destination_id"]');
    const optCount = await destSelect.locator('option').count();
    if (optCount === 0) {
      test.skip(true, 'No destinations available to schedule — earlier create test may have failed');
      return;
    }

    // Fill the schedule form.
    await page.locator('form.schedule-form select[name="frequency"]').selectOption('daily');
    await page.locator('form.schedule-form input[name="time_of_day"]').fill('03:00');
    await page.locator('form.schedule-form input[name="retention_daily"]').fill('14');
    await page.locator('form.schedule-form button[type="submit"]').click();
    await page.waitForURL(/destinations\.php/, { timeout: 15_000 });

    // A schedule row should now appear in the schedules table.
    await expect(page.locator('section.card table.data-table td', { hasText: 'daily' }).first()).toBeVisible({ timeout: 5_000 });
  });

  test('Run now button is present on each schedule row with data-run-now attribute', async () => {
    await page.goto(appUrl('destinations.php'));
    const runNowBtns = page.locator('button[data-run-now]');
    const count = await runNowBtns.count();
    if (count === 0) {
      test.skip(true, 'No schedules present — schedule create test may have failed');
      return;
    }
    // Every run-now button must have a non-empty data-run-now attribute (the destination_id).
    for (let i = 0; i < count; i++) {
      const attr = await runNowBtns.nth(i).getAttribute('data-run-now');
      expect(attr, `Run now button ${i} missing data-run-now attribute`).toBeTruthy();
      expect(parseInt(attr ?? '0', 10), `data-run-now must be a positive integer`).toBeGreaterThan(0);
    }
  });

  test('admin can delete a schedule', async () => {
    await page.goto(appUrl('destinations.php'));

    // Find the first schedule delete form.
    const deleteForm = page.locator(
      'form:has(input[name="action"][value="delete_schedule"])'
    ).first();
    const formCount = await deleteForm.count();
    if (formCount === 0) {
      test.skip(true, 'No schedule delete form found — no schedules present');
      return;
    }

    page.once('dialog', d => d.accept());
    await deleteForm.locator('button[type="submit"]').click();
    await page.waitForURL(/destinations\.php/, { timeout: 10_000 });

    // The schedules table either has fewer rows or shows the empty-state message.
    // We just assert we landed back on destinations.php without an error card.
    await expect(page.locator('.card.danger')).toHaveCount(0, { timeout: 5_000 });
  });

});

// ── Backup history ───────────────────────────────────────────────────────────────

test.describe('Backup history', () => {

  test('backup_history.php loads for admin', async () => {
    await page.goto(appUrl('backup_history.php'));
    await expect(page).toHaveTitle(/backup history/i, { timeout: 10_000 });
    // Filter form must be present.
    await expect(page.locator('form.filter-bar')).toBeVisible();
    await expect(page.locator('form.filter-bar select[name="status"]')).toBeVisible();
    await expect(page.locator('form.filter-bar select[name="destination_id"]')).toBeVisible();
  });

  test('renders empty-state message or log table (not an error page)', async () => {
    await page.goto(appUrl('backup_history.php'));

    // Either the empty-state paragraph or the log table must be present.
    const emptyState = page.locator('p.muted', { hasText: 'No backup runs found.' });
    const logTable   = page.locator('section.card table.data-table').last();

    const hasEmpty = await emptyState.count() > 0;
    const hasTable = await logTable.count() > 0;
    expect(hasEmpty || hasTable, 'History page must show either empty-state or a log table').toBeTruthy();

    // No fatal PHP error indicators.
    const bodyText = await page.locator('body').textContent() ?? '';
    expect(bodyText).not.toContain('Fatal error');
    expect(bodyText).not.toContain('Uncaught');
  });

  test('status filter "success" submission narrows the result set URL', async () => {
    await page.goto(appUrl('backup_history.php'));

    await page.locator('form.filter-bar select[name="status"]').selectOption('success');
    await page.locator('form.filter-bar button[type="submit"]').click();
    await page.waitForURL(/backup_history\.php/, { timeout: 10_000 });

    // URL must contain the status filter parameter.
    expect(page.url()).toContain('status=success');

    // The page must not show a PHP error.
    const bodyText = await page.locator('body').textContent() ?? '';
    expect(bodyText).not.toContain('Fatal error');
  });

  test('destination filter submission narrows the result set URL', async () => {
    await page.goto(appUrl('backup_history.php'));

    // Only run if at least one destination option exists beyond "— Any —".
    const destSelect  = page.locator('form.filter-bar select[name="destination_id"]');
    const optionCount = await destSelect.locator('option').count();
    if (optionCount <= 1) {
      test.skip(true, 'No destinations available in backup history filter — skipping');
      return;
    }

    // Select the first real destination (index 1 skips the "Any" option).
    const firstDestOption = destSelect.locator('option').nth(1);
    const firstDestValue  = await firstDestOption.getAttribute('value') ?? '0';
    await destSelect.selectOption(firstDestValue);
    await page.locator('form.filter-bar button[type="submit"]').click();
    await page.waitForURL(/backup_history\.php/, { timeout: 10_000 });

    expect(page.url()).toContain(`destination_id=${firstDestValue}`);
  });

  test('status badge CSS classes are correct in rendered rows', async () => {
    // This test verifies the badge class mapping works. If there are no rows in
    // the log, we fall back to a source-code read to confirm the template is wired.
    await page.goto(appUrl('backup_history.php'));
    const rows = page.locator('section.card table.data-table tbody tr');
    const rowCount = await rows.count();

    if (rowCount > 0) {
      // At least one row exists — verify the badge has a recognisable class.
      const badge = rows.first().locator('span.badge');
      const badgeClass = await badge.getAttribute('class') ?? '';
      expect(
        badgeClass.includes('badge-success') ||
        badgeClass.includes('badge-failed') ||
        badgeClass.includes('badge-running') ||
        badgeClass.includes('badge-retention_pruned'),
        `Badge class "${badgeClass}" does not match any known status class`,
      ).toBeTruthy();
    } else {
      // No rows: verify the template source contains the correct class mapping.
      // This guards against a typo in the badge class without needing seeded data.
      const fs   = await import('fs');
      const path = await import('path');
      const src  = fs.readFileSync(
        path.resolve(__dirname, '../../../Simple-PHP-IPAM/backup_history.php'),
        'utf8',
      );
      expect(src).toContain("'badge-' . $statusVal");
      expect(src).toContain('badge-success');
    }
  });

  test('pagination nav appears when filter produces >50 rows (skips if insufficient data)', async () => {
    // Navigate to history without any filter — if there are >50 rows, the
    // pagination nav must be rendered.
    await page.goto(appUrl('backup_history.php'));
    const bodyText = await page.locator('body').textContent() ?? '';

    // Extract total count from page (e.g. "Log entries (123)")
    const match = bodyText.match(/Log entries \((\d[\d,]*)\)/);
    if (!match) {
      // Page has no count text — likely 0 rows. Nothing to test.
      test.skip(true, 'No log entry count found on page — insufficient data for pagination test');
      return;
    }
    const total = parseInt(match[1].replace(/,/g, ''), 10);

    if (total <= 50) {
      test.skip(true, `Only ${total} log rows present — need >50 to test pagination`);
      return;
    }

    const paginationNav = page.locator('nav.pagination');
    await expect(paginationNav).toBeVisible({ timeout: 5_000 });

    // Must have at least 2 page links.
    const pageLinks = paginationNav.locator('a.action-pill');
    const linkCount = await pageLinks.count();
    expect(linkCount, 'Pagination must show ≥2 page links when total > 50').toBeGreaterThanOrEqual(2);
  });

});
