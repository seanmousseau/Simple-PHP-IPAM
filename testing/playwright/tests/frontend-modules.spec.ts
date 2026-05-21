/**
 * Frontend modularization regression guard (#939 + #1047).
 *
 * v3.34.0 split the former monolithic `assets/app.js` into 46 per-concern
 * modules under `assets/modules/*.js`, each loaded as its own
 * `<script defer>` tag. Browsers execute deferred scripts in DOM order, so
 * emit order = run order; the numeric/letter filename prefix encodes that
 * order. This spec pins three invariants that the split would silently
 * break if regressed:
 *
 *   4.1  Module-load-order — every page in the audited roster emits the
 *        canonical module list, in canonical order, and emits NO references
 *        to the retired `assets/app.js` or transitional `_monolith.js`.
 *
 *   4.2  Cross-module `window.*` namespace contract — after
 *        DOMContentLoaded, the documented globals exist with the documented
 *        shape; no surprise `Ipam*` globals leaked into window.
 *
 *   4.3  `prefers-reduced-motion` smoke — under the `reduce` emulation,
 *        the documented animation guards in `assets/app.css` are honoured
 *        (existing CSS-level @media block at app.css:1413; this just pins
 *        it to a Playwright run so a future regression doesn't slip past).
 */
import { test, expect } from '@playwright/test';
import { login, appUrl, ADMIN_USER, ADMIN_PASS } from '../fixtures/ipam';

// Canonical module order. Numeric/letter prefix dictates `<script defer>`
// emit order in lib/presentation.php and demo_gate.php. Keep this list
// in sync with those two emit sites — drift here means real drift in what
// ships to the browser.
const EXPECTED_MODULES: string[] = [
    '00-bootstrap',
    '10-theme-banner',
    '15-site-group-collapse',
    '20-drawer',
    '25-ping-shortcuts',
    '30-forms-core',
    '35-forms-confirm-bulk',
    '40-search-validation',
    '50-sidebar',
    '60-fill-ip-spinners',
    '65-contact-typeahead',
    '70-dashboard-prefs',
    '75-subnet-addr-grids',
    '80-command-palette',
    '81-tooltips',
    '82-audit-expand',
    '83-subnet-edit-drawer',
    '84-subnet-stats',
    '85-contact-browse',
    '86-contact-card',
    '87-contact-picker',
    '90-dhcp-export',
    '91-custom-fields-preview',
    '92-totp-verify-toggle',
    '93-smtp-test',
    '95-backups-modals',
    '96-totp-enroll-qr',
    '97-uplot-chart',
    '98-backup-history-actions',
    '99-subnets-site-filter',
    'b0-addresses-bulk-bar',
    'b1-addresses-site-cascade',
    'b2-webhooks-page',
    'b3-addresses-device-cascade',
    'b4-collapsible-rows',
    'b5-passkey-verify',
    'b6-step-up-prompt',
    'b7-passkey-register',
    'b8-settings-anchor-redirect',
    'b9-settings-rail-nav',
    'c0-destinations-admin',
    'c1-restore-confirm-typing',
    'c2-remote-backups-delete',
    'c3-destinations-verify-all',
    'c4-skeleton-toggle',
    'c5-sudo-replay-resume',
];

// Representative roster of authenticated surfaces.
const AUTHED_PAGES = [
    'dashboard.php',
    'subnets.php',
    'addresses.php',
    'settings.php?tab=authentication',
    'backup_admin.php?tab=destinations',
    'change_password.php',
];

// Match `<script defer src='assets/modules/<name>.js?v=…'></script>` and the
// double-quoted variant. Captures the module name. Ordered scan against
// EXPECTED_MODULES gives presence + order in one pass.
const MODULE_SCRIPT_RE =
    /<script\b[^>]*\bdefer\b[^>]*\bsrc=["']assets\/modules\/([a-z0-9-]+)\.js\?v=[^"']*["'][^>]*>\s*<\/script>/gi;

// Retired references that must not reappear anywhere in the rendered HTML.
const RETIRED_RE = /assets\/(app\.js|modules\/_monolith\.js)\b/i;

function extractModuleSequence(html: string): string[] {
    const out: string[] = [];
    MODULE_SCRIPT_RE.lastIndex = 0;
    let m: RegExpExecArray | null;
    while ((m = MODULE_SCRIPT_RE.exec(html)) !== null) {
        out.push(m[1]);
    }
    return out;
}

test.describe('Frontend modules (#939 + #1047)', () => {

    // ── 4.1 Module-load-order ─────────────────────────────────────────────

    test('login page emits the canonical module set in order', async ({ page }) => {
        const resp = await page.goto(appUrl('login.php'), { waitUntil: 'domcontentloaded' });
        expect(resp).not.toBeNull();
        const html = await resp!.text();
        const seq = extractModuleSequence(html);
        expect(seq, 'login.php must emit every documented module').toEqual(EXPECTED_MODULES);
        expect(
            RETIRED_RE.test(html),
            'login.php must not reference the retired assets/app.js or _monolith.js'
        ).toBe(false);
    });

    test('every authenticated page emits the canonical module set in order', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        const offenders: string[] = [];
        for (const path of AUTHED_PAGES) {
            const resp = await page.goto(appUrl(path), { waitUntil: 'domcontentloaded' });
            if (!resp) { offenders.push(`${path}: no response`); continue; }
            if (!resp.ok()) { offenders.push(`${path}: HTTP ${resp.status()}`); continue; }
            // Guard against a silent auth-redirect masquerading as success:
            // every authenticated page in the roster must land on its own
            // URL, NOT on login.php. Without this check, an expired session
            // would 302 → login.php and the canonical module list (which
            // login.php also emits) would still satisfy the order assertion.
            const finalPath = new URL(resp.url()).pathname;
            if (finalPath.endsWith('/login.php')) {
                offenders.push(`${path}: redirected to login.php (auth lost)`);
                continue;
            }
            const html = await resp.text();
            const seq = extractModuleSequence(html);
            if (seq.length !== EXPECTED_MODULES.length) {
                offenders.push(`${path}: emitted ${seq.length} modules, expected ${EXPECTED_MODULES.length}`);
            } else {
                for (let i = 0; i < EXPECTED_MODULES.length; i++) {
                    if (seq[i] !== EXPECTED_MODULES[i]) {
                        offenders.push(`${path}: position ${i} is "${seq[i]}", expected "${EXPECTED_MODULES[i]}"`);
                        break;
                    }
                }
            }
            if (RETIRED_RE.test(html)) {
                offenders.push(`${path}: references retired assets/app.js or _monolith.js`);
            }
        }
        expect(offenders, `module emit drift:\n  ${offenders.join('\n  ')}`).toEqual([]);
    });

    // ── 4.2 Cross-module window.* namespace contract ──────────────────────

    test('window.IpamDrawer is defined with the documented surface after DOMContentLoaded', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(appUrl('dashboard.php'), { waitUntil: 'domcontentloaded' });
        const surface = await page.evaluate(() => {
            const w = window as unknown as Record<string, unknown>;
            const drawer = w.IpamDrawer as Record<string, unknown> | undefined;
            return {
                hasDrawer:   typeof drawer === 'object' && drawer !== null,
                hasOpen:     drawer ? typeof drawer.open     === 'function' : false,
                hasOpenNode: drawer ? typeof drawer.openNode === 'function' : false,
                hasClose:    drawer ? typeof drawer.close    === 'function' : false,
            };
        });
        expect(surface.hasDrawer,   'window.IpamDrawer must exist (20-drawer.js)').toBe(true);
        expect(surface.hasOpen,     'window.IpamDrawer.open must be a function').toBe(true);
        expect(surface.hasOpenNode, 'window.IpamDrawer.openNode must be a function').toBe(true);
        expect(surface.hasClose,    'window.IpamDrawer.close must be a function').toBe(true);
    });

    test('no surprise Ipam* globals leaked onto window', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(appUrl('dashboard.php'), { waitUntil: 'domcontentloaded' });
        const unexpected = await page.evaluate(() => {
            // Documented surface: window.IpamDrawer (20-drawer.js) and
            // window.ipamSkeleton (c4-skeleton-toggle.js, lazy — only set
            // when the skeleton helper is first used). Anything else
            // starting with "Ipam" or "ipam" at top-level is a leak.
            const ALLOWED = new Set(['IpamDrawer', 'ipamSkeleton']);
            const leaks: string[] = [];
            for (const key of Object.keys(window)) {
                if (!ALLOWED.has(key) && /^[Ii]pam/.test(key)) {
                    leaks.push(key);
                }
            }
            return leaks;
        });
        expect(unexpected, `undocumented Ipam* globals on window: ${unexpected.join(', ')}`).toEqual([]);
    });

    // ── 4.3 prefers-reduced-motion smoke ──────────────────────────────────

    test('prefers-reduced-motion: reduce flattens the global animation-duration', async ({ browser }) => {
        // CSS rule at app.css:1413 sets animation-duration:0.01ms on
        // *,*::before,*::after under @media(prefers-reduced-motion:reduce).
        // Assert computed style on <body> reflects it — flat smoke that
        // catches a future edit accidentally removing the rule or pushing
        // it behind a more-specific selector.
        const ctx = await browser.newContext({
            reducedMotion: 'reduce',
            ignoreHTTPSErrors: true,
            httpCredentials: process.env.IPAM_BASIC_USER
                ? { username: process.env.IPAM_BASIC_USER, password: process.env.IPAM_BASIC_PASS || '' }
                : undefined,
        });
        const page = await ctx.newPage();
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(appUrl('dashboard.php'), { waitUntil: 'domcontentloaded' });
        const duration = await page.evaluate(() =>
            window.getComputedStyle(document.body).animationDuration
        );
        // The CSS rule sets `0.01ms`. Browsers report the resolved value in
        // seconds; Chromium normalises tiny values to scientific notation
        // (`1e-05s`). Compare as parsed seconds rather than asserting the
        // raw string — the contract is "must be the 0.01ms ceiling, not 0s
        // (rule missing) and not a larger user-facing duration".
        const seconds = duration.endsWith('ms')
            ? parseFloat(duration) / 1000
            : parseFloat(duration);
        expect(
            seconds,
            `prefers-reduced-motion: reduce must apply the app.css:1413 flatten rule (got "${duration}")`
        ).toBeLessThanOrEqual(0.00001);
        expect(
            seconds,
            'prefers-reduced-motion rule appears missing — body animation-duration resolved to 0'
        ).toBeGreaterThan(0);
        await ctx.close();
    });
});
