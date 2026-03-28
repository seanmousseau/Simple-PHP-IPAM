# REST API Reference

Simple-PHP-IPAM exposes a read-only JSON REST API (`api.php`) available from v0.11.

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

API keys are created by administrators at **Admin → API Keys** (`api_keys.php`).
Each key is a 64-character hex string generated with `random_bytes(32)`. Only a
SHA-256 hash of the key is stored — if you lose the key, delete it and generate a new one.

---

## Base URL

```
https://<your-host>/api.php
```

All requests use `GET`. The `resource` query parameter selects which resource to read.

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
| `401`  | Missing, invalid, or inactive API key |
| `404`  | Resource not found (e.g. unknown `resource=` value, or `id=` not found) |

---

## Resources

### Subnets

#### List all subnets

```
GET /api.php?resource=subnets
```

**Response**

```json
{
  "subnets": [
    {
      "id": 1,
      "cidr": "10.0.0.0/8",
      "ip_version": 4,
      "network": "10.0.0.0",
      "prefix": 8,
      "description": "RFC 1918 private range",
      "site": "HQ",
      "created_at": "2025-01-15 10:23:44"
    }
  ]
}
```

Results are ordered by IP version, then by network address (binary sort — correct numerical order).

`site` is `null` when the subnet is not assigned to a site.

#### Get a single subnet

```
GET /api.php?resource=subnets&id=<id>
```

**Response** — same object shape as a single element from the list, not wrapped in an array.

```json
{
  "id": 1,
  "cidr": "10.0.0.0/8",
  "ip_version": 4,
  "network": "10.0.0.0",
  "prefix": 8,
  "description": "RFC 1918 private range",
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
| `site` | string\|null | Site name if assigned, otherwise `null` |
| `created_at` | string | UTC timestamp (`YYYY-MM-DD HH:MM:SS`) |

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
      "created_at": "2025-02-01 08:15:30"
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
| `created_at` | string | UTC timestamp (`YYYY-MM-DD HH:MM:SS`) |

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

Returns `400` if `address_id` is missing, `404` if the address does not exist.

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

## Pagination

The `addresses` resource supports pagination. Use the `page` and `limit` parameters to page through large result sets.

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
2. Enter a descriptive name (e.g. `Monitoring script`, `Grafana`)
3. Click **Generate key**
4. Copy the key immediately — it is shown **only once**. The server stores only a SHA-256 hash.

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
