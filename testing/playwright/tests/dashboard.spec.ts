/**
 * Dashboard v3.8.0 — KPI cards + uPlot chart (#514).
 * Tests: KPI card count, chart render.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { login, newAuthContext, ADMIN_USER, ADMIN_PASS } from '../fixtures/ipam';

let ctx: BrowserContext;
let page: Page;

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);
  await page.goto('dashboard.php');
  // Wait for page to fully render
  await page.waitForLoadState('networkidle');
});

test.afterAll(async () => {
  await ctx.close();
});

test('KPI grid renders 4 cards', async () => {
  await expect(page.locator('.kpi-card')).toHaveCount(4);
});

test('uPlot chart renders canvas', async () => {
  await expect(page.locator('#growth-chart canvas')).toBeVisible({ timeout: 5000 });
});

test('growth chart shows structured empty state or chart canvas', async () => {
  const chartEl = page.locator('#growth-chart');
  await expect(chartEl).toBeAttached();

  const hasCanvas = await page.locator('#growth-chart canvas').count() > 0;
  const hasEmpty  = await page.locator('#growth-chart .chart-empty').count() > 0;
  expect(hasCanvas || hasEmpty, 'growth chart must render either canvas or .chart-empty').toBe(true);

  if (hasEmpty) {
    await expect(page.locator('#growth-chart .chart-empty svg.icon')).toBeAttached();
    await expect(page.locator('#growth-chart .chart-empty__msg')).toBeAttached();
    await expect(page.locator('#growth-chart .chart-empty__cta')).toHaveAttribute('href', expect.stringContaining('subnets.php'));
  }
});

test('grid.cols-2 shows 1 column at 900px viewport (no sidebar)', async ({ browser }) => {
  const narrowCtx = await browser.newContext({
    viewport: { width: 900, height: 768 },
    ignoreHTTPSErrors: true,
  });
  const p = await narrowCtx.newPage();
  await p.goto('login.php');
  await p.fill('input[name="username"]', 'demo');
  await p.fill('input[name="password"]', 'demo');
  await p.click('button[type="submit"]');
  await p.waitForURL('**/dashboard.php');
  await p.waitForLoadState('networkidle');

  const cols = await p.locator('.grid.cols-2').evaluate((el: Element) =>
    getComputedStyle(el).gridTemplateColumns.trim().split(/\s+/).length
  );
  expect(cols, 'grid.cols-2 must show 1 column at 900px viewport').toBe(1);
  await narrowCtx.close();
});

test('grid.cols-2 shows 2 columns at 1400px viewport (with sidebar)', async ({ browser }) => {
  const wideCtx = await browser.newContext({
    viewport: { width: 1400, height: 900 },
    ignoreHTTPSErrors: true,
  });
  const p = await wideCtx.newPage();
  await p.goto('login.php');
  await p.fill('input[name="username"]', 'demo');
  await p.fill('input[name="password"]', 'demo');
  await p.click('button[type="submit"]');
  await p.waitForURL('**/dashboard.php');
  await p.waitForLoadState('networkidle');

  const cols = await p.locator('.grid.cols-2').first().evaluate((el: Element) =>
    getComputedStyle(el).gridTemplateColumns.trim().split(/\s+/).length
  );
  expect(cols, 'grid.cols-2 must show 2 columns at 1400px viewport').toBe(2);
  await wideCtx.close();
});

// Requires demo seed data — both widgets must have rows to render the table (and wrapper)
test('dashboard widget tables have overflow-x:auto wrapper', async () => {
  await page.goto('dashboard.php');
  await page.waitForLoadState('networkidle');
  const wrappers = await page.locator('[data-widget="top-subnets"] .table-scroll, [data-widget="by-site"] .table-scroll').count();
  expect(wrappers, 'both dashboard widget tables must have .table-scroll overflow wrapper').toBe(2);
});
