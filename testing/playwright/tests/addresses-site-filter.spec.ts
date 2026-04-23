/**
 * Addresses page — site→subnet cascading filter (#630).
 *
 * Creates two test sites and two subnets (one per site), then verifies that:
 *  - The Site select is rendered (2+ sites with subnets cover the threshold).
 *  - Selecting a site narrows the subnet options client-side.
 *  - "All sites" (value=0) restores the full subnet list.
 *  - Loading addresses.php with a ?subnet_id= pre-selects the correct site.
 *  - The previously-selected subnet resets when switched to a site that doesn't include it.
 *  - No regression: address list still loads after filtering.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
    login, fetchPost, appUrl, subnetIdFor, deleteSubnet,
    ADMIN_USER, ADMIN_PASS,
    newAuthContext,
} from '../fixtures/ipam';

// ── Test data ─────────────────────────────────────────────────────────────────
const SITE_A = 'pw-sf-site-a';
const SITE_B = 'pw-sf-site-b';
const CIDR_A = '10.77.1.0/24';
const CIDR_B = '10.77.2.0/24';

let ctx: BrowserContext;
let page: Page;
let siteIdA: string | null = null;
let siteIdB: string | null = null;
let subnetIdA: number | null = null;
let subnetIdB: number | null = null;

// ── Helpers ───────────────────────────────────────────────────────────────────

async function siteIdFor(p: Page, name: string): Promise<string | null> {
    await p.goto('sites.php');
    return p.evaluate((n) => {
        for (const f of document.querySelectorAll<HTMLFormElement>('form')) {
            const act = f.querySelector<HTMLInputElement>('[name=action]');
            const id  = f.querySelector<HTMLInputElement>('[name=id]');
            if (act?.value === 'delete' && id) {
                const row = f.closest('tr');
                if (row?.innerText.includes(n)) return id.value;
            }
        }
        return null;
    }, name);
}

async function cleanup(p: Page) {
    // Delete subnets (cascade removes them; sites may have FKs)
    await p.goto('subnets.php');
    await deleteSubnet(p, CIDR_A);
    await deleteSubnet(p, CIDR_B);
    // Delete ALL instances of each test site (loop until none remain — guards against
    // duplicate sites left by prior failed runs where only the first match was deleted)
    for (const siteName of [SITE_A, SITE_B]) {
        let sid = await siteIdFor(p, siteName);
        while (sid) {
            await fetchPost(p, appUrl('sites.php'), { action: 'delete', id: sid });
            await p.goto('sites.php');
            sid = await siteIdFor(p, siteName);
        }
    }
}

// ── Setup / teardown ──────────────────────────────────────────────────────────

test.beforeAll(async ({ browser }: { browser: Browser }) => {
    ctx = await newAuthContext(browser);
    page = await ctx.newPage();
    await login(page, ADMIN_USER, ADMIN_PASS);

    // Stale cleanup from any prior failed run
    await cleanup(page);

    // Create two sites
    await fetchPost(page, appUrl('sites.php'), { action: 'create', name: SITE_A, description: 'site-filter test A' });
    await fetchPost(page, appUrl('sites.php'), { action: 'create', name: SITE_B, description: 'site-filter test B' });

    siteIdA = await siteIdFor(page, SITE_A);
    siteIdB = await siteIdFor(page, SITE_B);

    // Create one subnet per site
    await fetchPost(page, appUrl('subnets.php'), {
        action: 'create', cidr: CIDR_A, description: 'sf-test-A',
        confirm_overlap: '1',
        site_id: siteIdA ?? '0',
    });
    await fetchPost(page, appUrl('subnets.php'), {
        action: 'create', cidr: CIDR_B, description: 'sf-test-B',
        confirm_overlap: '1',
        site_id: siteIdB ?? '0',
    });

    await page.goto('subnets.php');
    subnetIdA = await subnetIdFor(page, CIDR_A);
    subnetIdB = await subnetIdFor(page, CIDR_B);
});

test.afterAll(async () => {
    try {
        if (page) await cleanup(page);
    } finally {
        await ctx?.close();
    }
});

// ── Tests ─────────────────────────────────────────────────────────────────────

test('site select renders on addresses.php when 2+ distinct sites exist', async () => {
    expect(subnetIdA, 'subnetIdA must be set').not.toBeNull();
    await page.goto(`addresses.php?subnet_id=${subnetIdA}`);
    const siteFilter = page.locator('#addrSiteFilter');
    await expect(siteFilter).toBeVisible();
});

test('site select contains both test sites', async () => {
    await page.goto(`addresses.php?subnet_id=${subnetIdA}`);
    const options = await page.locator('#addrSiteFilter option').allInnerTexts();
    expect(options.some(o => o.includes(SITE_A))).toBe(true);
    expect(options.some(o => o.includes(SITE_B))).toBe(true);
});

test('selecting site A hides subnet B option client-side', async () => {
    await page.goto('addresses.php');
    // Choose site A in the filter
    await page.selectOption('#addrSiteFilter', { label: SITE_A });
    // CIDR_B option should be hidden
    const cidrbOpt = page.locator(`select[name="subnet_id"] option[value="${subnetIdB}"]`);
    await expect(cidrbOpt).toBeHidden();
    // CIDR_A option should still be visible
    const cidraOpt = page.locator(`select[name="subnet_id"] option[value="${subnetIdA}"]`);
    await expect(cidraOpt).toBeVisible();
});

test('"All sites" restores full subnet list', async () => {
    await page.goto('addresses.php');
    // Select site A to filter…
    await page.selectOption('#addrSiteFilter', { label: SITE_A });
    const cidrbOpt = page.locator(`select[name="subnet_id"] option[value="${subnetIdB}"]`);
    await expect(cidrbOpt).toBeHidden();

    // …then reset to "All sites"
    await page.selectOption('#addrSiteFilter', { value: '0' });
    await expect(cidrbOpt).toBeVisible();
});

test('pre-selects correct site when ?subnet_id= belongs to site A', async () => {
    expect(subnetIdA).not.toBeNull();
    await page.goto(`addresses.php?subnet_id=${subnetIdA}`);
    const selectedSiteValue = await page.locator('#addrSiteFilter').inputValue();
    expect(selectedSiteValue).toBe(String(siteIdA));
});

test('selected subnet resets when switching to a site that does not own it', async () => {
    expect(subnetIdA).not.toBeNull();
    // Load page with subnet A selected
    await page.goto(`addresses.php?subnet_id=${subnetIdA}`);
    // Verify subnet A is selected
    const subnetVal = await page.locator('select[name="subnet_id"]').inputValue();
    expect(subnetVal).toBe(String(subnetIdA));

    // Switch site filter to site B — subnet A should be hidden and selection reset
    await page.selectOption('#addrSiteFilter', { label: SITE_B });
    const cidraOpt = page.locator(`select[name="subnet_id"] option[value="${subnetIdA}"]`);
    await expect(cidraOpt).toBeHidden();
    // Subnet select value should have reset to the placeholder
    const newVal = await page.locator('select[name="subnet_id"]').inputValue();
    expect(newVal).toBe('0');
});

test('addresses page still loads correctly after site filter interaction', async () => {
    expect(subnetIdA).not.toBeNull();
    // Navigate directly with subnet_id — address list should load normally
    await page.goto(`addresses.php?subnet_id=${subnetIdA}`);
    await expect(page.locator('.card h2, .card h1').first()).toBeVisible();
    // No JS errors expected (console-clean spec covers the baseline; here just check page loads)
    const title = await page.title();
    expect(title.toLowerCase()).toContain('address');
});
