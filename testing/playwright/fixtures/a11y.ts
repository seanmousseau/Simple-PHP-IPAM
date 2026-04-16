/**
 * axe-core a11y assertion helper for Playwright.
 *
 * Usage:
 *   import { expectNoA11yViolations } from '../fixtures/a11y';
 *   await expectNoA11yViolations(page);
 */
import { type Page, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { EXCLUDED_RULES } from './a11y-exclusions';

export interface A11yOptions {
  disableRules?: string[];
  include?: string;
}

export async function expectNoA11yViolations(
  page: Page,
  opts: A11yOptions = {},
): Promise<void> {
  const disabledRules = [
    ...EXCLUDED_RULES.map(r => r.rule),
    ...(opts.disableRules ?? []),
  ];

  let builder = new AxeBuilder({ page }).disableRules(disabledRules);
  if (opts.include) {
    builder = builder.include(opts.include);
  }

  const results = await builder.analyze();

  const critical = results.violations.filter(
    v => v.impact === 'critical' || v.impact === 'serious',
  );
  const moderate = results.violations.filter(
    v => v.impact === 'moderate' || v.impact === 'minor',
  );

  if (moderate.length > 0) {
    const summary = moderate
      .map(v => `  ${v.id}: ${v.help} (${v.nodes.length} nodes)`)
      .join('\n');
    console.warn('[a11y] moderate/minor violations:\n%s', summary);
  }

  expect(
    critical,
    `axe-core found ${critical.length} critical/serious violation(s):\n` +
      critical
        .map(
          v =>
            `  - ${v.id} (${v.impact}): ${v.help}\n` +
            v.nodes
              .slice(0, 3)
              .map(n => `    ${n.html.substring(0, 120)}`)
              .join('\n'),
        )
        .join('\n'),
  ).toHaveLength(0);
}
