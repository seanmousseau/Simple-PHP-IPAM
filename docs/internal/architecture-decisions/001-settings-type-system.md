# ADR-001: Settings table type system

**Status:** draft
**Decided:** —
**Scope:** prerequisite for refactor wave 1 (v3.30.0) — informs ADR-002 (per-key vs group-form) and ADR-003 (`$config` global).
**Stamped by:** —

---

## Context

The `settings` table has been the centralised key/value store for runtime configuration since v2.6.0. It has the following shape in `Simple-PHP-IPAM/schema.sql`:

```sql
CREATE TABLE IF NOT EXISTS settings (
  tenant_id  INTEGER,
  key        TEXT NOT NULL,
  value      TEXT,
  type       TEXT NOT NULL DEFAULT 'string'
             CHECK(type IN ('string','int','bool','json')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL
);
```

Engine-portable: identical structure across `schema.sql` / `schema.mysql.sql` / `schema.pgsql.sql` (the CHECK is implicit in the pgsql variant via the same column constraint).

The authoritative metadata for each setting lives **in PHP**, not in the DB, in `ipam_setting_definitions()` (`lib.php:1665`) — an array of arrays. A representative entry:

```php
'security.session_idle_seconds' => [
    'label'       => 'Session idle timeout (seconds)',
    'description' => '...',
    'type'        => 'int',
    'group'       => 'security',
    'default'     => 1800,
    'sensitive'   => false,
    'config_key'  => 'session_idle_seconds',
],
```

The full registry currently has **~60 entries** with the following metadata keys in use:

| Key | Usage |
|---|---|
| `label` | UI display name |
| `description` | help text shown under the field |
| `type` | one of `'string'`, `'int'`, `'bool'`, `'json'` — must match the DB CHECK |
| `group` | which Settings page tab |
| `default` | seed value on fresh install |
| `sensitive` | `true` for secrets (5 entries — webhook secret, SMTP password, OIDC secret, recaptcha secret, app_secret) |
| `hidden` | `true` for system-only entries not rendered in UI (6 entries — install-key announce flags, etc.) |
| `config_key` | mirror name in `config.php` |
| `options` | enum domain (literal array or callable `@<resolver>`) |

### Pain points (roadmap § 10 quote)

> "Settings table type system — bool/int/string/json is impoverished; sensitive flag bolted on."

Two distinct concerns:

1. **Type system is impoverished.** The 4-value CHECK has been stretched to cover:
   - **Enums** (`options` array on the PHP side, `'type' => 'string'` in the DB) — e.g. `oidc.default_role` is "really" enum-of-`['admin','netops','readonly']` but stored as 'string'.
   - **Secrets** (`sensitive: true` on the PHP side, `'type' => 'string'` in the DB) — there is no separate handling at the storage layer; secrets are stored as plain rows with a bolt-on UI guard.
   - **URLs / emails** (no validation at the type level — all 'string').
   - **Datetimes** (none currently, but `branding.timezone` is one example where a string with semantic structure is stored as plain 'string').

   The result: type-driven validation, type-driven rendering, and type-driven serialization all happen in 3+ different code paths that branch on `$def['type']`, plus extra branches on `$def['sensitive']`, plus extra branches on `$def['options']`. This is the "settings.php is hard to refactor safely" symptom Bug V (#1121) sits on top of.

2. **`sensitive` is bolted on at the PHP layer, not the DB layer.** A directly-INSERTed setting row bypasses every "is this sensitive?" check because the flag doesn't exist in the table. The Settings page redacts these on render, but a non-Settings code path (audit emit, export, support bundle generation) has no way to know without consulting the registry.

### Why the decision matters now

ADR-002 (per-key vs group-form bifurcation in `settings.php`) and the lib.php-decomposition work (ADR-004) both touch the validation/rendering code paths that branch on type. Resolving the type-system shape **before** wave 1 starts means those refactors can target the post-decision shape rather than landing under the current 4-value CHECK and being re-touched 6 weeks later.

## Decision drivers

- **Backward compatibility.** ~60 settings exist on every install since v2.6.0. A change that requires every install to migrate values is non-negotiable cost.
- **Engine portability.** Three SQL backends. Any DB-shape change needs a migration that's idempotent on all three.
- **Cold-break boundary.** v4.0.0 is the next planned cold break (backup format). v3.x deliberately avoids schema cold breaks.
- **Discoverability.** Settings inspection from SQL alone (no PHP context) must work for support / DR. Today it doesn't — you need the registry to interpret a row.
- **Coupling to ADR-002 + ADR-004.** Whatever shape lands here is the input to those decisions.
- **Migration debt.** Every type-system addition we accept now must run as a `migrations.php` closure on all 3 engines, FK-safe, idempotent. That's real cost per option.

## Options considered

### Option A — Expand the CHECK, add `is_sensitive` DB column

**Mechanism:** Add new allowed values to the type CHECK (`'enum'`, `'secret'`, `'url'`, `'datetime'`). Add an `is_sensitive INTEGER NOT NULL DEFAULT 0` column. Migrate existing rows by re-mapping based on the registry.

**Pros:**
- Type info readable from SQL alone (Discoverability driver: ✅).
- Engine-portable migration is straightforward (one ALTER per engine).
- `sensitive` moves out of the bolt-on tier.
- Modest scope — no entity reshuffle.

**Cons:**
- Still has the "registry duplicates DB type" problem — `ipam_setting_definitions()` continues to encode the type. Drift between registry and DB row is still possible.
- Enum domains (`options: ['admin','netops','readonly']`) still only live in PHP — the DB knows the column is `'enum'` but not which values are valid.
- Modest migration cost (~60 rows × 3 engines).

### Option B — Schema-defined registry (DB-first)

**Mechanism:** Introduce a second table `setting_definitions(key, label, description, type, default_value, is_sensitive, is_hidden, group_name, options_json)`. The `settings` table loses the `type` column entirely — type is inherited from the definition row. `ipam_setting_definitions()` becomes a thin cache over a `SELECT` of `setting_definitions`.

**Pros:**
- Single source of truth (Discoverability ✅).
- Enum domains move into the DB (`options_json`).
- Adding a new setting becomes "INSERT into setting_definitions" with no PHP code edit required (eventually).
- `sensitive` is in the DB.

**Cons:**
- Heaviest migration: new table, populate from registry, then drop the `type` column from `settings` (an ALTER on SQLite which requires a table copy).
- `setting_definitions` rows still need to be seeded from PHP at install time (the registry stays the source-of-truth-for-the-source-of-truth) — net architectural win is smaller than it looks.
- Validation logic (e.g. "session_idle_seconds must be ≥ 60") doesn't fit cleanly in a column — needs a `validator` field that points back to PHP. Pulls some metadata back into code.
- "What is the default?" question — DB default vs registry default — creates a second drift surface.

### Option C — Strongly-typed columns

**Mechanism:** Replace `value TEXT` with `value_string TEXT, value_int INTEGER, value_bool INTEGER, value_json TEXT` (and add columns as new types arrive). Only the column matching the type is populated per row.

**Pros:**
- True at-rest typing.
- No string-to-int coercion at read time.
- Easy SQL queries that filter on real values.

**Cons:**
- Disproportionate cost for the gain. Adding a new type requires a schema migration **every time**.
- Encodes "type" twice — once in column choice, once in CHECK.
- Wasted space (5 of 6 columns NULL per row).
- Doesn't solve `sensitive`-as-bolt-on.
- Operator-hostile — "what is this setting?" turns into "look at 6 columns to find the populated one."

### Option D — Defer DB shape; formalise the PHP registry instead

**Mechanism:** Leave the `settings` table exactly as-is (`string|int|bool|json` CHECK). Treat `ipam_setting_definitions()` as the authoritative contract:

- Make `sensitive` and `hidden` mandatory keys in every registry entry — add a unit test that fails the build if any entry omits either.
- Add PHP-level subtypes that serialize to `'string'` in the DB: `'enum'`, `'url'`, `'email'`, `'timezone'`, `'cidr'`, `'secret'`. Each gets a validator + a renderer. The DB type column stays one of the original 4.
- Refactor `ipam_setting_get` / `ipam_setting_set` / Settings-page rendering / Settings-page validation to dispatch on the PHP-layer subtype, never on the DB `type` column.
- Schedule the DB schema change (Option A or B shape) for v4.0.0 where it joins the backup cold break.

**Pros:**
- Zero migration cost in v3.x.
- Unblocks ADR-002 and ADR-004 immediately — the PHP layer is what those refactors actually touch.
- Lets us **prove the new PHP-layer subtype model works** before committing the DB shape to it.
- Preserves the v3.x "no schema cold breaks" rule.

**Cons:**
- The "DB doesn't know about sensitivity" problem stays open until v4.0.0.
- A direct SQL `INSERT INTO settings` bypassing the registry still works (this is mostly a non-problem — only install/migration paths do this and they're code-reviewed).
- Defers the discoverability win.

## Recommendation

**Pick Option D (defer DB schema, formalise PHP registry).**

The decision drivers tip toward D because:

1. **The pain that's blocking refactor wave 1 is in the PHP layer**, not the DB. The dispatch sprawl that makes `settings.php` hard to refactor branches on `$def['type']`, `$def['sensitive']`, and `$def['options']` — all of which are PHP-side. A DB schema change doesn't reduce that dispatch surface; a PHP-layer subtype model does.

2. **Migration cost is real.** Options A and B both require running a migration on every existing install across 3 engines. The footgun history (`apply_migrations()` FK-cascade incident, v2.2.1 wipe) means every migration we ship has an irreducible review tax — paying it for a change that doesn't unblock anything is not justified.

3. **v4.0.0 is the right home for the schema change.** The backup cold-break already breaks the v3 → v4 upgrade path; bundling a settings schema migration there means one cold break instead of two.

4. **D is reversible.** If the PHP-layer subtype model proves insufficient over the next 2–3 releases, we can still land Option A or B at v4.0.0 with no regret. A and B are NOT reversible — once we've migrated 60 rows on every install, rolling back is a second migration.

The "discoverability from SQL alone" win that Options A and B offer is real but small in practice — every real support workflow already involves looking at code, not just SQL.

## Implications

If accepted:

- **GH issues to open:**
  - `refactor(settings): introduce PHP-layer subtype dispatch (enum/url/email/timezone/cidr/secret)` — milestone #56
  - `tests(settings): assert every ipam_setting_definitions() entry declares 'sensitive' and 'hidden'` — milestone #56
  - `tests(settings): freeze the v3.x DB schema; add an "intentional drift gate" so a future DB shape change requires explicit ADR override` — milestone #56
  - `roadmap(v4.0.0): land settings schema migration (option A-shape) as part of the backup cold break` — milestone v4.0.0 / #19
- **GH issues to close / scope-cut:**
  - None directly; ADR-002 (per-key vs group-form) can now reference the locked subtype model rather than block on it.
- **Files that change:**
  - `Simple-PHP-IPAM/lib.php` — `ipam_setting_definitions()` gains mandatory keys; subtype dispatch helpers introduced
  - `Simple-PHP-IPAM/settings.php` — render + validate dispatch flips to subtype
  - `tests/SettingsTest.php` (or new `tests/SettingsRegistryShapeTest.php`) — schema-of-the-registry pinning
- **Schema migrations needed:** NONE in v3.x. The migration is scheduled for v4.0.0.
- **Docs to update:**
  - `docs/internal/config-reference.md` — document the subtype list
  - `docs/internal/coding-guide.md` — add "every new setting must declare type + subtype + sensitive + hidden"
  - `docs/internal/roadmap.md` § 10 — strike "Settings table type system" from the locked-pre-wave-1 list; ADR-001 now resolves it
- **Future ADRs unblocked:** ADR-002, ADR-004 can both now write against a stable subtype model.

## Open questions

1. **Is "defer the DB shape" actually acceptable per the v4.0.0 cold-break plan?** The cold break's existing scope is backup format. Adding settings-schema-migration to it inflates the cold-break scope. Acceptable cost? Or is the discoverability win in A worth doing in v3.x after all?
2. **`secret` subtype — at-rest encryption?** Today secrets are stored plaintext in the `value` column (modulo libsodium-encrypted webhook secrets which are an exception). Does ADR-001's "secret subtype" imply encrypt-at-rest, or just "this field is sensitive, redact it in UI / exports"? The latter is the current behaviour; the former is a bigger change.
3. **Test umbrella:** does this slot under #1045 ("behavioral parity for lib.php decomposition") or its own milestone? Leaning #1045 since the dispatch refactor IS lib.php decomposition.

## References

- `docs/internal/roadmap.md` § 10 (locked 2026-05-11)
- `docs/internal/roadmap.md` § 10.1 (six-decision sprint)
- `Simple-PHP-IPAM/schema.sql:474-487` (current settings table)
- `Simple-PHP-IPAM/lib.php:1665` (ipam_setting_definitions registry)
- Bug V #1121 — the per-key/group-form bug that ADR-002 addresses; type-system shape is upstream of that bifurcation
- `docs/internal/cleanup.md` — historical sensitive-flag bolt-on context
