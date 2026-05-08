# Step-up authentication

> Reference for the v3.27.0 step-up subsystem. Read before touching any sensitive-action handler that re-authenticates the operator (vault key reveal, sensitive setting reveal, DB import, API key creation, MFA disable). The originating bug — and the contract here — keeps the gate decoupled from how the user originally logged in so OIDC-only admins can still pass it.

---

## What "step-up" means here

Step-up authentication is **a fresh proof of identity at the moment of a sensitive action**, separate from how the operator originally signed in. It is not the login MFA; it runs *after* login, against an admin who is already authenticated, every time they reach for a high-blast-radius affordance.

The two are intentionally different:

| Aspect | Login MFA | Step-up auth |
|---|---|---|
| When it runs | Once per session, at sign-in | Every time a sensitive action is taken (subject to TTL) |
| What satisfies it | Whatever the user enrolled (TOTP / Email OTP / passkey) on top of password or OIDC | Any method allowed by the install policy that the user can satisfy: TOTP, Email OTP, WebAuthn, or provider re-auth |
| Where the policy lives | `mfa.*` settings + per-user enrollment | `auth.step_up.*` settings (separate registry group) |
| Failure mode | Refuse login | Refuse the sensitive action; user remains logged in |

Login MFA is bound to the login provider — an OIDC-only admin satisfies their primary login through the IdP and never enrols a TOTP for it. Step-up has to keep working for that admin even though they have no local password and no login MFA. That's the bug the v3.27.0 gate fixes (#1098).

---

## The helper contract

```php
require __DIR__ . '/lib/auth_step_up.php';
ipam_sudo_verify(\PDO $db, int $userId, array $proof): bool
```

Implementation in `Simple-PHP-IPAM/lib/auth_step_up.php`. Resolution order (each step is checked against the install's `auth.step_up.*` policy and the user's enrollment):

1. **Cached grant.** If `$_SESSION['sudo_until_ts']` is in the future, return true and refresh the TTL on use. No audit row, no rate-limit hit. The caller should treat this as a no-op success.
2. **Strong MFA proof.** If the policy permits the supplied method *and* the user has the matching enrollment:
   - `totp` → `ipam_totp_decrypt_secret(users.totp_secret_enc, app_secret)` then `ipam_totp_verify($secret, $code)`
   - `email_otp` → existing `email_otp_*` verify path
   - `webauthn` → `lbuchs/webauthn` assertion verify against the in-flight challenge issued at prompt-render time
3. **Provider re-auth fallback.** If `auth.step_up.allow_provider_reauth=true`:
   - Local-password user (real `password_hash`) → `password_verify($proof['password'], $hash)`. The sentinel `'!disabled'` hash is **never** accepted (regression-tested by `SudoVerifyTest::testLockedPasswordHashIsNeverAcceptedAsProof`).
   - OIDC user → return false; caller redirects through `oidc_login.php?prompt=login&return_to=…` and the OIDC callback completes the gate. Future LDAP/SAML providers add their own branches at this layer.
4. **Refusal.** If no permitted method satisfies the gate, return false. The caller renders the shared prompt partial which surfaces the actionable "no method available" message (see [Prompt UX](#prompt-ux)).

On success the helper writes:

| Session key | Meaning |
|---|---|
| `sudo_until_ts` | Unix timestamp at which the grant expires |
| `sudo_method` | Method that satisfied the gate (audit detail; not consulted for trust) |

On failure it emits an `auth.sudo_failed` audit row with method + IP + stable reason code (e.g. `totp_invalid`, `webauthn_no_challenge`, `method_unavailable`) and increments the `sudo` rate-limit bucket via `record_auth_failure()`. Hitting the cap emits `auth.sudo_rate_limited` and refuses subsequent proofs from the same IP for the cap window.

---

## Calling pattern

Every sensitive POST handler follows the same shape:

```php
require __DIR__ . '/lib/auth_step_up.php';

if (!ipam_sudo_active()) {
    $stepUpError = '';
    $proof = ipam_sudo_proof_from_post();
    if ($proof !== null) {
        if (!ipam_sudo_verify($db, current_user()['id'], $proof)) {
            $stepUpError = 'Verification failed. Please try again.';
        }
    }
    if (!ipam_sudo_active()) {
        page_header('Confirm your identity');
        $stepUpUserId       = current_user()['id'];
        $stepUpFormAction   = $_SERVER['REQUEST_URI'];
        $stepUpHiddenFields = ['action' => 'vault_reveal']; // round-trip the original action + any context
        include __DIR__ . '/views/_step_up_prompt.php';
        page_footer();
        exit;
    }
}

// Past this line: the operator has a fresh sudo grant. Run the actual
// sensitive action.
```

`ipam_sudo_active()` is the cheap "do I have a warm grant?" check that sensitive handlers should call before doing any work. `ipam_sudo_proof_from_post()` extracts the `_sudo_*` fields the prompt partial submits and routes them to `ipam_sudo_verify()`.

The shared prompt partial at `Simple-PHP-IPAM/views/_step_up_prompt.php` expects three required vars:

- `$db` — the open PDO handle
- `$stepUpUserId` — current user id
- `$stepUpFormAction` — URL to POST proof to (typically `$_SERVER['REQUEST_URI']`)
- `$stepUpHiddenFields` — array of `name => value` hidden inputs to round-trip the original action and any context the handler needs to resume

Optional vars (`$stepUpTitle`, `$stepUpDescription`, `$stepUpError`, `$stepUpReturnPath`) override the defaults if the calling page wants to customise the prompt copy.

---

## Policy resolution

Five settings, all under the `step_up` registry group:

| Key | Type | Default | Effect |
|---|---|---|---|
| `auth.step_up.allow_totp` | bool | `true` | TOTP code can satisfy the gate (if user is enrolled) |
| `auth.step_up.allow_email_otp` | bool | `true` | Email OTP code can satisfy the gate (also requires `mfa.email_otp_enabled=true`) |
| `auth.step_up.allow_webauthn` | bool | `true` | WebAuthn assertion can satisfy the gate (if user has a passkey) |
| `auth.step_up.allow_provider_reauth` | bool | `true` | Local password verify or OIDC `prompt=login` |
| `auth.step_up.ttl_seconds` | int | `300` | Grant lifetime; discrete options 0/60/300/900/1800/3600 |

Values resolve through the standard `ipam_setting()` cascade so v3.x reads them globally and v4.0.0 will read them per-tenant first.

### Lock-out precondition

Before persisting any policy save the handler runs `ipam_sudo_policy_lockout_check($db, $proposed)`. The check iterates every active admin and asks `ipam_sudo_available_methods($db, $uid, $proposed) === []` — if any active admin has zero satisfiable methods under the proposed policy, the save is refused with a specific error naming the offender. This is the same shape as the existing last-active-admin guard and it's what stops a tightening edit from stranding the install.

The check covers two distinct scenarios that the regression tests pin down:

- **OIDC-only admin** — has no `password_hash`, depends on either MFA enrollment or provider re-auth. Disabling provider re-auth without enrolling them in MFA strands them.
- **Last admin with no MFA** — depends on provider re-auth. Disabling it strands them even if they're a normal local-password admin.

### Sudo grant invalidation

`$_SESSION['sudo_until_ts']` is unconditionally cleared on any of:

- logout
- session regeneration after password change
- role downgrade (admin → readonly)
- `oidc_sub` change on the user row
- MFA enrollment change (TOTP enroll/disable, passkey add/delete, email OTP enroll/disable)
- step-up policy update (the policy save handler calls `ipam_sudo_invalidate()` after a successful commit)

If you add a new state change that affects what an operator can prove, call `ipam_sudo_invalidate()` so the warm grant doesn't outlive the change.

---

## Prompt UX

The shared prompt at `views/_step_up_prompt.php` renders one of four shapes:

1. **No method available.** Renders an actionable error directing the user to enrol an MFA method or contact an administrator. **No proof inputs are rendered**, only a Cancel link. This is the branch that fires when policy + enrollment intersect to leave the operator with nothing — it's a deliberate degradation, not a crash.
2. **Single method available.** Method is carried as a hidden `_sudo_method` input; the page renders only that method's input field.
3. **Multiple methods available.** Method dropdown defaults to the user's strongest enrolled method (passkey > totp > email_otp > password > oidc_reauth) narrowed by the policy. Switching the dropdown shows the corresponding method-specific section.
4. **OIDC re-auth chosen.** Renders a button that redirects through `/oidc/start.php?prompt=login&return_to=…` and resumes the original action via the OIDC callback handler.

The Reveal-vault-key control is a primary inline button next to the fingerprint, **not** buried inside a `<details>` disclosure (#1111). When you add a new sensitive affordance, follow the same pattern — the gate is upstream of the action and the affordance should be discoverable, not hidden.

---

## How to add a new sensitive action

1. **Decide the action is sudo-class.** Sudo-class actions are catastrophic-or-irreversible reveals, write-overrides, or credential mints: vault reveal, DB import, API key creation, MFA disable, sensitive setting reveal. Routine writes are not sudo-class — gate them with the standard CSRF + role check, not step-up.
2. **Add the call site.** At the top of the POST handler, before any side effect, follow the calling pattern above. Round-trip every input the handler needs to resume in `$stepUpHiddenFields`.
3. **Define the action verb in `audit-actions.md`.** Use the existing `auth.sudo_passed` / `auth.sudo_failed` rows for the gate; emit your own `<entity>.<verb>` audit on the side-effect path.
4. **Update the registry group description.** The `step_up` group's description in `lib.php` lists the actions the gate covers — keep it accurate.
5. **Add tests at both layers.**
   - PHPUnit: extend `tests/SudoVerifyTest.php` if the action exercises a previously untested helper branch.
   - Playwright: add a row to `step-up-fan-out.spec.ts` (no-grant prompt-renders test) so future migrations don't regress the wiring.
6. **Update the `step_up` group description** in `lib.php` (the line listing the protected actions) so the admin card's helper text stays accurate.

---

## Future work

- **LDAP / SAML provider re-auth.** The provider-reauth branch in `ipam_sudo_verify()` is single-IdP today (OIDC). When the v4.x release stream introduces LDAP / SAML (per `v4-release-stream.md`), each provider gets its own branch in step 3 of the resolution order. The helper signature does not change; only the proof-routing layer.
- **Per-tenant policy.** v4.0.0 multi-tenancy resolves `auth.step_up.*` through the standard tenant-row → global-row → registry-default cascade (see `v4-tenancy-design.md`). v3.27.0 already reads through that cascade with `tenant_id=NULL`, so no follow-up code change is required when the wizard runs.
- **Deprecation of `backup.vault_key.sudo_failed`.** v3.27.0 emits both the new `auth.sudo_failed` and the legacy `backup.vault_key.sudo_failed` for vault-key reveal failures so existing log queries don't break. The legacy alias is removed in v3.28.0; track via the cleanup backlog.

---

## Cross-references

- `Simple-PHP-IPAM/lib/auth_step_up.php` — implementation
- `Simple-PHP-IPAM/views/_step_up_prompt.php` — shared prompt partial
- `docs/internal/auth-model.md` — login + MFA reference
- `docs/internal/audit-actions.md` — `auth.sudo_*` and `auth.step_up_policy.updated` row reference
- `docs/internal/adding-a-setting.md` — registry mechanics for the policy keys
- `docs/superpowers/plans/2026-05-07-v3.27.0.md` — design rationale and call-site migration table
