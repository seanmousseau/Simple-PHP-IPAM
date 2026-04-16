/**
 * .htaccess coverage — verifies that web-server deny rules in Simple-PHP-IPAM/.htaccess
 * and Simple-PHP-IPAM/data/.htaccess behave as intended against a real Apache instance.
 *
 * Runs as the `htaccess-subset` CI job in .github/workflows/playwright.yml.
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
// v2.11.0 #500: /vendor/ is blocked at the root .htaccess regardless of
// whether the directory exists on disk, so the hasVendor gate is gone.
const hasDialects = existsSync(resolve(APP_ROOT, 'dialects'));

// v2.11.0 #500: containerized OpenLiteSpeed ships a plain-HTTP listener on
// :8088 mapped to the Example vhost; the stock OLS image has no HTTPS on
// that vhost out of the box, so this spec can only hit it over HTTP.
// The root .htaccess force-HTTPS rewrite would return 301 on a plain-HTTP
// request, which is not "blocked" — it just redirects. Sending
// `X-Forwarded-Proto: https` skips that specific redirect rule while
// leaving every other deny rule in effect. Apache runs over real HTTPS
// so the header is a no-op there. The helper sends it unconditionally.
const proxyHeaders = { 'X-Forwarded-Proto': 'https' };

async function expectBlocked(request: import('@playwright/test').APIRequestContext, path: string): Promise<void> {
  const res = await request.get(path, {
    ignoreHTTPSErrors: true,
    maxRedirects: 0,
    headers: proxyHeaders,
  });
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
    const res = await request.get('/data/', {
      ignoreHTTPSErrors: true,
      maxRedirects: 0,
      headers: proxyHeaders,
    });
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

  test('blocks /vendor/autoload.php via root-level rewrite', async ({ request }) => {
    // v2.11.0 #500: blocked at the root .htaccess (`RewriteRule ^vendor(/|$)`)
    // rather than via vendor/.htaccess alone. OpenLiteSpeed's lsphp handler
    // dispatches PHP files BEFORE subdirectory .htaccess rewrites run, so
    // the subdirectory-level deny was insufficient. Root-level blocks fire
    // before handler dispatch on both Apache and OLS. Request must return
    // blocked regardless of whether vendor/ exists on disk.
    await expectBlocked(request, '/vendor/autoload.php');
  });

  test('blocks arbitrary paths under /vendor/', async ({ request }) => {
    await expectBlocked(request, '/vendor/phpmailer/anything.php');
  });

  test('blocks /dialects/SqliteDialect.php via root-level rewrite', async ({ request }) => {
    test.skip(!hasDialects, 'dialects/ does not exist until v2.9.0');
    await expectBlocked(request, '/dialects/SqliteDialect.php');
  });

  test('blocks /dialects/MysqlDialect.php via root-level rewrite', async ({ request }) => {
    test.skip(!hasDialects, 'dialects/ does not exist until v2.10.0');
    await expectBlocked(request, '/dialects/MysqlDialect.php');
  });

  test('blocks /dialects/PgsqlDialect.php via root-level rewrite', async ({ request }) => {
    test.skip(!hasDialects, 'dialects/ does not exist until v2.11.0');
    await expectBlocked(request, '/dialects/PgsqlDialect.php');
  });

  test('blocks /dialects/Dialect.php (interface) via root-level rewrite', async ({ request }) => {
    test.skip(!hasDialects, 'dialects/ does not exist until v2.9.0');
    await expectBlocked(request, '/dialects/Dialect.php');
  });

  test('blocks /PgsqlStatement.php at the web root', async ({ request }) => {
    // v2.11.0 #386 PDOStatement subclass — loaded via require_once from
    // ipam_db(), not via HTTP. Root .htaccess explicit-file deny covers it.
    await expectBlocked(request, '/PgsqlStatement.php');
  });

  test('blocks /schema.mysql.sql at the web root', async ({ request }) => {
    await expectBlocked(request, '/schema.mysql.sql');
  });

  test('blocks /schema.pgsql.sql at the web root', async ({ request }) => {
    await expectBlocked(request, '/schema.pgsql.sql');
  });
});
