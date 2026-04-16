/**
 * i18n / Unicode round-trip tests (#463).
 * Verifies that Unicode strings survive the full
 * browser -> PHP -> SQLite/MySQL/Postgres -> PHP -> browser cycle.
 */
import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  login, fetchPost, deleteSubnet, subnetIdFor, appUrl,
  ADMIN_USER, ADMIN_PASS,
  newAuthContext,
} from '../fixtures/ipam';

// ── Unicode fixtures ──────────────────────────────────────────────────────────

const UNICODE_FIXTURES = [
  { label: 'Cyrillic',     text: 'Сеть Северного Региона' },
  { label: 'CJK',          text: '東京データセンター' },
  { label: 'Arabic RTL',   text: 'شبكة المكتب الرئيسي' },
  { label: 'Emoji',        text: 'Server 🖥️ Room 42' },
  { label: 'Combining',    text: 'caf\u0065\u0301' },
  { label: 'ZWJ sequence', text: '👨\u200D💻 Admin' },
];

// Dedicated CIDR for Unicode tests — avoids collisions with other specs.
const UNICODE_SUBNET_CIDR = '10.77.63.0/24';
// Base IP; the last octet is incremented per fixture to avoid address conflicts.
const UNICODE_IP_BASE = '10.77.63.';

let ctx: BrowserContext;
let page: Page;
let subnetId: number | null = null;

test.beforeAll(async ({ browser }: { browser: Browser }) => {
  ctx = await newAuthContext(browser);
  page = await ctx.newPage();
  await login(page, ADMIN_USER, ADMIN_PASS);

  // Stale cleanup
  await page.goto('subnets.php');
  await deleteSubnet(page, UNICODE_SUBNET_CIDR);

  // Create the shared test subnet
  await fetchPost(page, appUrl('subnets.php'), {
    action: 'create',
    cidr: UNICODE_SUBNET_CIDR,
    description: 'PW unicode test',
    confirm_overlap: '1',
  });
  await page.goto('subnets.php');
  subnetId = await subnetIdFor(page, UNICODE_SUBNET_CIDR);
});

test.afterAll(async () => {
  try {
    if (page) {
      // Clean up stale contacts created by this spec
      await page.goto('contacts.php');
      for (const f of UNICODE_FIXTURES) {
        const ids = await page.evaluate((name) => {
          const ids: string[] = [];
          for (const form of document.querySelectorAll<HTMLFormElement>('form')) {
            const act = form.querySelector<HTMLInputElement>('[name=action]');
            const id  = form.querySelector<HTMLInputElement>('[name=id]');
            if (act?.value === 'delete' && id) {
              const row = form.closest('tr');
              if (row?.innerText.includes(name)) ids.push(id.value);
            }
          }
          return ids;
        }, `pw-u-${f.label}`);
        for (const id of ids) {
          await fetchPost(page, appUrl('contacts.php'), { action: 'delete', id });
        }
      }

      // Clean up stale tags created by this spec
      await page.goto('tags.php');
      for (const f of UNICODE_FIXTURES) {
        const tagName = `pw-u-${f.text}`.substring(0, 50);
        const ids = await page.evaluate((name) => {
          const ids: string[] = [];
          for (const form of document.querySelectorAll<HTMLFormElement>('form')) {
            const act = form.querySelector<HTMLInputElement>('[name=action]');
            const id  = form.querySelector<HTMLInputElement>('[name=id]');
            if (act?.value === 'delete' && id) {
              const row = form.closest('tr');
              if (row?.innerText.includes(name)) ids.push(id.value);
            }
          }
          return ids;
        }, tagName);
        for (const id of ids) {
          await fetchPost(page, appUrl('tags.php'), { action: 'delete', id });
        }
      }

      // Delete subnet (cascades addresses)
      await page.goto('subnets.php');
      await deleteSubnet(page, UNICODE_SUBNET_CIDR);
    }
  } finally {
    await ctx?.close();
  }
});

// ── 1. Subnet description round-trip ──────────────────────────────────────────

test.describe('subnet description round-trip', () => {
  for (const fixture of UNICODE_FIXTURES) {
    test(`${fixture.label}: "${fixture.text}"`, async () => {
      expect(subnetId, 'need subnet from beforeAll').not.toBeNull();

      // Update the shared subnet description with the Unicode text
      await page.goto('subnets.php');
      await fetchPost(page, appUrl('subnets.php'), {
        action: 'update',
        id: String(subnetId!),
        cidr: UNICODE_SUBNET_CIDR,
        description: fixture.text,
        confirm_overlap: '1',
      });

      // Reload and verify
      await page.goto('subnets.php');
      await expect(page.getByText(fixture.text).first()).toBeVisible();
    });
  }
});

// ── 2. Address hostname round-trip ────────────────────────────────────────────

test.describe('address hostname round-trip', () => {
  for (let i = 0; i < UNICODE_FIXTURES.length; i++) {
    const fixture = UNICODE_FIXTURES[i];
    const ip = `${UNICODE_IP_BASE}${10 + i}`;

    test(`${fixture.label}: "${fixture.text}"`, async () => {
      expect(subnetId, 'need subnet from beforeAll').not.toBeNull();

      await page.goto(`addresses.php?subnet_id=${subnetId}`);
      await fetchPost(page, appUrl('addresses.php'), {
        action: 'create',
        subnet_id: String(subnetId!),
        ip,
        hostname: fixture.text,
        owner: '',
        status: 'used',
        note: '',
        grp: '',
        mac: '',
        expires_at: '',
      });

      try {
        // Reload and verify
        await page.goto(`addresses.php?subnet_id=${subnetId}`);
        await expect(page.getByText(fixture.text).first()).toBeVisible();
      } finally {
        // Clean up: find the address ID and delete it
        await page.goto(`addresses.php?subnet_id=${subnetId}`);
        const addrId = await page.evaluate((targetIp) => {
          for (const a of document.querySelectorAll<HTMLAnchorElement>('a[href*="address_id"]')) {
            const row = a.closest('tr');
            if (row?.innerText.includes(targetIp)) {
              const m = a.href.match(/address_id=([0-9]+)/);
              if (m) return parseInt(m[1], 10);
            }
          }
          return null;
        }, ip);
        if (addrId) {
          await fetchPost(page, appUrl('addresses.php'), {
            action: 'delete',
            subnet_id: String(subnetId!),
            id: String(addrId),
          });
        }
      }
    });
  }
});

// ── 3. Contact name round-trip ────────────────────────────────────────────────

test.describe('contact name round-trip', () => {
  for (const fixture of UNICODE_FIXTURES) {
    // Prefix the contact name to make cleanup easier and avoid UNIQUE collisions
    const contactName = `pw-u-${fixture.label} ${fixture.text}`;

    test(`${fixture.label}: "${fixture.text}"`, async () => {
      await page.goto('contacts.php');

      const createCard = page.locator('#add-contact');
      await createCard.locator('input[name=name]').fill(contactName);
      await createCard.locator('input[name=email]').fill('unicode-test@example.com');
      await createCard.locator('button[type=submit]').click();

      try {
        await page.waitForURL(/contacts\.php/);
        await expect(page.locator('table')).toContainText(fixture.text);
      } finally {
        await page.goto('contacts.php');
        const deleteId = await page.evaluate((name) => {
          for (const f of document.querySelectorAll<HTMLFormElement>('form')) {
            const act = f.querySelector<HTMLInputElement>('[name=action]');
            const id  = f.querySelector<HTMLInputElement>('[name=id]');
            if (act?.value === 'delete' && id) {
              const row = f.closest('tr');
              if (row?.innerText.includes(name)) return id.value;
            }
          }
          return null;
        }, contactName);
        if (deleteId) {
          await fetchPost(page, appUrl('contacts.php'), { action: 'delete', id: deleteId });
        }
      }
    });
  }
});

// ── 4. Tag name round-trip ────────────────────────────────────────────────────

test.describe('tag name round-trip', () => {
  for (const fixture of UNICODE_FIXTURES) {
    // Keep under 50 chars (tags.name max). Prefix with pw-u- for cleanup.
    const tagName = `pw-u-${fixture.text}`.substring(0, 50);

    test(`${fixture.label}: "${tagName}"`, async () => {
      await page.goto('tags.php');

      const createCard = page.locator('#add-tag');
      await createCard.locator('input[name=name]').fill(tagName);
      await createCard.locator('input[name=colour]').evaluate(
        (el: HTMLInputElement, c: string) => { el.value = c; },
        '#6c757d',
      );
      await createCard.locator('button[type=submit]').click();

      try {
        await page.waitForURL(/tags\.php/);
        await expect(page.locator('table')).toContainText(tagName);
      } finally {
        await page.goto('tags.php');
        const deleteId = await page.evaluate((name) => {
          for (const f of document.querySelectorAll<HTMLFormElement>('form')) {
            const act = f.querySelector<HTMLInputElement>('[name=action]');
            const id  = f.querySelector<HTMLInputElement>('[name=id]');
            if (act?.value === 'delete' && id) {
              const row = f.closest('tr');
              if (row?.innerText.includes(name)) return id.value;
            }
          }
          return null;
        }, tagName);
        if (deleteId) {
          await fetchPost(page, appUrl('tags.php'), { action: 'delete', id: deleteId });
        }
      }
    });
  }
});

// ── 5. Search round-trip ──────────────────────────────────────────────────────

test.describe('search round-trip', () => {
  for (let i = 0; i < UNICODE_FIXTURES.length; i++) {
    const fixture = UNICODE_FIXTURES[i];
    const ip = `${UNICODE_IP_BASE}${100 + i}`;

    test(`${fixture.label}: search finds "${fixture.text}"`, async () => {
      expect(subnetId, 'need subnet from beforeAll').not.toBeNull();

      // Create an address with the Unicode hostname
      await page.goto(`addresses.php?subnet_id=${subnetId}`);
      await fetchPost(page, appUrl('addresses.php'), {
        action: 'create',
        subnet_id: String(subnetId!),
        ip,
        hostname: fixture.text,
        owner: '',
        status: 'used',
        note: '',
        grp: '',
        mac: '',
        expires_at: '',
      });

      try {
        // Search for the Unicode text — assert a result link contains the hostname
        await page.goto(`search.php?q=${encodeURIComponent(fixture.text)}`);
        await expect(page.locator('table').getByText(fixture.text).first()).toBeVisible();
      } finally {
        // Clean up the address
        await page.goto(`addresses.php?subnet_id=${subnetId}`);
        const addrId = await page.evaluate((targetIp) => {
          for (const a of document.querySelectorAll<HTMLAnchorElement>('a[href*="address_id"]')) {
            const row = a.closest('tr');
            if (row?.innerText.includes(targetIp)) {
              const m = a.href.match(/address_id=([0-9]+)/);
              if (m) return parseInt(m[1], 10);
            }
          }
          return null;
        }, ip);
        if (addrId) {
          await fetchPost(page, appUrl('addresses.php'), {
            action: 'delete',
            subnet_id: String(subnetId!),
            id: String(addrId),
          });
        }
      }
    });
  }
});
