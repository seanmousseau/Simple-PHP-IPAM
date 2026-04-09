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
- [Pagination](#pagination)
- [Managing API keys](#managing-api-keys)
- [Examples](#examples)

---

## Authentication

Every request must include a valid API key. Two methods are supported:

### Authorization header (recommended)

```
Authorization: Bearer <key>
```

### Query parameter (avoid in logs / URLs that may be cached)

```
GET /api.php?resource=subnets&api_key=<key>
```

> **Note:** Query parameter authentication is deprecated and will be removed in a future release. When used, the response includes `Deprecation: true` and `X-Deprecation-Reason` headers.

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
| `vlan_id` | integer | — | Filter by VLAN ID (1–4094) |
| `site_id` | integer | — | Filter to subnets assigned to this site |
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

`utilization_pct` is `(used + reserved) / total × 100`, rounded to 2 decimal places. Returns `0.0` when there are no address records.

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
| `vlan_id` | integer\|null | VLAN ID (1–4094), or `null` if not set |
| `site_id` | integer\|null | Site database ID, or `null` if not assigned |
| `site` | string\|null | Site name if assigned, otherwise `null` |
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
| `owner` | string | Owner/team (may be empty) |
| `status` | string | `used`, `reserved`, or `free` |
| `note` | string | Free-text note (may be empty) |
| `group` | string | Group/tag label (may be empty) |
| `created_at` | string | UTC timestamp (`YYYY-MM-DD HH:MM:SS`) |
| `updated_at` | string | UTC timestamp of last modification |

---

### Sites

```
GET /api.php?resource=sites
```

**Response**

```json
{
  "sites": [
    {
      "id": 1,
      "name": "HQ",
      "description": "Headquarters — London",
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
| `created_at` | string | UTC timestamp (`YYYY-MM-DD HH:MM:SS`) |

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
| `page` | integer | `1` | Page number |
| `limit` | integer | `100` | Records per page (max `500`) |

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

Returns a paginated list of unassigned IPv4 host addresses within a subnet. IPv6 subnets are not supported.

**Query parameters**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `subnet_id` | integer | **required** | Subnet ID (IPv4 only) |
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

Returns `400` if `subnet_id` is missing, the subnet is IPv6, or the subnet is larger than /24 (more than 256 assignable IPs).

Returns `404` if the subnet does not exist.

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
  "vlan_id": 100
}
```

| Field | Required | Description |
|-------|----------|-------------|
| `cidr` | yes | CIDR notation; must be unique |
| `description` | no | Free-text description |
| `site_id` | no | Integer ID of an existing site, or `null` |
| `vlan_id` | no | Integer 1–4094, or `null` |

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
