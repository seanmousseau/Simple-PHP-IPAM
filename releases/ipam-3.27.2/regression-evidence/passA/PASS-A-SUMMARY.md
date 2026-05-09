# v3.27.2 Pass A regression — sqlite test instance

**Date:** 2026-05-09
**Bundle SHA256:** 9530f839dd9679689133da9817e2860850046ca95f4496fc7d53204ea53a2558
**Deployed to:** `https://dev-direct.seanmousseau.com/testing/ipam/` (sqlite, 2 passkeys enrolled)

## Verification target

Reproduce the EXACT procedure that surfaced #1124 on v3.27.1 yesterday — dump → dry-run on a fresh backup with `webauthn_credentials` rows present. v3.27.1 produced:

> Body checksum does not match footer — apply will refuse to proceed (corruption or tampering).
> Body row count (793964) disagrees with footer total_rows (793966).

v3.27.2 expected: clean round-trip, no warnings.

## Result

**PASS.** Dry-run output:

- 24 tables, 793,980 INSERT statements (no off-by-N)
- `webauthn_credentials: 2 / 2 / +0` (rows correctly serialised in dump body)
- `hasChecksum` warning: `false`
- `hasRowCount` warning: `false`
- Warnings array: empty

The exact field-bug reproduction is closed.

## Other fixes (not exercised live this pass; locked by unit tests)

- #1120 OIDC auto-prov sentinel — `tests/OidcAutoProvSentinelTest.php` source-level lock; existing `claude-oidc` row on test instance predates fix and would require fresh OIDC re-provision to verify (out of scope; existing-row migration is a v3.27.3 follow-up if desired)
- #1121 settings UX consistency — `tests/SettingsToggleConsistencyTest.php` 6 wiring assertions
- #1122 MFA-disable strand guard — `tests/MfaDisableLockoutGuardTest.php` 5 unit cases + 1 wiring assertion across all 3 disable handlers
- #1124 conformance — `tests/IPAMBKL1FullSchemaConformanceTest.php` 6 cases (3 BLOB + 2 TEXT-on-allowlist + 1 negative-case writer-throw)

## Local gate (pre-push)

- PHPUnit: 949 tests, all passing
- PHPStan L9: 0 errors
- PHPCS: clean
- Semgrep: 0 findings (132 files)
