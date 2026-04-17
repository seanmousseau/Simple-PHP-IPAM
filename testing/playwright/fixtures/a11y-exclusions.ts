/**
 * Documented axe-core rule exclusions for known false positives.
 * Every entry must include the PR or issue that justifies the exclusion.
 */
export interface A11yExclusion {
  rule: string;
  reason: string;
  reference: string;
}

export const EXCLUDED_RULES: A11yExclusion[] = [
  // No exclusions yet — start clean and add only when a genuine false
  // positive is identified with a PR-link justification.
];
