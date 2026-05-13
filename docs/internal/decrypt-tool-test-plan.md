# Decrypt-tool test plan — `tools/decrypt-backup.php`

> **Status:** authoritative for the v4.0.0 cold-break plan. The standalone decrypt tool becomes load-bearing in v4.0.0 because the in-app writer + reader no longer support IPAMBKP1/IPAMBKP2/SQLite-binary/etc. — this is the only legal escape hatch for legacy archives. Before any of that lands, the tool needs to be exercised against every variant that exists in the wild.
>
> **Authored:** 2026-05-10, post-v3.27.7 deploy, during the chat-style architecture review with Sean.
>
> **Companion docs:** `roadmap.md` §3.5 (v3.27.8 + v3.28.x + v4.0.0 sequencing), `release-workflow.md`, `lessons-learned.md`. The tool's source-of-truth contract lives in `Simple-PHP-IPAM/tools/decrypt-backup.php` docblock — re-read it before changing this plan.

---

## Why the tool is load-bearing now

Before v4.0.0:
- In-app Restore decodes every variant (IPAMBKP1, IPAMBKP2, IPAMBKP3 stored, IPAMBKP3 transitory, IPAMBKU1, bare `.sql.gz`, bare `.ipambkl1.gz`).
- The standalone tool is a convenience — useful when the original install is gone but not the only path.

After v4.0.0:
- In-app writer emits only IPAMBKL1 inside IPAMBKP3 (logical + stored vault key) or unencrypted IPAMBKL1 if the destination is `unencrypted`.
- In-app reader accepts only the same two formats. **IPAMBKP1 / IPAMBKP2 / SQLite-binary dumps / bare `.sql.gz` reading is removed.**
- The standalone tool is the *only* in-product way to recover from a legacy archive — either to decrypt to plaintext for inspection / re-import, or to feed `tools/import-legacy-backup.php` (v4 path, TBD).

This plan validates the tool against every variant before we make the in-app readers go away.

---

## Pre-execution checklist

1. **Pull a copy of the tool from `main` at v3.27.7** (this is the contract we ship today; future fixes hold against this baseline):
   ```bash
   git show v3.27.7:Simple-PHP-IPAM/tools/decrypt-backup.php > /tmp/decrypt-backup-v3.27.7.php
   ```
2. **Spin up disposable PHP-only environments** so we can prove the tool works without an IPAM install:
   - Docker `php:8.2-cli` + `php:8.3-cli` images (no DB, no extensions beyond what the tool requires)
   - A bare host with just `apt install php-cli openssl` if you want to test the "operator after disaster" scenario
3. **Verify required PHP extensions** the tool declares (openssl, zlib, hash, mbstring? Confirm from the file). Test the failure path: PHP without one of them → tool exits with a clear error.
4. **Confirm `vendor/` requirement.** The docblock claims "minimal-dep: requires only the parts of …" — verify exactly which lib.php functions it pulls and whether `vendor/` is needed at all. Document the answer.
5. **Capture the test instance vault key + app_secret** somewhere safe before any fixture work — we'll need them as known-good credentials.

---

## Fixture matrix (Pass 1 produces these once, reuses across all cases)

Each fixture is a real backup archive produced by the noted version of IPAM, with a known credential, captured to `tests/fixtures/decrypt-tool/<fixture-id>/`.

| ID | Magic | Inner | Credential type | Producer install version | Sample file extension |
|---|---|---|---|---|---|
| **F1** | IPAMBKP1 | SQLite binary dump | `app_secret` (hex) | v3.18.x (or earlier — pre-streaming) | `.enc` |
| **F2** | IPAMBKP2 | SQLite binary dump | `app_secret` (hex) | v3.19.x – v3.25.x (streaming) | `.enc` |
| **F3** | IPAMBKP3 stored | IPAMBKL1 | `vault_key` (b64) | v3.26.0+ with vault key configured | `.ipambkp3` or `.enc` (depending on era) |
| **F4** | IPAMBKP3 transitory | IPAMBKL1 | `passphrase` (string) | v3.26.0+ one-shot encrypted export | `.ipambkp3` |
| **F5** | IPAMBKU1 | IPAMBKL1 | none (integrity-only) | v3.23.0+ logical unencrypted | `.ipambku1` or `.ipambkl1` |
| **F6** | (no envelope) | SQLite binary dump | none | v3.18.x or earlier plain-export | `.sql.gz` |
| **F7** | (no envelope) | IPAMBKL1 | none | v3.23.0+ unencrypted logical | `.ipambkl1.gz` |

**Total fixtures:** 7. Each generates one ~100KB archive minimum; F6/F7 also need a ~500MB variant for the OOM test (C8 below).

**Fixture storage location:** `tests/fixtures/decrypt-tool/<id>/<filename>` checked into the repo if size permits, or to a separate side-loaded repository if not. The credential for each fixture goes in `tests/fixtures/decrypt-tool/<id>/credential.txt` (chmod 600 + `.gitignore`'d via a per-directory rule, with the *file shape* checked in via a `credential.txt.example` so the layout is reproducible).

---

## Per-fixture case set (Pass 1 — manual)

For each fixture F1–F7, run **all 8 cases** below. Capture the result for each `<fixture> × <case>` cell in a results table.

| # | Case | What it tests | Expected outcome |
|---|---|---|---|
| **1** | **Green path** | Correct credential, fresh output target | Exit 0; output file matches expected size + inner format's magic (e.g. SQLite header `SQLite format 3\0`, or IPAMBKL1 magic) |
| **2** | **Wrong credential** | Same format, deliberately wrong app_secret / vault key / passphrase | Exit 3; stderr says "auth failed" / "HMAC mismatch"; no partial output left on disk |
| **3** | **Tampered ciphertext** | Flip 1 byte in the middle of the encrypted body (`printf '\x42' \| dd of=file.enc bs=1 seek=1024 count=1 conv=notrunc`) | Exit 3; stderr says "auth failed" / "GCM tag mismatch"; no partial output |
| **4** | **Truncated file** | `truncate -s -100 file.enc` to remove tail | Exit 3; stderr says "truncated" or "auth failed" depending on which byte is missing; no partial output |
| **5** | **Empty file** | `> empty.enc; tool empty.enc …` | Exit 2; stderr says "empty input" or "bad magic"; clean error not a stack trace |
| **6** | **Wrong-magic + wrong-credential type** | IPAMBKP3 file with `--app-secret` flag, or IPAMBKP1 file with `--vault-key` flag | Exit 2; stderr says "this archive is format X, you provided credential for format Y" (or close) |
| **7** | **`--output` collision** | Run with `--output existing.bin` where `existing.bin` already has bytes | Default: exit 2, refuses; with `--force` (or whatever flag exists): exit 0, overwrites |
| **8** | **Stdout output** | No `--output` flag at all | Output written to stdout; downstream `file -` or magic-byte check confirms inner format; exit 0 |

**Total per-fixture-case datapoints: 7 fixtures × 8 cases = 56.**

---

## Cross-cutting cases (Pass 1 — manual, not per-fixture)

| ID | Case | What it tests |
|---|---|---|
| **C1** | Tool with no args | Usage help printed; exit 2 |
| **C2** | Tool with `--help` | Full usage including one example per supported format; exit 0 |
| **C3** | Multiple conflicting credentials | E.g. `--app-secret X --vault-key Y` → reject with "pick one", exit 2 |
| **C4** | `IPAM_BACKUP_PASSPHRASE` env var for F4 | Passphrase NOT on command line; tool reads env; passphrase doesn't appear in `ps auxe` output during run |
| **C5** | No `vendor/` directory present | Tool either works (matches docblock's minimal-dep claim) OR fails with a clear "composer install --no-dev required" error |
| **C6** | Different machine from origin | Copy F1–F7 + the tool to a clean Linux box with no IPAM install, just `php-cli` + the required extensions. Run F1–F7 green path. All must succeed. |
| **C7** | Round-trip vs known plaintext | For F6 (bare `.sql.gz`), the inner format is *known plaintext* (gunzip → SQLite file). Decrypt F1 (IPAMBKP1 wrapping the same content) and verify byte-equal to the F6 decryption output. Proves the tool's output is bit-faithful, not just "looks correct." |
| **C8** | Large fixture (~500MB compressed) | Memory usage stays bounded (streaming, not load-entire-file). Use `/usr/bin/time -v` to capture max RSS — must stay under e.g. 256MB regardless of input size. |

**Total cross-cutting datapoints: 8.**

---

## Pass 1 deliverable

A single markdown file `releases/decrypt-tool-test-passN/results.md` (date-stamped) with a 7 × 8 + 1 × 8 results grid:

```
| Fixture | C1 green | C2 wrong-cred | C3 tampered | C4 truncated | C5 empty | C6 wrong-magic | C7 output-collision | C8 stdout |
|---|---|---|---|---|---|---|---|---|
| F1 | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| F2 | ✅ | ✅ | ⚠️ note | ✅ | ✅ | ✅ | ✅ | ✅ |
| F3 | ❌ FAIL | … |
…
| Cross-cutting | C1 | C2 | C3 | C4 | C5 | C6 | C7 | C8 |
|---|---|---|---|---|---|---|---|---|
| Result | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
```

Plus a "findings" section under the grid for every ⚠️ / ❌ cell, with: what went wrong, what the expected behavior is, and which release should fix it (v3.27.8 hotfix for blockers, v3.28.x for tool hardening, v4.0.0 if it's a "this only matters once in-app reader retires" issue).

---

## Pass 2 (automation, only after Pass 1 is fully green)

The green-path + wrong-cred + tampered + truncated cases (4 per fixture × 7 fixtures = 28) become PHPUnit tests against checked-in micro-fixtures. Each fixture is shrunk to <1KB by encrypting a known 50-byte plaintext rather than a real DB — proves the codec without bloating the repo.

The remaining cases (empty / wrong-magic / output-collision / stdout / cross-cutting C5–C8) go into a CI shell script (`testing/decrypt-tool-ci.sh`) invoked from `.github/workflows/php-qa.yml` because they need process-level visibility PHPUnit can't give:

- C4 needs `ps auxe` inspection mid-run
- C5 needs a temporary mv-`vendor` setup
- C6 needs a container without IPAM
- C8 needs `/usr/bin/time -v` RSS reporting

Each CI check runs in <60 seconds total. The micro-fixture PHPUnit set runs in <2 seconds.

---

## Slotting

| Slot | Work |
|---|---|
| **v3.27.8** (immediate hotfix) | Today's diagnosis bugs A+B+C+D; drop silent plaintext fallback; **no decrypt-tool work** |
| **v3.28.x** (legacy writer retirement) | Pass 1 manual execution of this plan. Capture findings. Fix any blockers in the same release. Document the tool prominently in `docs/upgrading.md` as the v4.0.0 escape hatch. |
| **v3.28.x or v3.29.x** | Pass 2 automation lands in CI |
| **v4.0.0** | Pass 1 + Pass 2 must be 100% green before the writer-retirement PR opens. Failing tests = blocker, no exceptions. |

---

## Open questions to resolve before Pass 1 starts

1. **F1 producer:** does the test instance have any v3.18-era backups we can capture as a real-world fixture, or do we synthesize from a v3.18 docker image? Synthesizing is cleaner; real-world is more honest.
2. **F4 transitory passphrase:** the manual passphrase-export path is in the UI — does it have a CLI / API equivalent we can drive deterministically, or do we capture one once and reuse?
3. **C7 round-trip plaintext source:** what's the canonical plaintext? A specific SQLite fixture committed to `tests/fixtures/decrypt-tool/plaintext-source.sqlite` that we encrypt under every credential type to produce F1/F2/F3/F4/F5? That makes the round-trip check meaningful and gives Pass 2 a reproducible source.

Settle these before generating fixtures or we'll throw away some of the work.
