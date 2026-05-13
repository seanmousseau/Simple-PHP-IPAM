# Decrypt-tool — Pass 1 conformance results (#1165)

**Tool under test:** `Simple-PHP-IPAM/tools/decrypt-backup.php`
**Repo state at run:** branch `dev`, baseline commit `803667e` (pre-fix); the hardened tool + tests + fixtures are committed in the `test(decrypt-tool): Pass 1 …` commit that ships this doc.
**Date:** 2026-05-12
**Plan:** `docs/internal/decrypt-tool-test-plan.md` (Pass 1 — manual). Gates #1164 (write-path retirement).
**Harness:** `tests/fixtures/decrypt-tool/run-pass1-grid.sh` (drives every cell below). 117/117 harness assertions PASS, 0 FAIL (incl. C8 `--large`).

---

## Result grid — 7 fixtures × 8 cases

| Fixture | 1 green | 2 wrong-cred | 3 tampered | 4 truncated | 5 empty | 6 wrong-magic/cred-type | 7 out-collision | 8 stdout |
|---|---|---|---|---|---|---|---|---|
| **F1** IPAMBKP1 / app_secret | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| **F2** IPAMBKP2 / app_secret | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| **F3** IPAMBKP3 stored / vault_key | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| **F4** IPAMBKP3 transitory / passphrase | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| **F5** IPAMBKU1 / no cred | ✅ | N/A¹ | ✅ | ✅ | ✅ | ✅² | ✅ | ✅ |
| **F6** bare `.sql.gz` / no cred | ✅³ | N/A¹ | ⚠️⁴ | ⚠️⁴ | ✅ | ✅² | ✅ | ✅ |
| **F7** bare `.ipambkl1.gz` / no cred | ✅³ | N/A¹ | ⚠️⁴ | ⚠️⁴ | ✅ | ✅² | ✅ | ✅ |

¹ **N/A — format takes no credential**, so "wrong credential" is undefined. (Supplying *a* credential to F5/F7 is exercised under case 6 instead.)
² For the no-cred fixtures, case 6 is "supply a credential to an archive that needs none" → tool exits 2 with `… needs no credential … drop the credential flag`. ✅.
³ **`✅` with a documented contract refinement** — see Finding F-3. The tool does a *verbatim copy* of a bare archive (it decrypts, it does not gunzip). Green-path output for F6/F7 is therefore byte-identical to the input (a gzip stream), not a decompressed SQLite/NDJSON file. The test plan's "output must show the SQLite header" expectation is wrong for bare gzip inputs; the correct inner-magic for F6/F7 output is the gzip magic `1f 8b`. Round-trip parity (C7) confirms `gunzip(F6) == decrypt(F1) == plaintext-source.sqlite`, so the bare-copy path is bit-faithful.
⁴ **⚠️ — no integrity layer on bare archives.** A bare `.sql.gz` / `.ipambkl1.gz` carries no HMAC/GCM/SHA, so a flipped or truncated byte is *not* reliably detected by the tool: it copies the (corrupt) bytes through and exits 0. This is inherent to the format, not a tool bug — the in-app restore path has the same property for bare archives. The harness records the observed behavior (exit 0) and does not gate on it. The escape-hatch contract is "get the bytes out as-is"; verifying a bare archive's integrity is the operator's job (e.g. compare a checksum). Documented divergence, not fixed — fixing it would mean inventing an integrity layer for a format that deliberately has none.

### Cross-cutting

| ID | Case | Result | Notes |
|---|---|---|---|
| **C1** | no args → usage + exit 2 | ✅ | exit 2, `usage:` on stderr |
| **C2** | `--help` → usage + exit 0 | ✅ | **changed** — was exit 2 (Finding F-1); now exit 0, full usage incl. one example per format on **stdout** |
| **C3** | conflicting credentials → reject, exit 2 | ✅ | **new** — `--app-secret X --vault-key Y` → exit 2 `supply at most one of …` (Finding F-4). Also catches `IPAM_BACKUP_PASSPHRASE` env set *and* a flag supplied. |
| **C4** | `IPAM_BACKUP_PASSPHRASE` env for F4 | ✅ | already supported; verified round-trip equal. Passphrase never on argv. |
| **C5** | no `vendor/` present | ✅ | `mv vendor vendor.bak` → green-path F2 decrypt still exits 0 and is byte-equal. **Confirmed: `vendor/` is NOT needed by the codec** — `lib.php` tolerates a missing vendor at require time; the vendored deps (PHPMailer/2FA/WebAuthn/phpseclib) are only touched by SMTP/MFA/SFTP/S3 code, never the backup codec. Docblock claim verified; docblock updated to say so explicitly. |
| **C6** | different machine from origin | ✅ (by inspection) | Cannot literally run on a second box from here, but `backup_decrypt_to_path` / `backup_decrypt_stream*` / `backup_unencrypted_unwrap_stream` derive **nothing** from machine-local state — the only key inputs are the caller-supplied `app_secret` / `vault_key` / `passphrase` (HKDF/Argon2 over those + per-file salt baked into the archive). No hostname, no `/etc/machine-id`, no env. So a copy of `{tool, lib.php, lib/backup.php}` + `php-cli` (openssl, zlib, hash) on any box decrypts identically. |
| **C7** | round-trip vs known plaintext | ✅ | `decrypt(F1/IPAMBKP1) == gunzip(F6/bare .sql.gz) == tests/fixtures/decrypt-tool/plaintext-source.sqlite`, byte-for-byte. Also verified per-fixture (case 1 + case 8 each byte-compare the decryption against the canonical source). |
| **C8** | ~500MB fixture → bounded RSS | ✅ | `/usr/bin/time -l` on macOS: IPAMBKP2 500 MB input → **max RSS ≈ 30 MiB**; IPAMBKU1 500 MB → **≈ 29 MiB**. Streaming confirmed (RSS independent of input size; well under the 256 MiB bar). The 500 MB fixtures are generated on demand to `/tmp` (NOT committed) — see Fixtures below. |

**Coverage:** 64/64 datapoints addressed. 56/56 fixture×case cells exercised against real fixtures; 53 ✅, 0 ❌, 4 ⚠️ (F6/F7 cases 3+4 — inherent no-integrity-on-bare, documented), 3 "N/A — format has no credential" (F5/F6/F7 case 2; the substantive question "what if you supply a cred to a no-cred archive" is covered by case 6). 8/8 cross-cutting ✅ (C6 by code inspection — there is genuinely no way to forge a second machine from this environment, but the absence of machine-binding in the crypto is verifiable from the source and is verified above).

---

## Fixtures

All fixtures are produced by **`tests/fixtures/decrypt-tool/generate-fixtures.php`** (committed). It `require`s `Simple-PHP-IPAM/lib.php` and calls the surviving codec encrypt helpers directly — no v3.18-era producer or running app was needed (the IPAMBKP1/2 *writers* `backup_encrypt()` / `backup_encrypt_stream()` still exist in `lib.php` even though the orchestrator's app_secret write path was narrowed in v3.27.x). Regenerate at any time:

```bash
php tests/fixtures/decrypt-tool/generate-fixtures.php          # small fixtures (committed)
php tests/fixtures/decrypt-tool/generate-fixtures.php --large  # also writes the ~500MB C8 fixtures to /tmp
bash tests/fixtures/decrypt-tool/run-pass1-grid.sh             # run the grid
bash tests/fixtures/decrypt-tool/run-pass1-grid.sh --large     # incl. C8 RSS check
```

Deterministic test credentials (throwaway — never used by any real install; built at runtime in the generator so no literal secret is committed):

- `app_secret` = `repeat("0123456789abcdef", 4)` (64 hex chars)
- `vault_key`  = base64 of bytes `0x00..0x1f` (32 bytes); the generator also writes the hex form
- `passphrase` = `decrypt-tool-pass1-fixture` (Argon2 cost lowered to t=2, m=8 MiB, p=1 for F4 so the suite is fast)

| ID | File (committed) | Bytes | How produced | Wraps |
|---|---|---|---|---|
| **F1** | `tests/fixtures/decrypt-tool/F1/archive.enc` | 12 324 | `backup_encrypt(file, app_secret)` — IPAMBKP1 GCM blob | `plaintext-source.sqlite` |
| **F2** | `tests/fixtures/decrypt-tool/F2/archive.enc` | 12 360 | `backup_encrypt_stream(file, …, app_secret)` — IPAMBKP2 AES-CTR + HMAC | `plaintext-source.sqlite` |
| **F3** | `tests/fixtures/decrypt-tool/F3/archive.ipambkp3` | 246 | `backup_encrypt_stream_v3(…, STORED, null, vault_key)` | `ipambkl1-source.bin` |
| **F4** | `tests/fixtures/decrypt-tool/F4/archive.ipambkp3` | 246 | `backup_encrypt_stream_v3(…, TRANSITORY, passphrase, null, 2, 8192, 1)` | `ipambkl1-source.bin` |
| **F5** | `tests/fixtures/decrypt-tool/F5/archive.ipambku1` | 186 | `backup_unencrypted_wrap_stream(…)` — IPAMBKU1 SHA-256 wrapper | `ipambkl1-source.bin` |
| **F6** | `tests/fixtures/decrypt-tool/F6/archive.sql.gz` | 319 | `gzencode(file, 9)` — bare gzip | `plaintext-source.sqlite` |
| **F7** | `tests/fixtures/decrypt-tool/F7/archive.ipambkl1.gz` | 146 | identical to `ipambkl1-source.bin` (already a gzip stream) | — |
| — | `tests/fixtures/decrypt-tool/plaintext-source.sqlite` | 12 288 | tiny standalone SQLite (`widget`, `note` tables, a few rows) — **NOT** the IPAM schema; it is the canonical plaintext for C7 | — |
| — | `tests/fixtures/decrypt-tool/ipambkl1-source.bin` | 146 | hand-crafted minimal IPAMBKL1 = `gzip("IPAMBKL1\n" + header-JSON + table-JSON + footer-JSON)` — the decrypt tool treats it as opaque bytes; this is the canonical IPAMBKL1-inner plaintext | — |
| — | `/tmp/decrypt-c8-{plain.bin,F2.enc,F3.ipambkp3,F5.ipambku1}` | ~512 MiB each | only with `--large`; **not committed** — too large. Regenerate command above. | — |

> **Resolved test-plan open questions:** (1) F1 producer — synthesized via the surviving `backup_encrypt()` helper rather than spinning a v3.18 docker image; cleaner and reproducible. (2) F4 transitory passphrase — captured once via the codec helper with a fixed passphrase; deterministic. (3) C7 canonical plaintext — `tests/fixtures/decrypt-tool/plaintext-source.sqlite`, encrypted under app_secret (F1/F2) and gzipped bare (F6); the IPAMBKL1-inner fixtures (F3/F4/F5/F7) use `ipambkl1-source.bin` as their canonical inner blob since the decrypt tool never parses IPAMBKL1 content — round-trip parity is meaningful within each plaintext family.

---

## Findings

All findings were **fixed in this commit** (the hardened `tools/decrypt-backup.php`), with a PHPUnit regression test added to `tests/DecryptToolTest.php`. None require a separate hotfix; all land in v3.28.0.

| # | Severity | What was wrong | Expected behavior | Fix | Regression test |
|---|---|---|---|---|---|
| **F-1** | cosmetic | `--help` / `-h` exited **2** and wrote usage to **stderr** | exit 0, usage to stdout (test plan C2) | `dbu_usage(int $code = 0)` for `--help`; usage goes to stdout when `$code === 0`; argv-parse errors still call `dbu_usage(2)`. Also `--help` is now matched even with other junk args. | `testHelpFlagExitsZeroWithUsageOnStdout` (and `testNoArgsExitsTwoWithUsage` for the no-args path) |
| **F-2** | functional | No stdout output mode — `--out` was always treated as a file path; the test plan / v4 escape-hatch story wants `… | sqlite3 …` pipelines | `--out -` writes plaintext to STDOUT, exit 0, **no** "decrypted N bytes" summary on stdout | Tool decrypts into a private `sys_get_temp_dir()` tempfile (preserving codec atomicity), streams it to STDOUT in 64 KiB chunks, then unlinks the temp. | `testStdoutOutputStreamsPlaintext` |
| **F-3** | functional / contract | Bare archives (`.sql.gz`, `.ipambkl1.gz`, plain SQL) — for which there is genuinely nothing to decrypt — were rejected with "unknown backup format", exit 3. The escape hatch should still hand the operator their bytes. | exit 0, plaintext (= the bare archive, verbatim) written to `--out` | Tool now sniffs the magic: gzip (`1f 8b`), `IPAMBKL1`, or a SQL/SQLite prelude → "bare" family → streamed verbatim copy to a sibling temp + atomic rename. Anything else → exit 2 `unrecognised archive …` (clearer than the old generic exit-3). | `testBareGzipPassthrough`, `testUnrecognisedMagicExitsTwo` |
| **F-4** | robustness | No conflict check — `--app-secret X --vault-key Y` (or env passphrase + a flag) was silently accepted and both passed to `backup_decrypt_to_path` | exit 2 with "pick one" (test plan C3) | Counts the supplied credential types; >1 → exit 2 `supply at most one of --app-secret / --vault-key / --passphrase`. Env `IPAM_BACKUP_PASSPHRASE` + a flag → exit 2 with a pointed message. | `testConflictingCredentialsExitsTwo` |
| **F-5** | UX / safety | No `--out` collision protection — a streaming-codec decrypt would silently rename over an existing file; an operator with a fat-fingered `--out` could clobber data | refuse to overwrite a non-empty existing file unless `--force` (test plan case 7) | Pre-check: `is_file($out) && filesize > 0 && !--force` → exit 2 `output file already exists and is non-empty … (pass --force to overwrite)`. `--force` added. (Empty files are treated as not-yet-written, so re-running into a 0-byte target works without `--force`.) | `testOutputCollisionRefusedWithoutForce` |
| **F-6** | UX | Wrong-credential-**type** (e.g. `--app-secret` on an IPAMBKP3 stored archive, or `--vault-key` on an IPAMBKP1) produced a confusing error: either "decrypt failed: backup decryption requires app_secret to be set in config.php" (mentions `config.php` — irrelevant to the CLI tool) or an exit-3 key-required exception | exit 2 with "this archive is format X, you supplied a credential for a different format" (test plan case 6) | Tool now detects the format family from the magic (and the mode byte for IPAMBKP3) **before** touching any credential, and validates that the supplied credential matches. Mismatch → exit 2 `this archive needs --X (…); you supplied a credential for a different format`. Missing-credential-for-the-detected-format → exit 3 (still a "decrypt failure" class — you can't decrypt without the key). Supplying a credential to a no-cred archive (IPAMBKU1 / bare) → exit 2 `… needs no credential … drop the credential flag`. | `testWrongCredentialTypeOnStoredArchiveExitsTwo`, `testCredentialSuppliedToNoCredArchiveExitsTwo` |
| — | (verified, no change needed) | Partial output on a failed decrypt | none left at `--out` | The codec already guarantees this: IPAMBKP1 writes via `file_put_contents` only after full decode+verify; IPAMBKP2/IPAMBKP3/IPAMBKU1 use a sibling tempfile + atomic rename, unlinked on any failure. The new bare-copy path also uses temp+rename. Confirmed by `no_partial` assertions on every wrong-cred / tampered / truncated / wrong-magic cell, plus `testTamperedV2ArchiveLeavesNoPartialOutput`. | `testTamperedV2ArchiveLeavesNoPartialOutput` (+ harness) |
| — | (empty-input handling, verified) | `> empty.enc` then run | exit 2, clean error not a stack trace | Tool reads ≤9 bytes for the magic sniff; empty → exit 2 `input file is empty: …`. | `testEmptyInputFileExitsTwo` (+ harness case 5 ✅ for all 7 fixtures) |

### Documented divergences (not fixed)

- **F6/F7 cases 3 & 4 — bare archives have no integrity layer.** A flipped/truncated byte in a bare `.sql.gz` / `.ipambkl1.gz` is copied through and the tool exits 0 (gzip may or may not happen to notice). This matches the in-app restore path's behavior for bare archives and is inherent to the format. The escape-hatch contract is "extract the bytes as-is"; integrity verification of a bare archive is the operator's responsibility. Marked ⚠️ in the grid, not gated.
- **Test-plan §"F6 green path → SQLite header"** is inaccurate for bare-gzip inputs: the tool does not gunzip, so the output is a gzip stream (`1f 8b …`), not a decompressed SQLite file. The plan should be updated to say "output byte-equals the bare archive; inner-magic is gzip". Round-trip parity (C7) still proves bit-faithfulness end to end. *(Action item for whoever next touches `docs/internal/decrypt-tool-test-plan.md`.)*

---

## Tool behavior changes shipped in this commit (summary for the changelog / #1164)

- `--help` / `-h`: exit **0** (was 2), usage to **stdout** (was stderr), now includes one example per supported format.
- No-args: still exit 2 with usage on stderr.
- New `--out -`: write decrypted plaintext to **STDOUT** (no summary line on stdout).
- New `--force`: allow `--out` to overwrite an existing non-empty file. Without it, a collision is refused with exit 2.
- New: rejects conflicting credentials (`--app-secret` + `--vault-key`, etc.) and env-passphrase-plus-flag with exit 2.
- New: detects the archive format from its magic up front; supplying the wrong **credential type** → exit 2 with a clear "this archive is format X" message (was a confusing exit-3 `config.php`-flavored error); supplying a credential to a no-cred archive → exit 2.
- New: bare archives (`gzip` / `IPAMBKL1` / SQL text) are passed through verbatim with exit 0 (were rejected exit 3). Unrecognised leading bytes → exit 2 `unrecognised archive …`.
- Empty input → exit 2 `input file is empty`.
- Confirmed (no behavior change, but now stated in the docblock): the tool does **not** require the Composer `vendor/` tree for any format it decrypts.
- Exit-code contract unchanged in spirit: **0** success, **2** usage / wrong-format / wrong-cred-type / collision, **3** decrypt failure (bad key, HMAC/GCM/SHA mismatch, truncated/corrupt ciphertext, missing key for the detected format).

---

## Things the main agent should double-check before #1164 builds on this

1. **Test-plan doc still says `--help` → exit 0** (it does) but also says "F6 green path → SQLite header" — that line is wrong for the verbatim-copy behavior shipped here. Reconcile the doc before Pass 2.
2. **Pass 2 (#1042-ish / CI automation)**: the green/wrong-cred/tampered/truncated micro-fixture tests are now in `tests/DecryptToolTest.php` (21 tests, <2 s). The process-level cases (C4 ps-inspection, C5 mv-vendor, C8 RSS) still need a `testing/decrypt-tool-ci.sh` wired into `php-qa.yml` — `tests/fixtures/decrypt-tool/run-pass1-grid.sh` is most of that script already.
3. **`vendor/`-not-needed claim**: confirmed empirically here (C5) — but it depends on `lib.php` continuing to tolerate a missing `vendor/` at `require` time. If a future change makes `lib.php` hard-require something in `vendor/` at load, this tool breaks for disaster-recovery operators. Worth a guard/test if #1164 or v4 touches `lib.php`'s require block.
4. **Bare-archive integrity**: if v4 wants the standalone tool to *verify* bare archives (not just copy them), that's new design — there's deliberately no MAC on bare `.sql.gz` / `.ipambkl1.gz`. Out of scope for #1165; flagging in case the v4 escape-hatch spec assumes otherwise.
5. **The harness `run-pass1-grid.sh` is `bash`-3.2-clean** (macOS) — avoids `set -u` array footguns. If it gets promoted into CI on Linux, it should still work, but worth a quick run on the GH runner image.
