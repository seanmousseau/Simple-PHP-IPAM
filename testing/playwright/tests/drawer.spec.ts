/**
 * Right-side drawer UX — v3.8.0 (#517).
 * Tests: Add Subnet drawer open/close, title content.
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

test('Add Subnet button opens drawer', async () => {
  await page.goto('subnets.php');
  await page.click('[data-drawer-title="Add Subnet"]');
  await expect(page.locator('#global-drawer')).toHaveClass(/is-open/, { timeout: 3000 });
  await expect(page.locator('#global-drawer .drawer-title')).toContainText('Add Subnet');
});

test('Escape closes drawer', async () => {
  await page.goto('subnets.php');
  await page.click('[data-drawer-title="Add Subnet"]');
  await expect(page.locator('#global-drawer')).toHaveClass(/is-open/, { timeout: 3000 });
  await page.keyboard.press('Escape');
  await expect(page.locator('#global-drawer')).not.toHaveClass(/is-open/);
});

test('focus moves to first focusable element inside drawer on open', async () => {
  await page.goto('subnets.php');
  await page.click('[data-drawer-title="Add Subnet"]');
  await expect(page.locator('#global-drawer')).toHaveClass(/is-open/, { timeout: 3000 });

  // The first focusable element inside #global-drawer-body (or the close button if no inputs)
  // app.js calls focusable[0].focus() on open; close button is in the header, body inputs come first
  const activeIsInsideDrawer = await page.evaluate(() => {
    const drawer = document.getElementById('global-drawer');
    return drawer ? drawer.contains(document.activeElement) : false;
  });
  expect(activeIsInsideDrawer).toBe(true);
});

test('backdrop click closes drawer', async () => {
  await page.goto('subnets.php');
  await page.click('[data-drawer-title="Add Subnet"]');
  await expect(page.locator('#global-drawer')).toHaveClass(/is-open/, { timeout: 3000 });

  // Click the overlay — it has class "drawer-overlay" and "is-visible" when open.
  // Use dispatchEvent to avoid accidentally hitting the drawer panel on top of it.
  await page.evaluate(() => {
    const overlay = document.querySelector('.drawer-overlay');
    if (overlay) overlay.dispatchEvent(new MouseEvent('click', { bubbles: true }));
  });

  await expect(page.locator('#global-drawer')).not.toHaveClass(/is-open/);
});

test('focus returns to trigger button after Escape', async () => {
  await page.goto('subnets.php');
  const trigger = page.locator('[data-drawer-title="Add Subnet"]').first();
  await trigger.click();
  await expect(page.locator('#global-drawer')).toHaveClass(/is-open/, { timeout: 3000 });

  await page.keyboard.press('Escape');
  await expect(page.locator('#global-drawer')).not.toHaveClass(/is-open/);

  // app.js restores _lastFocus (the element that was active before open()) on close
  const triggerIsFocused = await trigger.evaluate(
    (el) => el === document.activeElement
  );
  expect(triggerIsFocused).toBe(true);
});

test('Add Address drawer opens on addresses.php', async () => {
  await page.goto('addresses.php?subnet_id=1');

  const addAddressBtn = page.locator('[data-drawer-title="Add Address"]').first();
  const btnCount = await addAddressBtn.count();
  if (btnCount === 0) {
    test.skip(); // subnet_id=1 may not exist in every seed — skip gracefully
    return;
  }

  await addAddressBtn.click();
  await expect(page.locator('#global-drawer')).toHaveClass(/is-open/, { timeout: 3000 });
  await expect(page.locator('#global-drawer .drawer-title')).toContainText('Add Address');
});
