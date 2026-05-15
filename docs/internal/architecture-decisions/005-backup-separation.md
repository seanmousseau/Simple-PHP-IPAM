# ADR-005: `backup.php` orchestrator / codec / dispatcher separation

**Status:** draft
**Decided:** —
**Scope:** prerequisite for the v4.0.0 backup cold break; informs the v3.32.0 `lib/backup_codec.php` + `lib/backup_run.php` extractions in ADR-004's domain layer.
**Stamped by:** —

---

## Context

The backup subsystem is split across two physical locations today:

| Location | Lines | Role | Notable functions |
|---|---|---|---|
| `Simple-PHP-IPAM/lib/backup.php` | ~3,300 | Orchestrator (claim, schedule, run, log, retention) + helpers | `ipam_backup_claim_due_schedule`, `ipam_backup_run_for_destination`, `ipam_backup_logical_dump`, `ipam_backup_state_*`, `ipam_backup_runs_purge` |
| `Simple-PHP-IPAM/lib.php` | ~1,200 inline backup-related | **Codec** (encrypt, decrypt, header pack/unpack) + a small chunk of orchestration helpers (`backup_run_dump`, mysql/pgsql password-file writers) | `backup_encrypt`, `backup_encrypt_stream`, `backup_decrypt_stream`, `backup_encrypt_stream_v3`, `backup_decrypt_stream_v3`, `ipam_backup_v3_pack_header`, `backup_unencrypted_*` |
| `Simple-PHP-IPAM/lib/BackupClientInterface.php` + `LocalBackupClient.php` + `S3Client.php` + `SftpClient.php` | ~500 each | **Dispatcher** — the only part of the subsystem that's already shape-correct: a real interface with three implementations |

So today there are **three roles**, but only two are file-separated:

```
ORCHESTRATOR (lib/backup.php) ─┬─> CODEC (lib.php — backup_encrypt_*, backup_decrypt_*) ──> bytes
                               └─> DISPATCHER (BackupClient impls)                       ──> remote
```

The orchestrator calls codec functions inline. The codec lives in `lib.php`. The dispatcher is the only role that's already a clean interface.

### What "the encrypt-write-path bug" was

Roadmap §10 quotes the rationale as:

> "backup.php orchestrator/codec/dispatcher separation — Enabled the encrypt-write-path bug."

The bug was the **v3.24.0 IPAMBKP3 stored-mode introduction**: the orchestrator created a tmp file, the codec wrote into it, the dispatcher copied it to the destination — but the orchestrator-codec coupling meant a partial write (e.g. disk-full mid-encrypt) left a corrupted tmp file that the dispatcher then happily shipped to S3, where it was indistinguishable from a complete backup until restore time. The fix at the time was inline (more aggressive size checks, `flock`-based atomicity guards), but the underlying tangling — codec functions called inline by the orchestrator with shared mutable state — survived.

Subsequent backup-related bugs (v3.26.0 vault-key handling, v3.27.4 retention pruning races, the v3.28.1 mode-detection in `restore.php`) share a common shape: a function in the orchestrator had to know something about codec internals to do its job, or vice versa. The boundary isn't policed.

### Why this matters for v4.0.0

v4.0.0 is the documented "backup cold break" — the release where the backup format is allowed to change incompatibly. Operators are expected to either:

1. Run a final v3.x backup with old format support.
2. Migrate via a documented "decrypt-with-old-codec, re-encrypt-with-new-codec" tool path.

That migration tool is implementable only if the codec is decoupled from the orchestrator — a tool that decrypts a v3.x backup file shouldn't have to spin up the full schedule-claim-run state machine just to read bytes. **ADR-005 is the design contract for that decoupling.** Whether implementation lands in v3.32.0 (preparatory) or v4.0.0 (cold break) is the staging question.

### Why this matters for v3.32.0

ADR-004 already declared `lib/backup_codec.php` + `lib/backup_run.php` as v3.32.0 domain-layer modules. The split is "lift the codec out of `lib.php`, consolidate the orchestrator helpers in `lib/backup.php`." ADR-005 makes that split structurally correct — i.e. defines the interface between the two so the v3.32.0 extraction lands a real boundary, not just two files that happen to share the same call patterns.

## Decision drivers

- **Operator-data safety.** The encrypt-write-path bug is the kind of incident that costs operators their backups in a recoverable-but-painful way. Future similar incidents are easier to prevent if the codec is a sealed module.
- **Migration tool feasibility.** The v3 → v4 codec migration tool described above is the practical test of "is the codec really decoupled." If you can build the tool without `require`-ing the orchestrator, the separation is real.
- **Preservation of three encrypt-mode coverage.** Three modes exist today (`'unencrypted'`, `'transitory'`, `'stored'`) plus three magic versions (`IPAMBKP1`, `IPAMBKP2`, `IPAMBKP3` + `IPAMBKU1` for the unencrypted wrapper). Whatever shape we lock in must accommodate all of them in v3.x (no codec-mode removal) and clearly stage which are dropped at v4.0.0.
- **Streaming discipline.** Codec calls are stream-based (`backup_encrypt_stream_v3` works on file handles, not in-memory blobs) for memory-bound reasons. Any interface needs to preserve streaming semantics.
- **Coupling to ADR-004.** `lib/backup_codec.php` + `lib/backup_run.php` are v3.32.0 modules. ADR-005 defines what each contains.

## Options considered

### Option A — Sealed-codec contract, v3.32.0 extraction, v4.0.0 evolution

**Mechanism:** Define a strict codec API surface. v3.32.0 implements it as a no-behaviour-change refactor: move codec functions into `lib/backup_codec.php`, move all orchestration-and-state into `lib/backup_run.php` (or keep in `lib/backup.php`). v4.0.0 evolves the codec to a new format (e.g. AES-256-GCM-SIV, or post-quantum-friendly KEM-wrapped DEKs, or whatever the v4.0.0 design eventually decides) while keeping the orchestrator unchanged.

**Codec API surface (proposed):**

```php
// lib/backup_codec.php — pure functions only, no DB access, no schedule state

// Mode/version detection from header bytes (used by restore.php and the migration tool)
function ipam_backup_codec_detect_format(string $headerBytes): array;
// → ['version' => 'IPAMBKP3', 'mode' => 'stored', 'argon_params' => [...], ...]

// Streaming encrypt (mode-aware)
function ipam_backup_codec_encrypt_stream(
    string $srcPath,
    string $dstPath,
    string $mode,            // 'unencrypted' | 'transitory' | 'stored'
    array  $keyMaterial,     // ['passphrase' => '...'] or ['raw_key' => '...']
    array  $params = []      // version, argon overrides, etc.
): void;

// Streaming decrypt (mode auto-detected from header)
function ipam_backup_codec_decrypt_stream(
    string $srcPath,
    string $dstPath,
    array  $keyMaterial      // operator-provided passphrase OR vault key from orchestrator
): array;
// → ['version' => 'IPAMBKP3', 'mode' => 'stored', 'plaintext_size' => N, ...]

// Header introspection (used by restore.php's mode-detection branch)
function ipam_backup_codec_inspect(string $srcPath): array;
```

**Pros:**
- The v3 → v4 migration tool is implementable as `decrypt_stream(v3 file) | encrypt_stream(v4 file)` — pure composition.
- The codec is testable in isolation. PHPUnit unit tests against fixture files without needing a `settings` table.
- Bug-prevention payoff is structural: the codec can't accidentally read schedule state because it has no DB handle.
- Compatible with the existing 3-mode / 3-magic surface — the API is parametric over them.
- v4.0.0's new codec mode plugs in by adding a new `case` in the dispatch inside `ipam_backup_codec_encrypt_stream` + adding new header constants.

**Cons:**
- Real refactoring work in v3.32.0 — the orchestrator currently passes mutable state into codec helpers via shared globals + sometimes via passed-array args; converting to the API surface above requires examining every codec call site and threading the arguments cleanly.
- The codec API has to be designed carefully — premature versioning is a real risk. If we lock the wrong surface in v3.32.0, v4.0.0's new codec might need an awkward shim.

### Option B — Status quo + dependency lint

**Mechanism:** Leave the code shape as it is. Add a linter that forbids `lib/backup_codec.php` from `require`ing or calling any orchestrator function, and forbids the orchestrator from accessing codec internals directly. Codec keeps its current function shapes; just enforce the boundary.

**Pros:**
- Lowest cost in v3.32.0 — just split files and add a linter.
- Future v4.0.0 work isn't blocked.

**Cons:**
- A linter that bans cross-calls doesn't make the codec API correct — it just makes the wrongness visible. If the codec's function signatures are wrong for the migration tool, B doesn't help.
- The "encrypt-write-path bug" shape isn't really about the linter — it's about state coupling. A linter wouldn't have caught the v3.24.0 partial-write bug.

### Option C — Defer fully to v4.0.0

**Mechanism:** Don't touch the codec structure in v3.32.0. Keep `backup_encrypt_*`/`backup_decrypt_*` in whichever lib module they fall into (probably `lib.php` left-overs become `lib/backup_codec.php` as a pure file move, no API design). v4.0.0 does the real work.

**Pros:**
- Zero design risk in v3.32.0.
- All migration-tool design happens with v4.0.0's actual format in hand.

**Cons:**
- v3.32.0's ADR-004 extraction lands an arbitrary file shape that may need to be re-shuffled at v4.0.0 anyway. Extra churn.
- The migration tool now has to be designed AND implemented for v4.0.0 — double risk on the cold-break release.

### Option D — Skip the cold break entirely; evolve in v3.x

**Mechanism:** No v4.0.0 backup format change. Future codec evolution lands as new `IPAMBKP4`, `IPAMBKP5` magic versions, with the orchestrator dispatching on version. Backward compat preserved indefinitely.

**Pros:**
- No operator-facing cold break.
- Familiar evolution path (already in the codebase: IPAMBKP1 → IPAMBKP2 → IPAMBKP3).

**Cons:**
- The roadmap's v4.0.0 is partially defined by the backup cold break; removing that scope means re-asking "what's v4.0.0 even for?"
- The migration-tool argument still applies — if you're never breaking compat, you don't need a tool; but you also accumulate dispatch surface on every release.
- Doesn't address the underlying coupling problem at all.

## Recommendation

**Pick Option A (sealed-codec contract, v3.32.0 extraction, v4.0.0 evolution).**

The decision drivers tip toward A because:

1. **The migration-tool test is load-bearing.** v4.0.0's cold break is only operationally feasible if there's a tool that lets an operator decrypt-with-old then encrypt-with-new without spinning up an IPAM instance. That tool needs a sealed codec. Option A is the only one that produces it.

2. **The structural payoff is what was missing.** The v3.24.0 encrypt-write-path bug, the v3.26.0 vault-key bugs, and the v3.28.1 restore.php mode-detection bugs share a coupling-induced shape. A linter (B) marks the symptom; sealing the boundary (A) prevents the class of bug.

3. **v3.32.0 has the budget.** ADR-004's wave-2 milestone (#57) was already going to extract `lib/backup_codec.php`. Doing it as a no-behaviour-change refactor that incidentally lands the sealed contract is roughly the same scope as a "just move the functions" extraction — the design work is the new cost, and it's modest (the API surface above is ~5 functions).

4. **A is forward-compatible with C and D.** If v3.32.0 ships A and v4.0.0's plans change, the codec module is still a clean module — nothing forces a cold break. If v4.0.0 happens, the codec API just dispatches on a new version. Reversing decisions doesn't strand work.

The codec API surface in Option A's "Mechanism" block is the recommended starting point. It will likely need ≥1 refinement during v3.32.0 as the actual extraction surfaces edge cases — that's expected and is what an "ADR with a draft surface" is for.

## Implications

### v3.32.0 — Sealed-codec refactor (no behaviour change)

This rides alongside the rest of ADR-004's domain layer extraction:

- **New module `lib/backup_codec.php`** with the API surface from Option A's mechanism. All `backup_encrypt_*`, `backup_decrypt_*`, `backup_unencrypted_*`, `ipam_backup_v3_pack_header`, `ipam_backup_v3_unpack_header`, `ipam_backup_advance_ctr` live here.
- **Existing `lib/backup.php` shrinks** — orchestration helpers stay; codec calls route through the new module's public API only.
- **Dispatcher is already in shape** — `BackupClientInterface` + 3 impls — no change.
- **Linter rule:** `lib/backup_codec.php` may not `require` or call any function outside `lib/utils.php`, `lib/config.php`, and standard library. It is a leaf module by design.
- **Restore.php benefits incidentally** — its mode-detection branch (the #1177 #1196 area) now calls `ipam_backup_codec_inspect()` rather than peeking at header bytes directly.

### v4.0.0 — Cold break (deferred design)

When v4.0.0's planning starts in earnest:

- New codec mode (`IPAMBKP4` or a non-versioned format choice) implemented as new `case`s inside the codec module.
- Migration tool `tools/migrate-backups.php` (new file) uses the codec module standalone: `ipam_backup_codec_inspect()` + `ipam_backup_codec_decrypt_stream()` + write-new-codec.
- Operator-facing migration runbook in `docs/upgrading.md v4.0.0`.

### GH issues to open

For v3.32.0 (wave 2 milestone #57):
- `feat(backup): lib/backup_codec.php — sealed codec module with public API (ADR-005)`
- `refactor(backup): route lib/backup.php codec calls through ipam_backup_codec_* surface`
- `refactor(restore): use ipam_backup_codec_inspect() for mode detection`
- `tools: lib-module-linter rule — backup_codec.php is a leaf module`
- `tests: codec unit tests against fixture v1/v2/v3 backup files (no DB, no schedule state)` — under #1046 (wave-2 test umbrella)

For v4.0.0 (milestone #19):
- `design: v4.0.0 backup format (IPAMBKP4) — supersedes v3.x stored mode`
- `tool: tools/migrate-backups.php — v3.x → v4.0.0 codec migration`
- `docs(upgrading): v4.0.0 backup-format cold-break runbook`

### Files changed

v3.32.0:
- `Simple-PHP-IPAM/lib/backup_codec.php` — new
- `Simple-PHP-IPAM/lib/backup.php` — shrunk, codec calls removed
- `Simple-PHP-IPAM/lib.php` — codec functions removed (final drain of this file per ADR-004)
- `Simple-PHP-IPAM/restore.php` — mode detection via codec API
- `testing/scripts/lib-module-linter.php` — leaf-module rule

v4.0.0:
- `Simple-PHP-IPAM/lib/backup_codec.php` — new format mode added
- `tools/migrate-backups.php` — new tool
- `docs/upgrading.md` — v4.0.0 section

### Schema migrations needed

None for v3.32.0 (pure code organisation).

For v4.0.0 (if format change requires it): a one-time `backup_runs` row-level metadata update to indicate which rows are old-format vs new. Out of ADR-005's direct scope.

### Docs to update

v3.32.0:
- `docs/internal/design-document.md` — codec separation invariant; reference ADR-005
- `docs/internal/data-dictionary.md` — no DB change; note codec module's interface in the "Where to find what" pointer
- `docs/internal/architecture-decisions/README.md` — index update

v4.0.0 (deferred):
- `docs/upgrading.md` — full cold-break runbook
- `docs/internal/backups.md` — new format documentation

### Future ADRs unblocked

- **(Future) ADR for v4.0.0 backup format choice** — what algorithm, what KEM, what KDF. That's its own decision; ADR-005 only locks the *structure* (sealed codec), not the *content* (cipher choice).

## Open questions

1. **Migration tool location.** `Simple-PHP-IPAM/tools/migrate-backups.php` (alongside the app) vs `releases/v4.0.0-tools/migrate-backups.php` (release-bundle-adjacent, like the v3.x decrypt fixtures already in `tests/fixtures/decrypt-tool/`) vs a standalone Composer-installable package. My read: in-tree at `tools/`. Operators expect to find it next to `upgrade.sh`.
2. **Codec error model.** Today codec functions throw `RuntimeException` with descriptive messages. Sealed module makes the error surface a contract — should we introduce typed exceptions (`BackupCodecException`, `BackupCodecAuthenticationFailedException` for HMAC mismatch, etc.) or stick with string-typed `RuntimeException`? Trade: stronger contract vs. another class hierarchy to maintain.
3. **`backup_unencrypted_wrap_stream` / `_unwrap_stream` — codec or shim?** These exist for the "unencrypted" mode that's mostly a backward-compat path for the IPAMBKU1 unencrypted wrapper. They wrap a plain SQL dump in a checksum-only envelope. Is the unencrypted wrapper part of the codec's public API (codec handles "encrypt-or-not"), or a separate shim outside the codec? My read: part of the codec — mode is a parameter; "unencrypted" is just another value.
4. **v3.32.0 vs v4.0.0 line.** Should v3.32.0 land **only** the sealed-codec refactor with zero new behaviour, or also build the migration tool against the v3.x codec (single-format migration: v3.x → v3.x, no-op data-wise but exercises the tool's code path)? The latter would prove the tool design before v4.0.0 commits to it. Cost: extra v3.32.0 scope.

## References

- ADR-001 (accepted) — `setting_definitions` schema; the `secret`-subtype is the only ADR-001-touched key that intersects backup (vault key, app_secret).
- ADR-004 (accepted) — declared `lib/backup_codec.php` + `lib/backup_run.php` as v3.32.0 domain modules. ADR-005 specifies what `lib/backup_codec.php` contains.
- `docs/internal/roadmap.md` § 10 (locked 2026-05-11) — ADR-005's source.
- `Simple-PHP-IPAM/lib/backup.php` — current orchestrator (3,300 lines).
- `Simple-PHP-IPAM/lib.php:4683-5842` — current codec functions (IPAMBKP1/2/3, IPAMBKU1).
- `Simple-PHP-IPAM/lib/BackupClientInterface.php` — already-correct dispatcher contract; the model ADR-005 extends to the codec.
- `docs/internal/backups.md` — operator-facing backup model; will need expansion for v4.0.0.
