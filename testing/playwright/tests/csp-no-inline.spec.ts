/**
 * CSP regression guard — no inline <script>/<style> on the audited page roster.
 *
 * #906. The app ships a strict Content-Security-Policy (`script-src 'self'`,
 * `style-src 'self'` — only `style-src-attr 'unsafe-inline'` is relaxed, so
 * inline `style=` attributes are fine but `<style>` blocks and `<script>`
 * elements without a `src=` are not). A page that introduces an inline
 * `<script>` or `<style>` block silently breaks under that CSP in production —
 * exactly the v3.27.7-class regression this spec exists to catch. All
 * page-served JS/CSS must live in `assets/app.js` / `assets/app.css`.
 *
 * `<script type="application/json">` (and `application/ld+json`) data islands
 * are non-executable data blocks, not subject to `script-src`, so they're
 * exempted. If a real exemption is ever needed (a CSP hash/nonce), widen the
 * regex deliberately and document why — don't add `.fixme`.
 */
import { test, expect } from '@playwright/test';
import { login, appUrl, ADMIN_USER, ADMIN_PASS } from '../fixtures/ipam';

// Authenticated roster + login. Covers the backup/restore admin tabs where the
// v3.27.7 CSP regression actually bit, plus the high-traffic pages.
const AUTHED_PAGES = [
    'dashboard.php',
    'subnets.php',
    'addresses.php',
    'search.php',
    'audit.php',
    'backup_admin.php?tab=destinations',
    'backup_admin.php?tab=restore',
    'backup_admin.php?tab=history',
    'backup_admin.php?tab=notifications',
    'webhooks.php',
    'settings.php?tab=authentication',
    'settings.php?tab=backups',
    'users.php',
    'custom_fields.php',
    'health.php',
];

// inline <script> = a <script ...> element with no src= and a body, excluding
// non-executable JSON data islands (type="application/json" / "application/ld+json").
const INLINE_SCRIPT_RE =
    /<script(?![^>]*\bsrc=)(?![^>]*\btype=["'](?:application\/(?:ld\+)?json)["'])[^>]*>[\s\S]*?<\/script>/i;
const STYLE_BLOCK_RE = /<style[^>]*>[\s\S]*?<\/style>/i;

test.describe('CSP regression guard — no inline <script>/<style> (#906)', () => {
    test('login page is free of inline scripts and style blocks', async ({ page }) => {
        const resp = await page.goto(appUrl('login.php'), { waitUntil: 'domcontentloaded' });
        expect(resp).not.toBeNull();
        const html = await resp!.text();
        expect(INLINE_SCRIPT_RE.test(html), 'login.php has an inline <script> — move it to assets/app.js').toBe(false);
        expect(STYLE_BLOCK_RE.test(html), 'login.php has a <style> block — move it to assets/app.css').toBe(false);
    });

    test('every authenticated page is free of inline scripts and style blocks', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        const offenders: string[] = [];
        for (const path of AUTHED_PAGES) {
            const resp = await page.goto(appUrl(path), { waitUntil: 'domcontentloaded' });
            if (!resp) { offenders.push(`${path}: no response`); continue; }
            const html = await resp.text();
            if (INLINE_SCRIPT_RE.test(html)) offenders.push(`${path}: inline <script>`);
            if (STYLE_BLOCK_RE.test(html)) offenders.push(`${path}: <style> block`);
        }
        expect(offenders, `CSP-incompatible inline blocks found:\n${offenders.join('\n')}`).toEqual([]);
    });
});
