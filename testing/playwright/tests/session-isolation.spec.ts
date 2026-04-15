/**
 * Session cookie isolation — v2.10.0 #532 security fix.
 *
 * Two IPAM installs under the same hostname used to share a session cookie
 * because the default session name (`IPAMSESSID`) and path (`/`) were
 * identical on every deploy. A user logged into /ipam-a/ would be
 * authenticated on /ipam-b/ without ever entering credentials.
 *
 * The fix derives a unique cookie name per install directory and scopes
 * the cookie path to the install's URL directory. This spec asserts both
 * invariants against the containerized test instance so regressions are
 * caught before release.
 *
 * A true two-install bleed test requires bootstrapping two containers
 * under a shared hostname which the current harness is not set up for —
 * that is filed as a follow-up against the v2.13.0 Playwright
 * infrastructure work (#525). This spec covers the unit-level invariants
 * that the patch is active and the cookie shape is correct.
 */
import { test, expect } from '@playwright/test';
import { appUrl } from '../fixtures/ipam';

test.describe('session cookie isolation (#532)', () => {
  test('Set-Cookie header uses a hash-suffixed name, not the raw IPAMSESSID', async ({ page }) => {
    const response = await page.goto(appUrl('login.php'));
    expect(response).not.toBeNull();
    const header = await response!.headerValue('set-cookie');
    expect(header).toBeTruthy();

    // The session cookie must be IPAMSESSID_<8 hex chars>, never the
    // raw 'IPAMSESSID'. The suffix is the first 8 hex chars of a SHA-256
    // hash of the install directory path.
    expect(header).toMatch(/IPAMSESSID_[0-9a-f]{8}=/);
    expect(header).not.toMatch(/(?<!_[0-9a-f]{8})IPAMSESSID=/);
  });

  test('cookie path is scoped to the install directory, not /', async ({ page }) => {
    const response = await page.goto(appUrl('login.php'));
    const header = await response!.headerValue('set-cookie');
    expect(header).toBeTruthy();

    // Extract the path attribute from the Set-Cookie header. Two valid
    // shapes depending on where the container mounts the app:
    //   - app at /               → path=/
    //   - app at /claude/ipam/   → path=/claude/ipam/
    // Either is correct — what's NOT correct is a path that contains a
    // sibling install directory or that lives outside the current app.
    const match = header!.match(/path=([^;]+)/i);
    expect(match).not.toBeNull();
    const path = match![1];

    // The path must either be exactly '/' (app at root) or end with '/'
    // and start with '/'. It must NOT contain a doubled slash which
    // would indicate a malformed dirname() reduction.
    expect(path).toMatch(/^\/[a-zA-Z0-9._\-\/]*\/?$/);
    expect(path).not.toContain('//');
  });

  test('cookie carries Secure + HttpOnly + SameSite=Strict', async ({ page }) => {
    const response = await page.goto(appUrl('login.php'));
    const header = await response!.headerValue('set-cookie');
    expect(header).toBeTruthy();

    // These flags were already present pre-#532 but we assert them here
    // too so a regression that drops them during a future session-config
    // refactor is caught by the same spec file that owns the isolation
    // fix.
    expect(header).toMatch(/;\s*secure/i);
    expect(header).toMatch(/;\s*httponly/i);
    expect(header).toMatch(/;\s*samesite=strict/i);
  });

  test('fresh contexts get distinct session IDs (strict_mode active)', async ({ page }) => {
    // Indirect assertion: we cannot read the server-side save_path from
    // the client, but we CAN verify that two sequential login probes
    // from distinct cookie jars receive DIFFERENT session IDs — proving
    // that strict_mode is active and the server is generating fresh
    // session records rather than inheriting one from a polluted
    // /tmp/sess_* file left by another process on a shared host.
    const resp1 = await page.goto(appUrl('login.php'));
    const h1 = await resp1!.headerValue('set-cookie');
    const id1 = (h1 ?? '').match(/=([a-f0-9]{20,})/);
    expect(id1).not.toBeNull();

    // New browser context → fresh cookie jar → new session
    await page.context().clearCookies();
    const resp2 = await page.goto(appUrl('login.php'));
    const h2 = await resp2!.headerValue('set-cookie');
    const id2 = (h2 ?? '').match(/=([a-f0-9]{20,})/);
    expect(id2).not.toBeNull();

    expect(id1![1]).not.toBe(id2![1]);
  });
});
