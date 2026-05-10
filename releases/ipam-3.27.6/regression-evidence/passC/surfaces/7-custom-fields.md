# Surface 7 — custom fields

**Verdict:** ✅ **PASS WITH FINDING** (1 Medium, 1 Note). Validation, escaping, immutability, and CSV/API round-trip all clean.
**Date:** 2026-05-10
**Method:** Static audit via Claude Explore agent. Test instance has `custom_field_defs` count = 0 — fully static.

## Clean items

| # | Item | Evidence |
|---|---|---|
| 1 | Schema — `custom_field_defs` has `entity_type` (subnet/address), `key` (regex `^[a-z][a-z0-9_]{0,62}$`), `label`, `type` (text/number/date/boolean/select), `options`, `sort_order`, `is_required`, `is_deleted`; UNIQUE(entity_type, key) | `schema.sql:523-545`, `custom_fields.php:43` |
| 2 | Per-entity storage as JSON column on `subnets.custom_fields` and `addresses.custom_fields` (no dynamic-column anti-pattern; JSON1 functions work across all three engines) | `schema.sql:98, 154`; v3.5.0 migration comment |
| 4 | Immutability — `key`, `entity_type`, `type` immutable after creation; soft-delete blocked if field is in use | `custom_fields.php:367` comment + `custom_field_in_use()` |
| 5 | Value validation per type — number `is_numeric`, date `^\d{4}-\d{2}-\d{2}$`, boolean checkbox, select strict-membership; required enforcement throws `InvalidArgumentException` | `lib.php:10813-10841`; separate API path `validate_custom_fields_api_payload()` at `lib.php:10876` |
| 6 | HTML escape — all labels + values + select options through `e()`; no rich-text type | `lib.php:10967, 10985, 10988, 10991, 10997, 11004-11005` |
| 7 | CSV round-trip — export emits `custom_fields` as JSON string; import parses + validates via the form-side validator; required-field enforcement triggers per-row skip with reason | `export_addresses.php:16, 60`; `import_csv.php:266-283` |
| 8 | API exposure — `api_subnets_get` / `api_addresses_get` return `custom_fields` as JSON object; create/update accept via `validate_custom_fields_api_payload()` | `api.php:418, 540, 635, 669, 1888-1968, 2012-2044` |
| 9 | Audit on def CREATE/UPDATE/DELETE | `custom_fields.php:80-81, 133, 153-154` |

## Findings

### F-S7-01 — Medium — No step-up on custom field def create/update/delete

**Where:** `custom_fields.php:32, 90, 140` (action handlers `create`, `update`, `delete`). Each has `require_role('admin')` (line 5) + `csrf_require()` (line 6), but no `ipam_sudo_require()`.
**What:** Creating, modifying, or removing a custom field definition is a schema-shape change — it expands the per-row JSON document schema that every entity write thereafter must conform to. Adding a `required` field affects existing rows' future-save validation. This is more impactful than the average admin write, putting it in the same blast-radius bucket as the v3.27.0 sudo-class actions (vault key reveal, MFA disable, settings_reveal, db_tools import, api_keys create, backup destinations).
**Risk:** A compromised admin session can add/remove custom fields silently. Combined with the no-column-level diff on entity audit rows (Note N-S7-02), this can be hard to forensically reconstruct.
**Fix:** Add `ipam_sudo_require('custom_field.admin', 300)` at the top of the create/update/delete handlers. Register `custom_field.admin` in the step-up policy registry.
**Severity:** **Medium** — bundle with F-S3-02 (webhooks) and F-S5-01 (notify recipients) into a single "v3.28.0 step-up coverage sweep" backlog item.

### N-S7-02 — Note — Entity-level custom field changes audit as aggregate, not column-level diff

**Where:** Any custom field value change on a subnet or address audits as `subnet.update` / `address.update` with the full JSON before/after — not per-field.
**What:** Granular forensic question "who changed this specific custom field?" requires JSON-diffing the audit row. Tooling problem, not a bug.
**Fix:** Optional v3.29.0+ enhancement: per-field diff line in the audit detail (or a dedicated `custom_field.value_change` action for individual field mutations).
**Severity:** **Note** — quality-of-life, no action required for v3.28.0.

## Cross-cuts

- F-S7-01 + F-S3-02 (webhooks) + F-S5-01 (notify recipients): **step-up coverage sweep candidate** for v3.28.0.

## Test-instance state

No mutations made. `custom_field_defs` remains at 0 rows.
