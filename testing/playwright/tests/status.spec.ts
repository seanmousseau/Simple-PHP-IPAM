/**
 * status.php health check spec — #465
 *
 * Verifies the load-balancer probe endpoint behaves correctly:
 *  1. Returns HTTP 200 + {"status":"ok"} JSON body
 *  2. No authentication required (no cookies, no session)
 *  3. Does NOT set a PHPSESSID cookie (no session start)
 *
 * Note: response-time assertion is omitted — it is environment-dependent
 * and flaky in CI without a warmed JIT cache. The three structural
 * assertions above catch the real regression (accidentally requiring init.php).
 */
import { test, expect } from '@playwright/test';

test.describe('status.php health check', () => {
  test('returns HTTP 200 with {"status":"ok"} body', async ({ request }) => {
    const response = await request.get('status.php');
    expect(response.status()).toBe(200);

    const body = await response.json();
    expect(body).toMatchObject({ status: 'ok' });
  });

  test('accessible without authentication (no session cookie)', async ({ request }) => {
    // Make request with a completely fresh context — no cookies, no auth headers
    const response = await request.get('status.php', {
      headers: { Cookie: '' },
    });
    expect(response.status()).toBe(200);
    // Must not redirect to login
    const body = await response.text();
    expect(body).not.toMatch(/login\.php/);
    expect(body).toContain('"ok"');
  });

  test('does not set PHPSESSID cookie', async ({ request }) => {
    const response = await request.get('status.php');
    const setCookie = response.headers()['set-cookie'] ?? '';
    expect(setCookie).not.toMatch(/PHPSESSID/i);
  });
});
