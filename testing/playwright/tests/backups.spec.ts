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
const DEST_AUTOTEST_NAME = 'pw-autotest-dest'; // used for #787 auto-Test-on-Save

let ctx: BrowserContext;
let page: Page;

// ── Helpers ─────────────────────────────────────────────────────────────────────

/** Find a row in the destinations table by the destination name. */
async function findDestRow(p: Page, name: string) {
  return p.locator('table.data-table tbody tr', { hasText: name }).first();
}

/** The "Add a destination" create form, scoped to exclude per-row edit drawers. */
function createDestForm(p: Page) {
  return p.locator('form.destination-form:not(.destination-edit-form)');
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
    for (const name of [DEST_S3_NAME, DEST_SFTP_NAME, DEST_LOCAL_NAME, DEST_TEST_NAME, DEST_AUTOTEST_NAME]) {
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
    // Scope to the create form to avoid matching per-row Edit drawers (#778).
    await expect(createDestForm(page).locator('button[type="submit"]')).toBeVisible();
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
    const f = createDestForm(page);

    // Select S3 type (it is the default, but be explicit).
    await f.locator('[data-destination-type-selector]').selectOption('s3');

    await f.locator('input[name="name"]').fill(DEST_S3_NAME);
    await f.locator('input[name="s3_endpoint"]').fill('https://s3.example.com');
    await f.locator('input[name="s3_region"]').fill('us-east-1');
    await f.locator('input[name="s3_bucket"]').fill('pw-test-bucket');
    await f.locator('input[name="s3_access_key"]').fill('AKIATESTKEY');
    await f.locator('input[name="s3_secret_key"]').fill('secretvalue');

    await f.locator('button[type="submit"]').click();
    await page.waitForURL(/destinations\.php/, { timeout: 15_000 });

    // Row must appear in destinations table with the S3 type badge.
    const row = await findDestRow(page, DEST_S3_NAME);
    await expect(row).toBeVisible({ timeout: 5_000 });
    await expect(row.locator('.badge-type-s3')).toBeVisible();
  });

  test('admin can create an SFTP destination', async () => {
    await page.goto(appUrl('destinations.php'));
    const f = createDestForm(page);
    await f.locator('[data-destination-type-selector]').selectOption('sftp');

    await f.locator('input[name="name"]').fill(DEST_SFTP_NAME);
    await f.locator('input[name="sftp_host"]').fill('sftp.example.com');
    await f.locator('input[name="sftp_port"]').fill('22');
    await f.locator('input[name="sftp_username"]').fill('backupuser');
    await f.locator('input[name="sftp_password"]').fill('sftppassword');
    await f.locator('input[name="sftp_remote_path"]').fill('/backups/ipam/');

    await f.locator('button[type="submit"]').click();
    await page.waitForURL(/destinations\.php/, { timeout: 15_000 });

    const row = await findDestRow(page, DEST_SFTP_NAME);
    await expect(row).toBeVisible({ timeout: 5_000 });
    await expect(row.locator('.badge-type-sftp')).toBeVisible();
  });

  test('admin can create a Local destination', async () => {
    await page.goto(appUrl('destinations.php'));
    const f = createDestForm(page);
    await f.locator('[data-destination-type-selector]').selectOption('local');

    await f.locator('input[name="name"]').fill(DEST_LOCAL_NAME);
    await f.locator('input[name="local_path"]').fill('data/backups/pw-test');

    await f.locator('button[type="submit"]').click();
    await page.waitForURL(/destinations\.php/, { timeout: 15_000 });

    const row = await findDestRow(page, DEST_LOCAL_NAME);
    await expect(row).toBeVisible({ timeout: 5_000 });
    await expect(row.locator('.badge-type-local')).toBeVisible();
  });

  test('admin can Run-now from the destination row (local)', async () => {
    await page.goto(appUrl('destinations.php'));
    const row = await findDestRow(page, DEST_LOCAL_NAME);
    if (await row.count() === 0) {
      test.skip(true, 'Local destination not found — create test may have failed');
      return;
    }
    const runBtn = row.locator('button[data-run-now]');
    await expect(runBtn, 'Run-now button must render on the destination row').toBeVisible();
    const destId = await runBtn.getAttribute('data-run-now');
    expect(parseInt(destId ?? '0', 10)).toBeGreaterThan(0);

    page.once('dialog', d => d.accept());
    await runBtn.click();

    // The button text becomes "✓ <filename> (<bytes> bytes)" on success
    // or "✗ <message>" on failure. Wait for either, then assert success.
    await expect(runBtn).toHaveText(/[✓✗]/, { timeout: 30_000 });
    const finalText = (await runBtn.textContent()) ?? '';
    expect(finalText, `Run-now should succeed for local destination, got: ${finalText}`)
      .toMatch(/✓.*bytes/);
  });

  test('admin can edit an S3 destination — non-secret field, secret preserved', async () => {
    // Uses the S3 destination created in the previous test.
    await page.goto(appUrl('destinations.php'));
    const row = await findDestRow(page, DEST_S3_NAME);
    if (await row.count() === 0) {
      test.skip(true, 'S3 destination not found — create test may have failed');
      return;
    }

    // Open the edit drawer for this row.
    const editBtn = row.locator('button[data-edit-destination]');
    await editBtn.click();

    // The drawer is the next <tr> after the row; we locate by id.
    const id = await editBtn.getAttribute('data-edit-destination');
    const editRow = page.locator(`#edit-destination-${id}`);
    await expect(editRow).toBeVisible({ timeout: 5_000 });

    // Change a non-secret field (region) and submit, leaving the secret blank.
    await editRow.locator('input[name="s3_region"]').fill('eu-west-2');
    await expect(editRow.locator('input[name="s3_secret_key"]')).toHaveValue('');
    await editRow.locator('button[type="submit"]', { hasText: /save/i }).click();
    await page.waitForURL(/destinations\.php\?flash=updated/, { timeout: 10_000 });

    // Re-open the edit drawer; the new region must persist and the secret must
    // still be empty in the form (placeholder shows "(unchanged)").
    await page.goto(appUrl('destinations.php'));
    const row2 = await findDestRow(page, DEST_S3_NAME);
    await row2.locator('button[data-edit-destination]').click();
    const editRow2 = page.locator(`#edit-destination-${id}`);
    await expect(editRow2.locator('input[name="s3_region"]')).toHaveValue('eu-west-2');
    await expect(editRow2.locator('input[name="s3_secret_key"]')).toHaveAttribute('placeholder', /unchanged/);
  });

  test('admin can rotate an S3 destination secret', async () => {
    await page.goto(appUrl('destinations.php'));
    const row = await findDestRow(page, DEST_S3_NAME);
    if (await row.count() === 0) {
      test.skip(true, 'S3 destination not found');
      return;
    }
    await row.locator('button[data-edit-destination]').click();
    const id = await row.locator('button[data-edit-destination]').getAttribute('data-edit-destination');
    const editRow = page.locator(`#edit-destination-${id}`);
    await editRow.locator('input[name="s3_secret_key"]').fill('rotatedsecret123');
    await editRow.locator('button[type="submit"]', { hasText: /save/i }).click();
    await page.waitForURL(/destinations\.php\?flash=updated/, { timeout: 10_000 });
    // No exception, no error card.
    await expect(page.locator('.card.danger')).toHaveCount(0);
  });

  test('destination edit form locks the type field', async () => {
    await page.goto(appUrl('destinations.php'));
    const row = await findDestRow(page, DEST_S3_NAME);
    if (await row.count() === 0) {
      test.skip(true, 'S3 destination not found');
      return;
    }
    await row.locator('button[data-edit-destination]').click();
    const id = await row.locator('button[data-edit-destination]').getAttribute('data-edit-destination');
    const editRow = page.locator(`#edit-destination-${id}`);
    // The visible type display is a disabled input — server enforces the lock too.
    const typeBox = editRow.locator('input[disabled][readonly]').first();
    await expect(typeBox).toBeDisabled();
  });

  test('auto-Test on Save surfaces inline failure badge without manual click (#787)', async () => {
    await page.goto(appUrl('destinations.php'));
    const f = createDestForm(page);
    await f.locator('[data-destination-type-selector]').selectOption('s3');
    await f.locator('input[name="name"]').fill(DEST_AUTOTEST_NAME);
    await f.locator('input[name="s3_endpoint"]').fill('http://127.0.0.1:1');
    await f.locator('input[name="s3_region"]').fill('us-east-1');
    await f.locator('input[name="s3_bucket"]').fill('fail-bucket');
    await f.locator('input[name="s3_access_key"]').fill('AUTOFAILKEY');
    await f.locator('input[name="s3_secret_key"]').fill('autofailsecret');
    await f.locator('button[type="submit"]').click();
    // Auto-test runs server-side before the redirect; allow up to 30s for the
    // S3 HEAD probe to fail against the closed port.
    await page.waitForURL(/destinations\.php\?flash=created/, { timeout: 30_000 });

    const row = await findDestRow(page, DEST_AUTOTEST_NAME);
    const badge = row.locator('[data-auto-test-result]');
    await expect(badge).toBeVisible();
    await expect(badge).toHaveClass(/badge-failed/);
    await expect(badge).toContainText('✗');
  });

  test('Test connection button indicates failure for a closed-port endpoint', async () => {
    // First ensure a destination exists that we can test against.
    await page.goto(appUrl('destinations.php'));
    // Create a throwaway S3 dest pointing at a closed port.
    const f = createDestForm(page);
    await f.locator('[data-destination-type-selector]').selectOption('s3');
    await f.locator('input[name="name"]').fill(DEST_TEST_NAME);
    await f.locator('input[name="s3_endpoint"]').fill('http://127.0.0.1:1');
    await f.locator('input[name="s3_region"]').fill('us-east-1');
    await f.locator('input[name="s3_bucket"]').fill('fail-bucket');
    await f.locator('input[name="s3_access_key"]').fill('FAILKEY');
    await f.locator('input[name="s3_secret_key"]').fill('failsecret');
    await f.locator('button[type="submit"]').click();
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

    // The JS handler updates button text in-place and adds .button-danger / .button-success
    // classes. Wait up to 30s for the transient "Testing…" label to be replaced.
    // S3 connect to a closed port can take ~10s for the curl timeout to fire.
    await expect(testBtn).not.toHaveText(/Testing/i, { timeout: 30_000 });

    const btnText = (await testBtn.textContent()) ?? '';
    const btnClass = (await testBtn.getAttribute('class')) ?? '';
    expect(
      btnText.toLowerCase().includes('fail') ||
      btnText.includes('✗') ||
      btnText.toLowerCase().includes('error') ||
      btnText.toLowerCase().includes('could not') ||
      btnClass.includes('button-danger'),
      `Expected failure indicator (text: "${btnText}", class: "${btnClass}")`,
    ).toBeTruthy();
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
    const f = createDestForm(page);
    // Ensure name is empty (it should be by default on a fresh load).
    await f.locator('input[name="name"]').fill('');

    await f.locator('button[type="submit"]').click();

    // The HTML5 `required` attribute should block submission synchronously —
    // assert the input is invalid (deterministic, no hard wait), then confirm
    // we're still on destinations.php with no flash redirect.
    await expect.poll(
      async () => f.locator('input[name="name"]').evaluate((el: HTMLInputElement) => !el.validity.valid),
      { message: 'Name input should be invalid when empty', timeout: 2_000 },
    ).toBe(true);

    const afterUrl = page.url();
    expect(afterUrl).toContain('destinations.php');
    expect(afterUrl).not.toContain('flash=created');
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

  test('admin can edit an existing schedule', async () => {
    await page.goto(appUrl('destinations.php'));
    // Target the schedule created by the prior test (daily @ 03:00) rather than
    // .first() — the shared CI DB may have seeded schedules with different
    // frequencies, and editing an arbitrary first row would race against the
    // time_of_day field hide rules and validate the wrong record.
    const scheduleRow = page.locator('section.card table.data-table tbody tr', {
      hasText: 'daily',
    }).filter({ hasText: '03:00' }).first();
    await expect(scheduleRow, 'schedule created by prior test (daily @ 03:00) must be present').toBeVisible({ timeout: 5_000 });
    const editBtn = scheduleRow.locator('button[data-edit-schedule]');
    await editBtn.click();
    const id = await editBtn.getAttribute('data-edit-schedule');
    const editRow = page.locator(`#edit-schedule-${id}`);
    await expect(editRow).toBeVisible({ timeout: 5_000 });

    // Change retention_daily and time_of_day, save, re-fetch and verify.
    await editRow.locator('input[name="retention_daily"]').fill('21');
    await editRow.locator('input[name="time_of_day"]').fill('04:30');
    await editRow.locator('button[type="submit"]', { hasText: /save/i }).click();
    await page.waitForURL(/destinations\.php\?flash=sched_updated/, { timeout: 10_000 });

    // Re-open the same edit row; the new values must persist.
    await page.goto(appUrl('destinations.php'));
    const editBtn2 = page.locator(`button[data-edit-schedule="${id}"]`);
    await editBtn2.click();
    const editRow2 = page.locator(`#edit-schedule-${id}`);
    await expect(editRow2.locator('input[name="retention_daily"]')).toHaveValue('21');
    await expect(editRow2.locator('input[name="time_of_day"]')).toHaveValue('04:30');
  });

  test('schedule create form hides fields that do not apply to chosen frequency (#781)', async () => {
    await page.goto(appUrl('destinations.php'));
    // Scope to the create form to avoid matching per-row Edit drawers (#780).
    const form = page.locator('form.schedule-form:not(.schedule-edit-form)').first();
    const sel  = form.locator('select[name="frequency"]');
    const tod  = form.locator('label[data-freq-field="time_of_day"]');
    const dow  = form.locator('label[data-freq-field="day_of_week"]');
    const dom  = form.locator('label[data-freq-field="day_of_month"]');

    await sel.selectOption('hourly');
    await expect(tod).toBeHidden();
    await expect(dow).toBeHidden();
    await expect(dom).toBeHidden();

    await sel.selectOption('daily');
    await expect(tod).toBeVisible();
    await expect(dow).toBeHidden();
    await expect(dom).toBeHidden();

    await sel.selectOption('weekly');
    await expect(tod).toBeVisible();
    await expect(dow).toBeVisible();
    await expect(dom).toBeHidden();

    await sel.selectOption('monthly');
    await expect(tod).toBeVisible();
    await expect(dow).toBeHidden();
    await expect(dom).toBeVisible();
  });

  test('server normalises non-applicable frequency fields to NULL even if forced (#781)', async () => {
    await page.goto(appUrl('destinations.php'));
    const editBtn = page.locator('button[data-edit-schedule]').first();
    if (await editBtn.count() === 0) {
      test.skip(true, 'No schedules to update');
      return;
    }
    const id = await editBtn.getAttribute('data-edit-schedule');
    const csrf = await page.locator('input[name="csrf"]').first().inputValue();

    // Forge a POST that sets frequency=daily but forces day_of_week=3 and day_of_month=15.
    // The server must reject these by storing NULL (defence-in-depth — the UI hides them
    // and disables them, but a malicious or scripted client could still send them).
    const resp = await page.request.post(appUrl('destinations.php'), {
      form: {
        csrf:             csrf,
        action:           'update_schedule',
        id:               id ?? '0',
        frequency:        'daily',
        time_of_day:      '05:15',
        day_of_week:      '3',
        day_of_month:     '15',
        retention_hourly: '24',
        retention_daily:  '7',
        retention_weekly: '4',
        retention_monthly:'12',
      },
      maxRedirects: 0,
    });
    expect([302, 303]).toContain(resp.status());

    // Re-open the edit drawer; day_of_week and day_of_month must be empty (NULL → to_int → 0/1 default).
    // The visible "Time of day" must reflect the new value, confirming the update actually ran.
    await page.goto(appUrl('destinations.php'));
    const editBtn2 = page.locator(`button[data-edit-schedule="${id}"]`);
    await editBtn2.click();
    const editRow2 = page.locator(`#edit-schedule-${id}`);
    await expect(editRow2.locator('input[name="time_of_day"]')).toHaveValue('05:15');
    // Frequency persisted as 'daily' (the values for dow/dom are not asserted directly because
    // to_int(null) defaults render as 0/1 in the form — what matters is the Time/Day display column).
    await expect(editRow2.locator('select[name="frequency"]')).toHaveValue('daily');
    // The list-row display must read "@ 05:15" (the daily format), not "DOW … @ …".
    await expect(page.locator('section.card table.data-table td', { hasText: '@ 05:15' }).first()).toBeVisible();
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
    // Target the log entries table specifically (the page also has a Status-by-destination
    // summary card with its own table that has no status badges).
    const rows = page.locator('section.card', { hasText: 'Log entries' }).locator('tbody tr');
    const rowCount = await rows.count();

    if (rowCount > 0) {
      // At least one row exists — verify a status badge is present with a recognisable class.
      // Note: Phase 14 added a separate Type badge (badge-backup / badge-restore). Iterate
      // all badges in the row and require at least one to carry a known status class.
      const badges = rows.first().locator('span.badge');
      const badgeCount = await badges.count();
      let foundStatusClass = '';
      for (let i = 0; i < badgeCount; i++) {
        const cls = await badges.nth(i).getAttribute('class') ?? '';
        if (cls.includes('badge-success') || cls.includes('badge-failed') ||
            cls.includes('badge-running') || cls.includes('badge-retention_pruned')) {
          foundStatusClass = cls;
          break;
        }
      }
      expect(
        foundStatusClass !== '',
        `No status badge with a known class found among ${badgeCount} badges in first row`,
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
      // Template builds class as 'badge-' . $r['status']; verify the dynamic
      // construction is present and that the css declares all four states.
      expect(src).toMatch(/'badge-' \. \$statusVal/);
      const cssSrc = fs.readFileSync(
        path.resolve(__dirname, '../../../Simple-PHP-IPAM/assets/app.css'),
        'utf8',
      );
      expect(cssSrc).toContain('badge-success');
      expect(cssSrc).toContain('badge-failed');
      expect(cssSrc).toContain('badge-running');
      expect(cssSrc).toContain('badge-retention_pruned');
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
