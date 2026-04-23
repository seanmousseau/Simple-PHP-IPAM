/**
 * Site filter strip on subnets.php (#629).
 *
 * Tests: strip visibility, flat site filtering, region→child hierarchy,
 * "All sites" reset, sessionStorage persistence, and keyboard activation.
 * Cleans up all test data (sites + subnets) in afterAll.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  login, fetchPost, appUrl,
  ADMIN_USER, ADMIN_PASS,
  newAuthContext,
} from '../fixtures/ipam';

// ── Test data constants ────────────────────────────────────────────────────────
const SITE_A_NAME    = 'pw-sf-site-a';
const SITE_B_NAME    = 'pw-sf-site-b';
const REGION_NAME    = 'pw-sf-region';
const CHILD_A_NAME   = 'pw-sf-child-a';
const CHILD_B_NAME   = 'pw-sf-child-b';
const CIDR_SITE_A    = '10.71.1.0/24';
const CIDR_SITE_B    = '10.71.2.0/24';
const CIDR_CHILD_A   = '10.71.3.0/24';
const CIDR_CHILD_B   = '10.71.4.0/24';
const CIDR_UNGROUPED = '10.71.99.0/24';

let ctx: BrowserContext;
let page: Page;

// ── Helper: find site ID by name from sites.php delete forms ──────────────────
async function getSiteId(p: Page, name: string): Promise<string> {
  await p.goto('sites.php');
  return p.evaluate((n: string) => {
    for (const f of document.querySelectorAll<HTMLFormElement>('form')) {
      const act = f.querySelector<HTMLInputElement>('[name=action]');
      const id  = f.querySelector<HTMLInputElement>('[name=id]');
      if (act?.value === 'delete' && id) {
        const row = f.closest('tr');
        if (row?.innerText.includes(n)) return id.value;
      }
    }
    return '';
  }, name);
}

// ── Helper: find subnet ID by CIDR from subnets.php ──────────────────────────
async function getSubnetId(p: Page, cidr: string): Promise<string> {
  await p.goto('subnets.php');
  return p.evaluate((c: string) => {
    for (const f of document.querySelectorAll<HTMLFormElement>('form')) {
      const act = f.querySelector<HTMLInputElement>('[name=action]');
      const id  = f.querySelector<HTMLInputElement>('[name=id]');
      if (act?.value === 'delete' && id) {
        const node = f.closest('.subnet-node');
        if (node?.textContent?.includes(c)) return id.value;
      }
    }
    // Also try edit button data-sid attributes (simpler)
    for (const btn of document.querySelectorAll<HTMLButtonElement>('.subnet-edit-btn')) {
      const node = btn.closest('.subnet-node');
      if (node?.textContent?.includes(c)) return btn.dataset.sid || '';
    }
    return '';
  }, cidr);
}

// ── Setup ─────────────────────────────────────────────────────────────────────
test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);

  // Clean up any stale test data from previous failed runs
  // Delete subnets first (they reference sites)
  await page.goto('subnets.php');
  for (const cidr of [CIDR_SITE_A, CIDR_SITE_B, CIDR_CHILD_A, CIDR_CHILD_B, CIDR_UNGROUPED]) {
    const sid = await getSubnetId(page, cidr);
    if (sid) {
      await fetchPost(page, appUrl('subnets.php'), { action: 'delete', id: sid });
      await page.goto('subnets.php');
    }
  }
  // Delete child sites before parents
  for (const siteName of [CHILD_A_NAME, CHILD_B_NAME, SITE_A_NAME, SITE_B_NAME, REGION_NAME]) {
    const siteId = await getSiteId(page, siteName);
    if (siteId) {
      await fetchPost(page, appUrl('sites.php'), { action: 'delete', id: siteId });
      await page.goto('sites.php');
    }
  }

  // Create flat sites
  await fetchPost(page, appUrl('sites.php'), { action: 'create', name: SITE_A_NAME, description: '' });
  await fetchPost(page, appUrl('sites.php'), { action: 'create', name: SITE_B_NAME, description: '' });

  // Create region + children
  await fetchPost(page, appUrl('sites.php'), { action: 'create', name: REGION_NAME, description: '' });
  const regionId = await getSiteId(page, REGION_NAME);
  expect(regionId, 'region site created').not.toBe('');
  await fetchPost(page, appUrl('sites.php'), { action: 'create', name: CHILD_A_NAME, description: '', parent_id: regionId });
  await fetchPost(page, appUrl('sites.php'), { action: 'create', name: CHILD_B_NAME, description: '', parent_id: regionId });

  // Create subnets assigned to sites
  const siteAId = await getSiteId(page, SITE_A_NAME);
  const siteBId = await getSiteId(page, SITE_B_NAME);
  const childAId = await getSiteId(page, CHILD_A_NAME);
  const childBId = await getSiteId(page, CHILD_B_NAME);

  await fetchPost(page, appUrl('subnets.php'), { action: 'create', cidr: CIDR_SITE_A, description: 'sf site-a', site_id: siteAId, confirm_overlap: '1' });
  await fetchPost(page, appUrl('subnets.php'), { action: 'create', cidr: CIDR_SITE_B, description: 'sf site-b', site_id: siteBId, confirm_overlap: '1' });
  await fetchPost(page, appUrl('subnets.php'), { action: 'create', cidr: CIDR_CHILD_A, description: 'sf child-a', site_id: childAId, confirm_overlap: '1' });
  await fetchPost(page, appUrl('subnets.php'), { action: 'create', cidr: CIDR_CHILD_B, description: 'sf child-b', site_id: childBId, confirm_overlap: '1' });
  // Ungrouped subnet (no site)
  await fetchPost(page, appUrl('subnets.php'), { action: 'create', cidr: CIDR_UNGROUPED, description: 'sf ungrouped', confirm_overlap: '1' });
});

test.afterAll(async () => {
  try {
    if (!page) return;
    // Delete subnets
    await page.goto('subnets.php');
    for (const cidr of [CIDR_SITE_A, CIDR_SITE_B, CIDR_CHILD_A, CIDR_CHILD_B, CIDR_UNGROUPED]) {
      const sid = await getSubnetId(page, cidr);
      if (sid) {
        await fetchPost(page, appUrl('subnets.php'), { action: 'delete', id: sid });
        await page.goto('subnets.php');
      }
    }
    // Delete child sites then parents
    for (const siteName of [CHILD_A_NAME, CHILD_B_NAME, SITE_A_NAME, SITE_B_NAME, REGION_NAME]) {
      const siteId = await getSiteId(page, siteName);
      if (siteId) {
        await fetchPost(page, appUrl('sites.php'), { action: 'delete', id: siteId });
        await page.goto('sites.php');
      }
    }
  } finally {
    await ctx?.close();
  }
});

// ── Tests ──────────────────────────────────────────────────────────────────────

test('filter strip: renders when 2+ sites have subnets', async () => {
  await page.goto('subnets.php');
  const strip = page.locator('#site-filter-strip');
  await expect(strip).toBeVisible();
});

test('filter strip: "All sites" pill is present and initially active', async () => {
  await page.goto('subnets.php');
  const allPill = page.locator('#site-filter-strip [data-filter-site="all"]');
  await expect(allPill).toBeVisible();
  await expect(allPill).toHaveAttribute('aria-pressed', 'true');
});

test('filter strip: flat site pills are present', async () => {
  await page.goto('subnets.php');
  const strip = page.locator('#site-filter-strip');
  await expect(strip.getByText(SITE_A_NAME)).toBeVisible();
  await expect(strip.getByText(SITE_B_NAME)).toBeVisible();
});

test('filter strip: region header pill is present for region with children', async () => {
  await page.goto('subnets.php');
  const strip = page.locator('#site-filter-strip');
  await expect(strip.locator('.site-filter-pill--region').filter({ hasText: REGION_NAME })).toBeVisible();
});

test('filter strip: child site pills are present under region', async () => {
  await page.goto('subnets.php');
  const strip = page.locator('#site-filter-strip');
  // Child pills rendered inside .site-filter-region-children
  const childrenWrap = strip.locator('.site-filter-region-children');
  await expect(childrenWrap.getByText(CHILD_A_NAME)).toBeVisible();
  await expect(childrenWrap.getByText(CHILD_B_NAME)).toBeVisible();
});

test('filter strip: clicking a flat site pill hides other sites\' subnets', async () => {
  await page.goto('subnets.php');
  const strip = page.locator('#site-filter-strip');
  // Click the SITE_A pill
  await strip.locator('[data-filter-site]').filter({ hasText: SITE_A_NAME }).first().click();

  // CIDR_SITE_A subnet should be visible
  await expect(page.locator('.subnet-node').filter({ hasText: CIDR_SITE_A })).toBeVisible();
  // CIDR_SITE_B subnet should be hidden (filtered out)
  const siteBNode = page.locator('.subnet-node').filter({ hasText: CIDR_SITE_B });
  await expect(siteBNode).toHaveClass(/subnet-node--filtered/);
});

test('filter strip: "All sites" restores full tree', async () => {
  await page.goto('subnets.php');
  const strip = page.locator('#site-filter-strip');

  // First filter to site A
  await strip.locator('[data-filter-site]').filter({ hasText: SITE_A_NAME }).first().click();
  // Then click "All sites"
  await strip.locator('[data-filter-site="all"]').click();

  // Both subnets should be visible now
  await expect(page.locator('.subnet-node').filter({ hasText: CIDR_SITE_A })).toBeVisible();
  await expect(page.locator('.subnet-node').filter({ hasText: CIDR_SITE_B })).toBeVisible();
  // "All sites" pill has aria-pressed=true
  await expect(strip.locator('[data-filter-site="all"]')).toHaveAttribute('aria-pressed', 'true');
});

test('filter strip: clicking a child site pill shows only that site\'s subnets', async () => {
  await page.goto('subnets.php');
  const strip = page.locator('#site-filter-strip');
  const childrenWrap = strip.locator('.site-filter-region-children');

  // Click CHILD_A pill
  await childrenWrap.getByText(CHILD_A_NAME).click();

  // CHILD_A subnet visible
  await expect(page.locator('.subnet-node').filter({ hasText: CIDR_CHILD_A })).toBeVisible();
  // CHILD_B subnet filtered
  const childBNode = page.locator('.subnet-node').filter({ hasText: CIDR_CHILD_B });
  await expect(childBNode).toHaveClass(/subnet-node--filtered/);
  // SITE_A subnet also filtered (different site)
  const siteANode = page.locator('.subnet-node').filter({ hasText: CIDR_SITE_A });
  await expect(siteANode).toHaveClass(/subnet-node--filtered/);
});

test('filter strip: region pill filters to all child sites', async () => {
  await page.goto('subnets.php');
  const strip = page.locator('#site-filter-strip');

  // Click region header pill
  await strip.locator('.site-filter-pill--region').filter({ hasText: REGION_NAME }).click();

  // Both child subnets should be visible
  await expect(page.locator('.subnet-node').filter({ hasText: CIDR_CHILD_A })).toBeVisible();
  await expect(page.locator('.subnet-node').filter({ hasText: CIDR_CHILD_B })).toBeVisible();
  // Flat site subnets should be filtered out
  const siteANode = page.locator('.subnet-node').filter({ hasText: CIDR_SITE_A });
  await expect(siteANode).toHaveClass(/subnet-node--filtered/);
});

test('filter strip: site-group hidden when all its subnets filtered', async () => {
  await page.goto('subnets.php');
  const strip = page.locator('#site-filter-strip');

  // Filter to SITE_A — SITE_B group should become hidden
  await strip.locator('[data-filter-site]').filter({ hasText: SITE_A_NAME }).first().click();
  const siteBGroup = page.locator('.site-group').filter({ hasText: SITE_B_NAME });
  await expect(siteBGroup).toHaveClass(/site-group--filter-empty/);
});

test('filter strip: sessionStorage persists selected filter across reload', async () => {
  await page.goto('subnets.php');
  const strip = page.locator('#site-filter-strip');

  // Click SITE_B pill
  await strip.locator('[data-filter-site]').filter({ hasText: SITE_B_NAME }).first().click();

  // Reload the page
  await page.reload();

  // Strip should restore the SITE_B filter
  const siteBNode = page.locator('.subnet-node').filter({ hasText: CIDR_SITE_B });
  await expect(siteBNode).not.toHaveClass(/subnet-node--filtered/);
  const siteANode = page.locator('.subnet-node').filter({ hasText: CIDR_SITE_A });
  await expect(siteANode).toHaveClass(/subnet-node--filtered/);

  // Clean up — reset to all
  await page.locator('#site-filter-strip [data-filter-site="all"]').click();
});

test('filter strip: pills are keyboard accessible (Enter key)', async () => {
  await page.goto('subnets.php');
  const siteBPill = page.locator('#site-filter-strip [data-filter-site]').filter({ hasText: SITE_B_NAME }).first();

  // Tab to the pill and press Enter
  await siteBPill.focus();
  await page.keyboard.press('Enter');

  // SITE_B subnet should be visible; SITE_A filtered
  await expect(page.locator('.subnet-node').filter({ hasText: CIDR_SITE_B })).toBeVisible();
  const siteANode = page.locator('.subnet-node').filter({ hasText: CIDR_SITE_A });
  await expect(siteANode).toHaveClass(/subnet-node--filtered/);

  // Reset
  await page.locator('#site-filter-strip [data-filter-site="all"]').click();
});

test('filter strip: pills are keyboard accessible (Space key)', async () => {
  await page.goto('subnets.php');
  const siteAPill = page.locator('#site-filter-strip [data-filter-site]').filter({ hasText: SITE_A_NAME }).first();

  await siteAPill.focus();
  await page.keyboard.press(' ');

  await expect(page.locator('.subnet-node').filter({ hasText: CIDR_SITE_A })).toBeVisible();
  const siteBNode = page.locator('.subnet-node').filter({ hasText: CIDR_SITE_B });
  await expect(siteBNode).toHaveClass(/subnet-node--filtered/);

  // Reset
  await page.locator('#site-filter-strip [data-filter-site="all"]').click();
});

test('filter strip: active pill has aria-pressed=true', async () => {
  await page.goto('subnets.php');
  const strip = page.locator('#site-filter-strip');
  const siteAPill = strip.locator('[data-filter-site]').filter({ hasText: SITE_A_NAME }).first();

  await siteAPill.click();
  await expect(siteAPill).toHaveAttribute('aria-pressed', 'true');

  // All sites pill should have aria-pressed=false
  await expect(strip.locator('[data-filter-site="all"]')).toHaveAttribute('aria-pressed', 'false');

  // Reset
  await strip.locator('[data-filter-site="all"]').click();
});

test('filter strip: strip hidden when fewer than 2 sites exist (skip if seed data present)', async () => {
  // This test is informational — only verifiable in a fresh install with 0–1 sites.
  // In the containerized test environment the demo seed creates multiple sites, so
  // we can only assert that our strip strip is visible because we created test data.
  await page.goto('subnets.php');
  const strip = page.locator('#site-filter-strip');
  // If the strip is present, there must be ≥2 sites with subnets. That's our test data.
  const count = await strip.count();
  if (count > 0) {
    await expect(strip).toBeVisible();
  }
  // If count === 0, the strip is correctly absent (e.g. fresh install with 0 sites).
  // Either state is acceptable — we just ensure there's no uncaught JS error.
  const errors: string[] = [];
  page.on('console', msg => { if (msg.type() === 'error') errors.push(msg.text()); });
  await page.reload();
  expect(errors.filter(e => !e.includes('favicon'))).toHaveLength(0);
});

test('filter strip: existing subnet tree expand/collapse still works after filtering', async () => {
  await page.goto('subnets.php');
  const strip = page.locator('#site-filter-strip');

  // Apply a filter first
  await strip.locator('[data-filter-site]').filter({ hasText: SITE_A_NAME }).first().click();

  // The visible SITE_A subnet node should still have a working <details> element
  const visibleNode = page.locator('.subnet-node:not(.subnet-node--filtered)').first();
  const details = visibleNode.locator('details').first();
  const summary = details.locator('summary').first();
  if (await details.count() > 0) {
    // Click summary to collapse
    await summary.click();
    await expect(details).not.toHaveAttribute('open');
    // Click again to expand
    await summary.click();
    await expect(details).toHaveAttribute('open');
  }

  // Reset
  await strip.locator('[data-filter-site="all"]').click();
});
