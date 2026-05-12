# Backup format library

A local library of **one real, large-DB backup archive per format** Simple-PHP-IPAM
has ever produced. It's a regression-test safety net — exercise `tools/decrypt-backup.php`
and the in-app Restore wizard against *real* archives (sizeable, produced by the
actual codec from a populated database) rather than toy fixtures.

The full format catalogue (history, magics, what wraps what, credentials) is in
**`docs/internal/backup-formats-matrix.md`**. The `tools/decrypt-backup.php`
conformance plan is in **`docs/internal/decrypt-tool-test-plan.md`**.

## What's here

- `generate.php` — regenerates the whole library. Runs inside a dockerized,
  bulk-seeded app instance (`bootstrap-app.sh sqlite` + a large-data seed), then
  `docker cp`s the archives out. See "Regenerating" below. **Tracked in git.**
- `archives/` — the archive files (`.enc`, `.ipambkp3`, `.ipambku1`, `.sql.gz`,
  `.ipambkl1.gz`, `.sqlite`), one per format. **Git-ignored — local only.**
- `MANIFEST.md` — per-archive index: format, magic bytes, size, SHA-256, the
  credential needed, and the exact `decrypt-backup.php` invocation to verify it.
  **Git-ignored — records the (fixture) credentials, kept local.**

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

```bash
# 1. spin up a dockerized sqlite app and seed a large dataset
bash testing/playwright/bootstrap-app.sh sqlite
docker cp testing/scripts/seed-large-db.php ipam-pw-test:/tmp/seed-large-db.php
docker exec ipam-pw-test php /tmp/seed-large-db.php          # bulk-seed to ~50 MB

# 2. run the generator inside the container, emitting to /tmp/backup-library.
#    -d memory_limit=1024M: ipam_backup_dump_to_tmp() fetchAll()s each table,
#    and the 100k-row addresses table blows the container's 128M default.
docker cp tests/fixtures/backup-library/generate.php ipam-pw-test:/tmp/generate-backup-library.php
docker exec -e BACKUP_LIBRARY_OUT=/tmp/backup-library ipam-pw-test php -d memory_limit=1024M /tmp/generate-backup-library.php

# 3. copy the archives + manifest back out
rm -rf tests/fixtures/backup-library/archives tests/fixtures/backup-library/MANIFEST.md
docker cp ipam-pw-test:/tmp/backup-library/archives tests/fixtures/backup-library/archives
docker cp ipam-pw-test:/tmp/backup-library/MANIFEST.md tests/fixtures/backup-library/MANIFEST.md

# 4. tear down
bash testing/playwright/teardown-app.sh
```

(MySQL/PostgreSQL `.sql.gz` variants — which differ from SQLite's because they're
`mysqldump`/`pg_dump` output — are not generated here yet; the encrypted formats
and `.ipambkl1.gz` are engine-agnostic so the SQLite library covers them. Add the
other-engine `.sql.gz` variants if/when restore-across-engines testing needs them.)
