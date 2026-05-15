# ADR-003: `$config` global as the only config conduit

**Status:** accepted
**Decided:** 2026-05-15
**Scope:** prerequisite for refactor wave 2 (v3.32.0 — api.php + import_csv + migrations); shapes how `Simple-PHP-IPAM/config.php` and DB-resident settings are read across the codebase.
**Stamped by:** Sean Mousseau

---

## Context

`Simple-PHP-IPAM/config.php` (gitignored, copied from `config.php.example` at install) holds **bootstrap-only configuration** — the keys the app needs before the database connection is open. The example file shows the canonical surface:

```php
return [
    'db_driver'        => 'sqlite',
    'db_dsn'           => 'sqlite:' . __DIR__ . '/data/ipam.sqlite',
    'db_user'          => '',
    'db_pass'          => '',
    'session_name'     => 'IPAMSESSID',
    'force_https'      => true,
    'app_secret'       => '',            // TOTP encryption root
    'backup_vault_key' => '',            // backup-file encryption root
];
```

After `init.php` loads it, `$config` lives in the global scope. Every function that needs to read it does:

```php
function some_helper(): void {
    global $config;
    if (!empty($config['force_https'])) {
        ...
    }
}
```

Today there are **~55 `global $config;` declarations inside lib.php**, plus 5 more across `init.php` / `oidc_callback.php` / etc. Many of those declarations are accompanied by direct array access (`$config['db_dsn']`, `$config['app_secret']`, etc.).

### The contract is muddier than the example file implies

The roadmap quote is:

> "`$config` global as the only config conduit — Hides the dependency graph."

Two distinct problems:

1. **Hidden dependencies.** A function's signature doesn't reveal which config keys it reads. Refactoring a config key (rename, type change, removal) requires grepping every function body. PHPStan can't help because the type alias `IpamConfig` (declared in `phpstan.neon`) covers the shape but not which key any given function depends on.

2. **The boundary between "config" and "settings" is leaky in both directions.**
   - **config → settings:** `ipam_setting_config_fallback()` (lib.php:~1900) lets a setting fall back to a `config.php` value if its DB row is missing. The settings registry has a `'config_key' => 'app_name'` style mirror declaration. This is the historical migration path from "everything was in `config.php`" pre-v2.6.0 to "most things are now DB settings."
   - **settings → config:** `ipam_config_inject_or_replace_key()` (lib.php:4921) rewrites `config.php` in place to lazily auto-generate `app_secret` (v3.28.2) and `backup_vault_key` (v3.24.0). Two install-key paths now write back into `config.php`.

   The two-way flow makes it genuinely hard to answer "what's the source of truth for X." For `app_name`, the answer is "settings if a row exists, else `config.php` `app_name`, else default." For `app_secret`, the answer is "always `config.php`, lazy-generated on first use." For `db_dsn`, the answer is "always `config.php`, never in settings." The pattern is consistent in its inconsistency.

3. **Test injection is awkward.** Tests today set `$GLOBALS['config']` directly, manage saved/restored state via try/finally (see v3.29.0 ADR-001 review fix in `OidcAutoProvSentinelTest`), and have to remember to reset between tests. ADR-001's PR #1205 round-1 CR comment was specifically about this leak surface.

### Why a decision matters now

- ADR-001 (accepted) introduces `setting_definitions` and shifts the read surface for settings into the DB. The `config_key` mirror column in `setting_definitions` formalises the config→settings fallback. Good.
- ADR-002 (accepted) introduces `user_preferences` — a third source for some values (theme today, more tomorrow). The "where does X come from?" question grows a third arm.
- Refactor wave 2 (v3.32.0 — api.php + import_csv + migrations) is where `$config` reads are most concentrated outside lib.php. Decomposing api.php without first deciding how config reads happen means the new files inherit the `global $config;` pattern by default.

The decision lands now so wave 1's `lib/*.php` extractions can use the post-ADR-003 pattern from day one rather than carrying `global $config;` into the new files.

## Decision drivers

- **Discoverability.** Function signature should tell a reader which config keys the function depends on. Today it doesn't.
- **Test injection.** Test setup should be cheap and leak-proof.
- **Write surface preserved.** `app_secret` and `backup_vault_key` lazy-generation paths (which legitimately write back to `config.php`) must keep working.
- **Migration cost.** ~60 call sites is meaningful churn; the option's value must justify the churn.
- **Procedural-friendly.** ADR-004 locked us into procedural-with-files (no PHP namespaces). Any solution here should not force class-based dependency injection just to read a config key.
- **Coupling to ADR-001 + ADR-002.** Settings registry `config_key` mirror is already DB-schema-described post-ADR-001. ADR-003 should compose with that, not replace it.
- **Cold-break boundary.** v3.x preserves backward compat for operator-edited `config.php`. Any config-shape change has to keep the file's external contract identical.

## Options considered

### Option A — Explicit injection (every function takes `array $config`)

**Mechanism:** Every function that reads config gains an explicit parameter:

```php
function some_helper(array $config): void {
    if (!empty($config['force_https'])) { ... }
}
```

Call sites pass it through. The `$config` global goes away. `init.php` creates the array; everyone receives it.

**Pros:**
- Function signature documents exactly what each function reads.
- No global state.
- Test injection is trivial — just pass a different array.
- PHPStan can use the existing `IpamConfig` type alias to verify shape per call.

**Cons:**
- **~60 function signature changes + ~200 call sites updated.** Every page handler that calls 10 helpers has to thread the array through.
- A function that reads a single key now sees the whole array — not actually narrower than the global.
- Functions that don't currently use config but might in the future have to be modified later to add the parameter; harder to add config-reading to a deeply-nested helper without disturbing the chain.

### Option B — Config service class (singleton)

**Mechanism:** Introduce `IpamConfig` (or similar). Internally backed by the same loaded array:

```php
class IpamConfig {
    public static function init(array $cfg): void;
    public static function get(string $key, mixed $default = null): mixed;
    public static function getNested(string ...$path): mixed;
    public static function set(string $key, mixed $value): void; // internal use
}
```

Functions read via `IpamConfig::get('app_secret')`. The global goes away.

**Pros:**
- One canonical accessor.
- Internal change to the storage shape doesn't change call sites.
- Test-mode "freeze" / "override for one test" can be implemented cleanly.

**Cons:**
- **ADR-004 just locked in procedural-without-classes.** Introducing one OOP construct just for config is a special-case carve-out that's hard to justify. If we go class-based for config, why not for audit, settings, auth?
- Static-method classes are pseudo-OOP — most of the actual code structure (procedural files, function names) stays the same, but call sites become more verbose without the benefits of real OOP.

### Option C — Strict separation — `config.php` is bootstrap-only, never read after init

**Mechanism:** After `init.php` runs, `$config` is "absorbed" — DSN goes into the PDO connection, app_secret goes into a one-time-init slot, force_https sets up the redirect, etc. No function ever reads `$config` again. Any value that's needed at runtime is either:

- In the DB (`settings` or `setting_definitions`).
- Held in a process-scoped helper (`ipam_app_secret()`, `ipam_force_https_enabled()` — each owns one specific config concept).
- Computed from the current request (`ipam_app_base_url()`).

**Pros:**
- Strongest separation of bootstrap from runtime.
- The `config.php` "external contract" question becomes precise: it's the file operators edit, and the keys it contains have a 1:1 mapping to operator-controllable runtime behaviour.
- Migrating remaining settings out of `config.php` to the `settings` table is straightforward — they were already mirrored.

**Cons:**
- **Biggest migration of the four.** Every direct `$config['app_secret']` access becomes `ipam_app_secret()` (already partially done in v3.28.2). Every `$config['force_https']` becomes `ipam_force_https_enabled()`. Every `$config['session_name']` becomes a one-time init read.
- Some values are genuinely runtime-mutable (`app_secret` lazy generation) and need a per-concept helper that knows about both the read path and the write path. We have `ipam_app_secret()` for this already; ADR-003 would multiply it for `backup_vault_key`, `app_base_url`, etc.
- Loses the convenience of "everything operator-tweakable is in one place." Operators have to know which knob is in `config.php` vs which is in Admin → Settings.

### Option D — `ipam_config()` accessor function (read-only frozen config)

**Mechanism:** Replace every `global $config; $config['key']` with `ipam_config('key')`. Single accessor function in `lib/config.php`:

```php
function ipam_config(?string $key = null, mixed $default = null): mixed {
    static $cfg = null;
    if ($cfg === null) {
        $cfg = $GLOBALS['config'] ?? [];
    }
    if ($key === null) {
        return $cfg;
    }
    return $cfg[$key] ?? $default;
}

function ipam_config_nested(string ...$path): mixed { /* … */ }

// For lazy-generation paths only:
function ipam_config_invalidate_cache(): void { /* re-read after inject_or_replace */ }
```

The `global $config;` pattern is **linted out** by the new ADR-004 module-discipline linter. Direct `$GLOBALS['config']` access is forbidden too.

**Pros:**
- **Lowest churn.** ~60 `global $config;` lines deleted + array access converted to function calls = ~120 mechanical edits, every one PHPStan-checkable.
- Procedural-friendly. Matches ADR-004's "stay procedural" stance.
- The accessor IS the documentation: every read goes through one function.
- Tests use `$GLOBALS['config'] = [...]; ipam_config_invalidate_cache();` — still one line of setup, no static-method ceremony.
- Composes cleanly with the existing `ipam_app_secret()` / `ipam_bootstrap_key()` lazy-gen helpers — those keep their direct-write paths to `config.php`; `ipam_config_invalidate_cache()` gets called after the rewrite.
- Future migration to Option B or C is straightforward — `ipam_config()` is a thin facade; swapping its internals doesn't touch call sites.

**Cons:**
- Function signatures still don't document which keys are read. (Same problem as today, but at least the read surface is now greppable on one symbol.)
- Caching is internal to the function — operator-visible if cache invalidation is forgotten after `ipam_config_inject_or_replace_key`. Mitigated by making the invalidation an explicit call after the rewrite (and a CR rule).

### Option E — Defer; lint the existing `global $config;` pattern instead

**Mechanism:** Keep `global $config;` as-is. Add a linter rule that:
- `global $config;` is only allowed in `lib/*.php` files (not in page handlers).
- Every page handler must access config via a `lib/`-side helper.
- New helpers that read config must declare which keys they touch in a doc-block.

**Pros:**
- Zero migration in v3.30.0 or v3.32.0.
- Existing code keeps working.

**Cons:**
- Doesn't actually resolve "hides the dependency graph."
- The locked roadmap §10 item is specifically about resolving this, not deferring it.
- A doc-block convention is weaker than a code-level enforcement.

## Recommendation

**Pick Option D (`ipam_config()` accessor function, frozen-after-init, linted-out global access).**

The decision drivers tip toward D because:

1. **Lowest churn for highest discoverability win.** ~120 mechanical edits is roughly half of Option A's surface and an order of magnitude less than Option C. The conversion is greppable + verifiable: `grep "global \$config\|\$config\[" | wc -l` should reach zero. PHPStan + the ADR-004 module linter co-enforce.

2. **Procedural fit.** ADR-004 just chose procedural-with-files over namespaces/classes. Option B's static-method class is the wrong shape for this codebase. Option D is a function — the same shape as every other lib helper.

3. **Composable with the lazy-gen helpers.** `ipam_app_secret()` and `ipam_bootstrap_key()` (and the soon-to-arrive `ipam_backup_vault_key()` if not already) already encapsulate the read/lazy-generate/write-back semantics for their specific keys. Option D doesn't compete — it's the generic accessor for everything else (`db_driver`, `db_dsn`, `session_name`, `force_https`). The lazy-gen helpers stay as the special-case accessors for keys that need write-back.

4. **Migration path to deeper options preserved.** If, in v4.0.0, we want to fully migrate to Option C (strict separation, never read `$config` post-init), the work is "audit every `ipam_config(...)` call site and replace with a typed helper." The accessor isolates the migration — call sites don't have to change. Same logic for Option B — replace the function body with `IpamConfig::get(...)` and keep call sites identical.

5. **Test fixture cost stays cheap.** Tests today set `$GLOBALS['config'] = [...]`. Under D, they additionally call `ipam_config_invalidate_cache()` once (or the cache is invalidated automatically when `$GLOBALS['config']` is replaced wholesale — implementation detail). One extra line per test.

Option C is **the eventual destination** — strict separation is architecturally correct — but the cost is too high for v3.32.0 in one step. D is the intermediate stop that unblocks wave 2's decomposition without re-shaping the entire bootstrap path.

## Implications

### v3.30.0 — Foundation alongside the lib.php split (ADR-004)

ADR-004 already declared `lib/utils.php` and `lib/db.php` as v3.30.0 modules. A new `lib/config.php` module joins them:

- `lib/config.php` is **part of the v3.30.0 foundation** (deps: nothing beyond utils).
- All existing `global $config;` patterns inside the modules being extracted in v3.30.0 (utils, ip, db, audit, settings, user_preferences, presentation, auth ×4) are converted to `ipam_config()` calls as part of each extraction. Net effect on v3.30.0: ~120 mechanical edits spread across 11 modules. Each extraction PR includes "and convert the moved code's config access to `ipam_config()`."
- Lib module linter (ADR-004's accompaniment) gains a rule: `global $config;` is forbidden everywhere; `$GLOBALS['config']` direct access is forbidden.

### v3.32.0 — Sweep through remaining files

- api.php, page handlers, and the remaining lib functions (in modules extracted in v3.32.0) have their config access converted.
- After v3.32.0 the codebase has zero `global $config;` declarations.

### GH issues to open

For v3.30.0 (milestone #56):
- `feat(config): introduce lib/config.php with ipam_config() / ipam_config_nested() / ipam_config_invalidate_cache()` — counts toward ADR-004's 11-module v3.30.0 scope; not a separate extraction.
- `refactor: convert global $config; usages in v3.30.0-extracted modules to ipam_config()` — bundled with each extraction PR rather than a single sweep.
- `tools: extend lib-module-linter to forbid 'global $config;' and direct $GLOBALS['config'] access` — milestone #56.
- `tests: ipam_config() — null-default fallback, nested access, cache invalidation after $GLOBALS['config'] replacement` — under #1045.

For v3.32.0 (wave 2 milestone #57):
- `refactor: convert remaining global $config; usages in v3.32.0-extracted modules + api.php + page handlers` — bundled across the wave-2 PRs.
- `docs(internal): coding-guide.md final pass — global $config; is gone, ipam_config() is the only read surface`.

### Files changed

v3.30.0:
- `Simple-PHP-IPAM/lib/config.php` — new.
- `Simple-PHP-IPAM/lib/utils.php`, `lib/ip.php`, `lib/db.php`, `lib/audit.php`, `lib/settings.php`, `lib/user_preferences.php`, `lib/presentation.php`, `lib/auth.php`, `lib/auth_password.php`, `lib/auth_rate_limit.php`, `lib/auth_recaptcha.php` — all see the conversion as part of their extraction.
- `Simple-PHP-IPAM/init.php` — populates the cache via the existing `$config` global; no behavioural change to the loading sequence.
- `testing/scripts/lib-module-linter.php` — gains `global $config;` and `$GLOBALS['config']` rules.

v3.32.0:
- `Simple-PHP-IPAM/api.php`, `import_csv.php`, plus the wave-2 extracted modules.

### Schema migrations needed

None. This ADR is pure code organisation around the existing `config.php` file.

### Docs to update

- `docs/internal/coding-guide.md` — new "Reading config" section; `ipam_config()` is the only allowed surface.
- `docs/internal/design-document.md` — invariant table gains "no `global $config;` outside `lib/config.php`."
- `docs/internal/architecture-decisions/README.md` — index update.
- `docs/internal/roadmap.md` § 10 — strike "`$config` global" from the locked-pre-wave-2 list; ADR-003 resolves it.

### Future ADRs unblocked

- **ADR-005 (`backup.php` separation)** — when the backup orchestrator/codec/dispatcher split happens for v4.0.0, the backup code reads its config (vault key, retention settings) through `ipam_config()` instead of inheriting the global. Cleaner foundation.

## Open questions

All four resolved at stamping (2026-05-15):

1. ~~Cache invalidation surface?~~ **Resolved: both — explicit call AND auto-invalidate fallback.** `ipam_config_invalidate_cache()` exists for lazy-gen helpers and test fixtures that want determinism. The function additionally detects if `$GLOBALS['config']` has been reassigned since the last cached read and auto-flushes — protects against tests that forget to call the explicit helper. Implementation detail: cache stores a sentinel keyed on `spl_object_hash(...)`-equivalent for arrays (e.g. `count($GLOBALS['config']) . serialize_keys(...)`) or simply re-bind on every read if cost is negligible.
2. ~~Nested-key shape?~~ **Resolved: separate `ipam_config_nested(string ...$path)` function.** Two functions in `lib/config.php`. No ambiguity between literal-dot keys and nested-path keys.
3. ~~Sweep aggression?~~ **Resolved: strict ADR-004 boundary in v3.30.0 (only the 11 extracted modules); open a follow-up GH issue to sweep api.php / page handlers / remaining files in v3.31.0 or v3.32.0** (Sean's call at kickoff time). Keeps v3.30.0 CR scope contained while explicitly tracking the residual conversion.
4. ~~Backwards-compat?~~ **Resolved: no-op.** No known external integrations.

## References

- ADR-001 (accepted) — `setting_definitions` schema; `config_key` mirror column documents the formal config→settings fallback.
- ADR-002 (accepted) — `user_preferences`; introduces a third source for some values.
- ADR-004 (accepted) — `lib.php` module shape; declares `lib/config.php` as a foundation module.
- `Simple-PHP-IPAM/config.php.example` — canonical bootstrap surface.
- `Simple-PHP-IPAM/lib.php:1665` (`ipam_setting_definitions`) — `config_key` mirror declarations.
- `Simple-PHP-IPAM/lib.php:3845` (`ipam_validate_config`), `:4921` (`ipam_config_inject_or_replace_key`), `:3775` (`ipam_config_sync`) — existing config shape helpers.
- `Simple-PHP-IPAM/lib/app_secret.php` (#1178, v3.28.2) — pattern for per-key lazy-gen accessors that ADR-003 leaves intact.
- `docs/internal/roadmap.md` § 10 (locked 2026-05-11) — ADR-003's source.
