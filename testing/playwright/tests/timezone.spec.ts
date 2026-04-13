/**
 * Timezone display tests (#358) — verifies the `timezone` config setting actually
 * shifts displayed timestamps across the UI.
 *
 * Strategy:
 *   1. Deploy a lightweight PHP helper (set_config_tz.php) that patches config.php
 *      AND calls opcache_invalidate() so the next request immediately picks up
 *      the new timezone — bypassing the 60-second OPcache revalidate window.
 *   2. Read baseline timestamps under UTC, switch to Asia/Tokyo (UTC+9, no DST),
 *      verify the shift, then restore original timezone in a finally block.
 *
 * OPcache note: the dev server has opcache.revalidate_freq=60. File-based timezone
 * changes (sed, Python in-place writes) are invisible to PHP for up to a minute.
 * The PHP helper calls opcache_invalidate() from within the web process, making
 * the change effective on the very next request.
 *
 * Set-up: `set_config_tz.php` is deployed to the app root by this test's beforeAll
 * hook and removed by afterAll. It is protected by the same Basic Auth as /claude/.
 *
 * These tests are skipped when IPAM_BASE_URL does not point to the dev server.
 */

import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { execFileSync } from 'child_process';
import { login, appUrl, ADMIN_USER, ADMIN_PASS, newAuthContext, BASIC_AUTH_HEADER } from '../fixtures/ipam';

// ── Constants ──────────────────────────────────────────────────────────────────

const REMOTE       = 'root@192.168.80.15';
const REMOTE_PATH  = '/opt/container_data/dev.seanmousseau.com/html/claude/ipam/set_config_tz.php';
const TZ_HELPER    = 'set_config_tz.php';

// PHP helper source — patches config.php and invalidates OPcache in one step.
const HELPER_PHP = `<?php
declare(strict_types=1);
$tz  = trim($_GET['tz'] ?? '');
$cfg = __DIR__ . '/config.php';
if (!$tz || !preg_match('/^[A-Za-z0-9_\\-\\/+]+$/', $tz)) {
    http_response_code(400); echo json_encode(['error'=>'invalid timezone']); exit;
}
$content = file_get_contents($cfg);
if ($content === false) {
    http_response_code(500); echo json_encode(['error'=>'cannot read']); exit;
}
$new = preg_replace("/'timezone' => '[^']*'/", "'timezone' => '" . addcslashes($tz, "'\\\\") . "'", $content);
file_put_contents($cfg, $new);
if (function_exists('opcache_invalidate')) opcache_invalidate($cfg, true);
header('Content-Type: application/json');
echo json_encode(['timezone' => $tz]);
`;

// ── Skip guard ─────────────────────────────────────────────────────────────────

function isDevServer(): boolean {
  const base = process.env.IPAM_BASE_URL ?? '';
  return base.includes('192.168.80.15') || base.includes('dev-direct.seanmousseau.com');
}

// ── SSH helper (used only for setup/teardown) ──────────────────────────────────

function ssh(shellCmd: string): void {
  execFileSync('ssh', [
    '-o', 'StrictHostKeyChecking=no',
    '-o', 'ConnectTimeout=5',
    REMOTE,
    shellCmd,
  ], { stdio: 'pipe' });
}

// ── Timezone helper (HTTP-based, triggers OPcache invalidation) ────────────────

async function setRemoteTz(page: Page, tz: string): Promise<void> {
  const url      = appUrl(`${TZ_HELPER}?tz=${encodeURIComponent(tz)}`);
  const basicAuth = BASIC_AUTH_HEADER;
  const result = await page.evaluate(
    async ({ url, basicAuth }): Promise<{ timezone?: string; error?: string }> => {
      const headers: Record<string, string> = {};
      if (basicAuth) headers['Authorization'] = basicAuth;
      const r = await fetch(url, { headers, credentials: 'same-origin' });
      return r.json();
    },
    { url, basicAuth },
  );
  if (result.error) throw new Error(`setRemoteTz(${tz}) failed: ${result.error}`);
}

// ── Shared context ─────────────────────────────────────────────────────────────

let ctx: BrowserContext;
let page: Page;

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  if (!isDevServer()) return;

  // Deploy the PHP helper script.
  const phpContent = HELPER_PHP.replace(/'/g, "'\\''"); // shell-escape single quotes
  ssh(`printf '%s' '${phpContent}' > ${REMOTE_PATH} && chown www-data:www-data ${REMOTE_PATH}`);

  ctx = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);
});

test.afterAll(async () => {
  if (!isDevServer()) return;
  try { await setRemoteTz(page, 'UTC'); } catch { /* best effort */ }
  try { ssh(`rm -f ${REMOTE_PATH}`); } catch { /* best effort */ }
  await ctx?.close();
});

// ── Tests ──────────────────────────────────────────────────────────────────────

test('audit.php timestamps shift by +9h when timezone set to Asia/Tokyo', async () => {
  if (!isDevServer()) { test.skip(); return; }

  try {
    // Capture baseline under UTC.
    await setRemoteTz(page, 'UTC');
    await page.goto(appUrl('audit.php'));
    const utcTs = await page.locator('table tbody tr td:first-child').first().innerText();
    expect(utcTs).toMatch(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/);

    // Switch to Asia/Tokyo (UTC+9, no DST — unambiguous conversion).
    await setRemoteTz(page, 'Asia/Tokyo');
    await page.goto(appUrl('audit.php'));
    const tokyoTs = await page.locator('table tbody tr td:first-child').first().innerText();
    expect(tokyoTs).toMatch(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/);

    // Wall-clock difference should be +9h.
    const utcMs   = new Date(utcTs.replace(' ', 'T')).getTime();
    const tokyoMs = new Date(tokyoTs.replace(' ', 'T')).getTime();
    expect((tokyoMs - utcMs) / 3_600_000).toBeCloseTo(9, 1);

  } finally {
    await setRemoteTz(page, 'UTC');
  }
});

test('users.php last-login timestamp shifts between UTC and Asia/Tokyo', async () => {
  if (!isDevServer()) { test.skip(); return; }

  try {
    await setRemoteTz(page, 'UTC');
    await page.goto(appUrl('users.php'));
    const utcTs = await page
      .locator('table tbody tr td')
      .filter({ hasText: /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/ })
      .first()
      .innerText();
    expect(utcTs).toMatch(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/);

    await setRemoteTz(page, 'Asia/Tokyo');
    await page.goto(appUrl('users.php'));
    const tokyoTs = await page
      .locator('table tbody tr td')
      .filter({ hasText: /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/ })
      .first()
      .innerText();
    expect(tokyoTs).toMatch(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/);

    const diffHours = (
      new Date(tokyoTs.replace(' ', 'T')).getTime() -
      new Date(utcTs.replace(' ', 'T')).getTime()
    ) / 3_600_000;
    expect(diffHours).toBeCloseTo(9, 1);

  } finally {
    await setRemoteTz(page, 'UTC');
  }
});

test('negative offset (America/New_York) shows earlier time than UTC', async () => {
  if (!isDevServer()) { test.skip(); return; }

  try {
    await setRemoteTz(page, 'UTC');
    await page.goto(appUrl('audit.php'));
    const utcTs = await page.locator('table tbody tr td:first-child').first().innerText();

    await setRemoteTz(page, 'America/New_York');
    await page.goto(appUrl('audit.php'));
    const estTs = await page.locator('table tbody tr td:first-child').first().innerText();

    expect(utcTs).toMatch(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/);
    expect(estTs).toMatch(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/);

    const diffMs = (
      new Date(estTs.replace(' ', 'T')).getTime() -
      new Date(utcTs.replace(' ', 'T')).getTime()
    );
    // America/New_York is UTC-4 (EDT) or UTC-5 (EST) — always behind UTC.
    expect(diffMs).toBeLessThan(0);
    expect(Math.abs(diffMs) / 3_600_000).toBeGreaterThanOrEqual(4);
    expect(Math.abs(diffMs) / 3_600_000).toBeLessThanOrEqual(5);

  } finally {
    await setRemoteTz(page, 'UTC');
  }
});
