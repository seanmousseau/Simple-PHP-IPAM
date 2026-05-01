# Backup & Restore Overhaul — Working Draft

> **Status:** internal discussion document. Not a plan, not a spec. Captures the running list we agree on so the eventual milestone-and-issue split is informed and complete. **No code changes are made on the basis of this document until it's promoted to issues against an explicit milestone.**
>
> **Origin:** v3.19.1 hotfix shipped 2026-04-29 closed four production-blocking bugs in v3.17's backup feature (#783 #784 #785 #788). User's framing afterwards: "we will not even think about moving towards v4.0.0 until this is ironed out and 100% functional. We may need additional v3.x.x milestones."
>
> **Gating commitment (Sean, 2026-04-29):** **v4.0.0 multi-tenancy work does not start until this overhaul is fully complete.** No timeline pressure — the priority is getting it right this time. Means the v4.0.0 milestone (currently 25 issues) is frozen in scope; new tenancy planning waits until the unified backup surface is shipped, exercised, and stable across 1–2 release cycles.
>
> **Contributors:** Sean (project owner), Claude (assistant). Each section flagged either **AGREED** (both parties signed off), **PROPOSED** (one party suggested, awaiting other's view), or **OPEN** (active disagreement or unresolved).

---

## 1. Current state — the mess

Backup-related UI surfaces today are spread across **six** admin pages with no coherent grouping:

| Page | What's there | Why it shouldn't be there |
|---|---|---|
| `settings.php` (Backups tab) | `backup.enabled` toggle, retention count, `alert_email` recipient, `mail.from` | Backup operational config buried under generic settings |
| `db_tools.php` ("Database") | Manual SQL export / SQL import (SQLite only); manual JSON / CSV export; raw SQL dump download | Mixes data-export (different feature) with database-backup; SQLite-only gating is misleading; old "Database" branding is meaningless |
| `restore_web.php` ("Restore Database") | Three-phase restore wizard for SQLite (download → dry-run → apply) | Only restores from cloud destinations; no manual-upload flow; orphaned from the Backup pages it depends on |
| `remote_backups.php` | List remote files for one destination + per-row Download/Verify/Delete | Verify just-fixed in v3.19.1 (#788); no equivalent for local-on-disk backups; can't compare runs |
| `backup_history.php` | Local-only `backup_history` table view (legacy v3.7 CLI runner) | Disconnected from `backup_log` (v3.17 destination runner); two separate logs that mean different things |
| `destinations.php` | Create / Test / Toggle / Delete destinations + Schedules table | No Edit on either (#778, #780); no auto-Test on Save (#787); no Run-now on destination rows (#779); fields don't hide per frequency (#781); UTC timestamps (#782) |

Two parallel orchestrators (`run_db_backup_if_due` legacy v3.7 path → `backup_history`; `ipam_backup_run_for_destination` v3.17 path → `backup_log`) write to different tables, with different feature surfaces, and the user has no way to see them as one thing.

**This is the disaster.**

---

## 2. Proposed unified surface — `Backup & Restore`

**AGREED** baseline (Sean's proposal, Claude concurs): retire the six pages above and consolidate into a single **`Backup & Restore`** admin entry with tabs. Old "Database" admin section retires entirely. The `db_tools.php` data-export stuff (JSON/CSV/SQL dump) is a separate concern (database admin tooling) — that survives somewhere, possibly under a much-shrunk "Database" section or merged into Reports — but is **out of scope for this overhaul**. The `db_tools.php` *backup* parts move into the new surface.

**PROPOSED** tab structure (Sean's draft, Claude refining below):

### 2.1 `Backup` tab

**Manual backup trigger + scheduling.**

Functionality:
- **Manual run** (admin) — for any destination row visible on the Destinations tab, fire an immediate backup. Replaces #779 ("Run-now on destination rows"). Real progress feedback inline (not "submitted, look in History"). *AGREED*
- **Backup type selector** — `Database backup` (engine-native dump of the entire install — schema, all rows, users, settings, migrations, `tenants` table) or `Logical backup` (portable content export, tenant-scoped including users). See §2.1.1 below for the full model. *AGREED 2026-04-29 (Sean: "tenant backups need to include all relevant tenant data including users — logical vs database").* Decision missed making it into a v3.17 issue; recorded here properly per #785 lint convention.
- **Scheduling** — manage `backup_schedules` rows from this tab. Decide whether scheduling is a sub-tab here vs its own tab vs a drawer-driven wizard from the destinations row. *PROPOSED.*
- **Retention configuration** — see §3 open question.

### 2.1.1 The two backup types — agreed model

**AGREED 2026-04-29.** Two distinct on-disk formats, two distinct restore code paths, both ship pre-v4.0.0 so single-tenant operators can use them and so the v4.0.0 tenancy work has nothing left to design here.

**Logical is the primary, recommended option for most operators** (see §5d). Database backup ships as an engine-faithful escape hatch for operators who explicitly want byte-for-byte fidelity AND have CLI tools available. UI default = Logical; Database is opt-in via "Advanced" or an explicit "engine-native dump" toggle.

| | **Database backup** | **Logical backup** |
|---|---|---|
| **On-disk format** | `.sql.gz` engine-native dump (mysqldump / pg_dump / SQLite native) | `.json.gz` (or structured `.sql.gz`) of `INSERT` statements, engine-agnostic |
| **Magic / version** | `IPAMBKP2` (current streaming format from v3.19.0) | New magic, e.g. `IPAMBKL1` ("L" = logical) |
| **Includes** | Whole DB: schema, all rows, users, settings, migrations, `tenants` table, FK ordering, indexes | Tenant-scoped content only: subnets, addresses, sites, vlans, vrfs, contacts, tags, custom fields + values, dhcp pools, scan schedules + results, address history, alert state, audit log (scoped), tenant's own users, settings, api_keys, webhooks, backup_destinations, backup_schedules |
| **Excludes** | `app_secret` (always in config.php) | `app_secret`, `tenants` table itself, `schema_migrations`, super-admin users, other tenants' rows |
| **Restore can target** | Same engine, same install (re-hydrate-on-new-host) | Any engine, any install (data is portable across schema versions) |
| **Who can run it** (pre-v4) | install admin | install admin |
| **Who can run it** (v4.0.0) | super-admin only | super-admin AND tenant admin (tenant scope auto-applied) |
| **Use case** | DR / "the disk died, restore the install to today" | Migrate data to a new install / move tenant to different host / engine swap (sqlite→mysql) / clone for staging |
| **Ships in** | already exists (v3.17+, fixed in v3.19.1) | **new in the overhaul (v3.22.0 candidate)** |

**Single-tenant note:** for installs with no tenancy enabled (i.e. all v3.x installs and any v4.x install where the conversion wizard hasn't been run), Logical backup acts as "export the whole install minus the schema-level stuff" — no tenant_id filter applies. Same on-disk format, same restore code, same UI. v4.0.0 just layers tenant_id scoping on top.

**Implication for restore UI:** the restore wizard reads the magic byte and dispatches:
- `IPAMBKP2` (or v1 `IPAMBKP1` back-compat) → engine-native restore via `mysql --execute` / `psql -f` / SQLite import (or PDO replay if F18 lands)
- `IPAMBKL1` → JSON parse → row-by-row PDO insert with FK-disable bracketing → re-emit IDs (since logical doesn't preserve auto-increment values across installs)

The two restore paths share the staging + signature-token flow but diverge at the parse/replay step.

UI:
- Top: prominent "Run backup now" with destination dropdown; secondary action shows the inline progress drawer (not a redirect). *PROPOSED.*
- Below: "Schedules" section listing `backup_schedules` rows with edit/delete drawers. Schedule create + edit drawers replace #778/#780.
- Drawer pattern matches `subnets.php` / `addresses.php` etc. per Sean's direction. *AGREED.*

### 2.2 `Restore` tab

**The single restore entry point regardless of source.**

Functionality:
- **From a destination** — pick a destination, get a list of files (current `remote_backups.php` view), pick a file, run the wizard. Replaces `remote_backups.php` listing. *AGREED.*
- **Manual upload** — operator uploads a local `.sql.gz` or `.enc` file from their workstation, provides decryption material if encrypted, runs the wizard. *PROPOSED — Sean's note: "we should allow the user to set a backup encryption password" — see §4 app_secret discussion.*
- **For SQLite installs**, the existing `db_tools.php` "Import SQL Dump" flow lives here. *AGREED.*
- **PDO-only restore for all engines** — Sean's proposal: instead of requiring `mysql` / `psql` CLI clients on the deployment host, restore by parsing the `.sql` dump and replaying statements via PDO inside PHP. WordPress backup plugins do this. Engine-agnostic, no host-tool dependency. *PROPOSED — Claude: this is significant scope. Pros: zero host-side dependency, ships in the tarball, works in containers without privilege. Cons: dump-format parsing is engine-specific (MySQL `mysqldump`, PG `pg_dump`, SQLite native each emit different SQL); foreign-key bracketing is engine-specific (PG deferred constraints, MySQL `SET FOREIGN_KEY_CHECKS=0`, SQLite `PRAGMA foreign_keys=OFF`); large dumps are slow through PDO row-by-row vs. piping bytes through `mysql --execute`. **Probably worth the cost** for the "no CLI tool dependency" win — but this is real implementation work and needs its own design memo. Out of scope for v3.20.0; v3.21.0 candidate.*

UI:
- Top: tab-within-tab or segmented control: `From destination` / `Upload file`. *PROPOSED.*
- Restore wizard preserved (download → dry-run preview → "type RESTORE to confirm" gate → apply). Dry-run preview is genuinely useful; do not regress that. *AGREED.*

### 2.3 `Destinations` tab

**Local + remote destination CRUD.**

Functionality:
- Create / Edit / Delete / Toggle / Test (#778). *AGREED.*
- Auto-Test on Save (#787). *AGREED.*
- **Default destination selector** — Sean's proposal: one destination is "the default" so other tabs (Run-now, manual upload-then-store-here) can default to it. *PROPOSED. Claude: small thing, useful.*
- **Local destination becomes tenant-disabled** in v4.0.0. *AGREED-ish — implementation lives in the v4.0.0 tenancy work, not here, but the data model needs to anticipate.*

UI:
- Cards or rows with Edit/Test/Run/Delete in a drawer-based flow. *AGREED.*
- Hide-fields-on-frequency for the schedule attached to the destination (#781). *AGREED, applies to whichever tab hosts the schedule editor.*
- Timestamps in user TZ (#782). *AGREED.*

### 2.4 `Notifications` tab — AGREED 2026-04-29

**Dedicated `Notifications` tab.** Lives at the top level of the unified surface, alongside Backup / Restore / Destinations / History. Operators expect to find notification settings in a recognizable place; folding them under Backup buries them.

**Granularity (initial ship):** **global default only.** One set of notification preferences per install (or per tenant in v4.0.0). Covers ~90% of operator use cases.

**Per-schedule override:** parking lot — eventual target shape is "global default + per-schedule override," but ship global-only in v3.22.0 and revisit per-schedule overrides after operators have used the global path. Reduces v3.22.0 scope; per-schedule needs UI surface in the schedule-edit drawer that we don't have to design upfront.

**Events covered (proposal — refine in implementation memo):**
- Backup-success: per-schedule (event-fired by scheduled run completing) and per-manual-run (event-fired by manual run completing). Operator picks "all", "failures only", or "off".
- Backup-failure: same axes.
- Destination connection-test failure: server-side periodic re-test of all destinations; alert if a previously-working destination starts failing.
- Schedule-overdue: a schedule that should have fired but hasn't (cron blocked, host crashed, etc.) — alert after N missed cycles.
- Retention-prune notices: optional ("verbose mode") summary of "we deleted N files via retention policy on destination X."
- Encryption-mode change: audit-style alert when an operator/tenant changes encryption mode on a destination.

**v4.0.0 tenancy implication:** "global" is per-tenant. Each tenant configures their own. Super-admin configures system-level events (e.g., "destination connection-test failure" on a destination that crosses multiple tenants' shared infrastructure).

**Recipients:** comma-separated email addresses for the simple shape. Future: integrations (webhook, Slack, Pushover) — out of scope for the unified-surface ship; can be added without re-shaping the tab.

### 2.5 `History` tab

**Unified backup activity log.**

Functionality:
- Merge `backup_history` (legacy CLI runner) and `backup_log` (v3.17 destination runner) into a single chronological view. *PROPOSED. Claude: schema-merge work — different columns. Either a UNION query at read time, or a migration that consolidates into one table going forward.*
- Show **all** backup-relevant audit-log events: schedule created, destination created/deleted, backup run, backup verified, backup deleted. *PROPOSED — Sean's note: "perhaps we snapshot the audit log for just backup activity."* Claude: cheaper to filter the existing `audit_log` by `action LIKE 'backup.%' OR action LIKE 'destination.%' OR action LIKE 'schedule.%'` at read time than maintain a separate snapshot. Same data, no duplication.
- Per-row detail drawer with the full operation context (which destination, which file, which user, observed checksum, error if any). *PROPOSED.*

UI:
- Flat sortable/filterable table. *AGREED.*
- Filter chips: success / failure / by-destination / by-time-range. *PROPOSED.*

### Tab structure summary

| # | Tab | Replaces | Drawer-driven? |
|---|---|---|---|
| 1 | Backup | `db_tools` backup half + parts of `settings` Backups tab + manual-trigger from `destinations` schedules | Yes — for schedule create/edit |
| 2 | Restore | `restore_web.php` + `db_tools` SQL-import + (new) manual upload | Yes — for the wizard |
| 3 | Destinations | `destinations.php` destinations half | Yes — for create/edit/test/delete confirm |
| 4 | Notifications *(maybe)* | parts of `settings` Backups tab | TBD per §2.4 |
| 5 | History | `backup_history.php` + `remote_backups.php` log view | Yes — for per-row detail |

Old `db_tools.php` data-export flows (JSON / CSV / raw SQL) survive somewhere, **out of scope.**

---

## 3. Retention model — AGREED 2026-04-29

**Sean's original framing:** *"should we stick with it being at the schedule level, should it be at the destination level (covers manual backups too), or should it be global"*.

**Five sub-decisions, all agreed:**

| # | Question | Decision |
|---|---|---|
| 3a | Where does retention live? | **Destination-level.** `backup_destinations` carries `retention_hourly` / `_daily` / `_weekly` / `_monthly`. Schedule no longer carries tier counts. Matches cloud-native lifecycle policy convention (S3 / GCS / Azure Blob). |
| 3b | Schedules per destination? | **Strictly one-to-one.** A destination row has at most one `backup_schedules` row. Operators who want multi-cadence redundancy create multiple destinations pointing at the same backend with different prefixes (e.g. `s3://bucket/hourly/` vs `s3://bucket/daily/`). Removes ambiguity about "whose retention applies." **Bonus if low-effort:** add a "Clone destination" action in the UI to make the multi-cadence workaround one-click instead of full re-entry. Optional polish, not gating. |
| 3c | GFS tier-counts vs simpler model? | **Keep GFS** (hourly + daily + weekly + monthly counts). Move the four columns from `backup_schedules` to `backup_destinations`. Schedule keeps only `frequency` + timing fields (`time_of_day`, `day_of_week`, `day_of_month`). Existing-schedule migration: copy each schedule's tier-counts to its destination on the v3.21.0 (or whichever ships this) migration; deduplicate where multiple schedules → one destination (impossible after 3b, but back-compat handles current data). |
| 3d | Per-row protect flag? | **Add it.** New column `backup_log.is_protected INTEGER NOT NULL DEFAULT 0`. Retention engine excludes protected rows from prune. UI: 🔒 toggle in the History detail drawer. Use case: "backup-before-the-big-migration, never delete." Restore wizard auto-protects the rollback-safety backup it takes pre-restore. |
| 3e | Manual backups subject to retention? | **Yes**, but **`is_protected` defaults to checked** on the manual-run dialog. **Operator framing (Sean):** "manual backups need manual deletion" — the default-protect behavior keeps that mental model intact. Documented in `docs/backups.md` so operators know they own the cleanup of their manual backups (and can uncheck the protect box at backup time if they want the file to cycle through retention naturally). Scheduled backups default `is_protected=0` (subject to retention as expected). |

**Tier-promotion semantics:** the retention engine sorts ALL backups (manual + scheduled) by timestamp and assigns each backup to whichever tier slots it qualifies for:
- The most-recent backup of any given hour → fills the hourly tier
- The most-recent backup of any given day → fills the daily tier  
- The most-recent backup of any given Sunday (configurable week-start) → fills the weekly tier
- The most-recent backup of any given month-first-day → fills the monthly tier

A single backup file can occupy multiple tier slots simultaneously (e.g. a Sunday-1st-of-month backup occupies hourly + daily + weekly + monthly slots all at once). Borg / restic / Tarsnap all use this assignment model. Existing `ipam_gfs_select_for_deletion()` in `lib.php` already implements this for schedule-level retention; the v3.21.0 work just moves the input source from `backup_schedules` to `backup_destinations`.

**Migration plan:**
1. Add the four `retention_*` columns to `backup_destinations` (NULL allowed initially).
2. For each `backup_schedules` row, copy its retention values to its `backup_destinations` row. If multiple schedules → same destination (only possible in legacy data; new model forbids), use `MAX()` of each tier count across colliding schedules.
3. Drop the four `retention_*` columns from `backup_schedules`.
4. Add `backup_log.is_protected` column.
5. Add UI for tier-count edit on destinations + protect-toggle on history rows.

**OPEN sub-questions (defer to implementation memo, not to this design):**
- What's the default tier policy for a fresh destination? (Proposal: 24 hourly, 7 daily, 4 weekly, 12 monthly — same as today's default.)
- Does week-start day need to be configurable per install / tenant? (Proposal: hardcoded UTC Sunday for now; revisit if any operator complains.)

---

## 4. Backup encryption — AGREED 2026-04-29

**Sean (paraphrased):** "Break from `app_secret` for backups — not sustainable for tenancy. Passphrase is the only path forward. For automated backups we need to store the tenant key. Offer the option to store their passphrase, or do manual backups where the key is transitory."

**Three encryption modes, chosen per destination at setup:**

| Mode | Passphrase source | Used for | Trade-off |
|---|---|---|---|
| **Stored** (automated) | Operator/tenant sets at destination setup. Encrypted at rest with `config['backup_vault_key']` (new key, separate from `app_secret`). v4.0.0 layers per-tenant HKDF derivation: `tenant_vault = HKDF(backup_vault_key, "ipam-v4:tenant_id:vault")` so the tenant's stored passphrase is unwrappable only with that tenant's derived key. | Scheduled / unattended runs **AND** manual runs against the same destination (operator does NOT have to re-type the stored passphrase for manual runs — the stored passphrase is the destination's identity). | Convenience; host-compromise → all stored passphrases recoverable → all backup files decryptable |
| **Transitory** (manual only) | Operator types at backup time. Server never persists. Restore requires same passphrase to be re-typed. | Off-site / portable copies, "give me a one-off encrypted backup with a passphrase only I know" | Secure: a host compromise reveals nothing about the backup encryption. Can't automate. Operator must remember / record passphrase out-of-band. |
| **Unencrypted** | None | Trusted local destinations, full-disk-encrypted hosts, dev/test installs | Trivial; plaintext at rest. Files are still SHA-256 integrity-checked. |

**Manual-vs-scheduled, refined (Sean 2026-04-29):** the Stored / Transitory distinction is about **source of the passphrase**, NOT about manual-vs-scheduled. A destination with a Stored passphrase can be used for both scheduled and manual runs — the manual "Run backup now" button just reuses the destination's stored passphrase silently. The Transitory mode is a separate, deliberate choice for "I want a one-off encrypted backup whose passphrase exists only in my head" — no destination row, or a destination row that explicitly forbids storing passphrases. Operator picks per-action which mode to use:

- "Run scheduled backup" against a Stored-mode destination → uses stored passphrase
- "Run backup now" against a Stored-mode destination → uses stored passphrase (no re-prompt)
- "Run backup now" with the Transitory option toggled → prompts for passphrase, uses it once, never stores
- "Run scheduled backup" against a Transitory-mode destination → not allowed; UI prevents creating a schedule on a transitory-only destination

**Format implications:**

| Mode | Magic | KDF | Header |
|---|---|---|---|
| Stored / Transitory | `IPAMBKP3` (new) | Argon2id over passphrase, per-file random salt | `magic(8) salt(16) argon-params(8) iv(16) ciphertext hmac(32)` |
| Unencrypted | `IPAMBKU1` (new) | n/a | `magic(8) sha256(32) ciphertext` (just framing + integrity, no confidentiality) |
| **Legacy** | `IPAMBKP2` (v3.19.0+) and `IPAMBKP1` (v3.17–v3.18) | HKDF over `app_secret` | Continues to RESTORE on v3.22.0+. **Not produced by v3.22.0+ — operators upgrading get migrated to one of the three modes above on their next destination edit.** |

**Key separation, recorded:**

- **`config['app_secret']`** — continues to exist for TOTP secret derivation, restore-staging signature tokens, and other non-backup uses. Unchanged from today.
- **`config['backup_vault_key']`** — NEW. Used ONLY to encrypt stored passphrases at rest. Different rotation lifecycle from `app_secret`. Auto-generated on first destination setup if not present.
- Two-layer encryption for `Stored` mode: `vault_key` → wraps tenant-stored passphrase → which encrypts backup file. v4.0.0's per-tenant HKDF sits on top of `vault_key`, NOT `app_secret`.

**Single-tenant pre-v4 case:** `backup_vault_key` is just an additional config.php entry. Operator manages it. No tenant scoping yet.

**v4.0.0 case:** super-admin manages `backup_vault_key` server-side. Tenant admins choose per-destination encryption mode but never see the vault key itself; the server derives the tenant's key from the master + tenant_id and uses that to wrap their stored passphrases.

**Open sub-question (deferred to implementation memo):** Argon2id parameters (memory cost, time cost, parallelism). Default proposal: `memory=64MiB, time=3, parallelism=1` (OWASP minimum for password hashing 2024). Tunable per install if memory-limited.

**Issue carving note:** the migration from `IPAMBKP2`-with-`app_secret` to the new tri-mode system is itself a significant piece of work — has to handle existing deployed destinations gracefully. Probably needs:
1. v3.21.0 — destination "encryption mode" selector lands in UI, defaults to legacy (`IPAMBKP2` + app_secret) with a deprecation warning
2. v3.22.0 — `backup_vault_key` introduced, three new modes available, legacy still readable but produces deprecation warnings
3. v3.23.0 — legacy `IPAMBKP2` no longer produced for new backups; old files still restorable
4. v4.0.0 — per-tenant HKDF over `backup_vault_key`, tenancy-aware UI

---

## 5. PDO vs CLI tools — phased decisions

### 5a. Database backup dump engine — AGREED 2026-04-29

**Decision:** **Shell-out stays for now.** `mysqldump` / `pg_dump` remain prereqs for non-SQLite installs.

**Sean's position:** acknowledges the WordPress-plugin precedent for pure-PHP backup. Comfortable accepting the current shell-out implementation rather than landing the MySQL-PDO rewrite in this overhaul. **Revisit MySQL PDO later** (likely v3.23.0+ or post-v4.0.0) — gives time to scope it properly without holding up the rest of the overhaul. **Postgres limitation gets documented** in `docs/install.md` and `docs/backups.md` (operators on PG must have `pg_dump` on PATH; no PHP-only fallback). **PG vendor libraries** to be evaluated when revisiting (e.g., open-source PG dump implementations in PHP, or shipping a bundled PG client) — known unknowns, deferred.

**Future state Sean called out:** PDO-only restore would unlock **web-based restore for tenancy hosts** that don't have shell access (shared hosting, locked-down containers). That bonus motivates the eventual revisit but doesn't justify front-loading the work into this overhaul.

**Implication for the overhaul:**
- v3.22.0 ships Database backup with the existing shell-out implementation, just behind the new unified UI.
- `mysqldump` / `pg_dump` host prereqs documented.
- No new code in this area until the revisit.

**Future work (parking lot, not committed):**
- F26 (new): MySQL-PDO Database backup using `SHOW CREATE TABLE` shortcut — ~4 weeks of focused work, eliminates `mysqldump` host dep
- F27 (new): PostgreSQL-PDO Database backup — heavier (~7.5 weeks) due to no `SHOW CREATE TABLE` equivalent; investigate whether existing PG-dump-in-PHP libraries cover the gap
- F28 (new): Web-based restore for tenancy / shared-hosting installs — depends on F26 + F27 landing first

### 5b. Database backup restore engine — AGREED 2026-04-29 (4b corollary of 4a)

Follows 4a by parallel reasoning: shell-out for non-SQLite (`mysql --execute` / `psql -f`), PDO for SQLite. Operators on MySQL/PG without CLI tools get a degraded restore experience, captured here so we ship it deliberately:

**Degraded-but-functional fallback for restore-without-CLI:**
- The Restore tab shows the backup files in the destination, allows download to local disk, **but cannot perform an in-place server-side restore** when CLI tools are absent.
- Inline UI message: "this is a Database backup. To restore it, run `mysql --user=… < ipam-2026-04-29.sql` on the server, OR use a Logical backup instead."
- Documentation in `docs/install.md` and `docs/backups.md` explicitly calls out the limitation and points operators to the Logical option as the no-CLI-prereq path.

Same outcome as 4a: operators who want zero CLI dependency use Logical backups end-to-end.

### 5c. Logical backup dump + restore — see §2.1.1 (PDO-only, our format)

Already locked in §2.1.1: Logical backup is our format, PDO-only by definition. Engine-portable. No CLI tools involved.

### 5c'. Cross-engine logical restore — AGREED 2026-04-29: defer to v3.23.0+

**Decision:** v3.22.0 ships Logical backup with **same-engine restore only**. Cross-engine restore (sqlite-source → mysql-target, etc.) defers to v3.23.0+.

Sean: *"Defer. I expect several releases to clean things up."*

**Why:** cross-engine adds 4-6 weeks of type-mapping work (engine-specific type-coercion, constraint translation, sequence/auto-increment handling) on top of an already-large v3.22.0. Better to ship same-engine Logical first, give it track record, then add cross-engine as a v3.23.0+ unlock that builds on the `Dialect` class infrastructure already proving engine-portable type translation since v2.9.0.

### 5d. Both formats ship — Logical primary, Database escape hatch — AGREED 2026-04-29

Sean (paraphrased): "B is a good middle-of-the-road. We could also leave operators to perform their own DB backups. But at least B gives us the option."

**Both Database and Logical formats ship.** UI / docs / defaults treat **Logical as the recommended path** for most operators; Database is positioned as an "engine-native escape hatch" for operators who want byte-for-byte engine fidelity AND have CLI tools available.

| | Logical (primary) | Database (escape hatch) |
|---|---|---|
| UI default when creating a destination | Pre-selected | Available under "Advanced" / explicit opt-in |
| Doc emphasis | First, most examples, recommended path | Mentioned, with limitations called out |
| Restore web-based? | Yes (PDO replay), works on shared hosting | Only on SQLite installs; non-SQLite needs CLI |
| Cross-engine? | Yes (sqlite → mysql, etc.) | No (engine-locked) |
| Schema fidelity | Schema comes from target install's migration chain | Byte-for-byte from source dump |
| Tenant-accessible (v4.0.0+) | Yes | No (super-admin only) |
| Recommended for | Most operators, all tenants, container/shared hosting, migration & cloning | DR for engine-faithful snapshots, large installs where PDO walk is too slow |

**Operator escape hatch we always have:** an operator who finds Database-backup-via-IPAM unsatisfactory can run `mysqldump` themselves outside the app. So our maintenance commitment to Database is "best-effort, not the only path." If maintaining Database becomes painful in the future (M-version drift, edge cases), we reserve the right to deprecate it without leaving operators stranded — they'd just go back to running `mysqldump` themselves. Decision-reversibility is therefore preserved.

**Future-state revisit:** in v3.25.0+ (or post-v4.0.0), once Logical has shipped and proved its track record across 2-3 release cycles, **revisit whether Database backup is still earning its maintenance cost**. If Logical handles 99% of cases well and Database is rarely-used, it can be deprecated cleanly. If Database is genuinely load-bearing, keep it.

### 5e. Cross-version restore policy — AGREED 2026-04-29

Sean: *"We could always restrict you need to restore to the same version or newer and we apply migrations if its from an older version. We need this capability anyways."*

**Decision: restore target must be at the SAME or NEWER version than the backup. Older backups onto newer installs apply forward migrations as part of restore.**

Direction matrix:

| Source backup version | Target install version | Restore behavior |
|---|---|---|
| Same version | Same | Direct restore. Standard path. |
| Older | Newer | Forward-migrate the restored data. The restore wizard shows: "this backup is from v3.17.0; the install is v3.22.0; we'll apply 5 migrations to bring the data up to date." Operator confirms; restore proceeds. |
| Newer | Older | **Refuse.** UI shows: "this backup is from v3.25.0; the install is v3.22.0. Upgrade the install first, OR restore on a v3.25.0 install and downgrade-export." No automatic backward-migration; rolling back schema changes is not safe. |

**Implementation outline:**
- Backup metadata embeds source-install IPAM version (already partly there in the v3.17 schema; needs verifying).
- Restore wizard reads metadata, compares to current `IPAM_VERSION`, plans the restore accordingly.
- For "older → newer" path, the restore engine:
  1. Loads data into the target at the source-version schema state (either by spinning up a parallel pre-migration schema, or by restoring then applying migration deltas to the imported data — TBD in implementation memo)
  2. Runs forward migrations
  3. Re-validates row integrity (FK refs still resolve, NOT NULL still holds, etc.)

**Why this matters for the overhaul:**
- This capability is **required for v3.22.0 Logical backup ship** (operators will inevitably restore old backups onto newer installs)
- Same capability **unblocks v4.0.0 tenancy migration** (importing a tenant's exported data into a freshly-converted install)
- Database-backup restore can also leverage it (less common, but covers the "I have a v3.17 mysqldump, my install is now v3.22, restore it" case)

**Open implementation question (deferred):** can forward-migration be applied to imported-but-not-yet-committed data inside a transaction (rollback-safe), or do we need a staging schema? Probably a staging schema given migration complexity. To be decided in the v3.22.0 implementation memo.

---


## 6. Running list — Functionality improvements

Captured from Sean's note + Claude's audit. Each entry is a candidate issue but **none filed yet**. Priority column is for the eventual milestone-split conversation (P0 = blocking real use, P1 = severe UX gap, P2 = nice-to-have / polish).

| # | Item | Source | Priority |
|---|---|---|---|
| F1 | Unified `Backup & Restore` admin surface (5-tab layout) | §2 | P0 |
| F2 | Retire `db_tools.php` "Database" admin entry; data-export survives elsewhere | §2 | P0 |
| F3 | Single backup-history view merging `backup_history` (legacy) + `backup_log` (v3.17) | §2.5 | P0 |
| F4 | Edit destination drawer (existing #778) | v3.20.0 issue | P0 |
| F5 | Edit schedule drawer (existing #780) | v3.20.0 issue | P0 |
| F6 | Manual Run-backup-now from destination row (existing #779) | v3.20.0 issue | P0 |
| F7 | Auto-Test on destination Save/Edit (existing #787) | v3.20.0 issue | P1 |
| F8 | Frequency-aware schedule fields (existing #781) | v3.20.0 issue | P1 |
| F9 | Backup timestamps in user's TZ (existing #782) | v3.20.0 issue | P1 |
| F10 | Default destination selector | §2.3 | P2 |
| F11 | Per-row backup detail drawer in History | §2.5 | P2 |
| F12 | Filter chips on History (success/failure/dest/time) | §2.5 | P2 |
| F13 | Manual upload-and-restore flow with passphrase entry | §2.2, §4 | P0 |
| F14 | "Protect this backup" flag on `backup_log` rows | §3 | P1 |
| F15 | Move retention from schedule to destination level | §3 | P1 |
| F16 | Backup-encryption-passphrase scheme (`IPAMBKP3` format) | §4 | P1 (P0 if any v4.0.0 work starts) |
| F17 | Per-tenant HKDF key derivation in v4.0.0 | §4 | v4.0.0 |
| F18 | PDO-based restore (engine-agnostic, no CLI dependency) | §5 | P1 |
| F19 | PDO-based dump (follows F18 by 1+ release cycle) | §5 | P2 |
| ~~F20~~ | ~~Backup type selector: `Database` vs `Data` (rows-only)~~ | ~~§2.1~~ | **DROPPED 2026-04-29** — §2.1.1 two-type model (Database / Logical) is sufficient; CSV/JSON export from `db_tools.php` covers rows-only use case. Resolved as not needed; possible misalignment on original proposal. |
| F21 | Notifications config — global preference vs per-schedule | §2.4 | OPEN |
| F22 | "Default backup destination" tenant policy (local-disabled in v4.0.0) | §2.3 | v4.0.0 |
| F23 | Stale-cron / scanner-blocks-backup architectural fix (cron task ordering — backup before scanner, or scanner backgrounded) | release-session find | P1 |
| F24 | "Verify all backups" bulk action on a destination | new (Claude) | P2 |
| F25 | Configurable encryption: opt-out for trusted local destinations | new (Claude) | P2 |

---

## 7. Running list — UI improvements

| # | Item | Source | Priority |
|---|---|---|---|
| U1 | Drawer-driven create/edit/delete-confirm patterns across all backup pages | §2 | P0 |
| U2 | Inline progress reporting for Run-now (no redirect-and-flash) | §2.1 | P1 |
| U3 | Backup feature card on the dashboard sidebar/landing showing last-run, next-run, last-error, total-size-stored | new (Claude) | P2 |
| U4 | Health page shows per-destination connectivity status with last-test timestamp | new (Claude) | P2 |
| U5 | Restore wizard's "type RESTORE to confirm" stays — high-risk action gate is correct UX | §2.2 | AGREED |
| U6 | Skeleton loading states on remote-listing fetches (S3 LIST is 1-3s typical) | ui-ux-pro-max checklist | P2 |
| U7 | Empty-state copy on every tab when no destinations / no backups / no schedules | new (Claude) | P2 |
| U8 | Cancel-in-flight for Run-now (long uploads to slow S3 destinations) | new (Claude) | P2 |
| U9 | Show encryption-format icon (`v2 streaming` / `v1 single-shot` / `v3 passphrase`) in History list per row | new (Claude) | P2 |
| U10 | Confirm-on-delete with destination-name typed (already pattern in restore wizard, extend to delete) | new (Claude) | P2 |

---

## 8. Running list — Testing improvements

| # | Item | Source | Priority |
|---|---|---|---|
| T1 | MinIO/LocalStack integration test in CI (existing #789) — round-trip create→backup→list→verify→download→restore→row-count-parity per engine | v3.19.1 finding | P0 |
| T2 | OpenSSH-server sidecar for SFTP destination coverage in same CI job | new (Claude) | P0 |
| T3 | Local destination coverage in same CI job (currently the only path with no live-server test) | new (Claude) | P0 |
| T4 | Restore-to-empty-DB row-count parity assertion across all 3 engines | §5 | P0 |
| T5 | Cross-engine restore tests (sqlite-dump → mysql-target, etc.) once F18 lands | §5 | P1 |
| T6 | Tamper / corruption-detection tests for `IPAMBKP3` (matching what `IPAMBKP2` got in v3.19.0) | §4 | P1 |
| T7 | Schedule-fires-at-expected-time test (cron tick simulation) | new (Claude) | P1 |
| T8 | "destination disabled mid-backup" test — graceful failure, audit log, no orphaned tmpfiles | new (Claude) | P2 |
| T9 | Large-DB test (>1 GB synthetic) under realistic memory limits — proves streaming holds | extending v3.19.0 test | P2 |
| T10 | Permission / role test — non-admin operator cannot reach any of the new backup pages | new (Claude) | P0 |

---

## 8a. Running list — Documentation overhaul

**AGREED 2026-04-29.** Documentation rewrites land in lockstep with each milestone — no doc changes ahead of code, no doc debt left behind after. Each milestone's PR includes the doc updates for what *that* milestone delivers; the docs at any tagged release accurately describe the shipped behaviour and nothing more.

| # | Item | Lands with milestone |
|---|---|---|
| D1 | Rewrite `docs/backups.md` end-to-end as the unified `Backup & Restore` reference. Retire scattered references to "Database backup" vs "Remote backup" as separate features. | v3.21.0 (alongside unified surface) |
| D2 | New `docs/restore.md` covering both Logical and Database restore paths, manual upload flow, cross-version policy (same-or-newer, forward-migrate older), and CLI restore steps for MySQL/PostgreSQL Database backups. | v3.21.0 |
| D3 | Update `docs/configuration.md` — remove `backup.*` keys that move into the DB; document the new `backup_vault_key` requirement and its lifecycle (separate from `app_secret`). | v3.22.0 |
| D4 | Encryption section in `docs/backups.md` — document the three modes (Stored / Transitory / Unencrypted), the `IPAMBKP3` passphrase format, KDF (Argon2id) parameters, and the operator-vs-tenant key-storage model. | v3.22.0 |
| D5 | New `docs/internal/data-dictionary.md` regeneration after `backup_runs` table lands and the legacy two tables retire. | v3.22.0 |
| D6 | Marketing site `front-page.php` backup feature card rewrite — current copy (AES-256-CTR + HMAC-SHA256 streaming) is accurate for v3.19.x but will mislead once `IPAMBKP3` ships. | v3.22.0 release |
| D7 | New `docs/tenancy.md` section: per-tenant HKDF key derivation, what tenant admins see vs super-admins, tenant-scoped backup UI behaviour, restore implications. | v4.0.0 |
| D8 | `CLAUDE.md` "Runtime dependencies" + "Database" sections updated as new deps land (Argon2 already in PHP core, but if any new deps ship, they go in the whitelist with justification). | per-milestone, as needed |
| D9 | `README.md` "What's new" replacement for each release, per the existing project convention. | each milestone release |
| D10 | `docs/upgrading.md` — explicit notes for the v3.21.0 cutover (six pages → one), v3.22.0 cutover (encryption format change, `backup_vault_key` setup), and v4.0.0 cutover (tenancy + key derivation). | each milestone |
| D11 | Internal procedure doc `docs/internal/backup-restore-runbook.md` — operator runbook for incident response (restore from S3, verify integrity, cross-version restore, tenant-export hand-off). | v3.21.0, expanded each milestone |
| D12 | Retire / move this very doc (`backup_overhaul.md`) to `docs/internal/attic/` once §10 milestones are all shipped and the running lists are empty. | v4.0.0 release cleanup |

**Rule:** every backup-overhaul PR lists which D-items it touches in the PR description, the same way functionality/UI/testing items are tracked. Reviewer's first sanity check: "did the docs move with the code?"

---

## §A1 detail — `backup_runs` table consolidation (AGREED)

**Decision:** retire `backup_history` (v3.7 CLI runner) and `backup_log` (v3.17 destination runner) into one new `backup_runs` table. One-time migration on the v3.22.0 (or whichever ships this) upgrade.

**Proposed schema** (refine in implementation memo):

```sql
CREATE TABLE backup_runs (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    destination_id  INTEGER REFERENCES backup_destinations(id) ON DELETE SET NULL,
    schedule_id     INTEGER REFERENCES backup_schedules(id) ON DELETE SET NULL,
    backup_type     TEXT NOT NULL,        -- 'database' | 'logical'
    encryption_mode TEXT NOT NULL,        -- 'stored' | 'transitory' | 'unencrypted'
    triggered_by    TEXT NOT NULL,        -- 'schedule' | 'manual' | 'cli' (for legacy v3.7 CLI rows)
    status          TEXT NOT NULL,        -- 'running' | 'success' | 'failed'
    filename        TEXT,
    size_bytes      INTEGER,
    checksum        TEXT,                 -- SHA-256 of the produced .enc / .sql.gz
    source_version  TEXT NOT NULL,        -- IPAM_VERSION at the time of the backup run
    is_protected    INTEGER NOT NULL DEFAULT 0,  -- per §3d, retention engine skips when 1
    error_message   TEXT,
    started_at      TEXT NOT NULL,
    completed_at    TEXT
);

CREATE INDEX idx_backup_runs_destination ON backup_runs(destination_id);
CREATE INDEX idx_backup_runs_schedule    ON backup_runs(schedule_id);
CREATE INDEX idx_backup_runs_started     ON backup_runs(started_at DESC);
CREATE INDEX idx_backup_runs_protected   ON backup_runs(is_protected) WHERE is_protected = 1;
```

**Migration plan:**
1. Create `backup_runs` table with the shape above.
2. Copy `backup_log` rows in: straightforward column-mapping; `backup_type='database'` (today's only type), `encryption_mode='stored'` (legacy `IPAMBKP2` was server-derived → maps to stored; deprecation warning surfaced on first edit per §4 migration plan).
3. Copy `backup_history` rows in: `backup_type='database'`, `encryption_mode='unencrypted'`, `destination_id=NULL` (these are local-disk dumps with no destination), `triggered_by='cli'`.
4. Drop `backup_history` and `backup_log`. Code paths that wrote to them already updated to write to `backup_runs`.
5. Verification step in the migration: row-count parity between (`backup_history` + `backup_log`) before-state and `backup_runs` after-state; refuse to drop the source tables if counts don't match.

**Schema-parity work:** new table goes into `schema.sql` / `schema.mysql.sql` / `schema.pgsql.sql` AND the migration closure in `migrations.php`. Standard 3-file update.

**Backward-compat for the legacy CLI runner:** `run_db_backup_if_due()` in `lib.php` keeps working — its writes get redirected to `backup_runs` with `triggered_by='cli'`, `destination_id=NULL`. No change in operator-visible behavior; the runner just lands rows in the new table.

---

## 9. Architectural concerns — RESOLVED 2026-04-29

All seven architectural concerns are now settled. References point to the §-numbered detail.

| # | Concern | Status |
|---|---|---|
| A1 | Two backup tables (`backup_history`, `backup_log`) — collapse or keep separate? | **RESOLVED** §A1 — collapse to one new `backup_runs` table; one-time migration. *Sean: "no need to pollute the schema. Keep it clean."* |
| A2 | One destination = one schedule, OR many? | **RESOLVED** §3b — strictly one-to-one. Multi-cadence operators create multi-destination. |
| A3 | `app_secret` for backup encryption — keep, retire, or split? | **RESOLVED** §4 — split. New `backup_vault_key` separate from `app_secret`; per-tenant HKDF in v4.0.0 derives from vault_key, not app_secret. Three encryption modes (Stored / Transitory / Unencrypted). |
| A4 | Dump + restore engine — PDO or CLI tools? | **RESOLVED** §5a/5b/5c — Database backup keeps shell-out (`mysqldump`/`pg_dump` host prereqs). Logical backup is PDO-only. Both formats ship; Logical is primary, Database is escape hatch. MySQL-PDO + PG-PDO parked as future work. |
| A5 | Cron task ordering — backup vs scanner priority? | **RESOLVED** — backup task moves AHEAD of scanner. Scanner becomes lowest-priority cron task with a per-tick time budget. *Sean: "scanner at lowest priority. Investigate scanner speedup (parallelism, configurable timeout) in the future."* See A5 below for detail. |
| A6 | Notifications scope — per-schedule, global, both? | **RESOLVED** §2.4 — global-only initial ship; per-schedule override parked for revisit. Dedicated `Notifications` tab. |
| A7 | "Database backup" vs "Logical backup" distinction — shape? | **RESOLVED** §2.1.1 — two distinct on-disk formats (`IPAMBKP3` Database / `IPAMBKL1` Logical). Logical is portable / engine-agnostic / tenant-accessible. Database is engine-faithful / DR / super-admin-only. |

### §A5 detail — cron task ordering (AGREED)

**Decision:** reorder `cron.php` tasks so backup runs BEFORE scanner. Scanner becomes lowest-priority work. Add a per-tick time budget so the scanner cannot starve subsequent ticks.

**Specifically:**
1. Move "Task 9: Backup schedules" ahead of "Task 6: Network scanning" in `cron.php`. Backup runs first; scanner runs last (or as close to last as the housekeeping ordering permits).
2. Add `scan.max_seconds_per_tick` setting (default 300 = 5 min). When the scanner has consumed the budget, it stops mid-pass; remaining subnets pick up next tick. State (which subnets remain to scan) persists in `scan_schedules.last_run_at` — already there.
3. Document the priority order in `docs/internal/release-workflow.md` cron section so it's not silently re-shuffled by future work.

**Future scanner-speedup work** (not v3.22.0 scope; parking lot):
- F29 (new): scanner parallelism — spawn N concurrent ping subprocesses instead of sequential. Bound by `scan.max_concurrent_pings` setting. Each `/24` subnet's ICMP scan time drops from ~4 min sequential to ~30 seconds with parallelism=8.
- F30 (new): configurable per-IP scan timeout. Today hardcoded at 1s; for large subnets where most IPs are unresponsive, 200ms is more than enough.
- F31 (new): scanner skip-list — explicitly mark subnets / IP ranges as "do not scan" (e.g., security-restricted IPs that ping floods would alert on).

These are separate from the v3.22.0 ship; capture as "future scanner work" issues against v4.0.0+ or whichever release shows the cron load to be a real problem after the priority fix.

---

## §B. Code audit — 2026-04-29 thorough static review

**Owner ask:** *"Substantial concerns of the code quality. Determine what's reusable, what needs rewriting, find any glaring issues."*

**Method:** static review of all backup-related code: 11 PHP pages (~3,000 LOC), 5 lib classes (~2,200 LOC), backup symbols in `lib.php` (~1,100 LOC), 4 test files. No code changes. Two passes by Explore agent.

### Verdict

The code is **better than the v3.19.1 hotfix density suggests, but worse than its own comments imply.** Half the findings the consolidation plan already neutralizes; the other half it does not, and those are the ones that matter.

**Solid and worth keeping:**
- `BackupClientInterface` (53 LOC) — clean, narrow, three impls, tested.
- S3Client SigV4 helpers (`canonicalRequest`, `stringToSign`, `signature`, `authorizationHeader`) — pure static functions, ~400 LOC of tests, post-#784 fix verified.
- `IPAMBKP2` framing — encrypt-then-MAC, separate keys via HKDF, atomic-rename on decrypt, header-in-HMAC. Sound design; ports forward to `IPAMBKP3` with AAD bind + Argon2id KDF.
- `ipam_gfs_select_for_deletion` — pure 130 LOC with 430 LOC of tests in `BackupRetentionTest`. Reusable.

**The four scariest findings beyond the consolidation plan:**
1. **Notification feature is dead code.** `ipam_backup_notify` (lib.php:4525) has zero callers. `backup.notify_on_failure` / `backup.notify_on_success` settings are read but never acted on. Admins will never get email regardless of config.
2. **Stuck "running" rows have no recovery.** `run_db_backup_if_due` has no concurrency guard — second process can enter running state while first holds it; no cleanup of stale rows on next tick.
3. **`restore.php` inherits the sigchild bug v3.19.1 just fixed.** `proc_close` exit code unreliable on sigchild PHPs; the file-size fallback in `backup_run_dump` was not applied here.
4. **SQL splitter is fragile against real-world dumps.** `ipam_restore_split_sql_statements` uses regex + line-oriented depth tracking; fails on TRIGGER bodies with multi-line literals, inline comments, etc. Will break the first time an admin uploads a non-trivial dump.

**Distribution:** 70 findings total — 2 P0, 25 P1, 37 P2, 6 P3. SSRF / command-injection / traversal exposures all admin-gated and acceptable for the documented threat model. Crypto is fine. The rot is concentrated in legacy v3.7 paths (`run_db_backup_if_due`, `backup_history.php`, `db_tools.php` backup blocks) — most of which the consolidation plan already deletes (§A1, F1, #69, #70).

### B.1 — P0 findings (production currently quietly wrong)

| # | File:line | Issue | Action | Milestone |
|---|---|---|---|---|
| B-P0-1 | `lib.php:3508-3656` `run_db_backup_if_due` | No concurrency guard; stuck "running" rows accumulate, never recovered | Rewrite — also addressed by §A1 single-table + `is_protected` (need stale-row reaper too) | v3.21.0 |
| B-P0-2 | `lib.php:4525-4560` `ipam_backup_notify` | Zero callers; admin-configured failure email is dead code | Refactor — wire from both orchestrators **before** consolidation, so notifications work in v3.20.0 | **v3.20.0** (urgent) |

### B.2 — P1 findings (25) grouped by category

**Security (admin-gated, but defense-in-depth):**
- B-P1-2 `restore.php:170-188` — MySQL `MYSQL_PWD` env leaks via `ps`; route via stdin
- B-P1-29 `restore.php:194-245` — same risk for `PGPASSWORD` in pg_dump/psql
- B-P1-13 `lib/backup.php:537-555` `ipam_restore_sign` — no purpose-string in HMAC; tokens inter-changeable
- B-P1-40 `lib/backup.php:557-580` — restore-token timing-attack surface

**Resource / sigchild / proc_open:**
- B-P1-3 `lib.php:3660-3743` `backup_run_dump` — pipe-buffer fill risk; no `stream_select`
- B-P1-9 `restore.php:126-127` — SQLite restore `unset($db)` race against WAL checkpoint
- B-P1-37 `cron.php:315-375` — schedule iteration race; no per-row lock

**Crypto correctness (all minor relative to overall design):**
- B-P1-35 `lib.php:3826` — `random_bytes(12)` no explicit availability check

**Maintainability (rotting):**
- B-P1-4, B-P1-26 `lib/backup.php:342-351` — `schedule_id` FK exists but never populated; cron line 337 doesn't pass it through
- B-P1-8 `lib.php:4302-4446` — `ipam_backup_apply_retention` 112 LOC mixed responsibilities; extract GFS selection + client construction
- B-P1-15 `lib.php:3543-3572` — SQLite retention via `glob() + rsort`; lex-sort ≠ creation-order if filename format ever changes
- B-P1-21 `lib.php:4457-4516` `ipam_backup_next_run_at` — 112 LOC time arithmetic; off-by-one risk on Friday/month-boundary; needs explicit edge-case tests
- B-P1-31 — `backup_log` vs `backup_history` schema divergence (already in §A1)
- B-P1-33 `lib.php:3766-3773` `backup_info` — directory race on `glob()`
- B-P1-43 `restore_web.php:74-108` — `verify_signed` called twice; state-machine confusion

**Bugs:**
- B-P1-9 (above) — SQLite WAL checkpoint exception swallowed (also B-P1-44 `lib.php:3547`)
- B-P1-23 `destinations.php:153-161` — secret merge fragile; depends on form always including the field

**Test gap:**
- B-P1-45 `BackupRetentionTest` — likely missing edge cases: keep_*=0, multiple backups in same slot tie-break, all-fall-to-daily distribution

(Full P1 list in audit transcript; truncated here for readability — actionable items rolled into milestone schedule below.)

### B.3 — P2 / P3 findings (43 total) — categories and counts

| Category | Count | Highlights |
|---|---|---|
| Maintainability | 14 | Hardcoded type selectors (#38), GFS tier copy-paste (#24), retention defaults undocumented (#36), backup_info caching (#22), WAL ignored (#44) |
| Bug | 11 | step-skip in restore wizard (#6), checksum-vs-decryption ordering ambiguous (#16), retention destId mismatch risk (#47), dry-run does no validation (#20), exit-code semantics undocumented (#42) |
| Security | 4 | Filename traversal weakness in download (#12, #51), unbounded `gzread` memory (#39), restore wizard no rate-limit (#61) |
| Schema | 3 | `type` vs `triggered_by` ambiguous (#18), `started_at` vs `created_at` divergence (#31), missing `updated_at` on schedule UPDATE (#19) |
| Test gap | 4 | S3 error responses (#10, #57), restore dry-run/apply, sftp at all, notify path |
| Crypto | 3 | GCM IV reuse documentation (#5), GCM tag parameter semantics unclear (#46), HMAC double-call timing (#40) |
| Resource | 4 | restore_web no `set_time_limit` (#62), restore.php same, decrypt_stream temp cleanup (#32), backup.php exit codes (#42) |

### B.4 — Reusability map (what survives the rewrite)

| Component | Verdict | Notes |
|---|---|---|
| `lib/BackupClientInterface.php` | **KEEP** | 53 LOC; clean abstraction. Three impls survive. |
| `lib/S3Client.php` SigV4 helpers (static) | **KEEP** | Post-#784, well-tested. Move to `lib/sigv4.php` if S3Client itself gets rewritten around an SDK. |
| `lib/S3Client.php` connection/transport | **KEEP w/ refactor** | curl wrapping is fine; add range-request support (#28); error-redaction over-aggressive (#54). |
| `lib/SftpClient.php` | **KEEP w/ tests** | No tests today. Add MinIO-style integration coverage (T2). |
| `lib/LocalBackupClient.php` | **KEEP** | Trivial; works. |
| `IPAMBKP2` crypto (`backup_encrypt_stream`/`backup_decrypt_stream`/`ipam_backup_advance_ctr`) | **KEEP, fork to BKP3** | Same framing; replace key-derivation source (`app_secret` → `backup_vault_key` + Argon2id from passphrase). |
| `ipam_gfs_select_for_deletion` | **KEEP** | Pure, tested. Drop dead `$nowEpoch` parameter (#60). |
| `ipam_backup_apply_retention` | **REFACTOR** | Split into compute/apply (#59, B-P1-8). |
| `ipam_backup_next_run_at` | **REFACTOR + tests** | Edge-case test suite first, then any refactor (#21). |
| `ipam_backup_notify` | **REFACTOR (wire it up)** | Function body fine; just has no callers (#49 / B-P0-2). |
| `run_db_backup_if_due` (lib.php:3415-3657) | **DELETE** | Legacy v3.7 path; consolidates into single orchestrator (§A1, #69). |
| `db_tools.php:286-396` (backup blocks) | **DELETE** | Duplicates `remote_backups.php` and `backup_history.php` (#70). |
| `backup_history.php` | **DELETE** | Merges into unified `History` tab (F3). |
| `restore_web.php` SQL splitter | **REWRITE** | Real lexer, not regex (#17 / B-P0-4). |
| Restore wizard state machine | **REWRITE** | Step-skip vulnerability + token-reuse + cleanup asymmetry add up to "rewrite" not "patch". (#6, #43, #50, #56, #61, #62) |

### B.5 — Audit findings → milestone assignment

The 70 audit items roll into the existing milestone split as follows:

- **v3.20.0** — **B-P0-2 must land here** (wire up notifications). Plus easy wins: #19 schedule `updated_at`, #25 retention=0 semantics, #30 `getopt()` in `backup.php`, #42 exit codes documented, #54 S3 redaction tuned, #58 `BackupClientInterface::list` rename. Most P3 nits.
- **v3.21.0** — **B-P0-1 (concurrency / stuck-running)**, **B-P0-3 (sigchild in restore.php)**, **B-P0-4 (SQL splitter rewrite)**. Restore wizard state-machine rewrite. `schedule_id` FK populated (#4, #26). `ipam_backup_apply_retention` split (#8). Restore `set_time_limit` + session invalidation (#50, #62). All maintainability items in legacy paths get **deleted**, not refactored.
- **v3.22.0** — Crypto items (B-P1-13, B-P1-35, #5, #40, #46) addressed by IPAMBKP3 design. Test gaps for SFTP, notify, restore (#45, #57, T2/T3/T6) close as part of the encryption-format change PR.
- **v4.0.0** — Tenant-scoped retention (#47 prevention by design), per-tenant HKDF (already planned).

**Net effect on milestone scope:** v3.20.0 absorbs ~10 small items including the P0 notification fix; v3.21.0 absorbs the heaviest rewrites (~20 items); v3.22.0 absorbs ~15 crypto/test items already in scope; remainder get deferred or fixed incidentally during consolidation.

### B.6 — Recommended new functionality items (add to §6)

These came out of the audit and aren't in the existing F1–F31 list:

- **F32** — Stale-running-row reaper. Run on every cron tick: any `backup_runs` row stuck in `running` longer than 2× the configured timeout gets force-marked failed with `detail='reaper: stuck running'`, audit logged. (P0 finding B-P0-1.)
- **F33** — Pessimistic per-schedule lock during cron iteration. `SELECT ... FOR UPDATE SKIP LOCKED` (or SQLite-equivalent advisory) so two cron processes can never fire the same schedule. (P1 finding B-P1-37.)
- **F34** — Range-request resume support in S3Client::download. Required for backups >1GB on unstable links. (P2 finding #28.)
- **F35** — Streaming gzread in restore (no full-buffer load). Required for OOM safety on big dumps. (P2 finding #39.)
- **F36** — Pre-validate dump file in dry-run (parse first N statements). Fail early instead of at apply. (P2 finding #20.)
- **F37** — DSN credentials via stdin (mysql/psql), not env. Removes `ps`-output leak. (P1 findings #2, #29.)
- **F38** — `backup_runs` `triggered_by` consolidation: drop ambiguous combo of `type` + `triggered_by` from §A1 schema; single enum. (P1 finding #18.)

### B.7 — Recommended new testing items (add to §8)

- **T11** — `BackupRetentionTest` edge-case expansion: keep_*=0 disabled-tier, all-backups-fall-to-daily-only, same-slot tie-breaking, NTP-skewed timestamps. (B-P1-45.)
- **T12** — `ipam_backup_next_run_at` table-driven edge-case suite: Friday DOW boundary, month boundary, leap-day, DST transitions. (B-P1-21.)
- **T13** — Restore SQL splitter property test — round-trip parse → execute → row-count parity for synthetic dumps with TRIGGER bodies, multi-line literals, inline comments. Ships before B-P0-4 rewrite. (B-P0-4.)
- **T14** — Stale-running-row reaper test (F32).
- **T15** — Concurrency test: two cron processes hitting same schedule (F33).

These slot in alongside T1–T10 in §8.

---

## §C. Master prioritization table (FOR REVIEW — 2026-04-29)

Single source of truth for milestone shuffling. **Pulls from:**
- GitHub: open issues currently filed in v3.20.0, v3.21.0, v4.0.0
- Overhaul doc: F1–F25 functionality, U1–U10 UI, T1–T10 testing, D1–D12 docs
- Audit (§B): F32–F38 new functionality, T11–T15 new tests, B-P0/P1 specific findings
- Non-backup items already on the v3.21.0 roadmap (#770 MFA tests, #775 dashboard VR) — included so re-shuffling sees them

**Status legend:**
- **Cur** = currently filed milestone (`—` = not yet filed)
- **Sugg** = proposed milestone (this column drives the conversation)
- **Pri** = P0 blocking / P1 severe / P2 polish / P3 nit

> **Action review needed:** v3.21.0 is currently very thin (2 non-backup issues). v3.22.0 + v3.23.0 milestones don't exist yet and need to be created. Any row where Cur ≠ Sugg is a re-target.

### v3.20.0 — destinations UX cleanup + urgent fixes (in flight)

| ID | Type | Title | Cur | Sugg | Pri | Notes |
|---|---|---|---|---|---|---|
| #778 | GH/feat | Edit destination drawer | v3.20 | v3.20 | P0 | Already filed |
| #779 | GH/feat | Manual Run-now on destination row | v3.20 | v3.20 | P0 | Already filed |
| #780 | GH/feat | Edit schedule drawer | v3.20 | v3.20 | P0 | Already filed |
| #781 | GH/feat | Frequency-aware schedule fields | v3.20 | v3.20 | P1 | Already filed |
| #782 | GH/fix | Backup timestamps in user TZ | v3.20 | v3.20 | P1 | Already filed |
| #787 | GH/feat | Auto-Test on destination Save | v3.20 | v3.20 | P1 | Already filed |
| #789 | GH/test | MinIO/LocalStack integration test (T1) | v3.20 | v3.20 | P0 | Already filed; gate for everything downstream |
| B-P0-2 | Audit | **Wire `ipam_backup_notify` to real callers** | — | **v3.20** | **P0** | Production currently quietly broken; trivial wire-up; lands ahead of any other notifications work |
| B-P1-19 | Audit | Schedule UPDATE missing `updated_at` | — | v3.20 | P2 | Trivial |
| B-P1-23 | Audit | Destination secret-merge fragility | — | v3.20 | P2 | Triggered when Edit-destination (#778) ships — must fix together |
| B-P3-30 | Audit | `backup.php` CLI getopt | — | v3.20 | P3 | Tiny |
| B-P3-42 | Audit | Document `backup.php` exit codes | — | v3.20 | P3 | Tiny |
| B-P3-54 | Audit | S3Client error-redaction over-aggressive | — | v3.20 | P3 | Tiny |
| B-P3-58 | Audit | Rename `BackupClientInterface::list` (collides with `list()`) | — | v3.20 | P3 | Pre-rewrite cleanup |

**v3.20.0 total: 14 items** (7 already filed + 7 audit-driven). Same scope as filed today plus a P0 fix and small cleanups.

### v3.21.0 split rationale (2026-04-29)

Original §C draft put 37 items in v3.21.0. Owner: "Some of these releases are way too heavy." Re-split below across **six MINOR releases** before v4.0.0:

- **v3.20.0** — destinations UX cleanup + B-P0-2 wire notifications (in flight, ~14)
- **v3.21.0** — Unified Surface + restore rewrite (anchor, ~16)
- **v3.22.0** — Concurrency hardening (pure backend, ~12)
- **v3.23.0** — PDO restore + maintainability (~10)
- **v3.24.0** — Encryption v3 / `IPAMBKP3` (~10)
- **v3.25.0** — Retention rehome + polish (~16)
- **v3.26.0+** — Cross-engine restore parking lot
- **v4.0.0** — Multi-tenancy (frozen scope + F17/F22/D7 backup-tenancy bits)

Each milestone is now ≤16 items, single-theme. Less context-switching mid-release, easier review, smaller blast radius if any one milestone slips.

### v3.21.0 — Unified `Backup & Restore` surface + restore wizard rewrite

**Theme:** UI consolidation + restore-side P0 fixes. Schema migration to `backup_runs` lands here (§A1) since the unified History tab needs it.

| ID | Type | Title | Cur | Sugg | Pri | Notes |
|---|---|---|---|---|---|---|
| ~~F1~~ | ~~Func~~ | ~~Unified `Backup & Restore` admin (5 tabs)~~ | #797 | **DONE 2026-05-01** | P0 | Shipped Wave 4 — `backup_admin.php` with 5 tabs (Destinations / Backup / History / Restore / Notifications). |
| ~~F2~~ | ~~Func~~ | ~~Retire `db_tools.php` Database entry~~ | #798 | **DONE 2026-05-01** | P0 | Sidebar nav entry removed (`lib.php:7225` comment); `db_tools.php` retained as direct-URL data-export tool only, no backup UI. |
| ~~F3~~ | ~~Func~~ | ~~Single backup-history view (§A1 `backup_runs`)~~ | #799 | **DONE 2026-05-01** | P0 | Shipped Wave 2 — `backup_runs` table replaces `backup_log`+`backup_history`; legacy tables dropped at end of migration. |
| ~~U1~~ | ~~UI~~ | ~~Drawer-driven CRUD across surface~~ | #800 | **DONE 2026-05-01** | P0 | Global drawer (#803) for History details + Destinations editors; webhooks / notifications / restore-wizard already drawer-driven. |
| ~~U2~~ | ~~UI~~ | ~~Inline progress reporting on Run-now~~ | #801 | **DONE 2026-05-01** | P1 | Run-now now async + inline result panel; replaces redirect-and-flash. |
| ~~U7~~ | ~~UI~~ | ~~Empty-state copy across tabs~~ | #802 | **DONE 2026-05-01** | P2 | "No backup runs found" / "No history" empty-state strings on Backup + History views. |
| ~~F11~~ | ~~Func~~ | ~~Per-row backup detail drawer in History~~ | #803 | **DONE 2026-05-01** | P2 | Shipped: `backup_run_detail.php` partial + drawer triggers in History; `destination_edit_drawer.php` partial + drawer triggers replace inline editors on Destinations for unified-surface consistency. Follow-ups filed: #1052 (bulk multi-select delete) and #1053 (auto retention purge), both v3.22.0. |
| ~~F12~~ | ~~Func~~ | ~~Filter chips on History~~ | #804 | **DONE 2026-05-01** | P2 | Three chip rows (Status / Backup type / Time) above the existing form; chip clicks mutate single URL params, no JS; Clear-all chip resets. URL `type=` renamed to `backup_type=` (database\|logical), legacy `type=restore` still yields zero rows. |
| ~~B-P0-3~~ | ~~Audit~~ | ~~**Sigchild fix in `restore.php`**~~ | #805 | **DONE 2026-05-01** | **P0** | Same fallback applied as v3.19.1's fix to `backup_run_dump`. |
| ~~B-P0-4~~ | ~~Audit~~ | ~~**SQL splitter rewrite (real lexer)**~~ | #806 | **DONE 2026-05-01** | **P0** | Shipped Wave 1 (commit 90552da); covered by `RestoreSplitterTest`. |
| ~~Restore wizard rewrite~~ | ~~Audit~~ | ~~Step-machine + token + cleanup (#6/#43/#50/#56/#61/#62)~~ | #807 | **DONE 2026-05-01** | P1 | Shipped Wave 3 — `lib/restore_wizard.php` phase-locked state machine + `restore_web` rewrite + Playwright updates. |
| ~~F38~~ | ~~Func~~ | ~~Drop ambiguous `type`+`triggered_by` combo~~ | #808 | **DONE 2026-05-01** | P1 | `backup_runs` schema uses `backup_type` (database\|logical) + `triggered_by` (schedule\|manual\|cli) as separate axes. |
| B-P1-31 | Audit | `started_at` vs `created_at` divergence | #809 | **DEFERRED → v3.22.0** | P1 | `started_at` already serves as row insert time (orchestrator INSERTs at run start; spec admits `created_at == started_at in practice`). Formal `created_at` column needs a table rebuild (SQLite forbids non-constant DEFAULT on ADD COLUMN). Re-targeted to v3.22.0 alongside concurrency-hardening migration window. See [#809 comment 2026-05-01](https://github.com/seanmousseau/Simple-PHP-IPAM/issues/809#issuecomment-4360231729). |
| ~~T13~~ | ~~Test~~ | ~~SQL splitter property test~~ | #810 | **DONE 2026-05-01** | P0 | Shipped Wave 1 alongside the lexer rewrite; `tests/RestoreSplitterTest.php`. |
| ~~T10~~ | ~~Test~~ | ~~Non-admin role test on all new pages~~ | #811 | **DONE 2026-05-01** | P0 | Shipped: `tests/BackupAdminRbacTest.php` (structural lint) + `testing/playwright/tests/backup-rbac.spec.ts` (HTTP-level). |
| ~~D1~~ | ~~Doc~~ | ~~Rewrite `docs/backups.md` for unified surface~~ | #812 | **DONE 2026-05-01** | P0 | Rewritten end-to-end for unified surface. Obsolete `docs/backup.md` removed. |
| ~~D2~~ | ~~Doc~~ | ~~New `docs/restore.md`~~ | #813 | **DONE 2026-05-01** | P0 | Rewritten for unified Restore tab; both Logical / Database paths documented; cross-version policy formalised. |
| ~~D11~~ | ~~Doc~~ | ~~New `docs/internal/backup-restore-runbook.md`~~ | #814 | **DONE 2026-05-01** | P1 | Initial runbook with eight failure modes; living doc. |

**v3.21.0 total: ~18 items.** Single theme: surface + restore.

**Out of v3.21.0 (proposed move):** #770 MFA tests → v3.22.0, #775 VR dashboard → v3.22.0. They're non-backup test work and getting crowded out.

### v3.22.0 — Concurrency hardening + cron architecture

**Theme:** pure backend, no UI. Fixes the stuck-running hole, cron races, sigchild edges, and the cron-priority architecture issue.

| ID | Type | Title | Cur | Sugg | Pri | Notes |
|---|---|---|---|---|---|---|
| B-P0-1 | Audit | **Stale-`running` row reaper + concurrency guard** | — | v3.22 | **P0** | Stuck-row hole |
| F32 | Func | Stale-running-row reaper (formal) | — | v3.22 | P0 | Same as B-P0-1 |
| F33 | Func | Pessimistic per-schedule lock in cron | — | v3.22 | P1 | B-P1-37 |
| F23 | Func | Cron task reorder (backup before scanner) | — | v3.22 | P1 | §A5 detail |
| B-P1-3 | Audit | `backup_run_dump` pipe-buffer / `stream_select` | — | v3.22 | P1 | sigchild adjacency |
| B-P1-9/44 | Audit | WAL-checkpoint exception not swallowed | — | v3.22 | P1 | SQLite |
| F37 | Func | DSN credentials via stdin (mysql/psql) | — | v3.22 | P1 | B-P1-2, B-P1-29 |
| B-P1-4/26 | Audit | Populate `schedule_id` FK in cron + insert_log | — | v3.22 | P1 | Comes with §A1 |
| T14 | Test | Stale-running-row reaper test | — | v3.22 | P0 | With F32 |
| T15 | Test | Concurrency: two cron procs same schedule | — | v3.22 | P1 | With F33 |
| #770 | GH/test | MFA preferred-switch e2e (moved from v3.21) | v3.21 | v3.22 | P1 | Non-backup; fits anywhere |
| #775 | GH/test | VR dashboard harness (moved from v3.21) | v3.21 | v3.22 | P1 | Non-backup; fits anywhere |
| #1052 | Func | Bulk multi-select delete on backup History | — | v3.22 | P1 | Reuses #803 server-side delete handler (`ipam_backup_run_delete`); UI adds row checkboxes + bulk action bar. |
| #1053 | Func | Automatic `backup_runs` retention purge | — | v3.22 | P1 | Mirrors `audit.retention_days`; cron-driven prune of completed rows past the configured horizon. |

**v3.22.0 total: ~14 items.** Deliberately small; backend-only; no UI churn means easier review.

### v3.23.0 — PDO restore + maintainability cleanups

**Theme:** logical restore via PDO (no shell-out), retention/scheduler refactors, smaller bug fixes.

| ID | Type | Title | Cur | Sugg | Pri | Notes |
|---|---|---|---|---|---|---|
| F18 | Func | PDO-based restore (logical) | — | v3.23 | P1 | Anchor |
| F21 | Func | Notifications config resolution (global + per-sched) | — | v3.23 | P1 | Builds on B-P0-2 |
| B-P1-8 | Audit | Split `ipam_backup_apply_retention` into compute/apply | — | v3.23 | P1 | Pre-req for tests |
| B-P1-21 | Audit | `ipam_backup_next_run_at` edge cases (T12 first) | — | v3.23 | P1 | Tests then refactor |
| B-P1-15 | Audit | SQLite retention sort by mtime, not lex | — | v3.23 | P1 | After legacy retires |
| F35 | Func | Streaming gzread in restore (OOM safety) | — | v3.23 | P2 | Audit #39 |
| F36 | Func | Pre-validate dump in dry-run | — | v3.23 | P2 | Audit #20 |
| T11 | Test | `BackupRetentionTest` edge-case expansion | — | v3.23 | P1 | Pre-req for B-P1-8 |
| T12 | Test | `next_run_at` table-driven edge cases | — | v3.23 | P1 | Pre-req for B-P1-21 |
| T2 | Test | OpenSSH-server sidecar for SFTP coverage | — | v3.23 | P0 | Test debt |
| T3 | Test | Local destination CI coverage | — | v3.23 | P0 | Test debt |
| T4 | Test | Restore-to-empty-DB row-count parity | — | v3.23 | P0 | All 3 engines |

**v3.23.0 total: ~12 items.** Mostly mechanical cleanups; F18 is the visible feature.

### v3.24.0 — Encryption v3 (`IPAMBKP3`) + manual restore

**Theme:** the encryption-format upgrade. New `backup_vault_key` separates from `app_secret`; passphrase mode enables manual upload-and-restore.

| ID | Type | Title | Cur | Sugg | Pri | Notes |
|---|---|---|---|---|---|---|
| F16 | Func | `IPAMBKP3` passphrase scheme + `backup_vault_key` | — | v3.24 | P0 | Anchor |
| F13 | Func | Manual upload+restore with passphrase | — | v3.24 | P0 | Comes with F16 |
| Crypto audit cluster | Audit | B-P1-13, B-P1-35, B-P1-40, P2 #5/#46 | — | v3.24 | P1 | All addressed by IPAMBKP3 |
| T6 | Test | Tamper / corruption tests for `IPAMBKP3` | — | v3.24 | P1 | Matches IPAMBKP2 coverage |
| T7 | Test | Schedule-fires-at-expected-time | — | v3.24 | P1 | Cron tick simulation |
| D3 | Doc | `docs/configuration.md` `backup_vault_key` lifecycle | — | v3.24 | P0 | |
| D4 | Doc | Encryption section: 3 modes + Argon2id params | — | v3.24 | P0 | |
| D5 | Doc | Regenerate `data-dictionary.md` | — | v3.24 | P0 | After `backup_runs` and IPAMBKP3 schema bits |
| D6 | Doc | Marketing card rewrite for `IPAMBKP3` | — | v3.24 | P1 | |
| D10 | Doc | `docs/upgrading.md` v3.24 cutover notes | — | v3.24 | P0 | Encryption-format change is operator-facing |

**v3.24.0 total: ~10 items.** Tightly scoped to crypto.

### v3.25.0 — Retention rehome + polish + PDO dump

**Theme:** moves retention to destination level, "Protect this backup" flag, default destination, PDO dump (logical), all the U-series polish.

| ID | Type | Title | Cur | Sugg | Pri | Notes |
|---|---|---|---|---|---|---|
| F15 | Func | Retention re-homed at destination level | — | v3.25 | P1 | §3 AGREED |
| F14 | Func | "Protect this backup" flag on rows | — | v3.25 | P1 | §3 |
| F10 | Func | Default destination selector | — | v3.25 | P2 | §2.3 |
| F19 | Func | PDO-based dump (logical) | — | v3.25 | P1 | Per §5 cycle plan |
| F24 | Func | "Verify all backups" bulk action | — | v3.25 | P2 | New |
| F25 | Func | Opt-out encryption for trusted local | — | v3.25 | P2 | New |
| F34 | Func | Range-request resume in S3Client::download | — | v3.25 | P2 | Audit #28 |
| U3 | UI | Dashboard backup feature card | — | v3.25 | P2 | last-run / next-run / size |
| U4 | UI | Health page per-destination status | — | v3.25 | P2 | New |
| U6 | UI | Skeleton loading on remote-listings | — | v3.25 | P2 | UX checklist |
| U8 | UI | Cancel-in-flight on Run-now | — | v3.25 | P2 | New |
| U9 | UI | Encryption-format icon in History | — | v3.25 | P2 | v1/v2/v3 visibility |
| U10 | UI | Type-name-to-confirm on destination delete | — | v3.25 | P2 | Pattern extension |
| T8 | Test | Destination-disabled mid-backup | — | v3.25 | P2 | Graceful failure |
| T9 | Test | Large-DB streaming test (>1GB) | — | v3.25 | P2 | Memory bounds |

**v3.25.0 total: ~15 items.** Polish-heavy; can absorb minor cuts if it slips.

### v3.26.0+ — Cross-engine restore parking lot

| ID | Type | Title | Cur | Sugg | Pri | Notes |
|---|---|---|---|---|---|---|
| Cross-engine restore | Func | sqlite-dump → mysql-target, etc. | — | v3.26+ | P2 | AGREED defer §5c' |
| T5 | Test | Cross-engine restore tests | — | v3.26+ | P2 | Follows F18+F19 |
| ~~F20~~ | ~~Func~~ | ~~Backup type selector `Database` vs `Data`~~ | — | **DROPPED** | — | Resolved 2026-04-29: superseded by §2.1.1 two-type model |

### v4.0.0 — multi-tenancy (frozen scope; 25 issues already filed)

| ID | Type | Title | Cur | Sugg | Pri | Notes |
|---|---|---|---|---|---|---|
| F17 | Func | Per-tenant HKDF derivation | — | v4.0 | P0 | Tenancy-anchored |
| F22 | Func | "Default backup destination" tenant policy | — | v4.0 | P1 | Local-disabled in tenancy |
| #790 | GH | Scanner perf + remote-scanner SaaS investigation | v4.1 | v4.1 | P2 | Filed today; non-backup |
| (25 existing) | GH | All currently in v4.0.0 milestone | v4.0 | v4.0 | — | Frozen until overhaul completes |
| D7 | Doc | `docs/tenancy.md` — backup section | — | v4.0 | P0 | |
| D12 | Doc | Move `backup_overhaul.md` → attic | — | v4.0 release | — | Final cleanup |

### Per-release continuous

| ID | Type | Title | Cur | Sugg | Pri | Notes |
|---|---|---|---|---|---|---|
| D8 | Doc | `CLAUDE.md` deps + Database section updates | — | per-release | — | As needed |
| D9 | Doc | `README.md` "What's new" replacement | — | per-release | — | Project convention |

### Summary by milestone (post-split)

| Milestone | Existing GH | Audit/overhaul adds | New total | Theme |
|---|---|---|---|---|
| v3.20.0 | 7 | 7 | **14** | Destinations UX + B-P0-2 notifications wire |
| v3.21.0 | 0 backup (2 non-backup moving out) | ~18 | **~18** | Unified surface + restore wizard rewrite |
| v3.22.0 | 0 (created 2026-04-29) | ~12 | **~12** | Concurrency + cron architecture |
| v3.23.0 | 0 (created 2026-04-29) | ~12 | **~12** | PDO restore + maintainability cleanups |
| v3.24.0 | 0 (created 2026-04-29) | ~10 | **~10** | `IPAMBKP3` encryption + manual restore |
| v3.25.0 | 0 (created 2026-04-29) | ~15 | **~15** | Retention rehome + PDO dump + polish |
| v3.26.0+ | 0 | 3 | 3 | Cross-engine restore parking lot |
| v4.0.0 | 25 (frozen) | 3 | 28 | Multi-tenancy + tenant-derived crypto |

**Net effect of the split:** worst case is now ~18 items in any single release (v3.21.0). Six themed minor releases between v3.20.0 and v4.0.0; each has a single dominant theme; backend-only milestones (v3.22, v3.23) interleave with UI-touching milestones (v3.21, v3.24, v3.25) so review fatigue evens out.

### Decisions needed from this table

1. ~~**v3.20.0 B-P0-2 (notifications).**~~ **AGREED 2026-04-29.** Filed as #791 against v3.20.0.
2. ~~**#770 + #775 move to v3.22.0.**~~ **AGREED 2026-04-29.** Both moved.
3. ~~**F20 (Database vs Data backup type selector).**~~ **DROPPED 2026-04-29** — superseded by §2.1.1 two-type model.
4. **Issue creation pace.** Once milestones are confirmed, file v3.20.0 audit-adds first (small, urgent), then v3.21.0 batch, then v3.22+. Don't batch-file all 100+ — backlog noise.

---

## 10. Tentative milestone split (DRAFT — do not file yet)

Once §9 architectural concerns are settled and the running lists are prioritized, plausible carving:

**Superseded by §C (2026-04-29 split).** Original 4-release carving was too dense — v3.21.0 was projected at 37 items. Replaced by 6-release split below; see §C for the full per-milestone tables.

- **v3.20.0** — Destinations UX cleanup (#778-#787) + #789 MinIO test + B-P0-2 notifications wire. ~14 items.
- **v3.21.0** — Unified `Backup & Restore` surface + restore wizard rewrite + SQL splitter rewrite + sigchild fix in restore.php. **Docs:** D1, D2, D11. ~18 items.
- **v3.22.0** — Concurrency hardening: stuck-running reaper, per-schedule lock, cron reorder, sigchild edges. Pure backend, no UI. ~12 items.
- **v3.23.0** — PDO restore (F18), retention/scheduler refactors, maintainability cleanups, SFTP/local CI tests. ~12 items.
- **v3.24.0** — `IPAMBKP3` encryption + manual upload-and-restore. **Docs:** D3, D4, D5, D6, D10. ~10 items.
- **v3.25.0** — Retention re-homed at destination, "Protect this backup" flag, PDO dump (F19), all U-series polish. ~15 items.
- **v3.26.0+** — Cross-engine restore parking lot.
- **v4.0.0** — Per-tenant HKDF derivation (F17), tenant-scoped UI (F22). **Docs:** D7, D12. Once shipped, this doc moves to `docs/internal/attic/`.

D8 (CLAUDE.md) and D9 (README.md) apply to every release per existing project conventions.

Six minor releases of focused backup work before tenancy is even a conversation. Each themed; ≤18 items per release; backend-only milestones interleave with UI-touching ones for review-fatigue balance.

---

## §E. Test-suite updates (per milestone) — added 2026-04-30

Backup overhaul §8 already lists testing improvements as part of the running list, but rolling them into per-milestone **test-update umbrella issues** makes the test work first-class and enforces lockstep with refactors. (Added retroactively after the same pattern was instituted for the UX overhaul; see `ux_overhaul.md` §12 and `code_quality_review.md` §11.)

| Milestone | Test umbrella | Headline test work |
|---|---|---|
| v3.20.0 | not added (work in flight, mostly destination UX cleanup) | — |
| v3.21.0 | `tests: Playwright specs + drawer trap for unified Backup & Restore surface` (#1040) | Replace 4 backup-page specs with one `backup_restore.spec.ts` covering 5 tabs; drawer focus-trap; restore-wizard rewrite; manual upload; inline run-now progress; VR rebaseline. |
| v3.22.0 | `tests: concurrency + cron-architecture hardening` (#1041) | Concurrent Run-now, schedule overlap, cron lock-file, notifications subscriptions, schedule-overdue alerting. |
| v3.23.0 | `tests: PDO restore engine — parse + replay across all 3 dump formats` (#1042) | mysqldump / pg_dump / SQLite native parser tests; FK bracketing per engine; PDO replay integration tests; large-dump perf benchmark; tarball-deployment no-CLI assertion. |
| v3.24.0 | `tests: encryption v3 (IPAMBKP3) format + manual passphrase restore + decrypt tooling` (#1043) | IPAMBKP3 format tests; manual passphrase wizard; standalone decrypt tooling; back-compat with IPAMBKP1/2; cross-format round-trip. |
| v3.25.0 | `tests: GFS retention table + PDO logical dump format` (#1044) | GFS daily/weekly/monthly/yearly fixtures; retention dry-run preview; `IPAMBKL1` logical dump portability; cross-engine logical-dump assertion (restore parked at v3.26+); CLI-format escape-hatch smoke test. |
| v3.26.0+ | not yet (cross-engine restore parking lot) | — |

**Cross-cutting policy** (matches `ux_overhaul.md` §12 / `code_quality_review.md` §11):
- Each PR closing a backup-overhaul finding must update or add the matching test in the same commit.
- VR rebaselines for the unified surface (v3.21.0) are committed in their own commit per CLAUDE.md release-workflow guidance.
- The test umbrella issue stays open until *all* finding issues in its milestone are closed.
- 3-engine local containerized gate must be all-green before push (CLAUDE.md non-negotiable rule).

---

## 11. Process notes

- **No code changes** while this doc is open. The only thing this doc produces is a milestone-and-issue carving once we converge.
- **Discussion happens in this file.** Add `**SEAN:**` / `**CLAUDE:**` voice tags to sections under debate so we can see who said what when we look back.
- **Status tags** (`AGREED` / `PROPOSED` / `OPEN`) get updated as we converge. When everything is `AGREED` the doc gets converted into milestone scopes + issues, and the doc itself becomes an attic record (move to `docs/internal/attic/` or similar).
- **No public-facing doc changes** until issues are filed and milestones are public — keeps the v3.20.0 release announcements cleaner.

---

## §D. Issue creation log — 2026-04-29

All §C items filed as GitHub issues against their suggested milestones. **76 issues total** (#791 + #792-865; B-P0-2 was filed earlier as #791).

### Mapping of overhaul item → issue #

**v3.20.0** (14 total: 7 pre-existing #778-787,789 + 7 audit-add):
- B-P0-2 wire notifications → **#791**
- B-P1-19 schedule UPDATE updated_at → **#792**
- B-P1-23 destination secret-merge → **#793**
- B-P3-30/42 backup.php getopt + exit codes → **#794**
- B-P3-54 S3 error redaction → **#795**
- B-P3-58 BackupClientInterface::list rename → **#796**

**v3.21.0** (18 issues, #797-814):
- F1 unified surface → #797
- F2 retire db_tools.php → #798
- F3 backup_runs consolidation → #799
- U1 drawer CRUD → #800
- U2 inline progress → #801
- U7 empty states → #802
- F11 detail drawer → #803
- F12 filter chips → #804
- B-P0-3 sigchild restore.php → #805
- B-P0-4 SQL splitter rewrite → #806
- Restore wizard rewrite (B-P1-43, B-P2-50/56/61/62) → #807
- F38 drop type+triggered_by → #808
- B-P1-31 timestamp unification → #809
- T13 SQL splitter property tests → #810
- T10 RBAC tests → #811
- D1 docs/backups.md rewrite → #812
- D2 docs/restore.md → #813
- D11 backup-restore-runbook.md → #814

**v3.22.0** (11 total: 2 pre-existing #770, #775 + 9 backup):
- B-P0-1 / F32 stale-running reaper → #815
- F33 per-schedule lock → #816
- F23 cron task reorder → #817
- B-P1-3 stream_select pipe-buffer → #818
- B-P1-9/44 WAL-checkpoint exception → #819
- F37 DSN credentials via stdin → #820
- B-P1-4/26 schedule_id wiring → #821
- T14 reaper test → #822
- T15 concurrency test → #823

**v3.23.0** (12 issues, #824-835):
- F18 PDO restore → #824
- F21 unified notifications config → #825
- B-P1-8 split apply_retention → #826
- B-P1-21 next_run_at edge cases → #827
- B-P1-15 SQLite retention sort → #828
- F35 streaming gzread → #829
- F36 dry-run pre-validate → #830
- T11 retention edge tests → #831
- T12 next_run_at table tests → #832
- T2 OpenSSH SFTP CI → #833
- T3 Local destination CI → #834
- T4 row-count parity → #835

**v3.24.0** (10 issues, #836-845):
- F16 IPAMBKP3 + backup_vault_key → #836
- F13 manual upload+restore → #837
- Crypto audit cluster (B-P1-13/35/40, B-P2-5/46) → #838
- T6 IPAMBKP3 tamper tests → #839
- T7 schedule fire-time test → #840
- D3 configuration.md → #841
- D4 docs/backups.md encryption section → #842
- D5 data-dictionary regen → #843
- D6 marketing site card → #844
- D10 upgrading.md cutover notes → #845

**v3.25.0** (15 issues, #846-860):
- F15 retention rehome → #846
- F14 is_protected flag → #847
- F10 default destination → #848
- F19 PDO dump → #849
- F24 verify-all bulk → #850
- F25 opt-out encryption → #851
- F34 S3 range resume → #852
- U3 dashboard card → #853
- U4 health page destinations → #854
- U6 skeleton loading → #855
- U8 cancel in-flight → #856
- U9 encryption-format icon → #857
- U10 type-name-to-confirm delete → #858
- T8 destination-disabled mid-backup → #859
- T9 large-DB streaming test → #860

**v3.26.0** (2 issues, #861-862):
- Cross-engine restore → #861
- T5 cross-engine tests → #862

**v4.0.0** (28 total: 25 pre-existing + 3 backup):
- F17 per-tenant HKDF → #863
- F22 tenancy local-disabled policy → #864
- D7 docs/tenancy.md backup section → #865

**Out of overhaul scope but related:**
- #790 — Scanner perf + remote-scanner SaaS (v4.1.0)

### Maintenance protocol

When an issue ships:
1. The closing PR strikes through the corresponding row in §C
2. The closing PR notes scope deltas in §6 / §7 / §8 / §8a / §B
3. D8 (CLAUDE.md) and D9 (README.md) updates land in the same PR per release convention
4. Once all v3.21+ issues for a milestone close, update §10 with the actual delivery vs. plan delta

When this doc reaches end-of-life (v4.0.0 ships, all overhaul issues closed): move to `docs/internal/attic/` per D12.
