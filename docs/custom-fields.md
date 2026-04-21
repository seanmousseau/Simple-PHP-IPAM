# Custom Fields

Custom fields let admins attach site-specific metadata to subnets and addresses without changing the database schema. Examples: Cost Centre, Circuit ID, SLA Tier, VLAN tag, Asset Number.

## Contents

- [Overview](#overview)
- [Admin workflow](#admin-workflow)
  - [Field types](#field-types)
  - [Ordering definitions](#ordering-definitions)
  - [Deleting a definition](#deleting-a-definition)
- [End-user workflow](#end-user-workflow)
- [REST API](#rest-api)
  - [Response shape](#response-shape)
  - [Writing custom fields](#writing-custom-fields)
  - [Strict type validation](#strict-type-validation)
  - [Managing definitions via API](#managing-definitions-via-api)
- [CSV export and import](#csv-export-and-import)
- [Upgrading from v3.4.x](#upgrading-from-v34x)

---

## Overview

Custom field definitions are stored in the `custom_field_defs` table. Each definition has:

- A **slug key** (`^[a-z][a-z0-9_]{0,62}$`) used in API payloads, CSV headers, and JSON storage.
- A **display label** shown in the UI.
- An **entity type** (`subnet` or `address`) — definitions are scoped per entity type.
- A **type** (`text`, `number`, `date`, `boolean`, `select`).
- An optional list of **options** (select fields only).
- A **sort order** integer that controls the rendering order in forms.
- A **required** flag that makes the field mandatory on create and update.

Values are stored as JSON in the `custom_fields TEXT NOT NULL DEFAULT '{}'` column on `subnets` and `addresses`. The JSON object keys match the definition's slug. Keys with null values are stripped on write, so only set fields are stored.

---

## Admin workflow

Go to **Admin → Custom Fields** (`custom_fields.php`). The page is split by entity type — use the **All / Subnet / Address** toggle to scope the visible definitions.

To create a definition, fill in the add form on the left:

| Field | Description |
|-------|-------------|
| Key | Lowercase slug, e.g. `cost_centre`. Immutable after creation. |
| Label | Display name shown on forms. |
| Entity type | `subnet` or `address`. |
| Type | See [Field types](#field-types) below. |
| Options | Comma-separated list, only shown when type is `select`. |
| Sort order | Integer; lower numbers appear first in forms. |
| Required | Check to make the field mandatory. |

Click **Save** to create. The definition appears immediately in the main table and in all edit forms for the selected entity type.

To edit an existing definition, click the row to expand the inline edit form. The **key** cannot be changed after creation (changing it would orphan all stored values). You can rename the **label**, change the sort order, update options (select only), or toggle required.

### Field types

| Type | Stored as | UI control | Validation |
|------|-----------|------------|------------|
| `text` | JSON string | `<input type="text">` | None (any string) |
| `number` | JSON number (int or float) | `<input type="number" step="any">` | Must be numeric |
| `date` | JSON string `YYYY-MM-DD` | `<input type="date">` | Must match `YYYY-MM-DD` |
| `boolean` | JSON boolean | Styled checkbox | Must be `true`/`false` |
| `select` | JSON string (selected option) | `<select>` | Must be one of the defined options |

### Ordering definitions

Drag rows in the table or edit the **sort order** integer on each definition. Lower numbers appear first in forms. When two definitions share the same sort order, they are sorted alphabetically by key as a tie-breaker.

### Deleting a definition

A definition cannot be deleted while any subnet or address still has a stored value for its key. This protects against accidental data loss.

To delete a definition that is in use:

1. Clear the value on every entity (via the edit form, bulk update, or direct API call).
2. Return to Custom Fields and click **Delete**.

If you attempt to delete while values exist, the admin page shows an error message listing how many rows are in use.

---

## End-user workflow

Custom field inputs appear at the bottom of the subnet edit drawer and the address inline edit form, below all core fields and above the submit button. The section heading **Custom fields** is only shown when at least one definition exists for that entity type.

- **Required fields** are marked with a red asterisk (`*`).
- **Date fields** use the browser's native date picker.
- **Select fields** show a dropdown populated from the definition's options list.
- **Boolean fields** render as a checkbox; unchecked = `false`.

Client-side validation matches server-side rules: `<input type="number">` on number fields, `<input type="date">` on date fields, and `required` on mandatory fields. The server is authoritative — client validation is a convenience only.

---

## REST API

### Response shape

`GET ?resource=subnets` and `GET ?resource=addresses` responses now include a `custom_fields` object:

```json
{
  "id": 42,
  "cidr": "10.0.0.0/24",
  "description": "Core network",
  "custom_fields": {
    "cost_centre": "IT-001",
    "circuit_id": null
  },
  "..."
}
```

Keys with null values may appear in the response when the definition exists but no value has been set. The object is always present, even when empty (`{}`).

### Writing custom fields

Pass a `custom_fields` object in the JSON body of `PUT ?resource=subnets` or `PUT ?resource=addresses`:

```bash
curl -s -X PUT \
  -H "Authorization: Bearer $API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"custom_fields": {"cost_centre": "IT-001", "sla_tier": "gold"}}' \
  "https://ipam.example.com/api.php?resource=addresses&id=99"
```

Only the keys included in the payload are updated. Existing keys not present in the payload are preserved (merge, not replace). To clear a value, pass `null` for that key.

### Strict type validation

The API enforces strict types. Sending a value of the wrong type returns HTTP **422**:

```json
{"error": "cost_centre: expected string, got integer"}
```

Type rules:
- `text` — must be a JSON string
- `number` — must be a JSON number (integer or float; strings like `"123"` are rejected)
- `date` — must be a JSON string matching `YYYY-MM-DD`
- `boolean` — must be a JSON boolean (`true`/`false`; `1`/`0` are rejected)
- `select` — must be a JSON string equal to one of the definition's options

Unknown keys (keys not in the definition list) also return 422:

```json
{"error": "typo_key: unknown custom field key"}
```

### Managing definitions via API

Custom field definitions can be managed via `?resource=custom_field_defs`:

```bash
# List all definitions
curl "https://ipam.example.com/api.php?resource=custom_field_defs" \
  -H "Authorization: Bearer $API_KEY"

# Filter by entity type
curl "https://ipam.example.com/api.php?resource=custom_field_defs&entity_type=address" \
  -H "Authorization: Bearer $API_KEY"

# Create a definition (admin role required)
curl -X POST \
  -H "Authorization: Bearer $ADMIN_KEY" \
  -H "Content-Type: application/json" \
  -d '{"entity_type":"address","key":"sla_tier","label":"SLA Tier","type":"select","options":["bronze","silver","gold"],"sort_order":10,"is_required":false}' \
  "https://ipam.example.com/api.php?resource=custom_field_defs"
```

Write operations require an **admin-role** API key. Read operations work with any valid key.

---

## CSV export and import

### Export

`export_addresses.php` includes a `custom_fields` column in both per-subnet and cross-subnet exports. The value is the JSON object serialized as a single cell, e.g.:

```
ip,hostname,status,custom_fields
10.0.0.1,gw,used,"{""cost_centre"":""IT-001"",""sla_tier"":""gold""}"
10.0.0.2,srv1,used,{}
```

Empty custom fields export as `{}`.

### Import

The CSV import wizard (`import_csv.php`) accepts a `custom_fields` mapping. In step 2 (column mapping), map any column that contains JSON-encoded custom fields to **Custom fields (JSON, optional)**.

Per-row rules:

1. If the cell is empty or `{}`, no custom field values are written (existing values are preserved when deduplication mode is `overwrite`).
2. The cell must be valid JSON. If `json_decode` fails, the row is marked **invalid** and skipped.
3. The decoded object is validated against the active definitions using the same strict rules as the API. A type mismatch marks the row invalid with the failing key in the reason column.
4. Valid rows are imported and the `custom_fields` column is set to the validated JSON.

Round-trip example — export a subnet, then re-import:

```bash
# Export
curl -s "https://ipam.example.com/export_addresses.php?subnet_id=5" \
  -b session.cookies > addresses.csv

# Edit addresses.csv — modify custom_fields cells as needed

# Import via the wizard at import_csv.php
```

---

## Upgrading from v3.4.x

**No breaking changes.** Run `upgrade.sh` as normal.

**Schema additions** applied automatically by migration `3.5.0-custom-fields`:

- New table `custom_field_defs`
- `subnets.custom_fields TEXT NOT NULL DEFAULT '{}'`
- `addresses.custom_fields TEXT NOT NULL DEFAULT '{}'`

**API change (additive):** Subnet and address responses now include a `custom_fields` object. Clients that do not inspect this key are unaffected.

**CSV change (additive):** A `custom_fields` column is appended to address exports. Import files without this column continue to work — the column is optional in the import wizard.
