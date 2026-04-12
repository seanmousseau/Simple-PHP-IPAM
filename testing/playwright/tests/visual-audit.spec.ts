/**
 * Visual audit spec — screenshots every major page and checks for known UI issues.
 * Run with: npx playwright test visual-audit.spec.ts
 */
import { test, expect } from '@playwright/test';
import { login, ADMIN_USER, ADMIN_PASS } from '../fixtures/ipam';
import * as fs from 'fs';
import * as path from 'path';

const OUT = '/tmp/ipam-audit-pw';
fs.mkdirSync(OUT, { recursive: true });

async function shot(page: any, name: string) {
  await page.screenshot({ path: path.join(OUT, `${name}.png`), fullPage: false });
}

async function checkTableHeaderOrder(page: any, pageName: string): Promise<string[]> {
  const issues: string[] = [];
  const result = await page.evaluate(() => {
    const bad: string[] = [];
    document.querySelectorAll('table').forEach((t: Element, i: number) => {
      const rows = t.querySelectorAll('tr');
      if (rows.length < 2) return;
      if (!rows[0].querySelector('th')) {
        bad.push(`table[${i}] first row is <td>: "${rows[0].textContent?.replace(/\s+/g, ' ').trim().substring(0, 50)}"`);
      }
    });
    return bad;
  });
  if (result.length) {
    result.forEach((r: string) => issues.push(`[${pageName}] Sticky header appears below data: ${r}`));
  }
  return issues;
}

async function checkLiteralUnicode(page: any, pageName: string): Promise<string[]> {
  const issues: string[] = [];
  const result = await page.evaluate(() => {
    const text = document.body?.innerText || '';
    // Match both \uXXXX (4-digit) and \u{XXXX} (braced) escape sequences
    const matches = text.match(/\\u[0-9a-fA-F]{4}|\\u\{[0-9a-fA-F]+\}/g);
    return matches ? matches.slice(0, 5) : [];
  });
  if (result.length) {
    issues.push(`[${pageName}] Literal Unicode escape in page text: ${result.join(', ')}`);
  }
  return issues;
}

/**
 * Check that sticky table headers have an adequate z-index so they appear
 * above tbody rows during scroll (fix for #302).
 */
async function checkStickyHeaderZIndex(page: any, pageName: string): Promise<string[]> {
  const issues: string[] = [];
  const result = await page.evaluate(() => {
    const th = document.querySelector('thead th') as HTMLElement | null;
    if (!th) return null;
    const zi = parseInt(window.getComputedStyle(th).zIndex, 10);
    return isNaN(zi) ? 0 : zi;
  });
  if (result !== null && result < 10) {
    issues.push(`[${pageName}] thead th z-index is ${result} (expected ≥ 10 for sticky header)`);
  }
  return issues;
}

test('visual audit — all pages', async ({ page }) => {
  const allIssues: string[] = [];

  await login(page, ADMIN_USER, ADMIN_PASS);

  // ── Dashboard ──────────────────────────────────────────────────────────────
  await page.goto('dashboard.php');
  await shot(page, '01_dashboard');
  allIssues.push(...await checkTableHeaderOrder(page, 'dashboard'));
  allIssues.push(...await checkLiteralUnicode(page, 'dashboard'));

  // Check dashboard table structure in detail
  const dashTables = await page.evaluate(() => {
    const out: any[] = [];
    document.querySelectorAll('table').forEach((t: Element, i: number) => {
      const rows = t.querySelectorAll('tr');
      out.push({
        idx: i,
        rowCount: rows.length,
        row0Type: rows[0]?.querySelector('th') ? 'TH' : 'TD',
        row0Text: rows[0]?.textContent?.replace(/\s+/g, ' ').trim().substring(0, 70) || '',
      });
    });
    return out;
  });
  console.log('Dashboard tables:', JSON.stringify(dashTables, null, 2));

  // ── Search overlay ─────────────────────────────────────────────────────────
  await page.goto('dashboard.php');
  await page.waitForLoadState('domcontentloaded');
  await page.keyboard.press('Control+k');
  await page.waitForTimeout(800);
  await shot(page, '02_search_overlay');

  const overlayInfo = await page.evaluate(() => {
    const overlay = document.getElementById('search-overlay');
    if (!overlay) return { found: false };
    const inp = overlay.querySelector('input');
    const ph = inp ? inp.placeholder : '';
    const codes: number[] = [];
    for (let i = 0; i < Math.min(ph.length, 40); i++) codes.push(ph.charCodeAt(i));
    return {
      found: true,
      placeholder: ph,
      codes,
      visible: getComputedStyle(overlay).display !== 'none' && !overlay.hasAttribute('hidden'),
    };
  });
  console.log('Search overlay:', JSON.stringify(overlayInfo));

  if (overlayInfo.found) {
    const ph: string = (overlayInfo as any).placeholder || '';
    const codes: number[] = (overlayInfo as any).codes || [];
    // Check for literal backslash+u (codes 92, 117)
    for (let i = 0; i < codes.length - 1; i++) {
      if (codes[i] === 92 && codes[i + 1] === 117) {
        allIssues.push(`[search-overlay] Placeholder has literal \\uXXXX escape: ${JSON.stringify(ph)}`);
        break;
      }
    }
    if (!(overlayInfo as any).visible) {
      allIssues.push(`[search-overlay] Overlay not visible after Ctrl+K`);
    }
  } else {
    allIssues.push(`[search-overlay] No #search-overlay element found`);
  }

  await page.keyboard.press('Escape');
  await page.waitForTimeout(300);

  // ── Subnets ────────────────────────────────────────────────────────────────
  await page.goto('subnets.php');
  await shot(page, '03_subnets');
  allIssues.push(...await checkTableHeaderOrder(page, 'subnets'));
  allIssues.push(...await checkLiteralUnicode(page, 'subnets'));

  // Badge text check — verify em dash renders as literal '—' not as '\u{2014}'
  const badges = await page.evaluate(() => {
    const out: string[] = [];
    document.querySelectorAll('.badge').forEach((b: Element) => {
      const t = (b as HTMLElement).innerText?.trim();
      if (t) out.push(t);
    });
    return out.slice(0, 30);
  });
  console.log('Subnet badges:', badges);
  badges.forEach((b: string) => {
    // Check for PHP single-quoted escape sequences rendered literally
    if (b.includes('\\u{') || b.includes('\\u2014')) {
      allIssues.push(`[subnets] VLAN badge contains literal Unicode escape: "${b}"`);
    }
    // Legacy garbled badge patterns from old bugs
    if (b.startsWith('lu(') || b.includes('|u(')) {
      allIssues.push(`[subnets] Garbled badge text: "${b}"`);
    }
  });

  // Get subnet list HTML to inspect VLAN display
  const subnetListSample = await page.evaluate(() => {
    const items: string[] = [];
    // Look for subnet list items (different possible structures)
    document.querySelectorAll('[data-subnet-id], .subnet-row, li').forEach((el: Element) => {
      const t = (el as HTMLElement).innerText?.replace(/\s+/g, ' ').trim().substring(0, 120);
      if (t && t.includes('/')) items.push(t);
    });
    return items.slice(0, 5);
  });
  console.log('Subnet list items:', subnetListSample);

  // Subnet map view toggle (stored in localStorage — feature #255, toggled by JS)
  // Note: The map view is toggled via localStorage key 'ipam_subnet_view', not a URL param.
  await page.goto('subnets.php');
  await page.evaluate(() => localStorage.setItem('ipam_subnet_view', 'map'));
  await page.reload();
  await shot(page, '04_subnets_map');
  // Check that the map view container and node elements are present (#344)
  const mapContainer = await page.locator('#subnet-map-view').count();
  const mapNodes    = await page.locator('.map-node').count();
  if (mapContainer === 0) {
    allIssues.push('[subnets] Map view container #subnet-map-view not found in DOM');
  } else if (mapNodes === 0) {
    console.log('[subnets] Map view container present but no .map-node elements — no subnet data in demo DB');
  }
  // Restore list view
  await page.evaluate(() => localStorage.setItem('ipam_subnet_view', 'list'));

  // ── Addresses ─────────────────────────────────────────────────────────────
  await page.goto('subnets.php');
  const firstAddrLink = await page.locator('a[href*="addresses.php?subnet_id"]').first().getAttribute('href', { timeout: 5000 }).catch(() => null);
  if (firstAddrLink) {
    await page.goto(firstAddrLink);
    await shot(page, '05_addresses');
    allIssues.push(...await checkTableHeaderOrder(page, 'addresses'));
    allIssues.push(...await checkLiteralUnicode(page, 'addresses'));
  } else {
    console.log('[addresses] No subnets found — skipping address page check');
  }

  // ── Sticky header z-index check (#302) ────────────────────────────────────
  // Run on audit.php because it always has a large, scrollable table.
  await page.goto('audit.php');
  allIssues.push(...await checkStickyHeaderZIndex(page, 'audit'));

  // ── Audit ──────────────────────────────────────────────────────────────────
  await shot(page, '06_audit');
  allIssues.push(...await checkTableHeaderOrder(page, 'audit'));

  const auditRows = await page.evaluate(() => {
    const t = document.querySelector('table');
    if (!t) return [];
    const rows = t.querySelectorAll('tr');
    return Array.from(rows).slice(0, 3).map((r: Element) => ({
      type: r.querySelector('th') ? 'TH' : 'TD',
      text: (r as HTMLElement).innerText?.replace(/\s+/g, ' ').trim().substring(0, 60),
    }));
  });
  console.log('Audit rows:', JSON.stringify(auditRows));

  // ── Search page ────────────────────────────────────────────────────────────
  await page.goto('search.php?q=192.168');
  await shot(page, '07_search_results');
  allIssues.push(...await checkTableHeaderOrder(page, 'search'));

  // ── VLANs ──────────────────────────────────────────────────────────────────
  await page.goto('vlans.php');
  await shot(page, '08_vlans');
  allIssues.push(...await checkTableHeaderOrder(page, 'vlans'));

  const vlanRows = await page.evaluate(() => {
    const t = document.querySelector('table');
    if (!t) return [];
    return Array.from(t.querySelectorAll('tr')).slice(0, 4).map((r: Element) => ({
      type: r.querySelector('th') ? 'TH' : 'TD',
      text: (r as HTMLElement).innerText?.replace(/\s+/g, ' ').trim().substring(0, 70),
    }));
  });
  console.log('VLAN rows:', JSON.stringify(vlanRows));

  // ── VRFs ───────────────────────────────────────────────────────────────────
  await page.goto('vrfs.php');
  await shot(page, '09_vrfs');
  allIssues.push(...await checkTableHeaderOrder(page, 'vrfs'));

  // ── Contacts ──────────────────────────────────────────────────────────────
  await page.goto('contacts.php');
  await shot(page, '10_contacts');
  allIssues.push(...await checkTableHeaderOrder(page, 'contacts'));

  // ── Sites ─────────────────────────────────────────────────────────────────
  await page.goto('sites.php');
  await shot(page, '11_sites');
  allIssues.push(...await checkTableHeaderOrder(page, 'sites'));

  // ── Tags ──────────────────────────────────────────────────────────────────
  await page.goto('tags.php');
  await shot(page, '12_tags');
  allIssues.push(...await checkTableHeaderOrder(page, 'tags'));

  // ── Users ─────────────────────────────────────────────────────────────────
  await page.goto('users.php');
  await shot(page, '13_users');
  allIssues.push(...await checkTableHeaderOrder(page, 'users'));
  allIssues.push(...await checkLiteralUnicode(page, 'users'));

  // ── API Keys ──────────────────────────────────────────────────────────────
  await page.goto('api_keys.php');
  await shot(page, '14_api_keys');
  allIssues.push(...await checkTableHeaderOrder(page, 'api_keys'));

  // ── Unassigned ────────────────────────────────────────────────────────────
  await page.goto('unassigned.php');
  await shot(page, '15_unassigned');
  allIssues.push(...await checkTableHeaderOrder(page, 'unassigned'));

  // ── DHCP Pools ────────────────────────────────────────────────────────────
  await page.goto('dhcp_pool.php');
  await shot(page, '16_dhcp_pool');

  // ── Bulk Update ───────────────────────────────────────────────────────────
  await page.goto('bulk_update.php');
  await shot(page, '17_bulk_update');

  // ── Import CSV ────────────────────────────────────────────────────────────
  await page.goto('import_csv.php');
  await shot(page, '18_import_csv');

  // ── DB Tools ──────────────────────────────────────────────────────────────
  await page.goto('db_tools.php');
  await shot(page, '19_db_tools');

  // ── Change Password ───────────────────────────────────────────────────────
  await page.goto('change_password.php');
  await shot(page, '20_change_password');
  allIssues.push(...await checkLiteralUnicode(page, 'change_password'));

  // ── Mobile views ──────────────────────────────────────────────────────────
  await page.setViewportSize({ width: 375, height: 812 });
  await page.goto('dashboard.php');
  await shot(page, '21_mobile_dashboard');
  await page.goto('subnets.php');
  await shot(page, '22_mobile_subnets');
  await page.goto('vlans.php');
  await shot(page, '23_mobile_vlans');
  await page.goto('vrfs.php');
  await shot(page, '24_mobile_vrfs');
  await page.goto('contacts.php');
  await shot(page, '25_mobile_contacts');
  await page.setViewportSize({ width: 1280, height: 900 });

  // ── Dark mode ─────────────────────────────────────────────────────────────
  await page.goto('dashboard.php');
  await page.evaluate(() => {
    document.documentElement.setAttribute('data-theme', 'dark');
  });
  await shot(page, '26_dark_dashboard');
  await page.goto('subnets.php');
  await page.evaluate(() => { document.documentElement.setAttribute('data-theme', 'dark'); });
  await shot(page, '27_dark_subnets');
  await page.goto('vlans.php');
  await page.evaluate(() => { document.documentElement.setAttribute('data-theme', 'dark'); });
  await shot(page, '28_dark_vlans');

  // ── Print findings ────────────────────────────────────────────────────────
  console.log('\n' + '='.repeat(60));
  console.log(`TOTAL ISSUES: ${allIssues.length}`);
  allIssues.forEach((issue, i) => console.log(`  ${i + 1}. ${issue}`));
  console.log('='.repeat(60));

  fs.writeFileSync(path.join(OUT, 'findings.json'), JSON.stringify(allIssues, null, 2));
  console.log(`\nScreenshots: ${OUT}/`);

  // Don't fail the test — we just want the report
  expect(allIssues.length).toBeGreaterThanOrEqual(0);
});
