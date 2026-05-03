# `IPAMBKL1` — Logical backup format specification

**Status:** DRAFT — locks on merge of #824 (v3.23.0).
**Owners:** F18 / #824. Source-of-truth for #849, #1076, #1042, #1044, and any future tooling that produces or consumes Logical backups.
**Cross-references:** `backup_overhaul.md` §2.1.1, §5, §5c', §5d.

## Purpose

`IPAMBKL1` is the on-disk format for the **Logical** backup type. It carries the operational data of an IPAM install in an **engine-agnostic, abstract-typed JSON form** that any of the three supported engines (SQLite, MySQL, PostgreSQL) can produce or consume via PDO.

Logical backups are the **primary** path for most operators (per `backup_overhaul.md` §5d). They are portable across engines (sqlite-source → mysql-target restores cleanly), survive schema evolution within the same major IPAM version (older → newer applies forward migrations), and require no host-side CLI tools to dump or restore.

## Versioning model

Three orthogonal version axes. Each has a specific job. Don't conflate them.

| Axis | Where | When it changes |
|---|---|---|
| **Magic suffix** (`IPAMBKL1`, `IPAMBKL2`, …) | First 8 bytes of file | **Breaking** format change. Readers refuse unrecognised magics. |
| **`format_version`** (header field, integer) | Header JSON | Additive, backward-compat revisions. Older readers ignore unknown header fields; newer fields must not change semantics of existing fields. |
| **`schema_version`** (header field, integer) | Header JSON | IPAM `schema_migrations` high-water mark, defined as `COUNT(*)` of the table (= `MAX(id)` under `apply_migrations()` idempotency). Monotone over a single install's lifetime; identical on two installs sharing the same migration set. Drives the restorer's same-or-older-or-newer compat decision (see §"Restore compatibility" below). The label of the most recent migration is carried separately as `last_migration_version` for human-readable diagnostics; the restorer only consults `schema_version` for compat decisions. |

`IPAMBKL1` ships with `format_version=1`. Reserved future fields are listed at the bottom of this doc.

## Physical layout

The whole file is **gzip-compressed NDJSON** (newline-delimited JSON), with the first line being the 8-byte ASCII magic. Inside the gzip stream:

```
IPAMBKL1\n                                                ← line 1: magic (literal 8 bytes + LF)
{"header":true, ...}\n                                    ← line 2: header object
{"table":"users", "row":{...}}\n                          ← line 3: first body row
{"table":"users", "row":{...}}\n
…
{"table":"audit_log", "row":{...}}\n                      ← last body row
{"footer":true, ...}\n                                    ← final line: footer object
```

Readers identify each line's shape by its distinguishing field:

- `header: true` — exactly one, must be line 2 (immediately after magic). Refuse if absent or out of position.
- `table: "<name>"` — body row. Zero or more.
- `footer: true` — exactly one, must be the final line. Refuse if absent.

A line missing all three sentinels is a corrupt stream and aborts replay.

## Header object

```json
{
  "header": true,
  "format_version": 1,
  "schema_version": 47,
  "exported_at": "2026-05-03T18:42:11Z",
  "exported_by_ipam_version": "3.23.0",
  "tenant_id": null,
  "table_order": [
    "schema_migrations", "users", "sites", "vlans", "vrfs",
    "subnets", "contacts", "addresses", "tags",
    "subnet_tags", "address_tags",
    "audit_log", "address_history", "alert_state",
    "api_keys", "login_attempts",
    "backup_destinations", "backup_schedules", "backup_runs",
    "scan_schedules", "scan_results"
  ],
  "row_counts": {
    "schema_migrations": 47,
    "users": 3,
    "subnets": 142,
    "addresses": 1283,
    "_": "..."
  }
}
```

Required fields:

- **`header`** — literal `true`. Marker.
- **`format_version`** — integer. `1` for `IPAMBKL1`.
- **`schema_version`** — integer. `COUNT(*)` from source install's `schema_migrations` table. Equivalent to `MAX(id)` under apply-migrations idempotency. The compat axis the restorer evaluates.
- **`last_migration_version`** — string. Label of the most recently applied migration (e.g. `"3.22.0"`). Diagnostic only — the restorer does not use this for compat decisions.
- **`exported_at`** — ISO-8601 UTC timestamp string with trailing `Z`.
- **`exported_by_ipam_version`** — string. Source install's `IPAM_VERSION` constant. Informational; not used for compat decisions.
- **`tenant_id`** — integer or `null`. **Always `null` in v3.23.0 (no tenancy).** Reserved for v4.0.0 partial-tenant logical backups; v3.23.0 readers must accept any value but writers must always emit `null`.
- **`table_order`** — array of table names in topological FK order (parents before children, join tables before audit). Readers replay in receipt order; they do **not** re-sort.
- **`row_counts`** — object mapping table name → expected row count for that table in the body. Used by the footer's `total_rows` cross-check and by progress reporting in the restore wizard.

Optional fields (writers may omit; readers must accept):

- `notes` — free-form string for operator-supplied annotations.

Unknown fields **must be ignored** by readers (forward compat for `format_version` bumps within the same magic).

## Body row object

```json
{
  "table": "subnets",
  "row": {
    "id": 42,
    "cidr": "10.1.0.0/24",
    "ip_version": 4,
    "network_bin": {"$bin": "CgEAAA=="},
    "prefix": 24,
    "site_id": 7,
    "vlan_fk": null,
    "vrf_id": 3,
    "description": "office subnet",
    "created_at": "2026-04-12T09:00:00Z",
    "updated_at": "2026-04-12T09:00:00Z"
  }
}
```

- **`table`** — string. Must be a member of `header.table_order`. Rows for a given table arrive contiguously and in the same order as `table_order`. Reader detects table-boundary by the table-name change (no separator markers needed).
- **`row`** — object mapping column name → abstract-typed value. Must include the source PK column (so re-emit-IDs replay can build the idmap), all FK columns, and every non-nullable non-default column.

### Abstract type encoding

| Source value | JSON encoding |
|---|---|
| Integer (any size) | JSON number, integer literal |
| String (UTF-8 text) | JSON string |
| Boolean | JSON `true` / `false` |
| `NULL` | JSON `null` |
| Timestamp (any engine's native datetime) | JSON string, ISO-8601 with explicit timezone (always `Z` for UTC) |
| Date (no time component) | JSON string, `YYYY-MM-DD` |
| Binary blob (`ip_bin`, `network_bin`, future binary cols) | JSON object `{"$bin": "<base64>"}` — base64-encoded raw bytes. The `$bin` envelope is non-ambiguous because no normal column value would naturally produce such an object. |

The `$bin` envelope is the only "boxed" type. Everything else is plain JSON. This keeps the format readable in `jq` / `less` for debugging.

## Footer object

```json
{
  "footer": true,
  "checksum_sha256": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
  "total_rows": 1521
}
```

- **`checksum_sha256`** — hex SHA-256 of the concatenation of every body row's raw NDJSON bytes (i.e. lines 3 through N-1 verbatim, including trailing `\n`s, before gzip). Detects any in-flight corruption that gzip's CRC didn't catch.
- **`total_rows`** — integer. Must equal `sum(header.row_counts.values())`. Reader cross-checks; mismatch aborts.

Readers compute the running sha256 as they consume body rows; on the footer line they compare to `checksum_sha256` and fail loudly on mismatch.

## Restore compatibility — schema_version axis

The only compatibility decision the restorer makes is `header.schema_version` vs the install's current `MAX(schema_migrations.version)`:

| Source vs target | Behaviour |
|---|---|
| Equal | Direct replay. |
| Source older than target | Replay the data. Columns added by intervening migrations take their schema defaults at INSERT time (each engine applies the column's `DEFAULT` clause when the INSERT omits it). The target's `schema_migrations` history is preserved across restore — the install can resume normal migration flow afterward without re-running anything. Data-level transforms (a hypothetical migration that copies values between columns post-population) are **not** re-applied automatically; operators with that concern should restore on a same-version install. |
| Source newer than target | Refuse with operator-facing message: *"This backup is from schema version N; the install is at version M. Upgrade the install to N or newer before restoring."* No automatic backward migration — schema rollback is unsafe. |

Engine identity is **never** a compat axis. A sqlite-source backup restores cleanly onto a mysql target and vice versa, because the wire format carries no engine-specific syntax.

## Replay strategy — re-emit IDs

The restorer **does not** preserve source PKs. Source IDs are present in the dump only so that FK columns on later rows can reference them; the actual INSERT generates a fresh PK from the target engine's auto-increment.

State maintained during replay:

```
idmap: dict[table_name, dict[source_id, target_id]]
```

Procedure for each body row:

1. Look up the table's FK columns from the live schema.
2. For each FK column whose value is non-null, replace the value with `idmap[fk_target_table][source_value]`. If the lookup misses (parent not yet seen), the dump is malformed (FK target wasn't dumped before referrer) and replay aborts.
3. INSERT the (FK-remapped, PK-stripped) row.
4. Capture `lastInsertId()` and store `idmap[current_table][source_id] = target_id`.

### Self-referential tables

Tables with an FK pointing into themselves (currently only `sites` via `parent_id`) replay in **two passes**:

- **Pass 1:** insert every row of the table with the self-FK column set to `NULL`. Capture idmap entries as normal.
- **Pass 2:** walk the same body rows again; for each whose source self-FK was non-null, emit `UPDATE <table> SET parent_id = idmap[table][source_parent_id] WHERE id = idmap[table][source_id]`.

The format does not encode "this is a two-pass table" — the restorer infers it from the live schema.

### Tables without auto-increment PKs

`schema_migrations` is **not** inserted on restore at all — the target install's `apply_migrations()` already populated it to a compatible high-water mark, and the source's rows are redundant. This preserves the target's migration history so the install can resume normal migration flow afterward.

### Append-only audit table

`audit_log` is wiped-skip on restore: the per-table no-DELETE trigger blocks the wipe pass, and restore does not (and cannot) drop and recreate the trigger. Source's `audit_log` rows therefore **append** to whatever the target carries. Two consequences operators should be aware of:

- Rows emitted by a migration that both source and target ran (e.g. a `settings.seeded_from_config` audit entry) appear duplicated post-restore — once from the target's own migration run, once from the source's dump.
- `audit_log.user_id` references source's `users.id` values that no longer exist after re-emit-IDs replay. The column has no FK constraint so the row inserts cleanly; the `username` TEXT column preserves the human-readable identity.

This divergence is intentional — the alternative (drop triggers, DELETE, recreate triggers) trades a small amount of duplication for a much riskier restore path.

### Per-engine FK bracketing

The whole replay runs inside a single FK-disabled session, restored at the end:

| Engine | Disable | Restore |
|---|---|---|
| SQLite | `PRAGMA foreign_keys = OFF` *(set BEFORE `BEGIN TRANSACTION` per CLAUDE.md)* | `PRAGMA foreign_keys = ON` |
| MySQL | `SET FOREIGN_KEY_CHECKS = 0` | `SET FOREIGN_KEY_CHECKS = 1` |
| PostgreSQL | `SET session_replication_role = 'replica'` | `SET session_replication_role = 'origin'` |

Bracketing is applied exactly once per restore (not per-table). The `finally` clause restores the original setting on every exit path including failure.

### Per-engine binary binding

Every binary column write goes through `ipam_bind_binary()` (CLAUDE.md, v2.9.0 #379). This is non-negotiable on every engine — `PARAM_LOB` for SQLite (BLOB affinity), MySQL (`VARBINARY(16)` round-trip without null-byte truncation), and PostgreSQL (`BYTEA` round-trip with explicit type hint).

## Reserved fields (future)

The following fields are reserved for forward-compat without bumping the magic. Writers may emit them; older readers must ignore them:

- **`tenant_id`** in header — already in v3.23.0 spec, always `null`. v4.0.0 may emit a non-null tenant_id for per-tenant logical exports.
- **`encryption`** in header — deferred to v3.24.0 / `IPAMBKP3` work. Logical backups in v3.23.0 are unencrypted on disk; the existing IPAMBKP2 envelope wraps neither format yet.
- **`row.<col>`** with new `$tag` envelopes — the `{$bin: ...}` envelope is the only reserved pattern in v3.23.0. Future envelopes (e.g. `{$ref: "..."}` for cross-table references in partial-tenant exports) follow the same `$`-prefixed pattern.

A field that requires breaking compatibility (e.g. a different binary encoding, a non-NDJSON layout) bumps the magic to `IPAMBKL2`.

## Conformance

A writer is conformant iff it produces files that:

1. Begin with the 8-byte magic followed by `\n`.
2. Have exactly one header object and exactly one footer object, in those positions.
3. Emit all body rows in `header.table_order` order, contiguously per table.
4. Encode every value per the abstract-type table.
5. Produce `header.row_counts` and `footer.total_rows` matching the actual body content.
6. Produce `footer.checksum_sha256` matching the SHA-256 of the body NDJSON bytes.

A reader is conformant iff it:

1. Refuses files with an unrecognised magic.
2. Refuses files where `header.format_version` is greater than its supported version.
3. Refuses files where `header.schema_version` is greater than the install's current schema version.
4. Cross-checks the footer's checksum and `total_rows`; aborts on mismatch.
5. Performs FK remapping per the re-emit-IDs strategy above.
6. Restores all FK-bracket / sequence settings on every exit path including failure.

Conformance is asserted by #1042's test umbrella across all three engines and the 3×3 cross-engine matrix.
