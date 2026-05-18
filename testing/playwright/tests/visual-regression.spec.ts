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
// `subnets` was VR-covered v3.26.0–v3.31.0 but is excluded again as of
// v3.32.0 (#1251). When #1206 re-enabled the vr-* projects in CI it surfaced
// that the CI job runs the vr-* step AFTER the main E2E suite, in the same
// job, against a DB the suite has mutated. `subnets.php` lists every subnet,
// so the suite-created rows make the page ~600 px taller than a clean-seed
// baseline (`Expected 1440x3024, received 1440x3626`) — a structural height
// mismatch, not a rendering drift. Restoring coverage needs the same
// mutation-isolated capture path tracked for the dashboard (#1251).
// `search` stays covered — its default view does not grow with suite rows.
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
