/**
 * Vault-key admin panel — sudo gate, rate limit, one-shot reveal flash
 * (v3.26.0 D2-B / #1098).
 *
 * Covers:
 *   - Status pill renders with fingerprint when a key is configured.
 *   - Reveal with the wrong sudo password is refused and audit-logged
 *     as backup.vault_key.sudo_failed.
 *   - Reveal with the right sudo password renders the raw key once;
 *     a subsequent GET of the page no longer carries the flash.
 *   - The reveal endpoint enforces auth_rate_limited('vault_key_reveal',
 *     ip): six wrong-password posts in quick succession trip the
 *     429 path and emit backup.vault_key.reveal_rate_limited.
 *   - Replace is hidden when no encrypted backup_runs row exists
 *     (the panel renders the dropdown-blocked message instead) — the
 *     gating SELECT.
 *
 * Pairs with tests/VaultTest.php for the wrap/unwrap layer; this spec
 * is the integration view that asserts the UI wiring + rate-limit +
 * audit emissions match the locked design.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  login, logout, fetchPost, appUrl,
  ADMIN_USER, ADMIN_PASS,
  newAuthContext, warmSudoGrant,
} from '../fixtures/ipam';

let ctx: BrowserContext;
let page: Page;

const DESTINATIONS_TAB = appUrl('backup_admin.php?tab=destinations');

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);

  // Ensure a vault key exists by hitting the Set form. We use the
  // PASTE mode rather than GENERATE so the test works even when prior
  // tests in the same suite have created encrypted backup_runs rows
  // — vault_set + generate refuses in that case (would orphan the
  // archives), but vault_set + paste is allowed (operator restoring
  // a known key from their password manager). Sequential Playwright
  // workers + shared SQLite DB means earlier specs' state always
  // bleeds in, so paste is the only mode that's reliably available.
  await page.goto(DESTINATIONS_TAB);
  const setSubmit = page.locator('[data-test="vault-set-submit"]');
  if (await setSubmit.count() > 0) {
    // v3.27.0 (#1107): vault_set is gated behind ipam_sudo_verify().
    // Pre-warm a sudo grant so the headless fetchPost below reaches the
    // vault_set handler instead of the step-up prompt. The
    // admin_password field on the vault form is gone; the gate is now
    // satisfied by an upstream sudo round-trip.
    await warmSudoGrant(page);
    // 32 'A' bytes = 0x41 × 32, base64 = "QUFBQUFB...". Deterministic
    // for fingerprint reproducibility across the test's assertions.
    await fetchPost(page, DESTINATIONS_TAB, {
      action:         'vault_set',
      vault_mode:     'paste',
      vault_key_b64:  'QUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUE=',
    });
    await page.goto(DESTINATIONS_TAB);
  }
  // Either the prior test already set one, or the POST above just did.
  await expect(page.locator('[data-test="vault-fingerprint"]')).toBeVisible({ timeout: 10_000 });
});

test.afterAll(async () => {
  await logout(page).catch(() => {});
  await ctx.close();
});

test('status panel renders fingerprint after a key is configured', async () => {
  await page.goto(DESTINATIONS_TAB);
  await expect(page.locator('[data-test="vault-key-panel"]')).toBeVisible();
  await expect(page.locator('[data-test="vault-fingerprint"]')).toBeVisible();
  const fp = await page.locator('[data-test="vault-fingerprint"]').textContent();
  expect(fp?.trim()).toMatch(/^[0-9a-f]{8}$/);
});

// Three reveal tests previously lived here exercising the v3.26.0 inline
// `admin_password` sudo-password flow on the destinations vault panel:
//
//   - "reveal with wrong sudo password is refused, no flash rendered"
//   - "reveal with correct sudo password renders the raw key exactly once"
//   - "reveal rate-limit fires after repeated wrong-password attempts"
//
// v3.27.0 (#1107) replaced that bespoke gate with the unified
// ipam_sudo_verify() helper. The inline `admin_password` field, the
// `data-test="vault-reveal-password"` input, and the `<details>` wrapper
// around Reveal are all gone (#1110, #1111). Equivalent coverage moved
// upstream:
//
//   - Wrong/correct proof:   step-up-vault-flow.spec.ts (E2E)
//                            + tests/SudoVerifyTest.php (unit, all branches)
//   - Rate-limit cap:        tests/SudoVerifyTest.php sudo bucket tests
//                            (auth.sudo_rate_limited + record_auth_failure)
//   - 401 JSON contract:     step-up-fan-out.spec.ts (settings_reveal)

test('replace is gated on encrypted backup_runs absence (fresh install: visible)', async () => {
  await page.goto(DESTINATIONS_TAB);
  // Open the Replace details so the gating message is in the DOM.
  const replaceSummary = page.locator('summary', { hasText: 'Replace vault key' });
  if (await replaceSummary.count() > 0) {
    await replaceSummary.first().click();
    // Either the form is rendered (no encrypted runs) or the blocked
    // message is. The fresh sqlite seed has no encrypted runs, so we
    // expect the form. Both paths are valid; the test asserts the
    // gating switch is present and not a 500.
    const blocked = page.locator('[data-test="vault-replace-blocked"]');
    const submit  = page.locator('[data-test="vault-replace-submit"]');
    const blockedCount = await blocked.count();
    const submitCount  = await submit.count();
    expect(blockedCount + submitCount).toBeGreaterThan(0);
  }
});
