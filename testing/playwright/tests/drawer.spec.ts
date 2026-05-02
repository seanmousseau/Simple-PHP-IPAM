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

// ── Focus-trap cycling (#1040, v3.21.0) ─────────────────────────────────────────
//
// app.js _trapFocus wraps Tab/Shift+Tab inside #global-drawer:
//   Tab on last focusable      → first focusable
//   Shift+Tab on first focusable → last focusable
// These tests exercise the wrap-around behavior on the Add Subnet drawer
// (representative; the trap is shared across every drawer instance).

/** Returns the list of focusable elements inside #global-drawer, in DOM order. */
async function drawerFocusables(p: Page): Promise<string[]> {
    return p.evaluate(() => {
        const drawer = document.getElementById('global-drawer');
        if (!drawer) return [];
        const sel = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
        const out: string[] = [];
        drawer.querySelectorAll(sel).forEach((el) => {
            // Cheap stable identifier per element: tag + (id|name|aria-label|class).
            const e = el as HTMLElement;
            const tag = e.tagName.toLowerCase();
            const ident = e.id
                ? `#${e.id}`
                : e.getAttribute('name')
                    ? `[name=${e.getAttribute('name')}]`
                    : e.getAttribute('aria-label')
                        ? `[aria-label=${e.getAttribute('aria-label')}]`
                        : `.${e.className.split(/\s+/).filter(Boolean).join('.')}`;
            out.push(`${tag}${ident}`);
        });
        return out;
    });
}

/** Returns a stable identifier for the element currently holding focus. */
async function activeIdent(p: Page): Promise<string> {
    return p.evaluate(() => {
        const e = document.activeElement as HTMLElement | null;
        if (!e) return '<none>';
        const tag = e.tagName.toLowerCase();
        if (e.id) return `${tag}#${e.id}`;
        const name = e.getAttribute('name');
        if (name) return `${tag}[name=${name}]`;
        const aria = e.getAttribute('aria-label');
        if (aria) return `${tag}[aria-label=${aria}]`;
        return `${tag}.${e.className.split(/\s+/).filter(Boolean).join('.')}`;
    });
}

test.describe('drawer focus-trap cycling (#1040)', () => {

    test('Tab from last focusable wraps to first', async () => {
        await page.goto('subnets.php');
        await page.click('[data-drawer-title="Add Subnet"]');
        await expect(page.locator('#global-drawer')).toHaveClass(/is-open/, { timeout: 3000 });

        const focusables = await drawerFocusables(page);
        expect(focusables.length).toBeGreaterThan(1);

        // Move focus to the last focusable element via JS (avoids depending on
        // the actual tab-stop count, which varies with the form's field set).
        await page.evaluate(() => {
            const drawer = document.getElementById('global-drawer')!;
            const sel = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
            const list = drawer.querySelectorAll(sel);
            (list[list.length - 1] as HTMLElement).focus();
        });

        // Confirm we landed on the last element, then Tab should wrap to first.
        const beforeTab = await activeIdent(page);
        expect(beforeTab).toBe(focusables[focusables.length - 1]);

        await page.keyboard.press('Tab');
        const afterTab = await activeIdent(page);
        expect(afterTab).toBe(focusables[0]);
    });

    test('Shift+Tab from first focusable wraps to last', async () => {
        await page.goto('subnets.php');
        await page.click('[data-drawer-title="Add Subnet"]');
        await expect(page.locator('#global-drawer')).toHaveClass(/is-open/, { timeout: 3000 });

        const focusables = await drawerFocusables(page);
        expect(focusables.length).toBeGreaterThan(1);

        // Open() already lands on the first focusable; assert and Shift+Tab.
        const beforeTab = await activeIdent(page);
        expect(beforeTab).toBe(focusables[0]);

        await page.keyboard.press('Shift+Tab');
        const afterTab = await activeIdent(page);
        expect(afterTab).toBe(focusables[focusables.length - 1]);
    });

    test('Tab from a middle focusable advances naturally (no wrap)', async () => {
        await page.goto('subnets.php');
        await page.click('[data-drawer-title="Add Subnet"]');
        await expect(page.locator('#global-drawer')).toHaveClass(/is-open/, { timeout: 3000 });

        const focusables = await drawerFocusables(page);
        if (focusables.length < 3) {
            test.skip(); // Need at least 3 to have a meaningful "middle".
            return;
        }

        // Focus the second-from-last element; one Tab should land on the last,
        // not wrap. This proves _trapFocus only intervenes at the boundaries.
        await page.evaluate(() => {
            const drawer = document.getElementById('global-drawer')!;
            const sel = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
            const list = drawer.querySelectorAll(sel);
            (list[list.length - 2] as HTMLElement).focus();
        });

        await page.keyboard.press('Tab');
        const after = await activeIdent(page);
        expect(after).toBe(focusables[focusables.length - 1]);
    });

    test('focus stays inside drawer across many Tab presses', async () => {
        // Belt-and-braces: Tab through all focusables + a few extras and assert
        // focus never escapes the drawer container.
        await page.goto('subnets.php');
        await page.click('[data-drawer-title="Add Subnet"]');
        await expect(page.locator('#global-drawer')).toHaveClass(/is-open/, { timeout: 3000 });

        const focusables = await drawerFocusables(page);
        const presses = focusables.length + 3;
        for (let i = 0; i < presses; i++) {
            await page.keyboard.press('Tab');
            const inside = await page.evaluate(() => {
                const d = document.getElementById('global-drawer');
                return d ? d.contains(document.activeElement) : false;
            });
            expect(inside, `focus escaped drawer after Tab #${i + 1}`).toBe(true);
        }
    });
});
