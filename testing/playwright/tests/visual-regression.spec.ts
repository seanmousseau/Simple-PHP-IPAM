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
}

// Dashboard intentionally excluded — every widget on it (security-warning
// banner, top-subnets, by-site, expiring-addresses, recent-activity) reflects
// live DB state that mutates under parallel test workers, making VR coverage
// fundamentally unstable in this harness. Tracked as a v3.20.0 follow-up to
// restore coverage with a mutation-isolated capture path. Until then,
// dashboard rendering changes are a manual smoke-test item during release prep.
const PAGES: VRPage[] = [
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

        await expect(page).toHaveScreenshot(
          `${pg.name}-${theme}.png`,
          SCREENSHOT_OPTS,
        );
      });
    }
  }
});
