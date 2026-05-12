import { test, expect } from '@playwright/test';
import {
  login, appUrl, ensureRoUser, fetchPost, newAuthContext,
  RO_USER, RO_PASS, ADMIN_USER, ADMIN_PASS,
} from '../fixtures/ipam';

/**
 * #1151 (Pass C S-008): the session-fallback API path (GET api.php?resource=
 * contacts|subnet_stats, no API key) must re-check users.is_active before
 * minting the synthetic readonly key. A disabled admin holding a stolen
 * session cookie must not keep read access for the rest of the session
 * lifetime.
 */
test.describe('API session-fallback respects users.is_active (#1151)', () => {
  test('disabled user loses session-fallback API access mid-session', async ({ page, browser }) => {
    // 1. Admin: ensure the readonly test user exists.
    await login(page, ADMIN_USER, ADMIN_PASS);
    await ensureRoUser(page);

    // 2. New context: log in AS pw-readonly, confirm the session-fallback
    //    endpoint works (200) while the account is active.
    const roContext = await newAuthContext(browser);
    const roPage = await roContext.newPage();
    await login(roPage, RO_USER, RO_PASS);
    let r = await roPage.request.get(appUrl('api.php?resource=contacts'));
    expect(r.status(), 'active readonly user should reach the session-fallback endpoint').toBe(200);

    // 3. Admin: disable the pw-readonly account. Resolve its id from the users
    //    table, then POST toggle_active.
    await page.goto('users.php');
    const uid = await page.evaluate((u) => {
      for (const row of Array.from(document.querySelectorAll<HTMLElement>('table tr'))) {
        if (row.textContent?.includes(u)) {
          const m = row.innerHTML.match(/name="id"\s+value="(\d+)"/) || row.innerHTML.match(/data-user-id="(\d+)"/);
          if (m) return Number(m[1]);
        }
      }
      return 0;
    }, RO_USER);
    expect(uid, 'should resolve the pw-readonly user id').toBeGreaterThan(0);
    const dis = await fetchPost(page, appUrl('users.php'), { action: 'toggle_active', id: String(uid) });
    expect(dis.ok, `toggle_active should succeed: ${dis.body}`).toBeTruthy();

    // 4. Same readonly session cookie, account now inactive -> 401.
    r = await roPage.request.get(appUrl('api.php?resource=contacts'));
    expect(r.status(), 'disabled user must lose session-fallback access').toBe(401);

    // 5. Cleanup: re-enable pw-readonly so the seed state is restored.
    await fetchPost(page, appUrl('users.php'), { action: 'toggle_active', id: String(uid) });
    await roContext.close();
  });
});
