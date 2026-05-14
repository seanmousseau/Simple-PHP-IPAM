# Backup format matrix

> Every backup archive format Simple-PHP-IPAM has ever produced — what it is, when it appeared, what it wraps, what credential reads it, and whether it's still produced / still readable. Companion to `docs/internal/ipambkl1-format.md` (the logical-inner format spec), `docs/internal/archive/decrypt-tool-test-plan.md` (the `tools/decrypt-backup.php` conformance plan), and `docs/upgrading.md §v3.28.0` (the `app_secret`-encryption retirement path).
>
> **Why this exists:** the v4.0.0 cold break removes the in-app reader for the legacy formats, so `tools/decrypt-backup.php` becomes the only recovery path for anything pre-v4. Operators need a clear picture of what they have. There is also a local fixture library of one real, large-DB archive per format — see `tests/fixtures/backup-library/` (git-ignored; regenerate with the `generate-backup-library.php` in that directory; credentials in `~/.claude/dev-secrets.env` under `IPAM_BACKUP_LIBRARY_*`).

## Quick matrix

| ID | Format | On-disk shape | Introduced | Inner payload | Credential to read | Extension(s) | Status (writer) | Status (in-app reader) |
|----|--------|---------------|-----------|---------------|--------------------|--------------|-----------------|------------------------|
| **L0** | **Legacy local SQLite backup** | raw SQLite DB file, copied verbatim (no magic, no compression, no encryption) | v3.7.0 (`3.7.0-backup-history`) | the live `data/ipam.sqlite` file | none | `.sqlite` | **retired v3.26.0** (`3.26.0-retire-legacy-backup` / #1059 — `run_db_backup_if_due()` removed) | n/a — never had an in-app "restore from local backup" UI; `tools/decrypt-backup.php` treats it as a bare file (verbatim copy) |
| **B-SQL** | **Bare SQL dump** (`database` type, unencrypted) | gzip stream → engine SQL `.dump` text | original / pre-v3.7 | gzipped `sqlite3 .dump` (SQLite) / `mysqldump` / `pg_dump` SQL text | none | `.sql.gz` | **still produced** when destination `backup_type = database` and `encryption = unencrypted` | reads via Restore wizard through v3.x; **dropped in v4.0.0** |
| **B-L1** | **Bare logical dump** (`logical` type, unencrypted) | gzip stream → `IPAMBKL1` NDJSON | v3.23.0 (#824) | gzipped IPAMBKL1 (engine-agnostic NDJSON: magic line + header JSON + per-table lines + footer JSON) | none | `.ipambkl1.gz` | **still produced** — the v3.28.0 default for `unencrypted` destinations | reads via Restore wizard; **retained in v4.0.0** (IPAMBKL1 is the v4 native inner format) |
| **P1** | **IPAMBKP1** — encrypted v1 (`app_secret`, one-shot) | `IPAMBKP1`(8) ‖ IV(12) ‖ GCM-tag(16) ‖ ciphertext — AES-256-GCM, whole-file in memory | v3.17.0 (#690) | a Bare SQL dump (`.sql.gz`) | `app_secret` (64-hex, from `config.php`) | `.enc` | **writer retired v3.28.0** (#1164 — orchestrator no longer produces; codec helper `backup_encrypt()` still exists) | reads via Restore wizard and `tools/decrypt-backup.php --app-secret` through v3.x; **dropped from the in-app reader in v4.0.0** — `decrypt-backup.php` only |
| **P2** | **IPAMBKP2** — encrypted v2 (`app_secret`, streaming) | `IPAMBKP2`(8) ‖ HKDF-salt(16) ‖ CTR-IV(16) ‖ chunked AES-256-CTR ciphertext ‖ HMAC-SHA256(32) | v3.19.x (streaming-backup work, #860-era) | a Bare SQL dump (`.sql.gz`) | `app_secret` (64-hex) | `.enc` | **writer retired v3.28.0** (#1164 — codec helper `backup_encrypt_stream()` still exists) | same as P1 — readable through v3.x, `decrypt-backup.php` only after v4.0.0 |
| **P3-S** | **IPAMBKP3 stored** — encrypted v3 (`backup_vault_key`) | `IPAMBKP3`(8) ‖ 68-byte header (mode byte = `1`, salt/IV, reserved) ‖ chunked streamed ciphertext | v3.24.0 (#836 / #1043) | an `IPAMBKL1` logical dump (or a Bare SQL dump if `backup_type = database`) | `backup_vault_key` — 32 raw bytes, base64 (`44` chars); stored in the DB wrapped under `bootstrap_key` (libsodium `secretbox`, `IPAMWK1.` envelope) | `.ipambkp3` (older era: `.enc`) | **current** — the recommended encrypted format | reads via Restore wizard and `tools/decrypt-backup.php --vault-key`; **retained in v4.0.0** |
| **P3-T** | **IPAMBKP3 transitory** — encrypted v3 (passphrase) | `IPAMBKP3`(8) ‖ 68-byte header (mode byte = `2`, Argon2id salt(16): time=3 / mem=64 MiB / par=1, …) ‖ streamed ciphertext | v3.24.0 (#836 / #1043) | an `IPAMBKL1` logical dump | passphrase (Argon2id-derived key); supplied at export time, never stored. `decrypt-backup.php --passphrase` or `$IPAM_BACKUP_PASSPHRASE` | `.ipambkp3` | **current** — one-shot manual encrypted export | reads via Restore wizard (paste passphrase) and `decrypt-backup.php`; **retained in v4.0.0** |
| **U1** | **IPAMBKU1** — unencrypted integrity wrapper | `IPAMBKU1`(8) ‖ SHA-256(32) of the plaintext ‖ plaintext(N) | v3.24.0 (#836) | an `IPAMBKL1` logical dump | none (integrity check only) | `.ipambku1` | **current** — `logical` + `unencrypted` with an integrity envelope (vs. the bare `.ipambkl1.gz` which has no envelope) | reads via Restore wizard and `decrypt-backup.php`; **retained in v4.0.0** |

Notes on the "two version axes":
- **Envelope magic** (`IPAMBKP1` → `IPAMBKP2` → `IPAMBKP3`, and `IPAMBKU1`) — the on-the-wire encryption/wrapper format. P1 (GCM, in-memory) → P2 (CTR+HMAC, streaming, fixed P1's memory ceiling) → P3 (per-mode header: vault-key or passphrase; unifies stored & transitory). U1 is the unencrypted sibling of P3.
- **Inner magic** (`IPAMBKL1`, …) — the *logical* dump format (engine-agnostic NDJSON). Orthogonal to the envelope: an `IPAMBKL1` can be bare-gzipped (`.ipambkl1.gz`), wrapped in `IPAMBKU1`, or encrypted in `IPAMBKP3`. The other inner payload is a Bare SQL dump (`.sql.gz`), produced for the `database` backup type and wrapped by `IPAMBKP1`/`IPAMBKP2` (and by `IPAMBKP3` when `backup_type = database`). The pre-v3.7 era and the L0 legacy backup predate IPAMBKL1 entirely.

## Credential-vs-format cheat sheet (for `tools/decrypt-backup.php`)

| Archive | Run |
|---|---|
| `*.enc` (IPAMBKP1 / IPAMBKP2) | `php tools/decrypt-backup.php --in x.enc --out plain.sql.gz --app-secret <64-hex>` → then `gunzip` → SQL dump |
| `*.ipambkp3` stored | `php tools/decrypt-backup.php --in x.ipambkp3 --out plain.ipambkl1 --vault-key <44-char-base64>` |
| `*.ipambkp3` transitory | `IPAM_BACKUP_PASSPHRASE=… php tools/decrypt-backup.php --in x.ipambkp3 --out plain.ipambkl1` (or `--passphrase`) |
| `*.ipambku1` | `php tools/decrypt-backup.php --in x.ipambku1 --out plain.ipambkl1` (no credential — integrity-checked, then copied) |
| `*.sql.gz` / `*.ipambkl1.gz` / `*.sqlite` (bare) | `php tools/decrypt-backup.php --in x.sql.gz --out copy.sql.gz` — no credential; the tool detects "no envelope" and copies verbatim (it does **not** gunzip — the output is the same bytes). For bare archives there's no integrity layer, so a corrupted file copies through silently (matches the in-app restore behaviour). |

Magic-byte reference (first bytes of the file): `IPAMBKP1` = `49 50 41 4d 42 4b 50 31`; `IPAMBKP2` = `…4b 50 32`; `IPAMBKP3` = `…4b 50 33`; `IPAMBKU1` = `49 50 41 4d 42 4b 55 31`; gzip = `1f 8b 08`; SQLite file = `53 51 4c 69 74 65 20 66 6f 72 6d 61 74 20 33 00` (`SQLite format 3\0`); IPAMBKL1 (after gunzip) = `49 50 41 4d 42 4b 4c 31` followed by `\n`.

## v4.0.0 cold break — what stops being readable in-app

After v4.0.0, the Restore wizard accepts only `IPAMBKL1`-inside-`IPAMBKP3` and unencrypted `IPAMBKL1` (`U1` / `.ipambkl1.gz`). It **no longer reads**: `P1`, `P2` (the `app_secret` formats), and any `IPAMBKP3` / Bare archive whose inner payload is a SQL dump rather than `IPAMBKL1`, plus the bare `.sql.gz` and the `L0` legacy `.sqlite`. The only in-product recovery path for those becomes `tools/decrypt-backup.php` (ships in the release tarball; runs without a DB/webserver) — see `docs/upgrading.md §v3.28.0` for the migration plan.

## Codec entry points (`Simple-PHP-IPAM/lib.php` unless noted)

| Format | Writer | Reader |
|---|---|---|
| P1 | `backup_encrypt(string $plain, string $appSecret): string` | `backup_decrypt(...)` / `backup_decrypt_to_path()` |
| P2 | `backup_encrypt_stream(string $src, string $dst, string $appSecret): void` | `backup_decrypt_stream(...)` / `backup_decrypt_to_path()` |
| P3-S / P3-T | `backup_encrypt_stream_v3(string $src, string $dst, int $mode, ?string $passphrase, ?string $vaultKey): void` (`$mode` = `BACKUP_V3_MODE_STORED` / `BACKUP_V3_MODE_TRANSITORY`) | `backup_decrypt_stream_v3(...)` / `backup_decrypt_to_path()` |
| U1 | `backup_unencrypted_wrap_stream(string $src, string $dst): void` | `backup_unencrypted_unwrap_stream(...)` / `backup_decrypt_to_path()` |
| B-SQL | `ipam_backup_dump_to_tmp(PDO $db): string` (returns a `.sql.gz` temp path) | the Restore SQL splitter (`ipam_restore_*`) |
| B-L1 | `ipam_backup_logical_dump(PDO $db, $fh): void` (→ IPAMBKL1 NDJSON; gzip externally) | `ipam_restore_logical_*` (the IPAMBKL1 replayer) |
| L0 | (retired) `run_db_backup_if_due()` — copied `data/ipam.sqlite` to `data/backups/ipam-<date>.sqlite` | (none — `tools/decrypt-backup.php` treats it as a bare file) |
| any (auto-detect) | — | `tools/decrypt-backup.php` (CLI, no DB; detects envelope magic; one credential per format) and `backup_decrypt_to_path()` (in-app dispatcher) |

The orchestrator's encrypt-mode resolver — `ipam_backup_resolve_encrypt_to_tmp()` in `lib/backup.php` — picks the *writer* per the destination's `default_encryption_mode` (`unencrypted` → bare; `stored` → P3-S; `transitory` is the manual-export path, not scheduled). As of v3.28.0 (#1164) it has **no `app_secret` (P1/P2) write branch** — an encrypted scheduled backup with no `backup_vault_key` configured fails preflight with an actionable message rather than falling back to `app_secret`.
