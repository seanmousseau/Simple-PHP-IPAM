# ADR-001: Settings table type system

**Status:** accepted — **core decision reversed 2026-05-16 (see Amendment 2 below): Option B withdrawn, Option D adopted.**
**Decided:** 2026-05-15
**Scope:** prerequisite for refactor wave 1 (v3.30.0) — informs ADR-002 (per-key vs group-form) and ADR-003 (`$config` global).
**Stamped by:** Sean Mousseau

---

> ## ⚠️ Amendment 2 (2026-05-16, Sean) — decision reversed to Option D; the `setting_definitions` table is withdrawn
>
> **The 2026-05-15 choice of Option B (a DB-backed `setting_definitions` table)
> was wrong and is reversed. v3.30.0 adopts Option D instead: the settings
> registry stays in PHP; no `setting_definitions` table ships.**
>
> **What exposed it.** During v3.30.0 execution a full 3-driver Playwright +
> `run-engine-phpunit` gate run found `setting_definitions` is **empty on every
> fresh install of every engine**. Root cause: `ipam_db_init()`'s fresh-install
> branch *stamps* every migration as already-applied and never calls
> `apply_migrations()` — the project's settled design is "on a fresh install the
> schema file IS the final state; migrations only run on upgrades." The
> `3.30.0-setting-definitions` migration *seeds* ~103 rows, so on a fresh install
> that seed never executes, and no schema file carries the seed data. The table
> was therefore populated only on upgrades and, by accident, on SQLite demo-mode
> (where `demo_reset_db()` wipes `schema_migrations` and re-runs migrations).
>
> **Why Option B was the wrong call.** Option B was framed as "a heavier lift
> with a long-term payoff." Both halves were wrong:
> - The payoff — "single source of truth" — was *inverted*. The registry is
>   authored in PHP (`ipam_setting_definitions()`); the table is only a
>   projection of it. Option B's own cons section already conceded this ("the
>   registry stays the source-of-truth-for-the-source-of-truth"). The table did
>   not create a single source of truth — it created a *second copy* that had to
>   be kept in sync, and the proposed fixes (schema-file seed INSERTs) would have
>   added three more copies.
> - The other claimed payoff — SQL-level discoverability — is marginal. Setting
>   *definitions* are version-static application structure, identical across
>   every install of a release; they are a `grep` of `lib/settings.php` away.
>   (Setting *values* in the `settings` table are install-specific and remain
>   SQL-discoverable — `settings` is unaffected.)
> - The "lift" delivered no offsetting benefit: a table in three schema files, a
>   data-seeding migration, a DB-read + per-request-cache + seed-fallback path,
>   and 3-engine parity coverage — all to materialise a PHP array into SQL.
>
> Option D — keep the registry in PHP, add the type system as a PHP-layer model
> — was the originally-recommended option. It is now adopted.
>
> **Clean to remove.** The `setting_definitions` table was introduced *entirely
> within the unshipped v3.30.0 branch*. v3.29.0 (shipped) has no such table, so
> removal needs no production drop-migration — the branch's own
> `3.30.0-setting-definitions` migration and schema-file `CREATE TABLE` are
> simply withdrawn.
>
> **What v3.30.0 keeps** (the genuine value of this ADR's work — all of it lives
> in the PHP layer and is independent of the table):
> - The **11-value logical-type model** (`string,int,bool,json,enum,secret,url,
>   email,timezone,cidr,datetime`) and the **subtype dispatch / validation**
>   (`ipam_setting_validate()` and the storage-vs-logical type split).
> - `ipam_setting_definitions()` returns the registry array enriched with
>   `logical_type` and `storage_type`, computed in PHP (via
>   `ipam_setting_definitions_logical_type()`); no DB read, no fallback, no cache.
> - The **`settings.type` column drop** (Phase 5.4) stays — the storage type is
>   derived from the registry definition regardless.
> - Per-setting metadata (`min_value`, `max_value`, `multiline`, `deprecated`,
>   `options`) lives in the registry array, as it always did.
>
> **What is removed:** the `setting_definitions` table from `schema.sql` /
> `schema.mysql.sql` / `schema.pgsql.sql`; the `3.30.0-setting-definitions`
> migration and its amendments; the DB-read / per-request-cache / seed-fallback
> code in `lib/settings.php`; and the "frozen seed + every new setting needs its
> own migration INSERT" rule — new settings are simply new registry entries.
>
> **Amendment 1 (below, 2026-05-16) is now moot** — it tuned columns of a table
> that no longer exists. It is retained only as history.
>
> **Knock-on:** the v3.31.0 encrypt-at-rest milestone keys "is this secret?" off
> the registry's `sensitive` flag (as it always effectively did), not off a
> `setting_definitions.type = 'secret'` row. ADR-002 and ADR-004 cross-references
> to `setting_definitions` are corrected to "the PHP settings registry."

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

**Decided: Option B (schema-defined registry) + encrypt-at-rest for the `secret` subtype, in v3.30.0.**

Claude's original draft recommended Option D (defer the DB shape, formalise the PHP layer). Sean overrode to Option B + encrypt-at-rest on 2026-05-15 with the following rationale:

- **Single source of truth is the higher-order win.** The "registry drift" surface that Option D leaves open is the root of multiple v3.21–v3.27 bugs (Bug V's per-key/group-form bifurcation is one example). Moving the registry into the DB closes that drift surface permanently rather than papering over it with a PHP-side lint.
- **v4.0.0 is reserved for the backup cold break.** Adding settings migration to v4.0.0 inflates the cold-break scope on top of a release that is already a deliberately-narrow operator-visible disruption. Better to absorb the settings migration in v3.30.0 where there is no operator-visible UX change.
- **Encrypt-at-rest for secrets is non-negotiable in this iteration.** Plaintext secrets in the `settings` table (SMTP password, OIDC client secret, recaptcha secret, others) are a real exposure — every backup export, every support bundle, every operator with `cat ipam.sqlite` access reads them. The webhook-secrets exception established the libsodium pattern in v3.3.0; ADR-001 extends that pattern to the full `secret`-subtype set.
- **Reversibility is preserved differently.** Option B is reversible by writing a "B → D" migration that re-collapses the registry into PHP. That's a known migration shape, not a cold-break. The trade is real but acceptable given the payoff.

The "biggest payoff" framing in Option B's pros section is load-bearing for this decision.

## Implications

**Sliced across two releases** (decision 2026-05-15): v3.30.0 lands the schema-and-dispatch portion (no crypto); v3.31.0 lands encrypt-at-rest + webhook consolidation. Each release tells one story and runs its own CR cycle. Roadmap §6 shifts: wave 2 → v3.32.0, wave 3 → v3.33.0.

### v3.30.0 — Setting definitions schema + dispatch refactor (no crypto)

#### Schema changes (v3.30.0 migration)

New table `setting_definitions`:

```sql
CREATE TABLE setting_definitions (
  key            TEXT PRIMARY KEY,
  label          TEXT NOT NULL,
  description    TEXT NOT NULL DEFAULT '',
  type           TEXT NOT NULL,        -- 'string','int','bool','json','enum','secret','url','email','timezone','cidr','datetime'
  default_value  TEXT,
  group_name     TEXT NOT NULL,
  is_sensitive   INTEGER NOT NULL DEFAULT 0,
  is_hidden      INTEGER NOT NULL DEFAULT 0,
  options_json   TEXT,                 -- for type='enum': JSON array of allowed values OR '@<resolver>' sentinel
  config_key     TEXT,                 -- mirror name in config.php, nullable
  ordering       INTEGER NOT NULL DEFAULT 0
);
```

> **Amendment (2026-05-16, Sean) — as-built v3.30.0 schema differs from the block above.**
> An architecture review during v3.30.0 execution found `subtype` and `validator`
> were speculative scaffolding (shipped seeded `NULL`, no reader, no concrete
> design — `validator` was only ever listed as a *con* of Option B, `subtype` was
> never specified). Carrying unused columns in a frozen migration across releases
> is avoidable tech debt, so:
> - **`subtype` and `validator` are dropped.** They will be re-added *with a real
>   design* if and when a release concretely needs them.
> - **Four columns are added** to carry the validation/render metadata the v3.29.0
>   registry actually uses: `min_value`, `max_value` (numeric bounds — a float type,
>   so float-bounded settings such as `recaptcha_enterprise.score_threshold` fit),
>   `is_multiline`, `is_deprecated`.
>
> The 11-value `type` column and the dispatch model are unchanged. The in-memory
> definition array returned by `ipam_setting_definitions()` exposes the 11-value
> type as `logical_type` and the 4-value storage type as `storage_type` (never a
> bare `type` key — that name is reserved for the DB column to avoid a same-name /
> different-meaning collision).

`settings` table loses the `type` column (engine-portable via "create new table, INSERT-SELECT, drop old, rename" on SQLite; ALTER on mysql/pgsql).

#### v3.30.0 GH issues to open (milestone #56)

- `feat(settings): setting_definitions table + ipam_setting_definitions() reads from DB`
- `migration: drop settings.type column, populate setting_definitions from v3.29.0 registry seed` (needs `migration-reviewer` + `multi-engine-schema-parity` subagent passes)
- `refactor(settings.php): dispatch on setting_definitions.type, eliminate $def['type'] PHP-side branching`
- `tests(settings): full coverage of new subtype dispatch + migration idempotence` — bucketed under #1045
- `docs(internal): coding-guide.md — settings entry mandatory keys; 'sensitive' / 'hidden' are now DB columns, not PHP keys`
- `docs(internal): data-dictionary.md — setting_definitions entry + updated settings entry (no type column)`

v3.30.0 explicitly **does not** change at-rest encryption for any value. Plaintext secrets remain plaintext through v3.30.x → the encrypt-at-rest pipeline lands in v3.31.0.

### v3.31.0 — Encrypt-at-rest pipeline + webhook crypto consolidation

#### Encrypt-at-rest pipeline

- Every row in `settings` whose `setting_definitions.type = 'secret'` is libsodium-encrypted at write time using a KDF rooted at `app_secret` (already in `config.php`, not in DB — circular-key problem solved).
- New helpers `ipam_secret_get(string $key): ?string` / `ipam_secret_set(string $key, string $value): void` wrap `ipam_setting_get/set` and handle encrypt/decrypt transparently.
- Webhook secrets (already libsodium-encrypted since v3.3.0 via their own column-level path) migrate to this new shared pipeline — eliminates the duplicate crypto code path.
- A one-shot migration re-encrypts every existing plaintext secret row (~5 keys: SMTP password, OIDC client secret, recaptcha secret, …) using `ipam_secret_set`.

#### v3.31.0 GH issues to open (new milestone TBD — `Settings encrypt-at-rest + webhook crypto consolidation`)

- `feat(settings): ipam_secret_get / ipam_secret_set + libsodium pipeline rooted at app_secret`
- `migration: re-encrypt existing plaintext secret rows` (idempotent; safe to replay)
- `refactor(webhooks): consolidate v3.3.0 webhook-secret crypto onto shared ipam_secret_* pipeline`
- `tests: encrypt-at-rest round-trip across all secret subtype rows; key-rotation no-op smoke`
- `docs(internal): backups.md DR section — at-rest-encrypted secrets require app_secret in config.php to decrypt; refresh the "back up your keys, not just your data" guidance`
- `docs(internal): security-model.md — encrypt-at-rest secret pipeline section`
- `docs/upgrading.md v3.31.0 — operator-facing note that app_secret in config.php is now the encryption root for all settings-table secrets; backup config.php` (operator-visible callout)

### Test umbrella

All test work for this ADR slots under **#1045** (lib.php decomposition tests). The subtype dispatch refactor IS lib.php decomposition; tests ship with the refactor commits.

### GH issues to scope-out

- ADR-002 (per-key vs group-form) — references the locked subtype model rather than blocking on it. Its scope shrinks because some dispatch surface moves into the new schema.
- The "v4.0.0 settings schema migration" follow-up that the original recommendation would have opened is **no longer needed** — v3.30.0 absorbs it.

### Files that change

- `Simple-PHP-IPAM/schema.sql`, `schema.mysql.sql`, `schema.pgsql.sql` — new `setting_definitions` table, drop `settings.type` column
- `Simple-PHP-IPAM/migrations.php` — new migration closure for setting_definitions + drop-type-column + re-encrypt plaintext secrets
- `Simple-PHP-IPAM/lib.php` — `ipam_setting_definitions()` now reads DB, `ipam_secret_get/set` helpers, dispatch helpers
- `Simple-PHP-IPAM/settings.php` — render/validate flips to subtype
- `Simple-PHP-IPAM/webhooks.php` — switches to shared `ipam_secret_*` pipeline
- `Simple-PHP-IPAM/views/install_keys_panel.php` — confirm display logic still works after schema move

### Docs to update

- `docs/internal/data-dictionary.md` — `setting_definitions` table entry; updated `settings` table entry (no `type` column)
- `docs/internal/config-reference.md` — document the full subtype list + which subtypes are encrypted at rest
- `docs/internal/coding-guide.md` — settings registry rules, "use `ipam_secret_get` for sensitive reads"
- `docs/internal/backups.md` — DR consequence of encrypt-at-rest (already implied by app_secret backup, but make it explicit)
- `docs/internal/roadmap.md` § 10 — strike "Settings table type system" from the locked-pre-wave-1 list (ADR-001 resolves it)

### Future ADRs unblocked

- **ADR-002 (per-key vs group-form):** can now reference `setting_definitions` rows directly.
- **ADR-003 (`$config` global):** the migration map (which settings keys mirror to `$config`) now has a DB representation via `setting_definitions.config_key`.
- **ADR-004 (lib.php size):** the settings code path is one of the larger lib.php islands; this ADR shrinks it before decomposition starts.

### Roadmap §6 shift

The slice decision moves wave 2 and wave 3 by one slot each:

| Was | Now |
|---|---|
| v3.30.0 → #56 lib.php decomposition | **v3.30.0 → #56 lib.php decomposition** (unchanged theme; subset of ADR-001 scope — schema + dispatch only) |
| v3.31.0 → #57 wave 2 (api.php + import_csv + migrations) | **v3.31.0 → Settings encrypt-at-rest + webhook crypto consolidation** (new milestone) |
| v3.32.0 → #58 wave 3 (frontend) | **v3.32.0 → #57 wave 2** (slid one slot) |
| — | **v3.33.0 → #58 wave 3** (slid one slot) |

`docs/internal/roadmap.md` §6 table needs the corresponding edits.

## Open questions

All three open questions resolved at stamping (2026-05-15):

1. ~~Is "defer the DB shape" actually acceptable per the v4.0.0 cold-break plan?~~ **Resolved:** No — v3.30.0 absorbs the schema change (Option B).
2. ~~`secret` subtype — at-rest encryption?~~ **Resolved:** Yes — encrypt-at-rest for all secrets via the shared libsodium pipeline, rooted at `app_secret`.
3. ~~Test umbrella: #1045 or own?~~ **Resolved:** #1045.

## References

- `docs/internal/roadmap.md` § 10 (locked 2026-05-11)
- `docs/internal/roadmap.md` § 10.1 (six-decision sprint)
- `Simple-PHP-IPAM/schema.sql:474-487` (current settings table)
- `Simple-PHP-IPAM/lib.php:1665` (ipam_setting_definitions registry)
- Bug V #1121 — the per-key/group-form bug that ADR-002 addresses; type-system shape is upstream of that bifurcation
- `docs/internal/cleanup.md` — historical sensitive-flag bolt-on context
