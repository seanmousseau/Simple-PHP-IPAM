<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * Bug Y (Pass A 2026-05-08, v3.27.2 #1122) — disabling the last enrolled
 * step-up method must be refused with a precondition guard.
 *
 * Pre-fix: change_password.php disable handlers gate on ipam_sudo_require()
 * (correct) but none check whether disabling the targeted method would
 * leave the user with zero satisfiable step-up methods. A user whose
 * only enrolled method is TOTP can disable TOTP and become stranded
 * for any future sudo-class action.
 *
 * Post-fix v3.27.2:
 *   1. ipam_sudo_would_strand_user_after_disable() new helper.
 *   2. Each disable handler calls the helper and refuses on true.
 */
final class MfaDisableLockoutGuardTest extends TestCase
{
    private function freshDb(): PDO
    {
        // ipam_setting()'s static cache is process-wide; ipam_setting()
        // reads $GLOBALS['db'] (not a parameter). Both must be reset per
        // test so settings written this iteration are actually visible.
        ipam_setting_cache_bust(null);

        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $schema = (string) file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/schema.sql');
        $db->exec($schema);
        $db->exec('PRAGMA foreign_keys = ON');
        ensure_migrations_table($db);
        apply_migrations($db);
        $GLOBALS['db'] = $db;
        // Helper mirrors ipam_sudo_available_methods() which gates on the
        // install-level mfa.*_enabled flags. Defaults: totp=true,
        // email_otp=false, passkeys=false. Enable all three so the strand
        // verdict reflects user enrollment alone, not install policy.
        ipam_setting_set($db, 'mfa.totp_enabled', true);
        ipam_setting_set($db, 'mfa.email_otp_enabled', true);
        ipam_setting_set($db, 'mfa.passkeys_enabled', true);
        return $db;
    }

    private function seedUser(PDO $db, bool $totp, bool $emailOtp, int $passkeys, bool $oidcLinked = false): int
    {
        $hash = '!disabled';  // OIDC-only — strand surface is sharp
        $st = $db->prepare(
            "INSERT INTO users (username, password_hash, role, is_active, totp_enabled, email_otp_enabled, oidc_sub) " .
            "VALUES ('strand-test', :h, 'admin', 1, :t, :e, :sub)"
        );
        $st->execute([
            ':h'   => $hash,
            ':t'   => $totp ? 1 : 0,
            ':e'   => $emailOtp ? 1 : 0,
            ':sub' => $oidcLinked ? 'sub-strand-test' : null,
        ]);
        $uid = (int) $db->lastInsertId();
        if ($totp) {
            $db->prepare("UPDATE users SET totp_secret_enc = :s WHERE id = :id")
               ->execute([':s' => 'fake-encrypted-secret', ':id' => $uid]);
        }
        for ($i = 0; $i < $passkeys; $i++) {
            $st2 = $db->prepare(
                "INSERT INTO webauthn_credentials (user_id, credential_id, public_key, sign_count, name) " .
                "VALUES (:uid, :cid, :pk, 0, :nm)"
            );
            $st2->bindValue(':uid', $uid, PDO::PARAM_INT);
            $st2->bindValue(':cid', "cred-$i-" . random_bytes(16), PDO::PARAM_LOB);
            $st2->bindValue(':pk',  'fake-cose-bytes', PDO::PARAM_STR);
            $st2->bindValue(':nm',  "Passkey #$i", PDO::PARAM_STR);
            $st2->execute();
        }
        return $uid;
    }

    /** @return array<string,mixed> */
    private function policy(bool $totp = true, bool $email = true, bool $webauthn = true, bool $reauth = false): array
    {
        return [
            'sudo_once'             => false,
            'ttl_seconds'           => 300,
            'allow_totp'            => $totp,
            'allow_email_otp'       => $email,
            'allow_webauthn'        => $webauthn,
            'allow_provider_reauth' => $reauth,
        ];
    }

    public function testDisableTotpStrandWhenTotpIsTheOnlyMethod(): void
    {
        $db = $this->freshDb();
        $uid = $this->seedUser($db, totp: true, emailOtp: false, passkeys: 0);

        $strands = ipam_sudo_would_strand_user_after_disable(
            $db, $uid, 'totp', $this->policy(totp: true, email: false, webauthn: false)
        );
        $this->assertTrue($strands, '#1122: disabling TOTP when no other method is enrolled must strand');
    }

    public function testDisableTotpDoesNotStrandWhenEmailOtpAlsoEnrolled(): void
    {
        $db = $this->freshDb();
        $uid = $this->seedUser($db, totp: true, emailOtp: true, passkeys: 0);

        $strands = ipam_sudo_would_strand_user_after_disable(
            $db, $uid, 'totp', $this->policy(totp: true, email: true, webauthn: false)
        );
        $this->assertFalse($strands, 'email_otp remains as a fallback; not stranded');
    }

    public function testDisableLastPasskeyStrandsWhenNoOtherMethod(): void
    {
        $db = $this->freshDb();
        $uid = $this->seedUser($db, totp: false, emailOtp: false, passkeys: 1);

        $strands = ipam_sudo_would_strand_user_after_disable(
            $db, $uid, 'passkey', $this->policy(totp: false, email: false, webauthn: true)
        );
        $this->assertTrue($strands, '#1122: deleting the only passkey strands the user');
    }

    public function testDisablingOnePasskeyOfTwoDoesNotStrand(): void
    {
        $db = $this->freshDb();
        $uid = $this->seedUser($db, totp: false, emailOtp: false, passkeys: 2);

        $strands = ipam_sudo_would_strand_user_after_disable(
            $db, $uid, 'passkey', $this->policy(totp: false, email: false, webauthn: true)
        );
        $this->assertFalse($strands, 'second passkey remains as fallback');
    }

    public function testEmailOtpDisableNotStrandedWhenOidcReauthAvailable(): void
    {
        $db = $this->freshDb();
        $uid = $this->seedUser($db, totp: false, emailOtp: true, passkeys: 0, oidcLinked: true);

        ipam_setting_set($db, 'oidc.enabled', true);
        ipam_setting_set($db, 'oidc.client_id', 'cid');
        ipam_setting_set($db, 'oidc.client_secret', 'csec');
        ipam_setting_set($db, 'oidc.discovery_url', 'https://example.org/.well-known/openid-configuration');
        ipam_setting_set($db, 'oidc.redirect_uri', 'https://example.org/cb');

        $strands = ipam_sudo_would_strand_user_after_disable(
            $db, $uid, 'email_otp', $this->policy(totp: false, email: true, webauthn: false, reauth: true)
        );
        $this->assertFalse($strands, 'oidc_reauth remains as fallback when policy allows and user has oidc_sub and OIDC configured');
    }

    public function testHandlersWireTheGuardCall(): void
    {
        $contents = (string) file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/change_password.php');
        $this->assertNotEmpty($contents);

        // v3.30.0 (#920): the inline POST actions were extracted into top-of-file
        // cp_handle_*() functions dispatched via a table. The #1122 guard must
        // still fire inside each MFA-disable handler before any user-state
        // mutation — assert against the function body, not a flat grep window.
        foreach (['disable_totp', 'email_otp_disable', 'passkey_delete'] as $action) {
            $fn    = "function cp_handle_{$action}(";
            $start = strpos($contents, $fn);
            $this->assertNotFalse($start, "handler function cp_handle_{$action}() not found");
            // Slice from the function signature to the start of the next
            // top-level function (or EOF) so the assertion is scoped to this
            // handler's body alone.
            $next   = strpos($contents, "\nfunction ", $start + strlen($fn));
            $branch = $next === false
                ? substr($contents, $start)
                : substr($contents, $start, $next - $start);
            $this->assertStringContainsString(
                'ipam_sudo_would_strand_user_after_disable',
                $branch,
                "#1122: cp_handle_{$action}() must call ipam_sudo_would_strand_user_after_disable() before mutating user state"
            );
        }
    }
}
