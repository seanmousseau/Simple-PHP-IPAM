/**
 * Playwright regression test for #1146 — XHR sudo replay marker must
 * survive page loads where the matching pw-toggle button is NOT
 * present (e.g. step_up.php on an OIDC reauth round-trip), and must
 * be consumed only on the page where the button IS present (the
 * originating settings.php).
 *
 * Background:
 *   #1140 (v3.27.4) shipped a one-shot sessionStorage marker so that an
 *   eye-toggle reveal which 401'd into step_up.php could auto-replay on
 *   return. The first cut consumed the marker unconditionally on every
 *   page load that saw it — including step_up.php itself, which loads
 *   app.js but has no matching pw-toggle button. Net effect: the
 *   marker was eaten on the FIRST hop and gone by the time the user
 *   actually returned to settings.php → silent-drop UX trap returned.
 *
 *   #1146 fix (v3.27.6) moves the removeItem call inside the
 *   `if (replayBtn)` block so the marker survives intermediate pages
 *   and is only consumed on the page where it can do its job.
 *
 * Test strategy:
 *   We don't try to drive a real OIDC round-trip — the test container
 *   doesn't speak Authentik, and step_up.php has its own session-state
 *   side effects that complicate page navigation in CDP. Instead we
 *   test the JS behavior at two levels:
 *
 *     1. Load a page with NO matching pw-toggle button (dashboard.php
 *        is the simplest such page that loads app.js). Set a marker.
 *        Reload. Marker MUST still be present — that's the regression
 *        guard.
 *
 *     2. Load a page WITH a matching pw-toggle button (settings.php
 *        with seeded oidc.client_secret). Set a marker. Reload.
 *        Marker MUST be consumed AND the replay-in-progress flag set.
 *
 *   The second test exercises the consume code that runs DOMContentLoaded
 *   when the matching button is present.
 */

import { test, expect } from '@playwright/test';
import { login, ADMIN_USER, ADMIN_PASS, appUrl, setTestSetting } from '../fixtures/ipam';

test.describe('#1146 XHR sudo replay marker handling', () => {
    test('marker survives a page that loads app.js but has no matching button', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);

        // Dashboard has no pw-toggle button — the consume code's button
        // query will return null on this page.
        await page.goto(appUrl('dashboard.php'));
        await page.waitForLoadState('domcontentloaded');

        // Set a fresh, valid marker.
        await page.evaluate(() => {
            sessionStorage.setItem('ipam_xhr_replay_v1', JSON.stringify({
                type: 'pw_reveal',
                inputId: 'f-k_oidc__client_secret',
                revealKey: 'oidc.client_secret',
                ts: Date.now(),
            }));
        });

        // Reload — app.js's DOMContentLoaded consume code runs again
        // with the marker now in storage. Pre-#1146 it would removeItem
        // unconditionally; post-#1146 it only removes inside the
        // if (replayBtn) block, which won't fire on dashboard.
        await page.goto(appUrl('dashboard.php'));
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(50);

        const marker = await page.evaluate(
            () => sessionStorage.getItem('ipam_xhr_replay_v1'),
        );
        expect(
            marker,
            'marker MUST survive a page that loads app.js but has no matching pw-toggle (#1146)',
        ).not.toBeNull();
        const parsed = JSON.parse(marker ?? '{}');
        expect(parsed.type).toBe('pw_reveal');
        expect(parsed.inputId).toBe('f-k_oidc__client_secret');
    });

    test('marker is consumed on a page with the matching button', async ({ page }) => {
        // The pw-toggle for `oidc.client_secret` only renders when the
        // setting has a stored value (settings_group_form.php conditions
        // the data-pw-reveal-key attribute on `$isSet`). Seed via the
        // allow-listed test helper so the registry's encryption path runs.
        await setTestSetting('oidc.client_secret', 'spec-fixture-secret');

        // Suppress the eye-click navigation that would normally fire after
        // a successful consume. The click triggers fetch(settings_reveal.php),
        // which 401s without a warm sudo grant, and the handler navigates to
        // step_up.php — our assertions can't observe the consume-side state
        // after the navigation. Stub click() to a no-op so the consume's
        // `removeItem` and `__ipamPwReplayInProgress` are observable on the
        // originating page.
        await page.addInitScript(() => {
            const origClick = HTMLButtonElement.prototype.click;
            // Suppress only on pw-toggle buttons inside settings group cards.
            HTMLButtonElement.prototype.click = function(this: HTMLButtonElement) {
                if (this.classList.contains('pw-toggle') && this.getAttribute('data-pw-reveal-key')) {
                    (window as { __ipamPwReplaySuppressedClicks?: number }).__ipamPwReplaySuppressedClicks =
                        ((window as { __ipamPwReplaySuppressedClicks?: number }).__ipamPwReplaySuppressedClicks ?? 0) + 1;
                    return;
                }
                origClick.call(this);
            };
        });

        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(appUrl('settings.php?tab=authentication'));

        // Verify the button is in the DOM with the reveal-key attribute.
        await expect(page.locator(
            'button.pw-toggle[data-pw-toggle-for="f-k_oidc__client_secret"][data-pw-reveal-key="oidc.client_secret"]',
        )).toBeAttached();

        // Set a marker so the consume runs on the next reload with both
        // marker and matching button present.
        await page.evaluate(() => {
            sessionStorage.setItem('ipam_xhr_replay_v1', JSON.stringify({
                type: 'pw_reveal',
                inputId: 'f-k_oidc__client_secret',
                revealKey: 'oidc.client_secret',
                ts: Date.now(),
            }));
        });

        // Reload — consume fires, marker matches button, marker removed,
        // replay flag set, click() called (but suppressed so we can observe).
        await page.goto(appUrl('settings.php?tab=authentication'));
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(100);

        const finalState = await page.evaluate(() => ({
            url: location.href,
            replayInProgress: (window as { __ipamPwReplayInProgress?: boolean }).__ipamPwReplayInProgress,
            suppressedClicks: (window as { __ipamPwReplaySuppressedClicks?: number }).__ipamPwReplaySuppressedClicks,
            marker: sessionStorage.getItem('ipam_xhr_replay_v1'),
        }));

        expect(finalState.marker, 'marker MUST be consumed on a page with the matching button').toBeNull();
        expect(finalState.replayInProgress).toBe(true);
        expect(finalState.suppressedClicks, 'consume should have triggered an eye-toggle click').toBe(1);
    });

    test('expired marker is purged without firing replay', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(appUrl('dashboard.php'));

        // Synthesize a stale marker (older than the 120 s TTL).
        await page.evaluate(() => {
            sessionStorage.setItem('ipam_xhr_replay_v1', JSON.stringify({
                type: 'pw_reveal',
                inputId: 'f-k_oidc__client_secret',
                revealKey: 'oidc.client_secret',
                ts: Date.now() - 200_000,
            }));
        });

        // Reload — consume hits the else-branch (TTL expired) and purges
        // without firing the replay or matching the button.
        await page.goto(appUrl('dashboard.php'));
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(50);

        const marker = await page.evaluate(
            () => sessionStorage.getItem('ipam_xhr_replay_v1'),
        );
        expect(marker).toBeNull();

        const replayInProgress = await page.evaluate(
            () => (window as { __ipamPwReplayInProgress?: boolean }).__ipamPwReplayInProgress,
        );
        expect(replayInProgress).toBeUndefined();
    });
});
