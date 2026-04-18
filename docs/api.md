# REST API Reference

Simple-PHP-IPAM exposes a JSON REST API (`api.php`). Read endpoints are available from v0.11; write support (POST/PUT/DELETE) was added in v1.11.

## Contents

- [Authentication](#authentication)
- [Base URL](#base-url)
- [Common response format](#common-response-format)
- [Error codes](#error-codes)
- [Resources](#resources)
  - [Subnets](#subnets)
  - [Addresses](#addresses)
  - [Sites](#sites)
  - [VLANs](#vlans)
  - [History](#history)
  - [Search](#search)
  - [Audit Log](#audit-log)
  - [Unassigned IPs](#unassigned-ips)
- [Write endpoints](#write-endpoints)
  - [Create a subnet](#create-a-subnet)
  - [Update a subnet](#update-a-subnet)
  - [Delete a subnet](#delete-a-subnet)
  - [Create an address](#create-an-address)
  - [Update an address](#update-an-address)
  - [Delete an address](#delete-an-address)
  - [Create a site](#create-a-site)
  - [Update a site](#update-a-site)
  - [Delete a site](#delete-a-site)
- [Bulk write](#bulk-write)
  - [Bulk create addresses](#bulk-create-addresses)
  - [Bulk create subnets](#bulk-create-subnets)
- [Pagination](#pagination)
- [Managing API keys](#managing-api-keys)
- [Examples](#examples)
- [API versioning](#api-versioning)
- [Machine-readable spec](#machine-readable-spec)
- [Devices](#devices)
- [Device interfaces](#device-interfaces)

---

## Authentication

Most API requests must include a valid API key via the `Authorization` header:

```http
Authorization: Bearer <key>
```

> **Breaking change (v3.0.0):** Query parameter authentication (`?api_key=<key>`) was removed in v3.0.0. Use `Authorization: Bearer <key>`.
> **Note:** The `contacts` and `subnet_stats` GET endpoints also accept browser session authentication (no API key required when logged in).

API keys are created by administrators at **Admin → API Keys** (`api_keys.php`).
Each key is a 64-character hex string generated with `random_bytes(32)`. Only a
SHA-256 hash of the key is stored — if you lose the key, delete it and generate a new one.

---

## Base URL

```
https://<your-host>/api.php
```

The `resource` query parameter selects the resource. Use `GET` for reads; `POST`, `PUT`, and `DELETE` for writes (see [Write endpoints](#write-endpoints)).

---

## Common response format

Successful responses return HTTP `200` with `Content-Type: application/json; charset=utf-8`.

Error responses return an appropriate HTTP status code and a JSON body:

```json
{ "error": "Human-readable description" }
```

---

## Pagination (v2.8.0+)

Every list endpoint that supports `?page=` / `?limit=` now sets an `X-Total-Count` response header containing the total row count for the unpaged result set. Use it to drive a pagination UI without fetching every page just to count rows.

```http
HTTP/1.1 200 OK
Content-Type: application/json; charset=utf-8
X-Total-Count: 1247
```

The default response shape stays flat for backwards compatibility:

```json
{
  "total": 1247,
  "page": 1,
  "limit": 200,
  "subnets": [ ... ]
}
```

Pass `?envelope=1` to switch to a generic `{data, meta}` envelope. The list key is replaced with `data` so a single client can iterate every paginated resource with one shape:

```json
{
  "data": [ ... ],
  "meta": {
    "total":    1247,
    "page":     1,
    "per_page": 200,
    "pages":    7
  }
}
```

Both shapes always include the `X-Total-Count` header, so a polling client can use HEAD-style introspection without parsing the body.

---

## Error codes

| Status | Meaning |
|--------|---------|
| `400`  | Bad request — missing or invalid parameter |
| `401`  | Missing, invalid, or inactive API key |
| `403`  | Forbidden — read-only API key attempted a write operation (POST/PUT/DELETE) |
| `404`  | Resource not found (e.g. unknown `resource=` value, or subnet/address `id=` not found) |
| `405`  | HTTP method not allowed for this resource |
| `409`  | Conflict — duplicate CIDR/IP, or attempted subnet delete while addresses exist |
| `429`  | Too many failed API key attempts — rate-limited |
| `207`  | Multi-status — bulk write completed with partial success (some items failed) |

---

## Resources

### Subnets

#### List all subnets

```
GET /api.php?resource=subnets
```

**Query parameters**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `ip_version` | integer | — | Filter by IP version: `4` or `6` |
| `vlan_id` | integer | — | Filter by legacy VLAN ID (1–4094) on `subnets.vlan_id` |
| `site_id` | integer | — | Filter to subnets assigned to this site |
| `tag` | string | — | Filter to subnets with the given tag name attached |
| `counts` | flag | — | Include address count fields in each subnet object (any non-empty value enables, e.g. `?counts=1`) |
| `page` | integer | `1` | Page number (1-based) |
| `limit` | integer | `200` | Records per page (max `1000`) |

**Response**

```json
{
  "total": 10,
  "page": 1,
  "limit": 200,
  "subnets": [
    {
      "id": 1,
      "cidr": "10.0.0.0/8",
      "ip_version": 4,
      "network": "10.0.0.0",
      "prefix": 8,
      "description": "RFC 1918 private range",
      "vlan_id": null,
      "site_id": 1,
      "site": "HQ",
      "created_at": "2025-01-15 10:23:44"
    }
  ]
}
```

Results are ordered by IP version, then by network address (binary sort — correct numerical order).

`site` and `site_id` are `null` when the subnet is not assigned to a site.

When `?counts=1` is passed, each subnet object also includes an `address_counts` object:

```json
"address_counts": {
  "used": 12,
  "reserved": 3,
  "free": 5,
  "total": 20,
  "utilization_pct": 75.0
}
```

`utilization_pct` is `(used + reserved) / host_capacity × 100` against the actual usable host count of the subnet (e.g. 254 for a /24), rounded to 2 decimal places. `null` for IPv6 subnets (address space too large for a meaningful percentage). `total` is the count of tracked address records in the database.

#### Get a single subnet

```
GET /api.php?resource=subnets&id=<id>
```

Accepts `?counts=1` (same as the list endpoint).

**Response** — same object shape as a single element from the list, not wrapped in an array.

```json
{
  "id": 1,
  "cidr": "10.0.0.0/8",
  "ip_version": 4,
  "network": "10.0.0.0",
  "prefix": 8,
  "description": "RFC 1918 private range",
  "vlan_id": null,
  "site_id": 1,
  "site": "HQ",
  "created_at": "2025-01-15 10:23:44"
}
```

Returns `404` if the ID does not exist.

**Subnet object fields**

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Internal database ID |
| `cidr` | string | Canonical CIDR notation, e.g. `10.0.0.0/8` |
| `ip_version` | integer | `4` or `6` |
| `network` | string | Network address, e.g. `10.0.0.0` |
| `prefix` | integer | Prefix length, e.g. `8` |
| `description` | string | Free-text description (may be empty) |
| `vlan_id` | integer\|null | Legacy VLAN ID integer (1–4094), or `null` |
| `vlan_fk` | integer\|null | FK to `vlans.id` (v2.0.0+), or `null` |
| `vlan_name` | string\|null | VLAN name from the linked VLAN object, or `null` |
| `site_id` | integer\|null | Site database ID, or `null` if not assigned |
| `site` | string\|null | Site name if assigned, otherwise `null` |
| `tags` | array | Array of tag objects (`{"id":1,"name":"prod","colour":"#ff0000"}`); empty array if no tags |
| `created_at` | string | UTC timestamp (`YYYY-MM-DD HH:MM:SS`) |
| `address_counts` | object\|— | Present only when `?counts=1` — see above |

---

### Addresses

```
GET /api.php?resource=addresses
```

Returns a paginated list of address records.

**Query parameters**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `subnet_id` | integer | — | Filter to a single subnet |
| `site_id` | integer | — | Filter to addresses in subnets belonging to this site |
| `status` | string | — | Filter by status: `used`, `reserved`, or `free` |
| `tag` | string | — | Filter to addresses with the given tag name attached |
| `contact_id` | integer | — | Filter to addresses linked to this contact (v2.1.0+) |
| `expired` | flag | — | Pass `?expired=1` to return only addresses where `expires_at` is in the past |
| `page` | integer | `1` | Page number (1-based) |
| `limit` | integer | `100` | Records per page (max `500`) |

**Response**

```json
{
  "total": 243,
  "page": 1,
  "limit": 100,
  "addresses": [
    {
      "id": 12,
      "subnet_id": 3,
      "ip": "192.168.1.10",
      "hostname": "server01.example.com",
      "owner": "ops-team",
      "status": "used",
      "note": "Primary web server",
      "group": "web",
      "mac": "aa:bb:cc:dd:ee:ff",
      "expires_at": "2026-12-31",
      "created_at": "2025-02-01 08:15:30",
      "updated_at": "2025-03-15 11:00:00"
    }
  ]
}
```

Results are ordered by IP address (binary sort — correct numerical order within each subnet).

**Address object fields**

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Internal database ID |
| `subnet_id` | integer | ID of the containing subnet |
| `ip` | string | IP address |
| `hostname` | string | Hostname (may be empty) |
| `owner` | string | Owner/team free-text (may be empty) |
| `owner_contact_id` | integer\|null | FK to `contacts.id` if linked, else `null` (v2.1.0+) |
| `owner_contact_name` | string\|null | Contact name when `owner_contact_id` is set, else `null` (v2.1.0+) |
| `status` | string | `used`, `reserved`, or `free` |
| `note` | string | Free-text note (may be empty) |
| `group` | string | Group/tag label (may be empty) |
| `mac` | string | MAC address (free-form, max 64 chars, may be empty) |
| `expires_at` | string\|null | Lease expiry date (`YYYY-MM-DD`), or `null` if not set |
| `tags` | array | Tag objects attached to this address (`[{"id":1,"name":"prod","colour":"#28a745"}]`) |
| `created_at` | string | UTC timestamp (`YYYY-MM-DD HH:MM:SS`) |
| `updated_at` | string | UTC timestamp of last modification |

---

### Sites

```
GET /api.php?resource=sites
```

**Query parameters**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `id` | integer | — | Get a single site by ID |
| `parent_id` | integer | — | Filter to child sites of the given parent site (v2.0.0+) |

**Response**

```json
{
  "sites": [
    {
      "id": 1,
      "name": "Europe",
      "description": "European region",
      "parent_id": null,
      "parent_name": null,
      "created_at": "2025-01-10 09:00:00"
    },
    {
      "id": 2,
      "name": "HQ",
      "description": "Headquarters — London",
      "parent_id": 1,
      "parent_name": "Europe",
      "created_at": "2025-01-10 09:00:00"
    }
  ]
}
```

Results are ordered alphabetically by name.

**Site object fields**

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Internal database ID |
| `name` | string | Site name (unique) |
| `description` | string | Free-text description (may be empty) |
| `parent_id` | integer\|null | Parent site database ID (v2.0.0+), or `null` for root sites |
| `parent_name` | string\|null | Parent site name (v2.0.0+), or `null` for root sites |
| `created_at` | string | UTC timestamp (`YYYY-MM-DD HH:MM:SS`) |

---

### VLANs

```
GET /api.php?resource=vlans
```

Returns all managed VLAN objects. Added in v2.0.0.

**Query parameters**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `id` | integer | — | Get a single VLAN by database ID |
| `site_id` | integer | — | Filter to VLANs belonging to a specific site |

**Response**

```json
{
  "vlans": [
    {
      "id": 1,
      "vlan_id": 100,
      "name": "Management",
      "description": "Management VLAN",
      "site_id": null,
      "created_at": "2025-06-01 12:00:00",
      "updated_at": "2025-06-01 12:00:00"
    }
  ]
}
```

When fetching a single VLAN (`?id=N`), the response is `{"vlan": {...}}`.

**VLAN object fields**

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Internal database ID |
| `vlan_id` | integer | VLAN number (1–4094) |
| `name` | string | VLAN name |
| `description` | string | Free-text description (may be empty) |
| `site_id` | integer\|null | Optional site scope FK |
| `created_at` | string | UTC timestamp |
| `updated_at` | string | UTC timestamp |

**Write operations** (requires write-access API key):

```
POST   /api.php?resource=vlans          — create a VLAN (body: {"vlan_id":100,"name":"..."})
PUT    /api.php?resource=vlans&id=<id>  — update a VLAN
DELETE /api.php?resource=vlans&id=<id>  — delete a VLAN (→ 204)
```

`vlan_id` must be between 1 and 4094. A duplicate `(vlan_id, site_id)` pair returns `409 Conflict`.

---

---

### VRFs

```
GET /api.php?resource=vrfs
```

Returns all VRF (Virtual Routing and Forwarding) objects. Added in v2.1.0.

**Query parameters**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `id` | integer | — | Get a single VRF by database ID |

**Response**

```json
{
  "vrfs": [
    {
      "id": 1,
      "name": "Corporate",
      "description": "Main corporate VRF",
      "rd": "65000:1",
      "created_at": "2026-01-01 12:00:00",
      "updated_at": "2026-01-01 12:00:00"
    }
  ]
}
```

When fetching a single VRF (`?id=N`), the response is `{"vrf": {...}}`.

**VRF object fields**

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Internal database ID |
| `name` | string | Unique VRF name |
| `description` | string | Free-text description (may be empty) |
| `rd` | string | Route Distinguisher, free-form (e.g. `65000:1`); may be empty |
| `created_at` | string | UTC timestamp |
| `updated_at` | string | UTC timestamp |

**Write operations** (requires write-access API key):

```
POST   /api.php?resource=vrfs          — create a VRF (body: {"name":"...","rd":"65000:1"})
PUT    /api.php?resource=vrfs&id=<id>  — update a VRF
DELETE /api.php?resource=vrfs&id=<id>  — delete a VRF (→ 204; blocked if subnets assigned)
```

A duplicate `name` returns `409 Conflict`. Deleting a VRF that has subnets assigned returns `409 Conflict`.

---

### Tags (v2.8.0+)

Full CRUD for tags plus association endpoints. Tags are colour-coded labels attached to subnets and addresses through the `subnet_tags` and `address_tags` join tables (`ON DELETE CASCADE`). Subnet and address create/update bodies also accept an optional `tag_ids[]` array that replaces the full tag set in one call.

```text
GET    /api.php?resource=tags                  # list all tags
GET    /api.php?resource=tags&id=N             # single tag
POST   /api.php?resource=tags                  # create  body: {name, colour}
PUT    /api.php?resource=tags&id=N             # update  body: {name?, colour?}
DELETE /api.php?resource=tags&id=N             # delete (cascades attachments)

POST   /api.php?resource=subnet_tags           # attach  body: {subnet_id, tag_id}
DELETE /api.php?resource=subnet_tags&subnet_id=N&tag_id=M

POST   /api.php?resource=address_tags          # attach  body: {address_id, tag_id}
DELETE /api.php?resource=address_tags&address_id=N&tag_id=M
```

`name` is required and capped at 50 characters. `colour` must be a 6-digit hex string like `#6c757d` (default if not supplied on create). All write operations require a non-readonly API key and emit `tag.create` / `tag.update` / `tag.delete` / `tag.attach` / `tag.detach` audit entries.

To attach tags atomically when creating a subnet or address, pass `tag_ids: [1, 2, 3]` in the create body. Same on update — the array replaces the existing set.

---

### Contacts

```
GET /api.php?resource=contacts
```

Returns all contact records. Added in v2.1.0.

**Query parameters**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `id` | integer | — | Get a single contact by database ID |
| `q` | string | — | Fuzzy search — matches against name and email (case-insensitive LIKE) |

**Response**

```json
{
  "contacts": [
    {
      "id": 1,
      "name": "Alice Smith",
      "email": "alice@example.com",
      "phone": "555-1234",
      "org": "Example Corp",
      "note": "Primary network admin",
      "created_at": "2026-01-01 12:00:00",
      "updated_at": "2026-01-01 12:00:00"
    }
  ]
}
```

**Write operations** (requires write-access API key):

```
POST   /api.php?resource=contacts          — create a contact (body: {"name":"...",...})
PUT    /api.php?resource=contacts&id=<id>  — update a contact
DELETE /api.php?resource=contacts&id=<id>  — delete a contact (→ 204)
```

`name` is required. All other fields are optional (default empty string).

---

### History

```
GET /api.php?resource=history&address_id=<id>
```

Returns the paginated change history for a single address record.

**Query parameters**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `address_id` | integer | **required** | ID of the address record |
| `page` | integer | `1` | Page number (1-based) |
| `limit` | integer | `50` | Records per page (max `200`) |

**Response**

```json
{
  "address_id": 12,
  "ip": "192.168.1.10",
  "total": 5,
  "page": 1,
  "limit": 50,
  "history": [
    {
      "id": 42,
      "action": "update",
      "before": { "hostname": "old-name", "status": "free" },
      "after":  { "hostname": "server01", "status": "used" },
      "username": "admin",
      "created_at": "2025-03-01 14:22:10"
    }
  ]
}
```

Results are returned newest-first. `before` and `after` are `null` for the initial `create` event.

Returns `400` if `address_id` is missing. If the address has been deleted, the endpoint returns `200` with the history rows and `"ip": null` (rather than `404`) so history is accessible even after deletion.

**History object fields**

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Internal history record ID |
| `action` | string | `create`, `update`, or `delete` |
| `before` | object\|null | Field values before the change (null for creates) |
| `after` | object\|null | Field values after the change (null for deletes) |
| `username` | string | Username of the user who made the change |
| `created_at` | string | UTC timestamp (`YYYY-MM-DD HH:MM:SS`) |

---

### Search

```
GET /api.php?resource=search&q=<query>
```

Search addresses by IP, hostname, owner, note, or group.

**Query parameters**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `q` | string | **required** | Search query (max 500 chars) |
| `status` | string | — | Filter by status: `used`, `reserved`, or `free` |
| `site_id` | integer | — | Filter to addresses in subnets belonging to this site |
| `ip_version` | integer | — | Filter by IP version: `4` or `6` |
| `tag` | string | — | Filter to results with the given tag name |
| `vrf_id` | integer | — | Filter to results in subnets belonging to this VRF (v2.1.0+) |
| `page` | integer | `1` | Page number |
| `limit` | integer | `100` | Records per page (max `500`) |

**JSON format for ⌘K overlay** (v2.1.0+)

The web app's `⌘K`/`Ctrl+K` search overlay calls `search.php?q=<term>&format=json` directly. You can also call this URL (with session cookies) to get a compact array of up to 20 results for autocomplete use cases:

```
GET /search.php?q=<term>&format=json
```

Response (session-authenticated, not API key):

```json
[
  {
    "ip": "10.1.0.10",
    "hostname": "server01.example.com",
    "subnet_cidr": "10.1.0.0/24",
    "status": "used",
    "url": "/addresses.php?subnet_id=3#addr-99"
  }
]
```

**Response**

```json
{
  "total": 12,
  "page": 1,
  "limit": 100,
  "results": [
    {
      "id": 99,
      "subnet_id": 3,
      "ip": "10.1.0.10",
      "hostname": "server01.example.com",
      "owner": "ops",
      "status": "used",
      "note": "Primary web server",
      "group": "web",
      "subnet_cidr": "10.1.0.0/24",
      "site_name": "HQ",
      "created_at": "2025-02-01 08:15:30",
      "updated_at": "2025-03-15 11:00:00"
    }
  ]
}
```

---

### Audit Log

```
GET /api.php?resource=audit
```

Returns paginated audit log entries.

**Query parameters**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `action` | string | — | Filter by action prefix (e.g. `subnet.create`, `auth.login`) |
| `from` | string | — | Start date (ISO 8601: `YYYY-MM-DD` or `YYYY-MM-DD HH:MM:SS`) |
| `to` | string | — | End date |
| `page` | integer | `1` | Page number |
| `limit` | integer | `100` | Records per page (max `500`) |

**Response**

```json
{
  "total": 5280,
  "page": 1,
  "limit": 100,
  "entries": [
    {
      "id": 1234,
      "action": "address.create",
      "entity_type": "address",
      "entity_id": 99,
      "username": "admin",
      "ip": "192.168.1.50",
      "details": "ip=10.1.0.10 subnet=10.1.0.0/24",
      "created_at": "2025-03-15 11:00:00"
    }
  ]
}
```

---

### Unassigned IPs

```
GET /api.php?resource=unassigned&subnet_id=<id>
```

Returns a paginated list of unassigned host addresses within a subnet. Both IPv4 and IPv6 subnets are supported.

**Query parameters**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `subnet_id` | integer | **required** | Subnet ID |
| `page` | integer | `1` | Page number (1-based) |
| `limit` | integer | `100` | Records per page (max `256`) |

**Response**

```json
{
  "subnet_id": 3,
  "cidr": "10.1.0.0/24",
  "total_unassigned": 230,
  "page": 1,
  "pages": 3,
  "limit": 100,
  "unassigned": ["10.1.0.3", "10.1.0.4", "..."]
}
```

**IPv4 subnets:** Returns `400` if the subnet is larger than /24 (more than 256 assignable IPs).

**IPv6 subnets:** Enumerates the first 256 unassigned addresses starting from the network address + 1. The `total_unassigned` reflects only the IPs checked (up to the cap) — not the full address space.

Returns `400` if `subnet_id` is missing. Returns `404` if the subnet does not exist.

---

## Write endpoints

Write endpoints require a valid API key (same `Authorization: Bearer <key>` header). All writes are recorded in the audit log as `api:{key-name}`.

Request bodies must be JSON with `Content-Type: application/json`.

### Create a subnet

```
POST /api.php?resource=subnets
```

**Request body**

```json
{
  "cidr": "10.1.0.0/24",
  "description": "Office LAN",
  "site_id": 2,
  "vlan_id": 100,
  "vrf_id": 1
}
```

| Field | Required | Description |
|-------|----------|-------------|
| `cidr` | yes | CIDR notation; must be unique within the same VRF |
| `description` | no | Free-text description |
| `site_id` | no | Integer ID of an existing site, or `null` |
| `vlan_id` | no | Integer 1–4094, or `null` |
| `vrf_id` | no | Integer ID of an existing VRF, or `null` (global/default) |

**Response** — HTTP `201`

```json
{ "id": 42 }
```

> If the new subnet overlaps existing subnets (nests inside a parent or contains children), the response includes a `warnings` array:
> ```json
> { "id": 42, "warnings": ["Hierarchy notice — this subnet is nested inside: 10.0.0.0/8. Verify this nesting is intentional."] }
> ```

> If the subnet is a child of another subnet that has a `site_id`, the child automatically inherits the parent's site. The `site_id` field in the request is overridden.

Returns `400` on invalid CIDR, `404` if site not found, `409` if CIDR already exists.

---

### Update a subnet

```
PUT /api.php?resource=subnets&id=<id>
```

Only supply the fields you want to change. `cidr` and `ip_version` cannot be changed after creation.

**Request body**

```json
{
  "description": "Updated description",
  "vlan_id": 200
}
```

**Response** — HTTP `200`

```json
{ "id": 42 }
```

---

### Delete a subnet

```
DELETE /api.php?resource=subnets&id=<id>
```

**Response** — HTTP `204` (no body)

Returns `409` if the subnet has any address records. Delete addresses first.

---

### Create an address

```
POST /api.php?resource=addresses
```

**Request body**

```json
{
  "subnet_id": 3,
  "ip": "10.1.0.10",
  "hostname": "server01.example.com",
  "owner": "ops",
  "status": "used",
  "note": "Primary web server",
  "group": "web"
}
```

| Field | Required | Description |
|-------|----------|-------------|
| `subnet_id` | yes | ID of an existing subnet |
| `ip` | yes | IP address (must be within the subnet) |
| `hostname` | no | Hostname string |
| `owner` | no | Owner/team string |
| `status` | no | `used` (default), `reserved`, or `free` |
| `note` | no | Free-text note |
| `group` | no | Group/tag label |
| `mac` | no | MAC address (free-form, max 64 chars) |
| `expires_at` | no | Lease expiry date (`YYYY-MM-DD`), or omit/`null` to clear |

**Response** — HTTP `201`

```json
{ "id": 99 }
```

Returns `400` on invalid IP or mismatched IP version, `404` if subnet not found, `409` if a record for this IP already exists in the subnet.

---

### Update an address

```
PUT /api.php?resource=addresses&id=<id>
```

Only supply the fields you want to change. `ip` and `subnet_id` cannot be changed.

**Request body**

```json
{
  "hostname": "server01-new.example.com",
  "status": "reserved"
}
```

**Response** — HTTP `200`

```json
{ "id": 99 }
```

---

### Delete an address

```
DELETE /api.php?resource=addresses&id=<id>
```

**Response** — HTTP `204` (no body)

---

### Create a site

```
POST /api.php?resource=sites
```

**Request body**

```json
{
  "name": "DC-Chicago",
  "description": "Chicago data center"
}
```

| Field | Required | Description |
|-------|----------|-------------|
| `name` | yes | Site name (must be unique) |
| `description` | no | Free-text description |

**Response** — HTTP `201`

```json
{ "id": 5 }
```

Returns `409` if a site with this name already exists.

---

### Update a site

```
PUT /api.php?resource=sites&id=<id>
```

**Request body**

```json
{
  "name": "DC-Chicago-Primary",
  "description": "Updated description"
}
```

**Response** — HTTP `200`

```json
{ "id": 5 }
```

Returns `409` if name conflicts with another site.

---

### Delete a site

```
DELETE /api.php?resource=sites&id=<id>
```

Subnets assigned to this site will have their `site_id` set to `null`.

**Response** — HTTP `204` (no body)

---

## Bulk write

Bulk endpoints let you create multiple records in a single request. Add `?bulk=1` to any `POST ?resource=addresses` or `POST ?resource=subnets` request and pass a JSON **array** as the body instead of a single object.

- Up to **500 items per request** (configurable via `api_bulk_limit` in `config.php`)
- **Partial success**: items that fail validation or conflict do not abort the entire batch — successful inserts are committed regardless
- Each item in the result array has `"success": true` with the new `"id"`, or `"success": false` with an `"error"` string

### Bulk create addresses

```
POST /api.php?resource=addresses&bulk=1
```

**Request body**

```json
[
  { "subnet_id": 3, "ip": "10.1.0.10", "hostname": "web01", "status": "used" },
  { "subnet_id": 3, "ip": "10.1.0.11", "hostname": "web02", "status": "used", "mac": "aa:bb:cc:00:00:01", "expires_at": "2026-12-31" },
  { "subnet_id": 3, "ip": "10.1.0.10" }
]
```

**Response** — HTTP `201` (all succeeded), `207` (partial), or `400` (all failed)

```json
{
  "created": 2,
  "failed": 1,
  "results": [
    { "success": true, "id": 101 },
    { "success": true, "id": 102 },
    { "success": false, "error": "Address already exists in this subnet." }
  ]
}
```

---

### Bulk create subnets

```
POST /api.php?resource=subnets&bulk=1
```

**Request body**

```json
[
  { "cidr": "10.2.0.0/24", "description": "DC-LAN-1", "site_id": 2 },
  { "cidr": "10.2.1.0/24", "description": "DC-LAN-2", "vlan_id": 200 }
]
```

**Response** — HTTP `201` (all succeeded), `207` (partial), or `400` (all failed)

```json
{
  "created": 2,
  "failed": 0,
  "results": [
    { "success": true, "id": 43 },
    { "success": true, "id": 44 }
  ]
}
```

Each item accepts the same fields as the single-record [Create a subnet](#create-a-subnet) endpoint. Overlap warnings are not returned per-item in bulk mode.

---

## Scan endpoints (v2.3.0)

### Get scan results for a subnet

```
GET /api.php?resource=scan_results&subnet_id=N
Authorization: Bearer <key>
```

Returns the results of the most recent scan run for the subnet as an array. Each element contains `ip`, `is_up` (1/0), `latency_ms` (null if down), and `scanned_at`.

Returns an empty array `[]` if no scans have run.

---

### Get scan history for a subnet

```
GET /api.php?resource=scan_history&subnet_id=N[&limit=50]
Authorization: Bearer <key>
```

Returns paginated scan history rows, newest first. `limit` defaults to 50.

---

### Trigger an immediate scan

```
POST /api.php?resource=scan_run&subnet_id=N
Authorization: Bearer <key>
```

Triggers a synchronous scan of the subnet and returns a stats object:

```json
{ "scanned": 14, "up": 11, "down": 3, "stale_updated": 0, "method": "icmp" }
```

**Requires write API key.** Capped at /28 (16 IPs) — larger subnets return HTTP 400. Use the CLI runner (`scan_run.php --all`) for larger subnets.

---

### List all scan schedules

```
GET /api.php?resource=scan_schedules
Authorization: Bearer <key>
```

Returns an array of all configured scan schedules with `subnet_id`, `method`, `interval_minutes`, `is_active`, and `last_run_at`.

---

### Get scan schedule for a subnet

```
GET /api.php?resource=scan_schedules&subnet_id=N
Authorization: Bearer <key>
```

Returns the schedule object for the given subnet, or `null` if no schedule exists.

---

### Create or update a scan schedule

```
POST /api.php?resource=scan_schedules
Authorization: Bearer <key>
Content-Type: application/json

{
  "subnet_id": 5,
  "method": "icmp",
  "interval_minutes": 60,
  "is_active": 1,
  "tcp_port": null
}
```

**Requires write API key.** Upserts the schedule (creates or replaces). Returns HTTP 200 or 201 with the saved schedule object.

---

### Delete a scan schedule

```
DELETE /api.php?resource=scan_schedules&subnet_id=N
Authorization: Bearer <key>
```

**Requires write API key.** Returns HTTP 204 on success, 404 if no schedule exists.

---

## Pagination

The `addresses`, `subnets`, `search`, and `audit` resources support pagination. Use the `page` and `limit` parameters to page through large result sets.

```
GET /api.php?resource=addresses&subnet_id=5&page=2&limit=50
```

The response always includes `total` (the full record count matching the applied filters), which lets you calculate the total number of pages:

```
total_pages = ceil(total / limit)
```

---

## Managing API keys

API keys are managed through the web UI at **Admin → API Keys** (`api_keys.php`). Only users with the `admin` role can access this page.

**Creating a key**

1. Navigate to **Admin → API Keys**
2. Enter a descriptive name (e.g. `Monitoring script`, `Grafana`) and an optional description
3. Check **Read-only** if the key should be restricted to GET requests only
4. Click **Generate key**
5. Copy the key immediately — it is shown **only once**. The server stores only a SHA-256 hash.

**Read-only keys**

A key marked as read-only can perform any GET request but will receive HTTP `403 Forbidden` on any write operation (POST, PUT, DELETE). Use read-only keys for monitoring integrations, dashboards, and any consumer that should not be able to modify data.

**Deactivating a key**

Click **Deactivate** next to any active key. The key stops working immediately but remains in the list. It can be re-activated later.

**Deleting a key**

Click **Delete** to permanently remove a key and its record. This cannot be undone.

All key lifecycle events (create, deactivate, activate, delete) are recorded in the audit log.

---

## Examples

### curl

```bash
# List all subnets
curl -H "Authorization: Bearer <key>" https://ipam.example.com/api.php?resource=subnets

# Get a single subnet
curl -H "Authorization: Bearer <key>" "https://ipam.example.com/api.php?resource=subnets&id=3"

# List used addresses in subnet 3
curl -H "Authorization: Bearer <key>" \
  "https://ipam.example.com/api.php?resource=addresses&subnet_id=3&status=used"

# Page through all addresses (page 2, 50 per page)
curl -H "Authorization: Bearer <key>" \
  "https://ipam.example.com/api.php?resource=addresses&page=2&limit=50"

# List all sites
curl -H "Authorization: Bearer <key>" https://ipam.example.com/api.php?resource=sites

# Get change history for address ID 12
curl -H "Authorization: Bearer <key>" \
  "https://ipam.example.com/api.php?resource=history&address_id=12"
```

### Python

```python
import requests

BASE = "https://ipam.example.com/api.php"
HEADERS = {"Authorization": "Bearer <key>"}

# List all subnets
resp = requests.get(BASE, headers=HEADERS, params={"resource": "subnets"})
resp.raise_for_status()
subnets = resp.json()["subnets"]

# Page through all addresses for subnet 1
page, limit = 1, 100
while True:
    resp = requests.get(BASE, headers=HEADERS, params={
        "resource": "addresses",
        "subnet_id": 1,
        "page": page,
        "limit": limit,
    })
    resp.raise_for_status()
    data = resp.json()
    for addr in data["addresses"]:
        print(addr["ip"], addr["hostname"], addr["status"])
    if page * limit >= data["total"]:
        break
    page += 1
```

### PowerShell

```powershell
$headers = @{ Authorization = "Bearer <key>" }
$base    = "https://ipam.example.com/api.php"

# List all subnets
$subnets = (Invoke-RestMethod "$base`?resource=subnets" -Headers $headers).subnets

# List addresses for a subnet
$addresses = (Invoke-RestMethod "$base`?resource=addresses&subnet_id=3" -Headers $headers).addresses
```

---

## API versioning

Every response includes the `X-IPAM-API-Version: 1` header. The current stable API is version 1. Breaking changes (e.g. tenant scoping in v4.0) will increment to v2. Integrators who want advance notice of breaking changes should pin this header in their monitoring.

There is no URL path versioning — all endpoints remain at `api.php?resource=…`.

---

## Machine-readable spec

The full OpenAPI 3.1 spec is served at:

```
GET /api.php?resource=spec
```

No authentication required. Response is `Content-Type: application/yaml; charset=utf-8`. Compatible with Swagger UI and other OpenAPI tooling.

The spec covers all v3.2.0 resources including `devices`, `device_interfaces`, and the `spec` endpoint itself.

---

## Devices

Devices represent physical or virtual network equipment (routers, switches, servers, VMs, firewalls). Addresses can be linked to a device and optionally a device interface.

### List devices

```
GET ?resource=devices
GET ?resource=devices&id=N
GET ?resource=devices&type=router
GET ?resource=devices&site_id=2
GET ?resource=devices&search=core
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `id` | integer | — | Return a single device |
| `type` | string | — | Filter: `router`, `switch`, `server`, `vm`, `firewall`, `other` |
| `site_id` | integer | — | Filter by site |
| `search` | string | — | Free-text search on name, vendor, model, serial |
| `page` | integer | 1 | Pagination page |
| `limit` | integer | 100 | Results per page (max 500) |

**Single device response:**

```json
{
  "device": {
    "id": 1,
    "name": "core-sw-01",
    "type": "switch",
    "site_id": 2,
    "site_name": "New York",
    "vendor": "Cisco",
    "model": "Catalyst 9300",
    "serial": "FOC1234X567",
    "note": "",
    "interface_count": 4,
    "created_at": "2026-04-18T00:00:00Z"
  }
}
```

### Create a device

```
POST ?resource=devices
Content-Type: application/json

{
  "name": "core-sw-01",
  "type": "switch",
  "site_id": 2,
  "vendor": "Cisco",
  "model": "Catalyst 9300",
  "serial": "FOC1234X567",
  "note": ""
}
```

Write key required. `name` is required and must be unique. `type` defaults to `other`.

### Update a device

```
PUT ?resource=devices&id=N
```

Same body as POST. All fields optional; omitted fields are unchanged.

### Delete a device

```
DELETE ?resource=devices&id=N
```

Cascades `device_interfaces` (all interfaces deleted). Address `device_id` / `interface_id` columns are SET NULL.

---

## Device interfaces

Interfaces belong to a device and can be linked to individual address records.

### List interfaces

```
GET ?resource=device_interfaces&device_id=N
GET ?resource=device_interfaces&id=N
```

### Create an interface

```
POST ?resource=device_interfaces
Content-Type: application/json

{
  "device_id": 1,
  "name": "GigabitEthernet0/0",
  "description": "uplink to core"
}
```

`device_id` and `name` are required. `name` must be unique within the device.

### Update an interface

```
PUT ?resource=device_interfaces&id=N
```

### Delete an interface

```
DELETE ?resource=device_interfaces&id=N
```

Linked address `interface_id` columns are SET NULL.
