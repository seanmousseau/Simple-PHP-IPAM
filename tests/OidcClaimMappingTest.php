<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Simple-PHP-IPAM/lib.php';

/**
 * v3.29.0 #1099 — Unit tests for the OIDC claim-mapping + auto-link
 * + auto-provision helpers extracted from oidc_callback.php as a
 * follow-up to #867.
 *
 * Covers the three helpers:
 *
 *   - oidc_extract_claims(array $payload): array
 *   - oidc_resolve_user(PDO $db, string $sub, string $email, string $preferredUsername): ?array
 *   - oidc_provision_user(PDO $db, array $claims, string $role): array
 *
 * Each test opens a fresh sqlite::memory: DB with a minimal `users`
 * table matching the live schema's relevant columns, then exercises one
 * branch of the resolve / provision logic.
 */
final class OidcClaimMappingTest extends TestCase
{
    private \PDO $db;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        // Minimal users-table shape matching the production columns the
        // resolver / provisioner touch. is_active default 1; oidc_sub
        // nullable + indexed for the WHERE oidc_sub = … lookup.
        $this->db->prepare(
            "CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'readonly',
                is_active INTEGER NOT NULL DEFAULT 1,
                oidc_sub TEXT DEFAULT NULL,
                name TEXT NOT NULL DEFAULT '',
                email TEXT NOT NULL DEFAULT ''
            )"
        )->execute();
        $this->db->prepare("CREATE UNIQUE INDEX uq_users_oidc_sub ON users(oidc_sub) WHERE oidc_sub IS NOT NULL")->execute();
    }

    private function seedUser(string $username, string $email = '', ?string $oidcSub = null, string $name = ''): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO users (username, password_hash, email, oidc_sub, name) VALUES (:u, '!disabled', :e, :s, :n)"
        );
        $stmt->execute([':u' => $username, ':e' => $email, ':s' => $oidcSub, ':n' => $name]);
        return (int)$this->db->lastInsertId();
    }

    // ---- oidc_extract_claims ----

    public function testExtractClaimsTrimsAndSanitises(): void
    {
        $claims = oidc_extract_claims([
            'sub'                => '  abc-123  ',  // trim NOT applied to sub by design
            'email'              => '  alice@example.com  ',
            'name'               => '  Alice Example  ',
            'preferred_username' => '  alice!#$%  ',
        ]);
        $this->assertSame('  abc-123  ', $claims['sub']);  // sub is preserved verbatim (sanity)
        $this->assertSame('alice@example.com', $claims['email']);
        $this->assertSame('Alice Example', $claims['name']);
        // preferred_username: trim → first 64 chars → strip non-username chars.
        // 'alice!#$%' → after sanitisation → 'alice'.
        $this->assertSame('alice', $claims['preferred_username']);
    }

    public function testExtractClaimsEnforcesLengthCaps(): void
    {
        $longEmail = str_repeat('a', 300) . '@example.com';
        $longName  = str_repeat('b', 300);
        $longPref  = str_repeat('c', 100);
        $claims = oidc_extract_claims([
            'sub'                => 'x',
            'email'              => $longEmail,
            'name'               => $longName,
            'preferred_username' => $longPref,
        ]);
        $this->assertSame(255, strlen($claims['email']));
        $this->assertSame(255, strlen($claims['name']));
        $this->assertSame(64, strlen($claims['preferred_username']));
    }

    public function testExtractClaimsHandlesMissingFields(): void
    {
        $claims = oidc_extract_claims([]);
        $this->assertSame('', $claims['sub']);
        $this->assertSame('', $claims['email']);
        $this->assertSame('', $claims['name']);
        $this->assertSame('', $claims['preferred_username']);
    }

    // ---- oidc_resolve_user ----

    public function testResolveCurrentBySubWins(): void
    {
        $existingId = $this->seedUser('alice', 'alice@example.com', 'sub-abc-123');
        $row = oidc_resolve_user($this->db, 'sub-abc-123', 'alice@example.com', 'alice');
        $this->assertNotNull($row);
        $this->assertSame($existingId, (int)$row['id']);
    }

    public function testResolveByPreferredUsernameWhenSubMisses(): void
    {
        $aliceId = $this->seedUser('alice', 'alice@example.com', null /* unlinked */);
        $row = oidc_resolve_user($this->db, 'sub-new', 'alice@example.com', 'alice');
        $this->assertNotNull($row);
        $this->assertSame($aliceId, (int)$row['id']);
        $this->assertSame('alice', $row['username']);
    }

    public function testResolveByEmailWhenPrefUsernameMisses(): void
    {
        // Seed two unlinked users with different usernames; resolver
        // looks up by email when preferred_username doesn't match.
        $this->seedUser('bob',   'bob@example.com');
        $aliceId = $this->seedUser('alice', 'alice@example.com');
        $row = oidc_resolve_user($this->db, 'sub-new', 'alice@example.com', 'not-a-real-username');
        $this->assertNotNull($row);
        $this->assertSame($aliceId, (int)$row['id']);
    }

    public function testResolveReturnsNullWhenNoMatch(): void
    {
        $this->seedUser('bob', 'bob@example.com');
        $row = oidc_resolve_user($this->db, 'sub-new', 'nobody@example.com', 'nobody');
        $this->assertNull($row);
    }

    public function testResolveSkipsLinkedAccountsForUnlinkedLookups(): void
    {
        // alice is already linked to a DIFFERENT sub; resolver MUST NOT
        // return her when looking up by sub-new / preferred_username = alice.
        $this->seedUser('alice', 'alice@example.com', 'sub-other');
        $row = oidc_resolve_user($this->db, 'sub-new', 'alice@example.com', 'alice');
        $this->assertNull($row, 'resolver must skip rows already linked to a different oidc_sub');
    }

    public function testResolvePreferredUsernameTakesPrecedenceOverEmail(): void
    {
        // Two unlinked rows: bob owns the username, alice owns the email.
        // With preferred_username = 'bob' and email = 'alice@example.com',
        // resolver picks bob (username match wins because it runs first).
        $bobId = $this->seedUser('bob', 'bob@example.com');
        $this->seedUser('alice', 'alice@example.com');
        $row = oidc_resolve_user($this->db, 'sub-new', 'alice@example.com', 'bob');
        $this->assertNotNull($row);
        $this->assertSame($bobId, (int)$row['id']);
    }

    public function testResolveEmptyPrefUsernameSkipsToEmail(): void
    {
        $aliceId = $this->seedUser('alice', 'alice@example.com');
        $row = oidc_resolve_user($this->db, 'sub-new', 'alice@example.com', '');
        $this->assertNotNull($row);
        $this->assertSame($aliceId, (int)$row['id']);
    }

    // ---- oidc_provision_user ----

    public function testProvisionUsesPreferredUsername(): void
    {
        $claims = [
            'sub'                => 'sub-abc-123',
            'email'              => 'alice@example.com',
            'name'               => 'Alice Example',
            'preferred_username' => 'alice',
        ];
        $result = oidc_provision_user($this->db, $claims, 'readonly');
        $this->assertSame('alice', $result['username']);
        $row = $this->db->query("SELECT username, oidc_sub, password_hash, role, is_active FROM users WHERE id = " . $result['id'])->fetch();
        $this->assertSame('alice', $row['username']);
        $this->assertSame('sub-abc-123', $row['oidc_sub']);
        $this->assertSame('!disabled', $row['password_hash']);
        $this->assertSame('readonly', $row['role']);
        $this->assertSame(1, (int)$row['is_active']);
    }

    public function testProvisionFallsBackToEmailLocalPart(): void
    {
        $claims = [
            'sub'                => 'sub-abc-123',
            'email'              => 'alice@example.com',
            'name'               => '',
            'preferred_username' => '',
        ];
        $result = oidc_provision_user($this->db, $claims, 'readonly');
        $this->assertSame('alice', $result['username']);
    }

    public function testProvisionFallsBackToSanitisedSubWhenEmailMissing(): void
    {
        $claims = [
            'sub'                => 'okta|abc!@#$%def',  // sanitiser strips |!#$%
            'email'              => '',
            'name'               => '',
            'preferred_username' => '',
        ];
        $result = oidc_provision_user($this->db, $claims, 'readonly');
        // Sanitiser keeps . _ @ - and alphanumerics; strips | ! # $ %.
        // '@' survives because it's part of the local-username allowlist
        // (a quirk that pre-dates this refactor — kept verbatim per #1099).
        $this->assertSame('oktaabc@def', $result['username']);
    }

    public function testProvisionFallsBackToOidcUserSentinel(): void
    {
        $claims = [
            'sub'                => '!!!',  // every char sanitised away
            'email'              => '',
            'name'               => '',
            'preferred_username' => '',
        ];
        $result = oidc_provision_user($this->db, $claims, 'readonly');
        $this->assertSame('oidcuser', $result['username']);
    }

    public function testProvisionAppendsCounterOnUsernameCollision(): void
    {
        // Pre-seed an unlinked 'alice' so the first INSERT collides on UNIQUE(username).
        $this->seedUser('alice', 'alice@example.com');
        $claims = [
            'sub'                => 'sub-new',
            'email'              => 'alice2@example.com',
            'name'               => '',
            'preferred_username' => 'alice',
        ];
        $result = oidc_provision_user($this->db, $claims, 'readonly');
        $this->assertSame('alice_2', $result['username']);
    }

    public function testProvisionRejectsInvalidRoleAndFallsBackToReadonly(): void
    {
        $claims = [
            'sub'                => 'sub-x',
            'email'              => 'x@example.com',
            'name'               => '',
            'preferred_username' => 'x',
        ];
        $result = oidc_provision_user($this->db, $claims, 'superuser' /* not in the allowlist */);
        $row = $this->db->query("SELECT role FROM users WHERE id = " . $result['id'])->fetch();
        $this->assertSame('readonly', $row['role']);
    }

    public function testProvisionAcceptsAllowedRoles(): void
    {
        foreach (['admin', 'netops', 'readonly'] as $role) {
            // Each provision needs a fresh table since username = 'x' would collide.
            $this->db->prepare('DELETE FROM users')->execute();
            $claims = [
                'sub'                => "sub-$role",
                'email'              => "$role@example.com",
                'name'               => '',
                'preferred_username' => 'x',
            ];
            $result = oidc_provision_user($this->db, $claims, $role);
            $row = $this->db->query("SELECT role FROM users WHERE id = " . $result['id'])->fetch();
            $this->assertSame($role, $row['role']);
        }
    }
}
