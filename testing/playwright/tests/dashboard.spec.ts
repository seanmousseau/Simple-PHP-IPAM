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
