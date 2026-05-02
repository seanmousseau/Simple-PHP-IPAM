# v4.0.0 multi-tenancy design

> Forward-looking design for the v4.0.0 multi-tenancy release. **Not currently in force** — v4.0.0 has not shipped. This doc is the locked design that v4.0.0 implementation will follow; CLAUDE.md keeps only a one-line pointer.
>
> Read this when: planning v4.0.0 work, designing a feature that needs to be tenant-aware, or writing a migration whose version sorts after v4.0.0.

---

## Opt-in model

Multi-tenancy in v4.0.0 is **opt-in**. The v4.0.0 schema migration always runs (adds `tenant_id` columns, creates the `tenants` table, assigns all existing data to a built-in "default" tenant), but the multi-tenancy *feature* is disabled by default. The app behaves identically to v3.x until an admin explicitly activates it. Every install — including single-tenant self-hosted installs that never intend to use multi-tenancy — gets the schema upgrade automatically and works correctly without any further action.

### Activation wizard

Activation is via an explicit conversion wizard, not a settings toggle flip. The wizard:

1. Runs a pre-flight check (prerequisites met, admin email set, HTTPS enforced)
2. Prompts the admin to name the default tenant (name, slug, contact email)
3. Shows a summary of all rows to be assigned (subnet count, address count, user count, etc.) with a clear "this cannot be undone without a restore" warning
4. Requires the admin to type the tenant name to confirm (same pattern as GitHub repository deletion)
5. Sets `multi_tenancy_enabled = true` in the `settings` table and creates a `tenancy.enabled` audit log entry
6. Redirects to the tenant management page

This is a **one-way operation**. Reversing it requires a database restore.

### SQLite limitation

SQLite installs receive the default tenant and schema migration like any other engine, but **creating additional tenants is disabled** (`multi_tenancy_enabled` cannot be set to `true` on SQLite). SQLite is single-tenant by design.

---

## Tenant URL resolution — `/t/slug/` path prefix

*Decided 2026-04-24.*

Tenant context is resolved via a `/t/{slug}/` URL path prefix. Subdomains are not supported natively — operators who want subdomain branding use a reverse proxy (`acme.ipam.example.com` → `/t/acme/`). Single-tenant installs have zero URL change.

Apache rewrite:

```apache
RewriteRule ^t/([a-z0-9-]+)/(.*)$ /$2 [E=IPAM_TENANT:$1,L]
```

`init.php` reads `$_SERVER['IPAM_TENANT']`, resolves the tenant slug, sets and validates `$_SESSION['tenant_id']` on every request.

### Root URL `/` with multi-tenancy enabled

Shows a **tenant discovery page** — not a super-admin panel and not a direct login. User enters their organization slug or friendly name (case-insensitive match against `tenants.slug` OR `tenants.name`); system redirects to `/t/slug/login.php`.

Super-admins log in through the discovery page like any other user — they are regular users with `is_super_admin = 1` on a home tenant; their flag activates the global panel and tenant switcher after login. **No special super-admin URL.** Direct links to `/t/slug/login.php` bypass the discovery page — both paths work.

Single-tenant installs: `/` shows the normal login page as today; discovery page is never shown.

---

## Settings cascade — tenant → global fallback

*Decided 2026-04-24.*

`ipam_setting()` resolves in order:

1. **Tenant-specific row** (when `$tenantId` is provided)
2. **Global row** (`tenant_id IS NULL`)
3. **`$GLOBALS['config']`** via the registry's `config_key` path (v2.6 back-compat for installs that have not yet migrated config.php values to the DB)
4. **Registry default** → caller default

The `settings` table has a nullable `tenant_id` column. Uniqueness is enforced per engine:

- **SQLite and PostgreSQL** — partial unique indexes (`uq_settings_global WHERE tenant_id IS NULL`, `uq_settings_tenant WHERE tenant_id IS NOT NULL`).
- **MySQL** — composite `UNIQUE(tenant_id, key)` with a per-write advisory lock (`GET_LOCK`) to serialise concurrent INSERTs for global rows, because MySQL's composite UNIQUE allows multiple NULL values in the same column.

All v3.x rows use `tenant_id = NULL`.

**Tenant admins can never read or write global-layer settings** — the UI shows a read-only "Using system settings" indicator when falling back to global (e.g. SMTP). Only super-admins can access the global layer.

---

## `app_secret` and per-tenant key derivation

`app_secret` is a server-level master key. It **must always live in `config.php`**, never in the database. Its purpose is to protect data *in* the DB — storing it in the same place it protects defeats the entire security model. This rule is unconditional: no feature, migration, or "convenience" justification overrides it.

### Current use (v3.x)

`app_secret` is used directly as the key material for TOTP secret generation. All users on a single-tenant install share the same effective key.

### Per-tenant key isolation (v4.0.0)

Rather than storing per-tenant secrets in the DB (which would put the key and the protected data in the same breach radius), per-tenant keys are **derived at runtime** via HKDF:

```text
tenant_key(tenant_id, purpose) = HKDF-SHA256(app_secret, "ipam-v4:" || tenant_id || ":" || purpose)
```

- `purpose` is a fixed string per use case: `"totp"`, `"backup"`, etc.
- Each tenant gets a cryptographically unique key per purpose with no extra storage.
- A DB breach of one tenant's rows does not expose another tenant's keys.
- `app_secret` remains the single point of trust, outside the DB.

### Key rotation

Rotating `app_secret` invalidates all derived keys — existing TOTP enrollments stop working and backup encryption keys change. This is the correct and expected behaviour; operators are responsible for rotating deliberately and with a plan (re-enroll users, re-encrypt backups).

If zero-disruption rotation becomes a hard requirement in future, envelope encryption (per-tenant secrets encrypted with `app_secret`, stored in `tenants` table) can be adopted at that point — but that adds complexity and is not the v4.0.0 plan.

### Auto-generation

The v4.0.0 conversion wizard generates a cryptographically strong `app_secret` if `config.php` does not already contain one, writes it to `config.php` once, and never touches it again. **This is the only time auto-generation occurs** — runtime auto-generation is forbidden.

---

## Creating new data tables in post-v4.0.0 releases

*Applies to any migration whose version sorts after v4.0.0, including v4.0.x patches.*

Once v4.0.0 has shipped and the tenancy migration has run, every data table in the IPAM schema has a `tenant_id` column pointing at the `tenants` table. **Any migration in a release numbered greater than v4.0.0 that creates a new data table must include `tenant_id` in the `CREATE TABLE` statement from day one**, with `NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT` and an index on `tenant_id`.

This rule exists because the v4.0.0 tenancy migration only backfills tables that existed at the time it ran. A table created in v4.1.0, v5.0.0, or any later release is outside that migration's reach and must carry its own tenant scoping from creation.

Pre-v4.0.0 migrations do not need to worry about this — they predate tenancy and are handled automatically by v4.0.0's runtime table enumeration (see #406 for implementation). The rule applies strictly to migrations whose version key sorts greater than v4.0.0 under natural-sort ordering.

### Exception: explicitly global tables

Tables that are explicitly global and not tenant-scoped (e.g. `users`, `tenants` itself, future `system_health` or similar) do not take `tenant_id`.

Note: `settings` is **not** an exception — it has a nullable `tenant_id` column since v3.13.0 and uses a tenant→global fallback model.

When adding a genuinely global table:

1. Document in the migration closure why it is global.
2. Update `docs/tenancy.md` (user-facing docs) to list it as an exception.

---

## Cross-references

- `CLAUDE.md` → "Multi-tenancy (v4.0.0 — forward-looking)" — one-line pointer.
- `adding-a-setting.md` — the settings cascade is the v4.0.0 contract; reads work today.
- `adding-a-migration.md` — when v4.0.0 ships, this doc's rules become enforceable.
