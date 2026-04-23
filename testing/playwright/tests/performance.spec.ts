/**
 * Performance assertions — v3.8.0 (#528).
 * Tests: dashboard LCP, uPlot chart render time.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { login, newAuthContext, ADMIN_USER, ADMIN_PASS } from '../fixtures/ipam';

let ctx: BrowserContext;
let page: Page;

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);
});

test.afterAll(async () => {
  await ctx.close();
});

test('dashboard LCP under 3s', async () => {
  // Wall-clock includes PHP bootstrap + cold-cache SQLite startup on CI containers.
  const startTime = Date.now();
  await page.goto('dashboard.php');
  await page.locator('.kpi-card').first().waitFor({ state: 'visible' });
  expect(Date.now() - startTime).toBeLessThan(3000);
});

test('uPlot chart renders under 500ms', async () => {
  await page.goto('dashboard.php');
  const renderTime = await page.evaluate(function() {
    return new Promise<number>(function(resolve, reject) {
      var t0 = performance.now();
      var deadline = setTimeout(function() {
        reject(new Error('#growth-chart canvas never appeared within 5000ms'));
      }, 5000);
      function check() {
        var canvas = document.querySelector('#growth-chart canvas');
        if (canvas) { clearTimeout(deadline); resolve(performance.now() - t0); return; }
        requestAnimationFrame(check);
      }
      requestAnimationFrame(check);
    });
  });
  expect(renderTime).toBeLessThan(500);
});
