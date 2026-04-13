/**
 * Tooltip system (#354) — verifies [data-tooltip] elements actually appear on hover.
 *
 * Tests:
 * - Hovering a [data-tooltip] element shows #ipam-tooltip with correct text
 * - Moving mouse away hides the tooltip
 * - Tooltip renders on multiple admin pages
 * - No JS errors during hover
 *
 * Note: the tooltip is a JS-rendered #ipam-tooltip div at position:fixed (not CSS
 * ::before/::after), so we assert on that element's visibility, not computed pseudo-
 * element styles.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { login, appUrl, ADMIN_USER, ADMIN_PASS, newAuthContext } from '../fixtures/ipam';

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

test('hovering [data-tooltip] shows #ipam-tooltip with correct text', async () => {
  await page.goto(appUrl('vrfs.php'));
  const anchor = page.locator('[data-tooltip]').first();
  await expect(anchor).toBeVisible();

  const expectedText = await anchor.getAttribute('data-tooltip');
  expect(expectedText).toBeTruthy();

  // Hover — JS should show the tooltip div
  await anchor.hover();
  const tooltip = page.locator('#ipam-tooltip');
  await expect(tooltip).toBeVisible({ timeout: 1000 });
  await expect(tooltip).toContainText(expectedText!);
});

test('moving mouse away hides #ipam-tooltip', async () => {
  await page.goto(appUrl('vrfs.php'));
  const anchor = page.locator('[data-tooltip]').first();
  await anchor.hover();
  await expect(page.locator('#ipam-tooltip')).toBeVisible({ timeout: 1000 });

  // Move to a neutral element
  await page.locator('h1').hover();
  await expect(page.locator('#ipam-tooltip')).not.toBeVisible();
});

test('tooltip is positioned within viewport (not clipped)', async () => {
  await page.goto(appUrl('vrfs.php'));
  const anchor = page.locator('[data-tooltip]').first();
  await anchor.hover();
  await expect(page.locator('#ipam-tooltip')).toBeVisible({ timeout: 1000 });

  const box = await page.locator('#ipam-tooltip').boundingBox();
  expect(box).not.toBeNull();
  const vw = page.viewportSize()!.width;
  expect(box!.x).toBeGreaterThanOrEqual(0);
  expect(box!.x + box!.width).toBeLessThanOrEqual(vw + 1); // +1 for sub-pixel
});

test('aggregates.php — tooltip visible on hover', async () => {
  await page.goto(appUrl('aggregates.php'));
  const anchor = page.locator('[data-tooltip]').first();
  if (await anchor.count() === 0) return; // no tooltips on this page yet
  await anchor.hover();
  await expect(page.locator('#ipam-tooltip')).toBeVisible({ timeout: 1000 });
});

test('pd_pools.php — tooltip visible on hover', async () => {
  await page.goto(appUrl('pd_pools.php'));
  const anchor = page.locator('[data-tooltip]').first();
  if (await anchor.count() === 0) return;
  await anchor.hover();
  await expect(page.locator('#ipam-tooltip')).toBeVisible({ timeout: 1000 });
});

test('no JS errors during tooltip interactions', async () => {
  const errors: string[] = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.goto(appUrl('vrfs.php'));
  const els = await page.locator('[data-tooltip]').all();
  for (const el of els) {
    await el.hover({ force: true }).catch(() => {/* ignore unreachable elements */});
  }
  await page.locator('h1').hover(); // move away to trigger mouseleave
  expect(errors.filter(e => !e.includes('ResizeObserver'))).toHaveLength(0);
});
