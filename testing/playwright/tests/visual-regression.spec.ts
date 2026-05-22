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
  /** Optional Playwright grep tag (e.g. '@vr-dashboard'). When set the test
   *  title includes this tag so a project's `grep` option can select or
   *  de-select it without touching any other project's test list. */
  tag?: string;
}

// `subnets` was VR-covered v3.26.0–v3.31.0 but is excluded again as of
// v3.32.0 (#1251). When #1206 re-enabled the vr-* projects in CI it surfaced
// that the CI job runs the vr-* step AFTER the main E2E suite, in the same
// job, against a DB the suite has mutated. `subnets.php` lists every subnet,
// so the suite-created rows make the page ~600 px taller than a clean-seed
// baseline (`Expected 1440x3024, received 1440x3626`) — a structural height
// mismatch, not a rendering drift. Restoring coverage needs the same
// mutation-isolated capture path tracked for the dashboard.
// `search` stays covered — its default view does not grow with suite rows.
//
// Backup & Restore tabs (#1040, v3.21.0):
//   - Notifications + Restore (Step 1) are captured below — both are
//     static-only views (no data tables, no live counters) so VR is stable.
//   - Backup, Destinations, History tabs deferred for the same reason as
//     the dashboard: they render destination/schedule/history rows that
//     other tests create and tear down. Re-evaluate once the dashboard
//     mutation-isolation work lands.
//
// Dashboard (#775, v3.35.0):
//   Restored with a mutation-isolated capture path. The `@vr-dashboard` tag
//   routes this page to the `vr-dashboard-*` projects in playwright.config.ts,
//   which declare a `vr-dashboard-setup` dependency that re-bootstraps the app
//   against a freshly-seeded DB before any screenshot is taken. The standard
//   `vr-*` projects exclude tagged tests (grep inverse), so the dashboard is
//   never captured against a mutated DB.
const PAGES: VRPage[] = [
  { name: 'addresses', path: 'addresses.php' },
  { name: 'search',    path: 'search.php' },
  { name: 'login', path: 'login.php', skipAuth: true },
  { name: 'backup-admin-notifications', path: 'backup_admin.php?tab=notifications' },
  { name: 'backup-admin-restore', path: 'backup_admin.php?tab=restore' },
  // Dashboard: mutation-isolated via dedicated vr-dashboard-* projects (#775).
  { name: 'dashboard', path: 'dashboard.php', tag: '@vr-dashboard' },
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
      // Include the page's grep tag (if any) in the test title so Playwright
      // project-level `grep` options can select or exclude it. For example,
      // vr-dashboard-* projects pass `grep: /@vr-dashboard/` to capture only
      // the dashboard, while standard vr-* projects pass
      // `grepInvert: /@vr-dashboard/` to skip it — all without modifying PAGES.
      const title = pg.tag
        ? `${pg.name} — ${theme} ${pg.tag}`
        : `${pg.name} — ${theme}`;

      test(title, async ({ page }) => {
        test.setTimeout(30_000);

        // Suppress animations for deterministic screenshots
        await page.emulateMedia({ reducedMotion: 'reduce' });

        if (pg.skipAuth) {
          await page.goto(pg.path);
        } else {
          // vr-dashboard-* projects inject storageState at the project level
          // (playwright.config.ts), so the browser context is already
          // authenticated. For non-dashboard pages the context is fresh and
          // we log in here as before.
          if (!pg.tag) {
            await login(page, ADMIN_USER, ADMIN_PASS);
          }
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
