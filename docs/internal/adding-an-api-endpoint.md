# Adding an API endpoint

> Procedure for extending the REST API in `Simple-PHP-IPAM/api.php`. The API is single-file, dispatched on `?resource=...`, authenticated by Bearer API key (with a narrow session-cookie path for read-only browser fetches), and contract-documented in `Simple-PHP-IPAM/assets/api-spec.yaml`.

---

## Architecture in one minute

`api.php` is a flat dispatcher:

1. Loads `config.php` and `lib.php` directly — **no `init.php`, no session** by default.
2. Sets JSON + security headers (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `X-IPAM-API-Version`).
3. Authenticates: `Authorization: Bearer <key>` against the `api_keys` table (SHA-256 hash compared with `hash_equals`). Two narrow GET-only endpoints (`contacts`, `subnet_stats`) also accept session cookies for the browser.
4. Rate-limits by client IP via the same `login_attempts` table the login flow uses.
5. Dispatches on `$_GET['resource']` to a handler function (`api_subnets`, `api_addresses`, `api_sites`, etc.).
6. Handler returns via `api_json($data)` (success) or `api_error($code, $message)` (failure). Both are `never`-typed and `exit`.

Pagination, tag filtering, and validation use shared helpers — `api_paginated_response()`, `api_validate_tag_ids()`, `api_fetch_tags_for_ids()`. Use them; do not reinvent.

---

## Step-by-step

### 1. Decide the resource shape

A new endpoint is one of:

| Shape | Example | URL |
|---|---|---|
| **Resource** (CRUD) | `vrfs`, `contacts` | `?resource=vrfs` |
| **Read-only sub-resource** | `history`, `audit` | `?resource=history&address_id=42` |
| **Action** | `scan`, `restore` | `?resource=scan` (POST) |

If you're tempted to invent a new shape, stop and look at how the existing handlers do it first. The existing patterns cover ~95% of cases.

### 2. Write the handler in `api.php`

Naming: `api_<resource>(PDO $db): never` for GET; the create/update/delete variants use `api_<resource>_create`, `api_<resource>_update`, `api_<resource>_delete` and take `array $apiKey, array $body` (or `int $id` for update/delete) as additional parameters.

Boilerplate for a new GET resource:

```php
function api_widgets(PDO $db): never {
    $page  = max(1, to_int($_GET['page']  ?? 1));
    $limit = min(500, max(1, to_int($_GET['limit'] ?? 100)));
    $offset = ($page - 1) * $limit;

    $totalSt = $db->query("SELECT COUNT(*) FROM widgets");
    $total = (int) $totalSt->fetchColumn();

    $st = $db->prepare("SELECT id, name, created_at FROM widgets ORDER BY id LIMIT :l OFFSET :o");
    $st->bindValue(':l', $limit, PDO::PARAM_INT);
    $st->bindValue(':o', $offset, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();

    api_json(api_paginated_response('widgets', $rows, $total, $page, $limit));
}
```

Boilerplate for a write handler:

```php
function api_widgets_create(PDO $db, array $apiKey, array $body): never {
    if ((int)($apiKey['is_readonly'] ?? 0) === 1) {
        api_error(403, 'API key is read-only');
    }
    $name = trim(to_str($body['name'] ?? ''));
    if ($name === '') api_error(400, 'name is required');

    $st = $db->prepare("INSERT INTO widgets (name, created_at) VALUES (:n, " . ipam_dialect()->now() . ")");
    $st->execute([':n' => $name]);
    $id = (int) $db->lastInsertId();

    audit($db, 'widget.create', 'widget', $id, json_encode(['name' => $name]));
    api_json(['id' => $id, 'name' => $name]);
}
```

Use `ipam_dialect()->now()` for timestamps — never hard-code `datetime('now')` (SQLite-only) or `NOW()` (MySQL/Postgres-only). The dialect layer keeps the SQL portable across all three engines.

### 3. Wire the dispatch

`api.php` uses a `match ($resource)` block with nested `match ($method)` for each resource. Add a new branch to the outer match:

```php
'widgets' => match ($method) {
    'GET'    => api_widgets($db),
    'POST'   => api_widgets_create($db, $apiKey, $body),
    'PUT'    => api_widgets_update($db, $apiKey, to_int($_GET['id'] ?? 0), $body),
    'DELETE' => api_widgets_delete($db, $apiKey, to_int($_GET['id'] ?? 0)),
    default  => api_error(405, 'Method not allowed.'),
},
```

The variables `$method`, `$resource`, `$body`, and `$apiKey` are already in scope from the dispatcher. The `id` is read from `$_GET['id']` per-arm where needed (the existing handlers do this with `to_int($_GET['id'] ?? 0)` — match that pattern).

### 4. Authentication & authorization

- **Read-only API keys** (`api_keys.is_readonly = 1`) must be rejected from any write handler. The check is `if ((int)($apiKey['is_readonly'] ?? 0) === 1) api_error(403, ...)` — copy it verbatim from existing write handlers.
- **Session-cookie auth** is only enabled for the two GET endpoints listed in the dispatcher (`contacts`, `subnet_stats`). Do not add a new resource to that list without an explicit reason — Bearer-token auth is the default contract.
- **No CSRF token on the API.** API requests are stateless and authenticated per-request. The CSRF protection on the web UI is a separate layer for browser sessions.

### 5. Audit logging

Every write must emit an audit entry:

```php
audit($db, 'widget.create', 'widget', $id, json_encode([...]));
```

Action naming follows the existing convention (`<entity>.<verb>`) — see `CLAUDE.md` → "Audit logging" for the canonical list. Do not invent new verbs casually; match `create`/`update`/`delete`/`toggle_active`/etc. so log queries stay searchable.

The fifth argument (`$details`, optional — defaults to `''`) should be a JSON-encoded snapshot of what changed — for create, the new values; for update, the diff; for delete, the row that was removed. JSON is the recommended convention for greppable audit history; the column itself is plain `TEXT` so any string is valid.

### 6. Update the OpenAPI spec

`Simple-PHP-IPAM/assets/api-spec.yaml` is **served at `?resource=spec`** and consumed by clients (and the optional Swagger UI page). Add the new resource under `paths:` and any new schema under `components.schemas`.

The spec is hand-written. There is no generator. Mismatches between the handler and the spec are caught only by review — be careful, and round-trip your example payload against the live endpoint before merging.

### 7. Add an integration test

`testing/scripts/test_api.sh` runs against a live IPAM instance with `BASIC_AUTH=user:pass` set (the combined env var — see `test-suites.md` footgun (1)). Add a section that:

1. Creates a row via POST.
2. Reads it back via GET (single + list).
3. Updates it via PUT.
4. Deletes it via DELETE.
5. Confirms the audit log has the expected action.

Run the script against all three drivers (`bootstrap-app.sh sqlite|mysql|pgsql`) before merging — the SQL you write will run on all three engines, and only the actual run catches dialect mismatches.

### 8. Documentation

- Update `docs/api.md` with the new resource: URL, methods, request body schema, response shape, example curl.
- If the endpoint enables a user-facing feature, link it from the relevant section of the marketing site's feature card (`marketing-site.md` step 6).

---

## Recurring pitfalls

- **Hard-coded SQLite functions** (`datetime('now')`, `randomblob(...)`, `julianday(...)`). MySQL and Postgres test runs will fail. Always use `ipam_dialect()->now()` or whatever helper exists; if no helper exists, write one in `lib.php` first.
- **`PDO::PARAM_LOB` for binary** — when an endpoint accepts an IP address, the binding must go through `ipam_bind_binary()`. See `CLAUDE.md` → "Binary IP storage". `PARAM_STR` corrupts the value on every engine.
- **Forgetting the readonly check.** A new write handler that doesn't check `$apiKey['is_readonly']` lets a read-only key escalate to write. The check is one line; copy it.
- **Returning HTML in error paths.** PHP fatal errors emit HTML to stdout, which clients parse as JSON and choke on. Wrap risky code in try/catch and route through `api_error()`.
- **`api_json()` is `never`-typed and exits.** Don't write code after it expecting it to run.
- **Pagination off-by-one.** `$offset = ($page - 1) * $limit`. The first page is `page=1`, not `page=0`. The total in the response is the unfiltered total, not the page size.
- **Adding a resource to the session-auth list casually.** Bearer-token auth is the contract. Session-cookie fallback exists for two specific browser-internal use cases; expanding it weakens the security model. If you find yourself wanting it, add an API key to the browser's request instead.
- **Forgetting to update `api-spec.yaml`.** Clients that lean on the spec (Swagger UI, generated client SDKs in the future) silently ignore your endpoint. There is no automatic check for spec/handler drift in CI today.

---

## Pagination convention

All list endpoints return:

```json
{
  "widgets": [...],
  "page": 1,
  "limit": 100,
  "total": 1234,
  "pages": 13
}
```

Use `api_paginated_response($listKey, $rows, $total, $page, $limit, $extra)` — it produces this shape and merges any extra fields. Do not invent your own pagination envelope.

Default `limit` is 100. Hard cap is 500 (`min(500, ...)`). Higher caps degrade the JSON encode time and memory footprint linearly; if you need higher, the right answer is a streaming endpoint, not a higher limit.

---

## Cross-references

- `Simple-PHP-IPAM/api.php` — the dispatcher and existing handlers.
- `Simple-PHP-IPAM/assets/api-spec.yaml` — OpenAPI contract.
- `testing/scripts/test_api.sh` — integration tests.
- `docs/api.md` — user-facing API documentation.
- `CLAUDE.md` → "Audit logging" — action-name convention.
- `CLAUDE.md` → "Binary IP storage" — IP-binding rules.
- `test-suites.md` — `BASIC_AUTH` env var and the 7 dev-direct footguns.
