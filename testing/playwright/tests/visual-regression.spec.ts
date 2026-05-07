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
//
// `subnets` and `search` re-added in v3.26.0 (#1091) after bisecting the
// 200–470 px PostgreSQL drift reported in #1073. Root-cause investigation
// in v3.26.0 D3 found the rendered HTML byte-identical (modulo CSRF +
// timestamps) and the api.php?resource=subnet_stats payload byte-identical
// across drivers, so the historical drift no longer reproduces. The most
// likely root cause was a transient JS-fill timing race between the
// page-load `networkidle` waiter and the async subnet-stats fetch, which
// has since become deterministic enough on both drivers to pass the
// 1%-pixel threshold. If drift returns, restore the exclusion comment
// here and file a fresh issue with the new diff.
//
// Backup & Restore tabs (#1040, v3.21.0):
//   - Notifications + Restore (Step 1) are captured below — both are
//     static-only views (no data tables, no live counters) so VR is stable.
//   - Backup, Destinations, History tabs deferred for the same reason as
//     the dashboard: they render destination/schedule/history rows that
//     other tests create and tear down. Re-evaluate once the dashboard
//     mutation-isolation work lands.
const PAGES: VRPage[] = [
  { name: 'addresses', path: 'addresses.php' },
  { name: 'subnets',   path: 'subnets.php' },
  { name: 'search',    path: 'search.php' },
  { name: 'login', path: 'login.php', skipAuth: true },
  { name: 'backup-admin-notifications', path: 'backup_admin.php?tab=notifications' },
  { name: 'backup-admin-restore', path: 'backup_admin.php?tab=restore' },
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
