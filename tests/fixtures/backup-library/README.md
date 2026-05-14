# Backup format library

A local library of **one real, large-DB backup archive per format Simple-PHP-IPAM
has ever produced, per supported engine** (SQLite, MySQL, PostgreSQL). It's a
regression-test safety net — exercise `tools/decrypt-backup.php` and the in-app
Restore wizard against *real* archives (sizeable, produced by the actual codec from
a populated database on each engine) rather than toy fixtures.

The full format catalogue (history, magics, what wraps what, credentials) is in
**`docs/internal/backup-formats-matrix.md`**. The `tools/decrypt-backup.php`
conformance plan is in **`docs/internal/archive/decrypt-tool-test-plan.md`**.

## What's here

- `generate.php` — regenerates the library **for whichever engine the app
  instance is running**. Detects the live PDO driver and emits under
  `archives/<driver>/`. Runs inside a dockerized, bulk-seeded app instance
  (`bootstrap-app.sh sqlite|mysql|pgsql` + a large-data seed), then `docker cp`s
  the archives out. See "Regenerating" below. **Tracked in git.**
- `archives/<driver>/` — the archive files, one per format, per engine:
  - `sqlite/` — `.sqlite` (L0, raw DB file), `.sql.gz` (B-SQL), `.ipambkl1.gz`
    (B-L1), `.ipambkp1.enc` / `.ipambkp2.enc` (P1/P2), `.stored.ipambkp3` /
    `.transitory.ipambkp3` (P3-S/P3-T), `.ipambku1` (U1).
  - `mysql/`, `pgsql/` — same set **minus the `.sqlite` L0 archive** (a raw
    SQLite-file backup is SQLite-only; on these engines the local-backup path
    always produced a `mysqldump`/`pg_dump` SQL stream, which *is* the B-SQL
    `.sql.gz` here). The `.sql.gz` is real `mysqldump | gzip` / `pg_dump | gzip`
    output; the `.ipambkl1.gz` and all encrypted formats are engine-agnostic.
  - **Git-ignored — local only.**
- `archives/<driver>/MANIFEST.md` — per-engine, per-archive index: format, magic
  bytes, size, SHA-256, the credential needed, and the exact `decrypt-backup.php`
  invocation to verify it. **Git-ignored — records the (fixture) credentials.**

## Credentials

The library uses **deterministic fixture credentials** (so the archives are
reproducible and the recorded credentials stay valid). They are throwaway —
never used by any real install. They live in `~/.claude/dev-secrets.env`:

| Var | Used for |
|---|---|
| `IPAM_BACKUP_LIBRARY_APP_SECRET` | the IPAMBKP1 / IPAMBKP2 archives (`--app-secret`) |
| `IPAM_BACKUP_LIBRARY_VAULT_KEY_B64` | the IPAMBKP3 *stored* archive (`--vault-key`) |
| `IPAM_BACKUP_LIBRARY_PASSPHRASE` | the IPAMBKP3 *transitory* archive (`--passphrase` / `$IPAM_BACKUP_PASSPHRASE`) |

`generate.php` derives the same values, so it's safe to regenerate and the
recorded credentials keep working. `MANIFEST.md` also lists them inline.

## Regenerating

Run the same flow once per engine. Substitute `$DRV` ∈ {`sqlite`, `mysql`, `pgsql`}:

```bash
# 1. spin up a dockerized app on the chosen engine and bulk-seed it
bash testing/playwright/teardown-app.sh            # if something's already up
bash testing/playwright/bootstrap-app.sh "$DRV"
docker cp testing/scripts/seed-large-db.php ipam-pw-test:/tmp/seed-large-db.php
# seed-large-db.php is fail-closed: it refuses to run without IPAM_ALLOW_LARGE_SEED=1.
docker exec -e IPAM_ALLOW_LARGE_SEED=1 ipam-pw-test php -d memory_limit=1024M /tmp/seed-large-db.php   # bulk-seed

# 2. run the generator inside the container, emitting to /tmp/backup-library.
#    It detects the driver and writes archives/<driver>/.
#    -d memory_limit=1024M: ipam_backup_dump_to_tmp() / the logical dump touch
#    every table, and the 100k-row addresses table blows the container's 128M default.
#    (mysqldump / pg_dump ship in testing/playwright/Dockerfile.apache as
#    default-mysql-client / postgresql-client, so no extra install is needed.)
docker cp tests/fixtures/backup-library/generate.php ipam-pw-test:/tmp/generate-backup-library.php
docker exec -e BACKUP_LIBRARY_OUT=/tmp/backup-library ipam-pw-test php -d memory_limit=1024M /tmp/generate-backup-library.php

# 3. copy that engine's archives (+ its MANIFEST.md) back out
rm -rf "tests/fixtures/backup-library/archives/$DRV"
docker cp "ipam-pw-test:/tmp/backup-library/archives/$DRV" "tests/fixtures/backup-library/archives/$DRV"

# 4. tear down before the next engine
bash testing/playwright/teardown-app.sh
```

After all three runs, `archives/` holds `sqlite/`, `mysql/`, `pgsql/`, each with
its format set + a `MANIFEST.md` (only `sqlite/` has the `.sqlite` L0 archive).
