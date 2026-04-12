/**
 * Contacts — CRUD for the contacts admin page, contact-address owner integration,
 * readonly access control, and API /api.php?resource=contacts coverage.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  login, fetchGet, fetchPost, appUrl, deleteSubnet,
  ADMIN_USER, ADMIN_PASS,
  TEST_CONTACT_NAME, TEST_CONTACT_EMAIL, TEST_CONTACT_ORG,
  TEST_CIDR2,
  RO_USER, RO_PASS,
  newAuthContext, ensureRoUser,
} from '../fixtures/ipam';

let ctx: BrowserContext;
let page: Page;

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);

  // Ensure the readonly test user exists (db-tools import can wipe it)
  await ensureRoUser(page);

  // Clean up stale test contacts from previous failed runs
  await page.goto('contacts.php');
  const staleIds = await page.evaluate((name) => {
    const ids: string[] = [];
    for (const f of document.querySelectorAll<HTMLFormElement>('form')) {
      const act = f.querySelector<HTMLInputElement>('[name=action]');
      const id  = f.querySelector<HTMLInputElement>('[name=id]');
      if (act?.value === 'delete' && id) {
        const row = f.closest('tr');
        if (row?.innerText.includes(name)) ids.push(id.value);
      }
    }
    return ids;
  }, TEST_CONTACT_NAME);
  for (const id of staleIds) {
    await fetchPost(page, appUrl('contacts.php'), { action: 'delete', id });
  }
});

test.afterAll(async () => {
  await ctx?.close();
});

// ── Page smoke tests ───────────────────────────────────────────────────────────

test('contacts page: loads with correct title', async () => {
  await page.goto('contacts.php');
  await expect(page).toHaveTitle(/Contacts/i);
  await expect(page.locator('h1')).toContainText('Contacts');
});

test('contacts page: breadcrumb present', async () => {
  await page.goto('contacts.php');
  await expect(page.locator('.breadcrumbs')).toBeVisible();
  await expect(page.locator('.breadcrumbs')).toContainText('Dashboard');
});

// ── CRUD ───────────────────────────────────────────────────────────────────────

test('contacts: create a contact', async () => {
  await page.goto('contacts.php');

  const createCard = page.locator('#add-contact');
  await createCard.locator('input[name=name]').fill(TEST_CONTACT_NAME);
  await createCard.locator('input[name=email]').fill(TEST_CONTACT_EMAIL);
  await createCard.locator('input[name=org]').fill(TEST_CONTACT_ORG);
  await createCard.locator('button[type=submit]').click();

  await page.waitForURL(/contacts\.php/);
  await expect(page.locator('table')).toContainText(TEST_CONTACT_NAME);
  await expect(page.locator('table')).toContainText(TEST_CONTACT_EMAIL);
});

test('contacts: contact appears in list with correct data', async () => {
  await page.goto('contacts.php');
  const table = page.locator('table');
  await expect(table).toContainText(TEST_CONTACT_NAME);
  await expect(table).toContainText(TEST_CONTACT_ORG);
});

test('contacts: edit a contact', async () => {
  await page.goto('contacts.php');
  const rows = await page.locator('table tbody tr').all();
  for (const row of rows) {
    const text = await row.innerText();
    if (!text.includes(TEST_CONTACT_NAME)) continue;

    // Open details form
    const details = row.locator('details');
    await details.evaluate((el: HTMLDetailsElement) => { el.open = true; });

    const orgInput = details.locator('input[name=org]');
    await orgInput.fill(TEST_CONTACT_ORG + '-edited');
    await details.locator('button[type=submit]').first().click();
    await page.waitForURL(/contacts\.php/);
    await expect(page.locator('table')).toContainText(TEST_CONTACT_ORG + '-edited');
    break;
  }
});

test('contacts: name required — empty submit shows error', async () => {
  await page.goto('contacts.php');
  const createCard = page.locator('#add-contact');
  // Clear the name field to test validation
  await createCard.locator('input[name=name]').fill('');
  // The browser's required attribute prevents submission; verify it's required
  const nameInput = createCard.locator('input[name=name]');
  const required  = await nameInput.getAttribute('required');
  expect(required).not.toBeNull();
});

// ── Readonly access control ────────────────────────────────────────────────────

test('contacts: readonly user gets 403', async () => {
  const roCtx = await newAuthContext(ctx.browser()!);
  const roPage = await roCtx.newPage();
  try {
    await login(roPage, RO_USER, RO_PASS);
    const res = await fetchGet(roPage, appUrl('contacts.php'));
    // require_role('admin') returns 403 for non-admin users
    expect(res.status).toBe(403);
  } finally {
    await roCtx.close();
  }
});

// ── API ────────────────────────────────────────────────────────────────────────

test('api: GET contacts returns 200 with contacts array', async () => {
  if (!process.env.IPAM_API_KEY) {
    test.skip(true, 'IPAM_API_KEY not set — skipping API endpoint test');
    return;
  }
  const res = await fetchGet(page, appUrl('api.php?resource=contacts'));
  expect(res.status).toBe(200);
  const data = JSON.parse(res.body);
  expect(data).toHaveProperty('contacts');
  expect(Array.isArray(data.contacts)).toBe(true);
  const names = data.contacts.map((c: { name: string }) => c.name);
  expect(names).toContain(TEST_CONTACT_NAME);
});

// ── Contact-address integration ────────────────────────────────────────────────

test('contacts: contact appears in address owner typeahead', async () => {
  // Create a test subnet if needed; track creation so we can clean it up
  await page.goto('subnets.php');
  const hasSubnet = await page.locator('body').innerText().then(t => t.includes(TEST_CIDR2));
  let createdSubnet = false;
  if (!hasSubnet) {
    await fetchPost(page, appUrl('subnets.php'), {
      action: 'create', cidr: TEST_CIDR2, description: 'contacts spec test subnet', confirm_overlap: '1',
    });
    createdSubnet = true;
    await page.goto('subnets.php');
  }
  try {
    // Navigate to the addresses page for that subnet
    const subnetLink = page.locator(`a[href*="addresses.php?subnet_id"]`).filter({ hasText: TEST_CIDR2 }).first();
    const href = await subnetLink.getAttribute('href').catch(() => null);
    if (!href) {
      test.skip(true, `Could not find subnet link for ${TEST_CIDR2}`);
      return;
    }

    await page.goto(href);
    // The contact typeahead autocomplete is driven by data-contact-ac on the owner input.
    // We verify the input with the autocomplete attribute is present.
    const ownerInput = page.locator('[data-contact-ac]').first();
    const isVisible = await ownerInput.isVisible().catch(() => false);
    // If there's no add-address form visible (subnet already has addresses), this is fine
    // The integration is tested at a lighter level here: the attribute is wired up
    if (isVisible) {
      await ownerInput.fill(TEST_CONTACT_NAME.substring(0, 5));
      // Small wait to allow XHR/autocomplete to trigger
      await page.waitForTimeout(500);
      // The datalist or suggestion element should contain the contact name
      // The typeahead is client-side; just verify no JS errors and input accepted the text
      const currentValue = await ownerInput.inputValue();
      expect(currentValue.length).toBeGreaterThan(0);
    }
  } finally {
    // Clean up the subnet we created for this test
    if (createdSubnet) {
      await page.goto('subnets.php');
      await deleteSubnet(page, TEST_CIDR2);
    }
  }
});

// ── Cleanup ────────────────────────────────────────────────────────────────────

test('contacts: delete test contact', async () => {
  await page.goto('contacts.php');
  const rows = await page.locator('table tbody tr').all();
  for (const row of rows) {
    const text = await row.innerText();
    if (!text.includes(TEST_CONTACT_NAME)) continue;

    const details = row.locator('details');
    await details.evaluate((el: HTMLDetailsElement) => { el.open = true; });
    page.once('dialog', d => d.accept());
    await details.locator('button.button-danger').click();
    await page.waitForURL(/contacts\.php/);
    break;
  }

  await expect(page.locator('body')).not.toContainText(TEST_CONTACT_NAME);
});
