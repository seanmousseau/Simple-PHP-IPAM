# Adding a new page

> Procedural checklist for adding a new PHP page to the application. Every page in this codebase follows the same shape — bootstrap → auth → CSRF → query → render → footer. This doc walks through the boilerplate so nothing gets missed.
>
> Architectural conventions and the inventory of existing pages live in `CLAUDE.md` and `docs/internal/page-inventory.md`.

## Procedure

1. **Create the file** under `Simple-PHP-IPAM/<page>.php`.

2. **Bootstrap line — first executable line of every page:**
   ```php
   <?php
   require __DIR__ . '/init.php';
   ```
   `init.php` enforces HTTPS, configures the session, opens `$db`, runs migrations if pending, runs lazy housekeeping, initialises the CSRF token. After this line `$db` and `$config` are in scope.

   **Exceptions** (do NOT include `init.php`): `api.php` and `status.php` — those are stateless and load `lib.php` + `config.php` directly.

3. **Auth gates — one of the following based on access level:**
   ```php
   require_login();              // any authenticated user
   require_role('admin');        // admin only
   require_write_access();       // admin + write — readonly users blocked
   ```
   Place these immediately after the `init.php` require.

4. **CSRF on every POST handler — non-negotiable:**
   ```php
   if ($_SERVER['REQUEST_METHOD'] === 'POST') {
       csrf_require();
       // ... handler logic
   }
   ```
   Every form must include the hidden token field:
   ```html
   <form method="post">
     <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
     ...
   </form>
   ```

5. **Output goes through `e()`.** Never echo user-controlled data without `e()` (which wraps `htmlspecialchars`). Semgrep rule `ipam-xss-unsanitized-echo` enforces this; `e()` is registered as a sanitizer.

6. **Page chrome:**
   ```php
   page_header('Page title');
   // ... main content
   page_footer();
   ```
   `page_header()` opens the full `<html>...<body>` and renders the nav. `page_footer()` closes `</div></body></html>` and renders the footer with version + update check. **`page_footer()` does not call `exit()`** — do not output anything after it.

7. **Audit every write.** Convention: `<entity>.<action>` (e.g. `subnet.create`, `address.update`, `user.delete`).
   ```php
   audit($db, 'subnet.create', 'subnet', $subnetId, "cidr=$cidr");
   ```
   Audit log is append-only — SQLite triggers reject UPDATE/DELETE on `audit_log`.

8. **Add the page to the sidebar nav** in `lib.php` (search for the existing `<nav class="sidebar">` block in `page_header()`). Use a Heroicon SVG from `assets/icons.svg` for consistency. Admin-only pages go in the Admin section.

9. **Bump asset cache-buster** if you added or changed CSS/JS in `assets/app.css` or `assets/app.js`. Two places:
   - `?v=X.Y.Z` in `page_header()` (`lib.php`)
   - `?v=X.Y.Z` in `demo_gate.php` lines 74–75 (separate `<head>`, does not call `page_header()`)

10. **Update `docs/internal/page-inventory.md`** — add a row in the table with auth requirement, role, and a one-line description.

11. **Add a Playwright spec** (or extend an existing one in `testing/playwright/tests/`). At minimum cover: page loads at the expected URL, auth gate behaves correctly, primary form POST works end-to-end. Use the `loginAs(page, 'admin'|'readonly')` fixture from `tests/fixtures/auth.ts`.

12. **Run the local gate** before pushing — at minimum:
    ```bash
    php -l Simple-PHP-IPAM/<page>.php
    vendor/bin/phpstan analyse --memory-limit=1G
    vendor/bin/phpcs
    semgrep --config=.semgrep/rules.yml --error Simple-PHP-IPAM/
    bash testing/playwright/bootstrap-app.sh sqlite
    (cd testing/playwright && IPAM_BASE_URL=https://127.0.0.1:8443 \
      IPAM_ADMIN_USER=demo IPAM_ADMIN_PASS=demo \
      npx playwright test <page>.spec.ts --project=chromium)
    bash testing/playwright/teardown-app.sh
    ```

## Common omissions (caught in review)

- **Forgot `csrf_require()`** — the most common silent omission. POST handlers without it accept any browser request.
- **Forgot `audit()` call** — the second-most common. Writes that don't audit are invisible in the audit log.
- **Echo without `e()`** — semgrep catches most, but watch for indirect output like `header()` calls or JSON without `htmlspecialchars`.
- **Output after `page_footer()`** — produces visible whitespace + can break HTML when a stale handler echoes after redirect intent.
- **Page added to sidebar but not to `page-inventory.md`** — the inventory drifts.
- **No Playwright spec** — every other page has at least basic coverage.
- **Asset cache-buster bumped in `lib.php` but not `demo_gate.php`** — demo gate has its own `<head>`.

## CLI-only pages

Pages meant to run via cron or CLI (`cron.php`, `migrate.php`, `backup.php`, `restore.php`, `tmp_cleanup.php`, `demo_reset.php`, `demo_seed.php`, `scan_run.php`) must guard against web access at the top:

```php
if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit(1);
}
```

Add the `CLI` row to `page-inventory.md` under "Auth required" and dash for "Role".

## AJAX endpoints

For JSON-returning AJAX endpoints (e.g. `ping_host.php`, `user_preference.php`, `smtp_test.php`, `test_destination.php`):

```php
require __DIR__ . '/init.php';
require_login();  // or require_role('admin')
csrf_require();   // required even for AJAX

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'message' => '...']);
```

Do not call `page_header()` / `page_footer()` — pure JSON response only.

## Cross-references

- `CLAUDE.md` "Bootstrap sequence" — what `init.php` does.
- `CLAUDE.md` "Authentication & authorisation" — role + helper details.
- `CLAUDE.md` "UI conventions" — CSS classes, Heroicon usage.
- `CLAUDE.md` "Audit logging" — full action-name convention list.
- `docs/internal/page-inventory.md` — the master table to update.
