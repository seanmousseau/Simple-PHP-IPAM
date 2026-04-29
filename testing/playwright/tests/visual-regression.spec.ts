/**
 * Visual regression baseline — v2.12.0 lock.
 *
 * Captures screenshots of 5 key pages in both light and dark themes.
 * Run via the vr-* projects (vr-1440, vr-1024, vr-768, vr-375) which
 * set fixed viewports and 1x device scale for deterministic captures.
 *
 * Usage:
 *   npx playwright test visual-regression --project=vr-1440
 *   npx playwright test visual-regression           # all 4 viewports
 *
 * To update baselines after an intentional visual change:
 *   npx playwright test visual-regression --update-snapshots
 *
 * Baselines are platform-specific (Playwright appends -darwin/-linux).
 * Generate Linux baselines in CI with --update-snapshots on the first run,
 * then commit both sets. Local dev captures macOS baselines.
 */
import { test, expect } from '@playwright/test';
import { login, ADMIN_USER, ADMIN_PASS } from '../fixtures/ipam';

interface VRPage {
  name: string;
  path: string;
  skipAuth?: boolean;
  // CSS selectors hidden via display:none before screenshot capture.
  // Use for sections whose content AND height vary between runs
  // (e.g. audit log rows where the `details` column wraps differently
  // per row, making the rendered widget height non-deterministic). The
  // Playwright `mask` option paints a fixed-size pink box and breaks
  // when the masked region changes size; display:none removes the
  // region from layout entirely so the rest of the page renders the
  // same regardless of what would have been there.
  hideSelectors?: string[];
}

const PAGES: VRPage[] = [
  // Recent Activity card shows the 10 newest audit_log rows. The `details`
  // column wraps to a variable number of lines per row, so the widget's
  // rendered height shifts between runs. Hide it entirely to keep the
  // rest of the dashboard a meaningful regression target.
  { name: 'dashboard', path: 'dashboard.php',
    hideSelectors: ['[data-widget="recent-activity"]'] },
  { name: 'subnets', path: 'subnets.php' },
  { name: 'addresses', path: 'addresses.php' },
  { name: 'search', path: 'search.php?q=10' },
  { name: 'login', path: 'login.php', skipAuth: true },
];

const THEMES = ['light', 'dark'] as const;

const SCREENSHOT_OPTS = {
  maxDiffPixelRatio: 0.01,
  threshold: 0.2,
  fullPage: true,
};

async function setTheme(page: any, theme: 'light' | 'dark') {
  await page.evaluate((t: string) => {
    document.documentElement.setAttribute('data-theme', t);
  }, theme);
  await page.waitForTimeout(100);
}

test.describe('visual regression baseline', () => {
  test.beforeAll(async () => {
    test.setTimeout(120_000);
  });

  for (const theme of THEMES) {
    for (const pg of PAGES) {
      test(`${pg.name} — ${theme}`, async ({ page }) => {
        test.setTimeout(30_000);

        // Suppress animations for deterministic screenshots
        await page.emulateMedia({ reducedMotion: 'reduce' });

        if (pg.skipAuth) {
          await page.goto(pg.path);
        } else {
          await login(page, ADMIN_USER, ADMIN_PASS);
          await page.goto(pg.path);
        }

        await page.waitForLoadState('networkidle');
        await setTheme(page, theme);

        if (pg.hideSelectors && pg.hideSelectors.length > 0) {
          // The app has `style-src 'self'` so addStyleTag is blocked. Remove
          // the volatile element from the DOM instead — page.evaluate runs
          // script that's already CSP-cleared (script-src 'self' + nonce).
          await page.evaluate((selectors: string[]) => {
            for (const sel of selectors) {
              document.querySelectorAll(sel).forEach((el) => el.remove());
            }
          }, pg.hideSelectors);
        }
        await expect(page).toHaveScreenshot(
          `${pg.name}-${theme}.png`,
          SCREENSHOT_OPTS,
        );
      });
    }
  }
});
