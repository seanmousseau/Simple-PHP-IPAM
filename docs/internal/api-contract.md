# API contract

> **Audience:** developer changing `api.php` or `docs/api-spec.yaml`; integrator wondering how versioning works. The procedural "how to add an endpoint" recipe is in `adding-an-api-endpoint.md`; the OpenAPI spec at `Simple-PHP-IPAM/assets/api-spec.yaml` is the per-endpoint source of truth. This doc covers the **contract** that wraps every endpoint: versioning policy, response shape, error codes, auth, breaking-change rules.

---

## Surface

One file: `Simple-PHP-IPAM/api.php`. Loaded directly with `require 'config.php'; require 'lib.php'`. No `init.php`, no session by default, no CSRF. Dispatcher is a `match ($resource)` block on `$_GET['resource']`.

Two narrow GET-only resources (`contacts`, `subnet_stats`) accept session cookies as a fallback to support browser fetches from the IPAM UI itself. Every other surface is Bearer-only.

OpenAPI 3.1 spec lives in `Simple-PHP-IPAM/assets/api-spec.yaml`. Every endpoint change updates the spec in the same PR.

---

## Authentication

```http
Authorization: Bearer <api-key>
```

Key is matched against `api_keys.key_hash` (SHA-256 of the raw key, compared with `hash_equals`). The `api_keys` table:

- `is_active` — disabled keys 401.
- `is_readonly` — readonly keys 403 on every write endpoint. Enforcement is per-handler (`if ((int)($apiKey['is_readonly'] ?? 0) === 1) api_error(403, ...)`); copy verbatim from existing handlers, don't paraphrase.
- `last_used_at` — updated on every authenticated request.

API key generation: 32 bytes from `random_bytes()`, base64url-encoded. Only the hash is stored; the raw key is shown to the operator once at creation time and never again.

Rate limiting: per-client-IP via the `login_attempts` table (same bucket as login). Apply the same lazy purge.

---

## Versioning

| Header | Always present |
|---|---|
| `X-IPAM-API-Version` | The major.minor of the API contract |
| `X-Content-Type-Options: nosniff` | Always |
| `X-Frame-Options: DENY` | Always |
| `Referrer-Policy: no-referrer` | Always |

The API version is **not** the same as `IPAM_VERSION`. The API version reflects the *contract*; the app version reflects the *release*. They drift independently:

- App patch + minor releases (most of them) keep the API contract intact.
- App major releases may bump the API contract, but don't always.
- API breaking changes are always announced one release in advance via a `Sunset` or `Deprecation` header on the affected endpoint.

Bumping `X-IPAM-API-Version` is a **deliberate breaking-change signal** and follows the policy below.

---

## Breaking change taxonomy

| Change | Counts as breaking? | Required handling |
|---|---|---|
| Removing an endpoint | Breaking | Emit `Deprecation: true` + `Sunset: <date>` for at least one minor release; document in CHANGELOG; new major bumps the contract version |
| Removing a field from a response | Breaking | Same as above |
| Removing a query parameter | Breaking | Same as above |
| Changing a field's type | Breaking | Same as above |
| Changing semantics of a field without changing its type | Breaking (silent) | **Never do this.** Add a new field; deprecate the old one. |
| Tightening validation (rejecting input previously accepted) | Breaking | Same as removing a parameter |
| Changing error code for an existing failure mode | Breaking | Same as changing field type |
| Adding a new endpoint | Non-breaking | None |
| Adding an optional query parameter | Non-breaking | None |
| Adding a new field to a response | Non-breaking | None (consumers MUST tolerate unknown fields) |
| Widening validation (accepting input previously rejected) | Non-breaking | None |
| Adding a new error code for a previously generic 400 | Non-breaking | Document in spec |

The two ways to ship a breaking change cleanly:

1. **Deprecate-then-remove.** Mark the endpoint/field with `Deprecation: true` + `Sunset: <ISO date>` for one minor release; remove in the next minor. Both releases bump the API version on response.
2. **Versioned alongside.** Introduce a new endpoint or new field that supersedes the old. Old endpoint enters deprecation. Removal follows the same one-release window.

Do not silently change behaviour. Every consumer integration that has ever talked to this API is one regression away from breaking; the deprecation window is the only mechanism that prevents it.

---

## Response envelope

Success responses are returned via `api_json($data)` which emits:

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-IPAM-API-Version: …
```

Body shapes by endpoint class:

| Endpoint class | Body shape |
|---|---|
| Single resource | The resource object directly, e.g. `{"id": 42, "name": "..."}` |
| List (paginated) | `api_paginated_response()` envelope: `{"<resource>": [...], "total": N, "page": P, "limit": L, "has_more": bool}` |
| Action | Action-specific; document in the OpenAPI spec |

Lists always return the paginated envelope, even when `total ≤ limit`. Consumers can rely on the envelope shape.

The `<resource>` key in the paginated envelope matches the URL `?resource=` parameter, plural form. So `?resource=subnets` returns `{"subnets": [...], ...}`. This is enforced by `api_paginated_response()` — pass the same name as the URL parameter.

---

## Error envelope

Failures are returned via `api_error(int $code, string $message)`:

```http
HTTP/1.1 <code> <reason>
Content-Type: application/json
X-IPAM-API-Version: …

{"error": "<message>"}
```

| Status | Use |
|---|---|
| 400 | Validation failure (bad input, missing required field) |
| 401 | Authentication failure (no key, invalid key, expired key) |
| 403 | Authorisation failure (readonly key on write, unauthorised resource) |
| 404 | Resource not found |
| 405 | Method not allowed for this resource |
| 409 | Conflict (UNIQUE constraint, version mismatch) |
| 422 | Semantic validation failure (input parses but is logically invalid) |
| 429 | Rate-limited |
| 500 | Server error (uncaught exception) |

Never invent a new status code outside this set. Never return 200 with `{"error": "..."}` — the status code is the primary signal.

The `error` message is human-readable and safe to render to the operator. It must not include stack traces, SQL fragments, or credentials. For machine-readable error classification, use the status code; we don't ship error codes in the envelope by design (kept the envelope minimal).

---

## Pagination

Every list endpoint accepts `?page=N&limit=N`:

- `page` ≥ 1, default 1.
- `limit` ∈ [1, 500], default 100.
- Out-of-range values are clamped to the boundary, not rejected.

Response includes `total`, `page`, `limit`, `has_more`. Consumers should drive their pagination off `has_more`, not by computing `total / limit` themselves (in case the underlying count drifts between calls).

`api_paginated_response()` is the helper that produces this envelope. Use it.

---

## Filtering

Endpoint-specific filters are query parameters. Naming:

- Booleans: `?expired=1`, `?active=0`. Accept `1`/`0`/`true`/`false`.
- IDs: `?subnet_id=42`.
- Tag filters: `?tags=12,18,33` (comma-separated tag IDs). The helper `api_validate_tag_ids()` parses and validates; copy from `api_addresses` for the canonical usage.
- Free-text search: `?q=...` (where supported).
- Date ranges: `?since=YYYY-MM-DD&until=YYYY-MM-DD`.

Don't invent new filter syntaxes per endpoint — match the existing patterns.

---

## Audit logging

Every state-changing API call (POST, PUT, DELETE) emits an `audit_log` row with the same vocabulary as browser actions (`subnet.create`, `address.update`, etc.). The action verb is the same regardless of which surface (browser vs API) initiated it. See `audit-actions.md`.

The audit row carries `username` populated from `api_keys.name` prefixed with `api:`, e.g. `api:scanner-key`, so audit consumers can distinguish browser vs API origin.

---

## CSRF

API is exempt from CSRF (stateless Bearer auth). Do **not** turn on CSRF for `api.php`. The two narrow GET-only session-cookie endpoints are exempt because they're read-only and the browser is already in the same origin.

---

## Forwards compatibility from the consumer side

Consumers MUST:

- Tolerate unknown fields in response bodies. New fields can be added in any minor release.
- Send `Accept: application/json` (although the API responds with JSON regardless).
- Treat status code as the primary failure signal, not response body shape.
- Re-fetch the OpenAPI spec when `X-IPAM-API-Version` changes — the spec is the contract.

Consumers MUST NOT:

- Assume field order in JSON objects (PHP `json_encode` preserves insertion order but consumers shouldn't rely on it).
- Cache responses past the body's freshness without consulting the audit log or the resource's `updated_at` field.

---

## Spec maintenance

`Simple-PHP-IPAM/assets/api-spec.yaml` is shipped to the operator and consumed by the API docs page. Every endpoint change updates the spec **in the same PR** as the code change. PR-time gate #4 in `coding-guide.md` enforces this.

Procedure for adding a new endpoint, including the OpenAPI spec edit, is in `adding-an-api-endpoint.md`.

---

## Cross-references

- `adding-an-api-endpoint.md` — procedural recipe.
- `Simple-PHP-IPAM/assets/api-spec.yaml` — per-endpoint source of truth.
- `docs/api.md` — operator/integrator-facing reference.
- `security-model.md` — threat model and auth boundaries.
- `audit-actions.md` — action vocabulary.
- `coding-guide.md` → "PR-time gates" — the must-update list.

---

## Update protocol

- New endpoint shape pattern → add to "Surface" or to one of the request/response sections with a concrete example.
- New error code in production use → add to the error code table with the canonical use.
- Versioning policy change → update "Versioning" and "Breaking change taxonomy" together; announce in CHANGELOG.
- Filter syntax convention adopted across more than one endpoint → document under "Filtering" so the next endpoint follows it.
