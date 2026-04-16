/**
 * Form interaction helpers for Playwright tests.
 *
 * Usage:
 *   import { expectLoadingButton } from '../fixtures/forms';
 *   await expectLoadingButton(page, 'form[action*="subnets"]');
 */
import { type Page, expect } from '@playwright/test';

export async function expectLoadingButton(
  page: Page,
  formSelector: string,
): Promise<void> {
  const form = page.locator(formSelector);
  const btn = form.locator('button[type="submit"], input[type="submit"]').first();

  await btn.click({ noWaitAfter: true });

  await expect(btn).toBeDisabled();
  await expect(btn).toHaveAttribute('aria-busy', 'true');
}
