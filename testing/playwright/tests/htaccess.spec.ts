/**
 * .htaccess coverage — verifies that web-server deny rules in Simple-PHP-IPAM/.htaccess
 * and Simple-PHP-IPAM/data/.htaccess behave as intended against a real Apache instance.
 *
 * Runs as the `htaccess-subset` CI job in .github/workflows/playwright-nightly.yml.
 * Does not require login, a database, or any seed state — pure HTTP-level checks.
 *
 * Context: tests 1..6 cover the root .htaccess RewriteRules that block direct access
 * to PHP internals and build artefacts. Tests 7..9 cover the data/.htaccess "deny
 * everything" rule. Tests 10..12 cover CLI-only PHP scripts, which the .htaccess
 * blocks at the web-server level so the PHP_SAPI guard inside them is not the only
 * defence.
 *
 * Some target paths (/vendor/, /dialects/) do not exist in v2.5.2. They become
 * reachable in v2.9.0 when bundled runtime deps ship. Tests that reference them
 * are gated with `test.skip(!fs.existsSync(...))` so they light up automatically
 * without needing a separate PR in the release that introduces the files.
 */
import { test, expect } from '@playwright/test';
import { existsSync } from 'fs';
import { resolve } from 'path';

const APP_ROOT = resolve(__dirname, '..', '..', '..', 'Simple-PHP-IPAM');
const hasVendor = existsSync(resolve(APP_ROOT, 'vendor'));
const hasDialects = existsSync(resolve(APP_ROOT, 'dialects'));

async function expectBlocked(request: import('@playwright/test').APIRequestContext, path: string): Promise<void> {
  const res = await request.get(path, { ignoreHTTPSErrors: true, maxRedirects: 0 });
  // Apache RewriteRule [F] returns 403. A 404 would also be acceptable (file
  // did not exist at all); the one thing that must not happen is a 200 with the
  // file contents served. Treat anything >= 400 as "blocked".
  expect(res.status(), `GET ${path} should be blocked, got ${res.status()}`).toBeGreaterThanOrEqual(400);
  expect(res.status()).toBeLessThan(500);
}

test.describe('.htaccess deny rules', () => {
  test('blocks direct access to config.php', async ({ request }) => {
    await expectBlocked(request, '/config.php');
  });

  test('blocks direct access to lib.php', async ({ request }) => {
    await expectBlocked(request, '/lib.php');
  });

  test('blocks direct access to init.php', async ({ request }) => {
    await expectBlocked(request, '/init.php');
  });

  test('blocks direct access to migrations.php', async ({ request }) => {
    await expectBlocked(request, '/migrations.php');
  });

  test('blocks direct access to schema.sql', async ({ request }) => {
    await expectBlocked(request, '/schema.sql');
  });

  test('blocks direct access to version.php', async ({ request }) => {
    await expectBlocked(request, '/version.php');
  });

  test('blocks the data/ directory index', async ({ request }) => {
    const res = await request.get('/data/', { ignoreHTTPSErrors: true, maxRedirects: 0 });
    expect(res.status()).toBeGreaterThanOrEqual(400);
    expect(res.status()).toBeLessThan(500);
  });

  test('blocks data/ipam.sqlite download', async ({ request }) => {
    await expectBlocked(request, '/data/ipam.sqlite');
  });

  test('blocks arbitrary files under data/', async ({ request }) => {
    // The data/.htaccess RewriteRule is a universal [F] — any path under data/
    // should be blocked regardless of whether the file exists.
    await expectBlocked(request, '/data/anything.txt');
  });

  test('blocks migrate.php (CLI-only script)', async ({ request }) => {
    await expectBlocked(request, '/migrate.php');
  });

  test('blocks tmp_cleanup.php (CLI-only script)', async ({ request }) => {
    await expectBlocked(request, '/tmp_cleanup.php');
  });

  test('blocks SHA256SUMS artefact', async ({ request }) => {
    await expectBlocked(request, '/SHA256SUMS');
  });

  test('blocks /vendor/ when present', async ({ request }) => {
    test.skip(!hasVendor, 'vendor/ does not exist until v2.9.0');
    await expectBlocked(request, '/vendor/autoload.php');
  });

  test('blocks /dialects/ when present', async ({ request }) => {
    test.skip(!hasDialects, 'dialects/ does not exist until v2.9.0');
    await expectBlocked(request, '/dialects/SqliteDialect.php');
  });
});
