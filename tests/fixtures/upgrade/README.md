# Upgrade Replay Fixtures

This directory holds minimal SQLite database files used by `tests/UpgradeReplayTest.php`
to verify that `apply_migrations()` can upgrade old installs without data loss.

The `testV221RegressionShape()` test constructs its fixture in-memory (no file needed)
and is the primary regression guard for the v2.2.1 data-loss bug. The file-based
fixtures below are optional and are skipped automatically if missing.

---

## Fixtures

| File | Source version | Content |
|------|---------------|---------|
| `v1.18-empty.sqlite` | v1.18.0 | Fresh install schema, no data |
| `v1.19-with-data.sqlite` | v1.19.0 | ~50 subnets, ~500 addresses |
| `v2.5-large.sqlite` | v2.5.0 | ~5000 addresses, stress-tests throughput |

---

## How to Generate

Check out the target release tag, initialise a fresh database, optionally seed data,
then copy the SQLite file here.

```bash
# Example: generate v1.19-with-data.sqlite from the v1.19.0 tag
git worktree add /tmp/ipam-v1.19 v1.19.0
cd /tmp/ipam-v1.19/Simple-PHP-IPAM
php -r "
\$config = require 'config.php'; require 'lib.php';
\$db = ipam_db(\$config);
ipam_db_init(\$db);
"
# Optionally run demo_seed.php or insert rows directly with sqlite3
# Then:
cp /tmp/ipam-v1.19/Simple-PHP-IPAM/data/ipam.sqlite \
   /path/to/tests/fixtures/upgrade/v1.19-with-data.sqlite
git worktree remove /tmp/ipam-v1.19
```

For the large fixture (~5000 addresses), use `testing/samples/large-db-sample/gen_large_db.php`
and copy the result.

---

## Notes

- Never commit a fixture that contains real passwords, personal data, or production IPs.
- These files are binary — they should not be modified by text editors.
- After generating, run `vendor/bin/phpunit tests/UpgradeReplayTest.php` to confirm the
  fixture passes before committing.
