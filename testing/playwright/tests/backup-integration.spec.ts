/**
 * backup-integration.spec.ts — End-to-end backup round-trip against real
 * destinations (#789).
 *
 * Unlike backups.spec.ts (which exercises CRUD UI + a connection test on a
 * known-bad endpoint), this spec drives the full upload path against the
 * MinIO sidecar and a writable local directory seeded by bootstrap-app.sh
 * (see fixtures/seed-backup-destinations.php).
 *
 * Coverage per destination:
 *   1. test_destination.php → ok=true (connection works against the real target)
 *   2. run_backup_now.php   → ok=true with non-empty filename and size > 0
 *   3. backup_history.php   → success row with a populated checksum
 *
 * Out of scope (deferred):
 *   - SFTP transport — covered in v3.23.0 #833 (requires sshd sidecar).
 *   - Restore-into-empty-DB round-trip — restore engine is exercised end-to-end
 *     by the existing restore wizard spec; the gate this file enforces is that
 *     the upload path actually delivers a usable artifact to the destination.
 *
 * Required fixture state:
 *   - 'ci-minio'  (s3)    — seeded by seed-backup-destinations.php
 *   - 'ci-local'  (local) — seeded by seed-backup-destinations.php
 *
 * The MinIO sidecar lives on the docker network created by bootstrap-app.sh.
 * If either destination is missing, the spec hard-fails (rather than skipping)
 * — D1 is a required gate for any change touching the backup engine.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { login, appUrl, newAuthContext, ADMIN_USER, ADMIN_PASS } from '../fixtures/ipam';

const DEST_S3    = 'ci-minio';
const DEST_LOCAL = 'ci-local';

let ctx: BrowserContext;
let page: Page;

async function findDestRow(p: Page, name: string) {
  return p.locator('table.data-table tbody tr', { hasText: name }).first();
}

async function destIdByName(p: Page, name: string): Promise<number> {
  await p.goto(appUrl('destinations.php'));
  const row = await findDestRow(p, name);
  await expect(row, `Seeded destination '${name}' must be present`).toBeVisible({ timeout: 5_000 });
  const runBtn = row.locator('button[data-run-now]');
  const id = await runBtn.getAttribute('data-run-now');
  const parsed = parseInt(id ?? '0', 10);
  expect(parsed, `data-run-now id for '${name}' must be > 0`).toBeGreaterThan(0);
  return parsed;
}

async function getCsrf(p: Page): Promise<string> {
  await p.goto(appUrl('destinations.php'));
  const tok = await p.locator('input[name="csrf"]').first().getAttribute('value');
  expect(tok, 'CSRF token must be present on destinations.php').toBeTruthy();
  return tok!;
}

async function postForm(p: Page, path: string, fields: Record<string, string>): Promise<{ ok: boolean; body: string; json: any }> {
  const body = new URLSearchParams(fields).toString();
  const res = await p.request.post(appUrl(path), {
    data: body,
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  });
  const text = await res.text();
  let json: any = null;
  try { json = JSON.parse(text); } catch { /* not JSON */ }
  return { ok: res.ok(), body: text, json };
}

test.describe('Backup integration (MinIO + local)', () => {
  test.beforeAll(async ({ browser }: { browser: Browser }) => {
    ctx  = await newAuthContext(browser);
    page = await ctx.newPage();
    await login(page, ADMIN_USER, ADMIN_PASS);
  });

  test.afterAll(async () => {
    await ctx.close();
  });

  for (const destName of [DEST_S3, DEST_LOCAL]) {
    test(`${destName}: connection test succeeds`, async () => {
      const id = await destIdByName(page, destName);
      const csrf = await getCsrf(page);
      const res = await postForm(page, 'test_destination.php', {
        id: String(id),
        csrf,
      });
      expect(res.json, `test_destination.php response must be JSON: ${res.body}`).toBeTruthy();
      expect(res.json.ok, `test_destination(${destName}) failed: ${res.json?.message ?? res.body}`).toBe(true);
    });

    test(`${destName}: run-now → backup_history success row round-trip`, async () => {
      // Combined into a single test so the unique filename produced by run-now
      // never crosses test boundaries — eliminates the module-state coupling
      // that breaks under retries / parallel workers (CR review on PR #1050).
      const id = await destIdByName(page, destName);
      const csrf = await getCsrf(page);

      // ── run-now: upload a backup ──────────────────────────────────────────
      const res = await postForm(page, 'run_backup_now.php', {
        destination_id: String(id),
        csrf,
      });
      expect(res.json, `run_backup_now.php response must be JSON: ${res.body}`).toBeTruthy();
      expect(res.json.ok, `run_backup_now(${destName}) failed: ${res.json?.message ?? res.body}`).toBe(true);
      expect(res.json.size, 'size must be > 0').toBeGreaterThan(0);
      expect(typeof res.json.filename, 'filename must be a non-empty string').toBe('string');
      expect(res.json.filename.length).toBeGreaterThan(0);
      const expectedFilename: string = res.json.filename;

      // ── backup_history: success row for *this* run ───────────────────────
      await page.goto(appUrl(`backup_history.php?destination_id=${id}&status=success`));

      const successRow = page.locator('table.data-table tbody tr', {
        has: page.locator('span.badge-success'),
        hasText: expectedFilename,
      }).first();
      await expect(successRow, `success row for ${destName} filename ${expectedFilename} must be visible`)
        .toBeVisible({ timeout: 10_000 });

      // backup_history.php column order (verified in source):
      //   0 started | 1 destination | 2 trigger | 3 type | 4 status |
      //   5 filename | 6 size | 7 duration | 8 checksum
      const cells = successRow.locator('td');
      const filename = (await cells.nth(5).textContent() ?? '').trim();
      const sizeText = (await cells.nth(6).textContent() ?? '').trim();
      const checksumText = (await cells.nth(8).textContent() ?? '').trim();

      expect(filename, 'filename column must not be empty').not.toBe('');
      expect(filename, 'filename column must not be a placeholder').not.toBe('—');
      expect(sizeText, 'size column must not be empty').not.toBe('');
      // Checksum is rendered as the first 12 chars of the SHA-256 with an
      // ellipsis (or similar) — accept any non-empty, non-placeholder value.
      expect(checksumText, 'checksum column must not be empty').not.toBe('');
      expect(checksumText, 'checksum column must not be a placeholder').not.toBe('—');
    });
  }
});
