/**
 * Smoke tests for the a11y testing infrastructure.
 * Verifies that axe-core, keyboard-walk, and form helpers work correctly.
 */
import { test, expect } from '@playwright/test';
import { login, ADMIN_USER, ADMIN_PASS } from '../fixtures/ipam';
import { expectNoA11yViolations } from '../fixtures/a11y';
import { walkFocusableElements } from '../fixtures/keyboard-walk';

test.describe('a11y infrastructure smoke tests', () => {
  test('axe-core runs against login page without crashing', async ({ page }) => {
    await page.goto('login.php');
    await page.waitForLoadState('networkidle');

    // This may find violations on the pre-v2.13.0 codebase — that's expected.
    // The point is that the axe fixture runs without errors.
    // Use disableRules to suppress known pre-v2.13.0 failures.
    await expectNoA11yViolations(page, {
      disableRules: [
        'color-contrast',
        'landmark-one-main',
        'page-has-heading-one',
        'region',
        'html-has-lang',
        'aria-dialog-name',
        'aria-hidden-focus',
        'link-in-text-block',
        'link-name',
        'select-name',
      ],
    });
  });

  test('keyboard-walk returns focus stops on login page', async ({ page }) => {
    await page.goto('login.php');
    await page.waitForLoadState('networkidle');

    const stops = await walkFocusableElements(page, 20);
    expect(stops.length).toBeGreaterThan(0);

    // Login page should have at least: username, password, submit button
    const tags = stops.map(s => s.tagName);
    expect(tags).toContain('input');
    expect(tags).toContain('button');
  });

  test('keyboard-walk returns focus stops on dashboard', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto('dashboard.php');
    await page.waitForLoadState('networkidle');

    const stops = await walkFocusableElements(page);
    expect(stops.length).toBeGreaterThan(3);

    // Every stop should have a bounding box (visible on screen)
    for (const stop of stops) {
      expect(stop.boundingBox).not.toBeNull();
    }
  });

  test('axe-core runs against dashboard without crashing', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto('dashboard.php');
    await page.waitForLoadState('networkidle');

    await expectNoA11yViolations(page, {
      disableRules: [
        'color-contrast',
        'landmark-one-main',
        'page-has-heading-one',
        'region',
        'html-has-lang',
        'aria-dialog-name',
        'aria-hidden-focus',
        'link-in-text-block',
        'link-name',
        'select-name',
      ],
    });
  });
});
