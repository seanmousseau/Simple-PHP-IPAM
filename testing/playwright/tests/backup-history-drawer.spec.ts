import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { login, appUrl, ADMIN_USER, ADMIN_PASS, newAuthContext } from '../fixtures/ipam';

// E2E coverage for the #803 Backup History per-row detail drawer.
// Shape of the drawer body lives in views/_backup_run_detail_body.php and
// the row triggers in views/backup_admin_history.php (tr.history-row with
// data-drawer-url="backup_run_detail.php?id=<runId>").

let ctx:  BrowserContext;
let page: Page;
// CR feedback PR #1054: capture the run id this suite creates so the delete
// test only removes its own row instead of clobbering shared seed data.
let suiteRunId: string | null = null;

test.describe('Backup history drawer (#803)', () => {

  test.beforeAll(async ({ browser }: { browser: Browser }) => {
    ctx  = await newAuthContext(browser);
    page = await ctx.newPage();
    await login(page, ADMIN_USER, ADMIN_PASS);

    // Snapshot the existing top history row id (if any) so we can detect a
    // newly-inserted run rather than waiting on a fixed timer.
    await page.goto(appUrl('backup_admin.php?tab=history'));
    const priorTop = await page.locator('tr.history-row').first().getAttribute('data-run-id').catch(() => null);

    // Best-effort: kick off one Run-now via the Backup tab (which targets a
    // known-good destination) so there's at least one history row. Falls
    // through cleanly if the bootstrap doesn't expose the button — tests
    // below skip when no rows are seeded.
    await page.goto(appUrl('backup_admin.php?tab=backup'));
    const runNow = page.locator('#run-now-button');
    if (await runNow.count() > 0 && await runNow.isVisible().catch(() => false)) {
      page.once('dialog', d => d.accept());
      await runNow.click().catch(() => null);
      // Best-effort wait for a new row at the top of History. We accept
      // either a new id or no change (tests that need a row will skip).
      // 30s budget absorbs slow dump/upload paths in CI; null result is
      // tolerated (this is non-blocking setup).
      await expect.poll(async () => {
        await page.goto(appUrl('backup_admin.php?tab=history'));
        const top = await page.locator('tr.history-row').first().getAttribute('data-run-id').catch(() => null);
        return top !== null && top !== priorTop ? top : null;
      }, { timeout: 30_000 }).not.toBeNull().catch(() => null);

      suiteRunId = await page.locator('tr.history-row').first().getAttribute('data-run-id').catch(() => null);
      // Only treat as "this suite's run" if it's actually new.
      if (suiteRunId === priorTop) {
        suiteRunId = null;
      }
    }
  });

  test.afterAll(async () => {
    // CR feedback PR #1054 (round 2): the delete test exercises the
    // suite-created run, but it can skip (e.g. when the row's delete
    // button is disabled by retention policy). Workers are sequential
    // and share the SQLite DB, so any leaked row would persist into
    // later specs. Unconditionally clean up here.
    if (suiteRunId !== null && page) {
      try {
        const csrf = await page.locator('input[name="csrf"]').first().inputValue().catch(() => '');
        if (csrf !== '') {
          await page.request.post(appUrl('backup_admin.php?tab=history'), {
            form: { csrf, action: 'delete', id: suiteRunId, confirm: 'DELETE' },
            maxRedirects: 0,
          });
        }
      } catch (_e) {
        // Best-effort: never fail the suite on teardown errors.
      }
    }
    await ctx?.close();
  });

  test('clicking a history row opens the detail drawer', async () => {
    await page.goto(appUrl('backup_admin.php?tab=history'));
    const rowCount = await page.locator('tr.history-row').count();
    test.skip(rowCount === 0, 'No history rows seeded — Run-now in beforeAll did not produce a row');

    const firstRow = page.locator('tr.history-row').first();
    const runId    = await firstRow.getAttribute('data-run-id');
    await firstRow.click();

    const drawer = page.locator('#global-drawer');
    await expect(drawer).toBeVisible();
    await expect(drawer).toContainText(new RegExp('Run #' + runId));
    // Three actions are always rendered (some may be disabled).
    await expect(drawer.locator('[data-action="verify"]')).toBeAttached();
    await expect(drawer.locator('[data-action="download"]')).toBeAttached();
    await expect(drawer.locator('[data-action="delete"]')).toBeAttached();
  });

  test('drawer for failed run disables Verify with a tooltip', async () => {
    await page.goto(appUrl('backup_admin.php?tab=history'));
    const failedRow = page.locator('tr.history-row').filter({ has: page.locator('.badge-failed') }).first();
    if (await failedRow.count() === 0) {
      test.skip(true, 'No failed run seeded; cannot exercise disabled-verify branch');
      return;
    }
    await failedRow.click();
    const drawer = page.locator('#global-drawer');
    await expect(drawer).toBeVisible();
    const verify = drawer.locator('[data-action="verify"]');
    await expect(verify).toBeDisabled();
    // Tooltip text is set server-side from $tooltip['verify'] when disabled.
    const title = await verify.getAttribute('title');
    expect(title ?? '', 'Verify must carry an explanatory title when disabled').not.toBe('');
  });

  test('Verify on a successful run reports a result', async () => {
    await page.goto(appUrl('backup_admin.php?tab=history'));
    const okRow = page.locator('tr.history-row').filter({ has: page.locator('.badge-success') }).first();
    if (await okRow.count() === 0) {
      test.skip(true, 'No successful run seeded; cannot exercise verify happy path');
      return;
    }
    await okRow.click();
    const drawer = page.locator('#global-drawer');
    await expect(drawer).toBeVisible();
    const verifyBtn = drawer.locator('[data-action="verify"]');
    if (await verifyBtn.isDisabled()) {
      // Older successful runs may have been pruned of artifact bytes; skip when
      // the action would be a no-op (this is the same disabled-tooltip state
      // the previous test asserts on the failed branch).
      test.skip(true, 'Verify disabled for the most recent success row');
      return;
    }
    await verifyBtn.click();
    const result = drawer.locator('#drawer-action-result');
    await expect(result).toBeVisible({ timeout: 30_000 });
    // Result is either an OK badge ("Verified") or an explicit error; either way
    // the drawer must report something rather than silently failing.
    const text = (await result.textContent()) ?? '';
    expect(text.trim(), 'Verify must surface a result message').not.toBe('');
  });

  test('Delete requires literal DELETE confirmation and removes the row', async () => {
    // CR feedback PR #1054: target the run created in beforeAll, not
    // .first() — sequential workers share the SQLite DB, so deleting an
    // arbitrary row could clobber fixture data or hit a protected row.
    test.skip(suiteRunId === null, 'beforeAll did not create a suite run');
    const runId = suiteRunId as string;

    await page.goto(appUrl('backup_admin.php?tab=history'));
    const target = page.locator(`tr.history-row[data-run-id="${runId}"]`);
    await expect(target, 'suite-created run still in history').toBeVisible();
    await target.click();
    const drawer  = page.locator('#global-drawer');
    await expect(drawer).toBeVisible();

    const deleteBtn = drawer.locator('[data-action="delete"]');
    if (await deleteBtn.isDisabled()) {
      test.skip(true, 'Delete disabled for the targeted row (e.g. retained-by-policy)');
      return;
    }
    await deleteBtn.click();
    // The arm/confirm UI is appended by app.js when Delete is clicked.
    const confirm = drawer.locator('#drawer-delete-confirm');
    await expect(confirm).toBeVisible({ timeout: 5_000 });
    await confirm.fill('DELETE');
    await drawer.locator('#drawer-delete-arm').click();

    const result = drawer.locator('#drawer-action-result');
    await expect(result).toBeVisible({ timeout: 15_000 });
    await expect(result).toContainText(/Deleted|removed/i);

    // The history table reload either drops the row immediately (if the drawer
    // closes + re-fetches) or on next navigation. Re-navigate and assert.
    await page.goto(appUrl('backup_admin.php?tab=history'));
    await expect(page.locator(`tr.history-row[data-run-id="${runId}"]`)).toHaveCount(0);
  });

});
