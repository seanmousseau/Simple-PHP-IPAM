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
        'page-has-heading-one',
        'region',
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
        'page-has-heading-one',
        'region',
        'aria-dialog-name',
        'aria-hidden-focus',
        'link-in-text-block',
        'link-name',
        'select-name',
      ],
    });
  });
});

test.describe('Focus ring regression guard', () => {
  for (const slug of ['dashboard.php', 'subnets.php', 'users.php']) {
    test(`${slug}: interactive elements have visible focus rings`, async ({ page }) => {
      await login(page, ADMIN_USER, ADMIN_PASS);
      await page.goto(slug);
      await page.waitForLoadState('networkidle');

      const violations = await page.evaluate(() => {
        const interactive = Array.from(document.querySelectorAll(
          'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled])'
        )).slice(0, 20);

        return interactive.filter(el => {
          (el as HTMLElement).focus();
          const style = window.getComputedStyle(el as HTMLElement);
          const outline = style.outlineWidth;
          const shadow  = style.boxShadow;
          return parseFloat(outline) === 0 && shadow === 'none';
        }).map(el => `${el.tagName}[${(el as HTMLElement).className}]`);
      });

      expect(
        violations,
        `${slug}: ${violations.length} interactive element(s) have no visible focus ring:\n  ${violations.join('\n  ')}`
      ).toHaveLength(0);
    });
  }
});

test.describe('Aria label guard — icon-only buttons', () => {
  for (const slug of ['dashboard.php', 'subnets.php', 'webhooks.php']) {
    test(`${slug}: icon-only buttons have aria-label`, async ({ page }) => {
      await login(page, ADMIN_USER, ADMIN_PASS);
      await page.goto(slug);
      await page.waitForLoadState('networkidle');

      const violations = await page.evaluate(() => {
        return Array.from(document.querySelectorAll('button'))
          .filter(btn => {
            const text = btn.textContent?.replace(/\s/g, '') || '';
            const hasSvg = btn.querySelector('svg') !== null;
            const noText = text.length === 0 || (hasSvg && text.length < 3);
            return noText && !btn.hasAttribute('aria-label') && !btn.hasAttribute('aria-labelledby') && !btn.hasAttribute('title');
          })
          .map(btn => btn.outerHTML.substring(0, 100));
      });

      expect(
        violations,
        `${slug}: icon-only button(s) missing aria-label:\n  ${violations.join('\n  ')}`
      ).toHaveLength(0);
    });
  }
});

test.describe('Form label association guard', () => {
  for (const slug of ['login.php', 'users.php', 'change_password.php']) {
    test(`${slug}: all visible inputs have associated labels`, async ({ page }) => {
      if (slug !== 'login.php') {
        await login(page, ADMIN_USER, ADMIN_PASS);
      }
      await page.goto(slug);
      await page.waitForLoadState('networkidle');

      const violations = await page.evaluate(() => {
        return Array.from(document.querySelectorAll('input:not([type="hidden"]):not([type="submit"]):not([type="button"])'))
          .filter(input => {
            const id = input.getAttribute('id');
            const hasLabel = id ? document.querySelector(`label[for="${id}"]`) !== null : false;
            const hasAriaLabel = input.hasAttribute('aria-label') || input.hasAttribute('aria-labelledby');
            const hasWrappingLabel = input.closest('label') !== null;
            return !hasLabel && !hasAriaLabel && !hasWrappingLabel;
          })
          .map(input => `${input.getAttribute('name') || '(unnamed)'} [${input.getAttribute('type') || 'text'}]`);
      });

      expect(
        violations,
        `${slug}: input(s) missing label association:\n  ${violations.join('\n  ')}`
      ).toHaveLength(0);
    });
  }
});
