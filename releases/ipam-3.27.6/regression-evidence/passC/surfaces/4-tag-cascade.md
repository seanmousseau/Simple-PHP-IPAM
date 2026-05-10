# Surface 4 — tag CASCADE behavior

**Verdict:** ✅ **PASS — no findings**
**Date:** 2026-05-10
**Method:** Empirical test against SQLite test instance (v3.27.6) via the app's `ipam_db()` PDO connection (so FK enforcement matches the production code path, not the bare `sqlite3` CLI which defaults to PRAGMA off).

## Hypothesis

`subnet_tags` and `address_tags` are join tables with FK CASCADE. The two CASCADE chains to verify:

- **Chain A:** `subnets` DELETE → `subnet_tags` removed for that subnet AND `addresses` for that subnet cascade-removed AND their `address_tags` cascade-removed.
- **Chain B:** `tags` DELETE → all `subnet_tags` AND `address_tags` rows for that tag removed, parent subnets/addresses untouched.

## Test script

`/tmp/passC-tag-cascade.php` (run as `www-data` inside the apache-php container). Opens DB via `ipam_db($config)`, exercises both chains on fresh scratch rows in 198.18.100.0/24 (RFC 2544 benchmark range — guaranteed not to overlap test fixtures).

## Pre-state

```
PRAGMA foreign_keys: 1
{"tags":5,"subnet_tags":100,"address_tags":4310,"subnets":500,"addresses":43100}
```

## Test 1 — subnet delete CASCADE chain

Seeded: subnet 1004 (198.18.100.0/30) + 2 addresses (198.18.100.1, 198.18.100.2) + tag 12 (`passC-T1`) + 1 subnet_tags link + 2 address_tags links.

```
DELETE FROM subnets WHERE id=1004
→ subnet_tags(sid=1004): 0  (cleaned)
→ address_tags(tid=12):  0  (cleaned via chain through addresses)
→ addresses(sid=1004):   0  (cleaned)
→ tag (id=12) still exists
```

**Verdict: PASS.** Chain works through two cascade hops.

## Test 2 — tag delete CASCADE

Seeded: subnet 1005 (198.18.100.4/30) + 1 address + tag 13 (`passC-T2`) + 1 subnet_tags + 1 address_tags.

```
DELETE FROM tags WHERE id=13
→ subnet_tags(tid=13):    0  (cleaned)
→ address_tags(tid=13):   0  (cleaned)
→ subnet (id=1005) still exists
→ address still exists
```

**Verdict: PASS.** Tag delete cleans both join tables without touching parents.

## Post-state (after script cleanup of scratch rows)

```
{"tags":5,"subnet_tags":100,"address_tags":4310,"subnets":500,"addresses":43100}
```

Identical to pre-state. No leaked rows.

## Engine-specific concerns

SQLite-only sweep. The schema parity test (`docs/internal/v2.9.0-multi-engine-schema-design.md`) asserts CASCADE actions match across SQLite / MySQL / PostgreSQL, so the same CASCADE behavior should hold on MySQL and Postgres. No escalation needed.

## Notes

- The app's `ipam_db()` enables PRAGMA foreign_keys = 1 (confirmed: line 1 of the script output). The bare `sqlite3` CLI defaults to 0; never use the CLI to assess CASCADE behavior.
- This test deliberately exercised the chained-CASCADE case (subnet → addresses → address_tags), which is the v2.2.1 data-loss regression class. The chain still cascades exactly as designed.
