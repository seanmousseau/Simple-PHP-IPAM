# PHPStan baseline triage — v3.29.0 (#898)

## Why this doc exists

D7 in `docs/internal/archive/code_quality_review.md` flagged `phpstan-baseline.neon`
as a 403-line dumping ground that was being treated as "mostly init.php $db/$config
noise" but was in fact masking real bugs (`Cannot cast mixed to string` in
`backup.php`, `Cannot access offset 'sha256' on mixed` in `db_tools.php`).

This doc records the v3.29.0 triage outcome so future reviewers don't re-relitigate
the same classification.

## Status after v3.29.0 triage

- Baseline length: **325 lines / 52 entries** (down from the 403 the review cited).
- The two specific bugs the review named (`backup.php` cast, `db_tools.php` sha256)
  are **no longer in the baseline** — fixed by ambient cleanup between the review
  and v3.29.0.
- Remaining entries fall into three categories below.

## Category 1 — init.php injection noise (1 entry, KEEP)

`init.php` populates `$db` and `$config` as globals for every top-level page.
PHPStan can't see across the `require __DIR__ . '/init.php';` boundary, so it
flags the first use as `variable.undefined`.

| File | Identifier | Count | Disposition |
|---|---|---|---|
| `export_dhcp.php` | `variable.undefined` (`$db`) | 1 (counts 3 uses) | KEEP — adding `global $db;` to every page is more noise than the baseline entry. Pattern is documented in `coding-guide.md`. |

If a new page lands in the baseline with the same `variable.undefined` shape,
that's expected — add it here, don't try to fix the page.

## Category 2 — PDO fetch() returns mixed (44 entries — health.php, FOLLOW-UP)

Every entry under `Simple-PHP-IPAM/health.php` is the same pattern:

```php
$r = $db->query("SELECT COUNT(*) AS c FROM …")?->fetch();
echo $r['c'];  // PHPStan: Cannot access offset 'c' on mixed
```

`PDOStatement::fetch()` returns `mixed` (in practice `array<string,mixed>|false`).
PHPStan correctly flags every subsequent `$r['key']` access.

These are **not masked bugs** in the "logic is wrong" sense — the queries return
exactly the columns being read. But they are **real type-safety gaps**: a query
that returns `false` (e.g. driver disconnect mid-scan) would fatal-error on the
offset access.

**Disposition:** Defer to follow-up #1203 — refactor `health.php` to wrap each
fetch in a typed helper (`array_or_empty(?->fetch())` or similar) and prune the
44 baseline entries en bloc. Doing it inline in v3.29.0 inflates scope; doing it
ad-hoc as health.php evolves loses the cluster discount.

## Category 3 — restore.php (6 entries — real masked bugs, FOLLOW-UP)

`restore.php` has six entries that ARE real masked bugs of varying severity:

| Identifier | Count | What it's masking |
|---|---|---|
| `cast.string` | 1 | `(string)` cast of a `realpath()` return that can be `string\|false` — false-cast yields `""` and silently corrupts the upload path |
| `argument.type` (`copy`) | 1 | `copy(string\|false, …)` — false breaks the call |
| `argument.type` (`basename`) | 3 | Same — `basename()` on a `false` argument silently returns `""` |
| `argument.type` (`proc_open` descriptor) | 2 | `proc_open` descriptor array contains `false` in the stdin file slot — handing `false` to `proc_open` is an undocumented foot-gun |
| `identical.alwaysTrue` | 1 | `$driver === 'pgsql'` inside a branch already gated on `$driver === 'pgsql'` — dead-code / refactor artefact |

**Disposition:** Defer to follow-up **#1204** — restore.php is a high-blast-radius
file (DB import path) and the right fix is per-bug, not a batch suppression. Each
of the six needs its own guard. Not safe to bundle into v3.29.0 without a
dedicated test pass against the dockerized harness.

## Operating rules going forward

1. **Never extend the baseline to silence a real bug.** `coding-guide.md §
   Static analysis tools` already says this; it remains true.
2. **When extracting/moving code, prune unmatched baseline entries.**
   PHPStan runs with `--report-on-unmatched-ignored-errors` (default in this
   project). Lessons-learned § 6.5 has the v3.28.1 #1177 worked example.
3. **When a new entry is added, classify it against Categories 1–3 above.**
   If it's Category 1 (init.php noise) it stays. If Category 2 or 3, open a
   follow-up issue and link the baseline entry to it.
4. **Re-audit when the baseline crosses 60 entries.** Periodic triage avoids
   the 403-line drift that triggered D7.
