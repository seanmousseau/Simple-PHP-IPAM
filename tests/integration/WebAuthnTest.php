<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * WebAuthn / passkey server-side unit tests (#886).
 *
 * Test gap #886: passkeys were previously covered only by Playwright. This
 * suite adds PHPUnit coverage of the server-side lbuchs/webauthn integration:
 * challenge generation, assertion (login) verification, and the sign-count
 * update / clone-detection path.
 *
 * -- What is genuinely unit-testable, and how --------------------------------
 *
 * ASSERTION (login) verification is fully testable end-to-end WITHOUT a real
 * authenticator. The lbuchs verification path (WebAuthn::processGet) only needs
 *   - authenticatorData : 37 raw bytes (rpIdHash + flags + signCount), no
 *                         attested-credential-data for a get() ceremony
 *   - clientDataJSON    : a JSON object {type,challenge,origin}
 *   - signature         : ECDSA-SHA256 over (authenticatorData . sha256(clientDataJSON))
 *   - credentialPublicKey : a PEM-encoded EC public key
 * Every one of those can be produced honestly in PHP with a real OpenSSL
 * P-256 keypair generated in setUp(). The signature is verified by
 * openssl_verify() inside the library -- there is NO mocking here. A tampered
 * signature, wrong challenge, or wrong origin causes a REAL cryptographic /
 * value-match failure. These tests verify behaviour, not a mock.
 *
 * SIGN-COUNT / CLONE DETECTION is likewise fully testable: processGet()
 * implements W3C step 17 (clone signal when prevSignCount >= newSignCount) and
 * the project's ipam_passkey_update_sign_count() persists the new value. Both
 * are exercised against real verification calls.
 *
 * CHALLENGE generation (ipam_passkey_dispatch_challenge) is pure server-side
 * state: random bytes, base64url encoding, session storage. Fully testable.
 *
 * -- What is fixture-limited -------------------------------------------------
 *
 * ATTESTATION (registration) verification -- WebAuthn::processCreate -- requires
 * a CBOR-encoded attestationObject produced by a real authenticator (or a
 * faithfully hand-rolled CBOR map with attStmt/authData). The lbuchs library
 * ships NO attestation test vectors (vendor/lbuchs/webauthn/_test/ contains
 * only root certificates and a manual browser harness). Synthesising a valid
 * attestationObject by hand would essentially re-implement an authenticator in
 * the test and would not increase confidence in the project's own code.
 *
 * Therefore attestation is covered at the project's WRAPPER boundary instead:
 * ipam_passkey_webauthn() -- RP-ID derivation from HTTP_HOST, port stripping,
 * IPv6 bracket stripping, loopback->localhost mapping, and rejection of
 * non-loopback IP literals (IP addresses are invalid WebAuthn RP IDs). That
 * wrapper is the project's own code and IS unit-testable. The library-internal
 * CBOR attestation parse is left to the lbuchs library's own coverage and to
 * the project's Playwright suite, which drives a real browser authenticator.
 */
class WebAuthnTest extends TestCase
{
    /** @var resource|\OpenSSLAsymmetricKey */
    private $privateKey;

    private string $publicKeyPem = '';

    /** Saved superglobal state restored in tearDown(). */
    private array $savedServer = [];

    /** rpId the WebAuthn object will derive for HTTP_HOST=localhost. */
    private const RP_ID = 'localhost';

    protected function setUp(): void
    {
        if (!class_exists('lbuchs\\WebAuthn\\WebAuthn')) {
            self::markTestSkipped('lbuchs/webauthn not installed (run composer install)');
        }
        if (!in_array('prime256v1', openssl_get_curve_names() ?: [], true)) {
            self::markTestSkipped('OpenSSL prime256v1 (P-256) curve unavailable');
        }

        // Real EC P-256 keypair -- this is the "authenticator" for the test.
        $res = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ]);
        self::assertNotFalse($res, 'EC keypair generation must succeed');
        $this->privateKey = $res;
        $details = openssl_pkey_get_details($res);
        self::assertIsArray($details);
        $this->publicKeyPem = (string) $details['key'];

        $this->savedServer = $_SERVER;
        // HTTP_HOST drives ipam_passkey_webauthn()'s rpId; localhost lets the
        // origin check accept http:// (no TLS in the unit-test environment).
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SERVER  = $this->savedServer;
        $_SESSION = [];
    }

    // -- Helpers --------------------------------------------------------------

    private function base64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /**
     * Build 37-byte authenticatorData for a get() assertion:
     * rpIdHash(32) + flags(1) + signCount(4, big-endian). No attested-credential
     * data and no extensions -- exactly what an authenticator emits on get().
     */
    private function buildAuthenticatorData(int $signCount, bool $userPresent = true): string
    {
        $rpIdHash = hash('sha256', self::RP_ID, true);
        $flags    = $userPresent ? 0x05 : 0x04; // bit0=UP, bit2=UV
        return $rpIdHash . chr($flags) . pack('N', $signCount);
    }

    private function buildClientDataJSON(string $challengeBin, string $origin): string
    {
        return (string) json_encode([
            'type'      => 'webauthn.get',
            'challenge' => $this->base64url($challengeBin),
            'origin'    => $origin,
        ]);
    }

    /** ECDSA-SHA256 sign of (authData . sha256(clientDataJSON)) -- what the authenticator does. */
    private function signAssertion(string $authData, string $clientDataJSON): string
    {
        $signed = $authData . hash('sha256', $clientDataJSON, true);
        $sig    = '';
        $ok     = openssl_sign($signed, $sig, $this->privateKey, OPENSSL_ALGO_SHA256);
        self::assertTrue($ok, 'openssl_sign must succeed');
        return $sig;
    }

    // -- Challenge generation -------------------------------------------------

    public function testDispatchChallengeReturnsFalseWithoutCredentials(): void
    {
        $db = $this->freshDb();
        $userId = $this->seedUser($db);
        // No credentials enrolled -> nothing to challenge against.
        self::assertFalse(ipam_passkey_dispatch_challenge($db, $userId));
        self::assertArrayNotHasKey('passkey_challenge', $_SESSION);
    }

    public function testDispatchChallengeStoresRandomChallengeInSession(): void
    {
        $db = $this->freshDb();
        $userId = $this->seedUser($db);
        $this->seedCredential($db, $userId);

        self::assertTrue(ipam_passkey_dispatch_challenge($db, $userId));

        self::assertArrayHasKey('passkey_challenge', $_SESSION);
        self::assertSame($userId, $_SESSION['passkey_pending_uid']);
        $challenge = $_SESSION['passkey_challenge'];
        self::assertIsString($challenge);
        // lbuchs default challenge length is 32 bytes of CSPRNG output.
        self::assertSame(32, strlen($challenge), 'challenge must be 32 raw bytes');

        // The challenge issued-at timestamp is stored for the 60s TTL check.
        self::assertArrayHasKey('passkey_challenge_issued_at', $_SESSION);
        self::assertIsInt($_SESSION['passkey_challenge_issued_at']);
    }

    public function testDispatchChallengeEncodesChallengeAsBase64UrlInOptions(): void
    {
        $db = $this->freshDb();
        $userId = $this->seedUser($db);
        $this->seedCredential($db, $userId);

        ipam_passkey_dispatch_challenge($db, $userId);

        // passkey_verify.php hands passkey_assertion_options straight to the
        // browser; its challenge field must be base64url of the raw bytes the
        // verify step compares against.
        $opts = json_decode((string) $_SESSION['passkey_assertion_options'], true);
        self::assertIsArray($opts);
        self::assertArrayHasKey('challenge', $opts);
        self::assertSame(
            $this->base64url((string) $_SESSION['passkey_challenge']),
            $opts['challenge'],
            'options.challenge must be base64url of the session challenge'
        );
        // base64url must contain no '+', '/' or '=' padding.
        self::assertDoesNotMatchRegularExpression('#[+/=]#', (string) $opts['challenge']);
    }

    public function testTwoChallengesDiffer(): void
    {
        $db = $this->freshDb();
        $userId = $this->seedUser($db);
        $this->seedCredential($db, $userId);

        ipam_passkey_dispatch_challenge($db, $userId);
        $first = $_SESSION['passkey_challenge'];
        $_SESSION = [];
        ipam_passkey_dispatch_challenge($db, $userId);
        $second = $_SESSION['passkey_challenge'];

        self::assertNotSame($first, $second, 'each dispatch must mint a fresh challenge');
    }

    public function testDispatchChallengeListsEnrolledCredentialInAllowList(): void
    {
        $db = $this->freshDb();
        $userId = $this->seedUser($db);
        $credIdBin = $this->seedCredential($db, $userId);

        ipam_passkey_dispatch_challenge($db, $userId);

        $opts = json_decode((string) $_SESSION['passkey_assertion_options'], true);
        self::assertIsArray($opts);
        self::assertArrayHasKey('allowCredentials', $opts);
        $ids = array_column($opts['allowCredentials'], 'id');
        self::assertContains(
            $this->base64url($credIdBin),
            $ids,
            'the enrolled credential id (base64url) must appear in allowCredentials'
        );
    }

    // -- Assertion (login) verification -- genuine end-to-end -----------------

    public function testValidAssertionVerifies(): void
    {
        $webAuthn  = ipam_passkey_webauthn('Test RP');
        $challenge = random_bytes(32);
        $authData  = $this->buildAuthenticatorData(1);
        $clientDataJSON = $this->buildClientDataJSON($challenge, 'http://localhost');
        $signature = $this->signAssertion($authData, $clientDataJSON);

        // prevSignatureCnt=0 (just-registered), new counter=1 -> valid.
        $result = $webAuthn->processGet(
            $clientDataJSON,
            $authData,
            $signature,
            $this->publicKeyPem,
            new \lbuchs\WebAuthn\Binary\ByteBuffer($challenge),
            0,
            false,
            true
        );
        self::assertTrue($result, 'a correctly-signed assertion must verify');
        self::assertSame(1, $webAuthn->getSignatureCounter());
    }

    public function testTamperedSignatureRejected(): void
    {
        $webAuthn  = ipam_passkey_webauthn('Test RP');
        $challenge = random_bytes(32);
        $authData  = $this->buildAuthenticatorData(1);
        $clientDataJSON = $this->buildClientDataJSON($challenge, 'http://localhost');
        $signature = $this->signAssertion($authData, $clientDataJSON);

        // Flip a byte in the middle of the DER signature.
        $bad = $signature;
        $bad[10] = chr(ord($bad[10]) ^ 0xFF);

        $this->expectException(\lbuchs\WebAuthn\WebAuthnException::class);
        $webAuthn->processGet(
            $clientDataJSON,
            $authData,
            $bad,
            $this->publicKeyPem,
            new \lbuchs\WebAuthn\Binary\ByteBuffer($challenge),
            0,
            false,
            true
        );
    }

    public function testAssertionSignedByDifferentKeyRejected(): void
    {
        $webAuthn  = ipam_passkey_webauthn('Test RP');
        $challenge = random_bytes(32);
        $authData  = $this->buildAuthenticatorData(1);
        $clientDataJSON = $this->buildClientDataJSON($challenge, 'http://localhost');
        $signature = $this->signAssertion($authData, $clientDataJSON);

        // A different (attacker) keypair's public key must not validate the
        // signature produced by the genuine credential.
        $other = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ]);
        self::assertNotFalse($other);
        $otherPem = (string) (openssl_pkey_get_details($other)['key'] ?? '');

        $this->expectException(\lbuchs\WebAuthn\WebAuthnException::class);
        $webAuthn->processGet(
            $clientDataJSON,
            $authData,
            $signature,
            $otherPem,
            new \lbuchs\WebAuthn\Binary\ByteBuffer($challenge),
            0,
            false,
            true
        );
    }

    public function testWrongChallengeRejected(): void
    {
        $webAuthn  = ipam_passkey_webauthn('Test RP');
        $challenge = random_bytes(32);
        $authData  = $this->buildAuthenticatorData(1);
        // clientDataJSON embeds the genuine challenge ...
        $clientDataJSON = $this->buildClientDataJSON($challenge, 'http://localhost');
        $signature = $this->signAssertion($authData, $clientDataJSON);

        // ... but the server compares against a DIFFERENT expected challenge
        // (e.g. a replay of a stale assertion against a fresh session).
        $this->expectException(\lbuchs\WebAuthn\WebAuthnException::class);
        $webAuthn->processGet(
            $clientDataJSON,
            $authData,
            $signature,
            $this->publicKeyPem,
            new \lbuchs\WebAuthn\Binary\ByteBuffer(random_bytes(32)),
            0,
            false,
            true
        );
    }

    public function testWrongOriginRejected(): void
    {
        $webAuthn  = ipam_passkey_webauthn('Test RP');
        $challenge = random_bytes(32);
        $authData  = $this->buildAuthenticatorData(1);
        // Origin belongs to an attacker's site, not the RP.
        $clientDataJSON = $this->buildClientDataJSON($challenge, 'https://evil.example.com');
        $signature = $this->signAssertion($authData, $clientDataJSON);

        $this->expectException(\lbuchs\WebAuthn\WebAuthnException::class);
        $webAuthn->processGet(
            $clientDataJSON,
            $authData,
            $signature,
            $this->publicKeyPem,
            new \lbuchs\WebAuthn\Binary\ByteBuffer($challenge),
            0,
            false,
            true
        );
    }

    public function testWrongRpIdHashRejected(): void
    {
        $webAuthn  = ipam_passkey_webauthn('Test RP');
        $challenge = random_bytes(32);
        // authenticatorData built with an rpIdHash for a different RP.
        $authData  = hash('sha256', 'attacker.example.com', true)
                   . chr(0x05) . pack('N', 1);
        $clientDataJSON = $this->buildClientDataJSON($challenge, 'http://localhost');
        $signature = $this->signAssertion($authData, $clientDataJSON);

        $this->expectException(\lbuchs\WebAuthn\WebAuthnException::class);
        $webAuthn->processGet(
            $clientDataJSON,
            $authData,
            $signature,
            $this->publicKeyPem,
            new \lbuchs\WebAuthn\Binary\ByteBuffer($challenge),
            0,
            false,
            true
        );
    }

    public function testUserPresentBitRequiredWhenRequested(): void
    {
        $webAuthn  = ipam_passkey_webauthn('Test RP');
        $challenge = random_bytes(32);
        // userPresent=false clears the UP flag bit.
        $authData  = $this->buildAuthenticatorData(1, false);
        $clientDataJSON = $this->buildClientDataJSON($challenge, 'http://localhost');
        $signature = $this->signAssertion($authData, $clientDataJSON);

        // passkey_verify.php calls processGet(..., requireUserPresent=true).
        $this->expectException(\lbuchs\WebAuthn\WebAuthnException::class);
        $webAuthn->processGet(
            $clientDataJSON,
            $authData,
            $signature,
            $this->publicKeyPem,
            new \lbuchs\WebAuthn\Binary\ByteBuffer($challenge),
            0,
            false,
            true
        );
    }

    // -- Sign-count update / clone detection ----------------------------------

    public function testSignCounterIncrementsAcrossAssertions(): void
    {
        $challenge = random_bytes(32);
        $authData  = $this->buildAuthenticatorData(7);
        $clientDataJSON = $this->buildClientDataJSON($challenge, 'http://localhost');
        $signature = $this->signAssertion($authData, $clientDataJSON);

        $webAuthn = ipam_passkey_webauthn('Test RP');
        $webAuthn->processGet(
            $clientDataJSON,
            $authData,
            $signature,
            $this->publicKeyPem,
            new \lbuchs\WebAuthn\Binary\ByteBuffer($challenge),
            3, // previous stored counter
            false,
            true
        );
        // New counter (7) > previous (3): accepted and reported as the new value.
        self::assertSame(7, $webAuthn->getSignatureCounter());
    }

    public function testClonedAuthenticatorDetectedWhenCounterGoesBackwards(): void
    {
        $challenge = random_bytes(32);
        $authData  = $this->buildAuthenticatorData(2); // counter regressed
        $clientDataJSON = $this->buildClientDataJSON($challenge, 'http://localhost');
        $signature = $this->signAssertion($authData, $clientDataJSON);

        $webAuthn = ipam_passkey_webauthn('Test RP');
        // W3C step 17: prevSignCount (10) >= newSignCount (2) -> clone signal.
        $this->expectException(\lbuchs\WebAuthn\WebAuthnException::class);
        $this->expectExceptionCode(\lbuchs\WebAuthn\WebAuthnException::SIGNATURE_COUNTER);
        $webAuthn->processGet(
            $clientDataJSON,
            $authData,
            $signature,
            $this->publicKeyPem,
            new \lbuchs\WebAuthn\Binary\ByteBuffer($challenge),
            10,
            false,
            true
        );
    }

    public function testEqualCounterTreatedAsCloneSignal(): void
    {
        $challenge = random_bytes(32);
        $authData  = $this->buildAuthenticatorData(5); // equal to previous
        $clientDataJSON = $this->buildClientDataJSON($challenge, 'http://localhost');
        $signature = $this->signAssertion($authData, $clientDataJSON);

        $webAuthn = ipam_passkey_webauthn('Test RP');
        // prevSignCount >= newSignCount is non-strict: equal counters also
        // trip the clone detector (the genuine authenticator would have
        // incremented).
        $this->expectException(\lbuchs\WebAuthn\WebAuthnException::class);
        $this->expectExceptionCode(\lbuchs\WebAuthn\WebAuthnException::SIGNATURE_COUNTER);
        $webAuthn->processGet(
            $clientDataJSON,
            $authData,
            $signature,
            $this->publicKeyPem,
            new \lbuchs\WebAuthn\Binary\ByteBuffer($challenge),
            5,
            false,
            true
        );
    }

    public function testZeroCounterAuthenticatorBypassesCloneDetection(): void
    {
        // Authenticators that never increment a counter always emit 0.
        // W3C step 17 only fires when either counter is non-zero, so a 0/0
        // pair must NOT be flagged as a clone.
        $challenge = random_bytes(32);
        $authData  = $this->buildAuthenticatorData(0);
        $clientDataJSON = $this->buildClientDataJSON($challenge, 'http://localhost');
        $signature = $this->signAssertion($authData, $clientDataJSON);

        $webAuthn = ipam_passkey_webauthn('Test RP');
        $result = $webAuthn->processGet(
            $clientDataJSON,
            $authData,
            $signature,
            $this->publicKeyPem,
            new \lbuchs\WebAuthn\Binary\ByteBuffer($challenge),
            0,
            false,
            true
        );
        self::assertTrue($result, 'a 0/0 counter pair must not be a clone signal');
        // getSignatureCounter() stays null when the authenticator reports 0.
        self::assertNull($webAuthn->getSignatureCounter());
    }

    public function testUpdateSignCountPersistsNewValue(): void
    {
        $db = $this->freshDb();
        $userId = $this->seedUser($db);
        $this->seedCredential($db, $userId, 3);

        $cred = ipam_passkey_get_credentials($db, $userId)[0];
        self::assertSame(3, (int) $cred['sign_count']);

        // The value passkey_verify.php would persist after a successful login.
        ipam_passkey_update_sign_count($db, (int) $cred['id'], 9);

        $after = ipam_passkey_get_credentials($db, $userId)[0];
        self::assertSame(9, (int) $after['sign_count'], 'sign_count must persist the new value');
        self::assertNotNull($after['last_used_at'], 'last_used_at must be stamped on update');
    }

    public function testFindByCredentialIdRoundTripsBinaryId(): void
    {
        $db = $this->freshDb();
        $userId = $this->seedUser($db);
        // Binary credential id with high bits and a null byte -- the production
        // shape that PARAM_LOB binding must round-trip.
        $credIdBin = "\x00\xff\x10" . random_bytes(29);
        $this->seedCredential($db, $userId, 0, $credIdBin);

        $found = ipam_passkey_find_by_credential_id($db, $credIdBin);
        self::assertIsArray($found);
        self::assertSame($userId, (int) $found['user_id']);

        // An unknown id must return null (no false-positive credential match).
        self::assertNull(ipam_passkey_find_by_credential_id($db, random_bytes(32)));
    }

    // -- Attestation wrapper boundary (fixture-limited -- see class docblock) -

    public function testWebAuthnObjectStripsPortFromRpId(): void
    {
        $_SERVER['HTTP_HOST'] = 'ipam.example.com:8443';
        // Construction must succeed: the port is stripped, leaving a valid RP ID.
        $webAuthn = ipam_passkey_webauthn('Test');
        self::assertInstanceOf(\lbuchs\WebAuthn\WebAuthn::class, $webAuthn);
    }

    public function testWebAuthnObjectMapsLoopbackToLocalhost(): void
    {
        $_SERVER['HTTP_HOST'] = '127.0.0.1:8080';
        // 127.0.0.1 is an IP literal but is remapped to 'localhost' rather
        // than rejected, so construction must succeed.
        $webAuthn = ipam_passkey_webauthn('Test');
        self::assertInstanceOf(\lbuchs\WebAuthn\WebAuthn::class, $webAuthn);
    }

    public function testWebAuthnObjectStripsIpv6Brackets(): void
    {
        $_SERVER['HTTP_HOST'] = '[::1]:8443';
        // [::1] -> ::1 -> remapped to localhost.
        $webAuthn = ipam_passkey_webauthn('Test');
        self::assertInstanceOf(\lbuchs\WebAuthn\WebAuthn::class, $webAuthn);
    }

    public function testWebAuthnObjectRejectsNonLoopbackIpLiteral(): void
    {
        // IP addresses are not valid WebAuthn RP IDs per the W3C spec; the
        // wrapper rejects them early with a clear configuration error.
        $_SERVER['HTTP_HOST'] = '192.168.1.50';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('IP addresses are not valid WebAuthn RP IDs');
        ipam_passkey_webauthn('Test');
    }

    // -- DB fixtures ----------------------------------------------------------

    private function freshDb(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $schema = (string) file_get_contents(__DIR__ . '/../../Simple-PHP-IPAM/schema.sql');
        $db->exec($schema);
        $db->exec('PRAGMA foreign_keys = ON');
        ensure_migrations_table($db);
        apply_migrations($db);
        return $db;
    }

    private function seedUser(PDO $db): int
    {
        $db->exec(
            "INSERT INTO users (username, password_hash, role, is_active) " .
            "VALUES ('passkey-user', '!disabled', 'admin', 1)"
        );
        return (int) $db->lastInsertId();
    }

    /** Insert one webauthn_credentials row; returns the binary credential id. */
    private function seedCredential(
        PDO $db,
        int $userId,
        int $signCount = 0,
        ?string $credIdBin = null
    ): string {
        $credIdBin ??= random_bytes(32);
        $st = $db->prepare(
            "INSERT INTO webauthn_credentials (user_id, credential_id, public_key, sign_count, name) " .
            "VALUES (:uid, :cid, :pk, :sc, :nm)"
        );
        $st->bindValue(':uid', $userId, PDO::PARAM_INT);
        $st->bindValue(':cid', $credIdBin, PDO::PARAM_LOB);
        $st->bindValue(':pk',  $this->publicKeyPem, PDO::PARAM_STR);
        $st->bindValue(':sc',  $signCount, PDO::PARAM_INT);
        $st->bindValue(':nm',  'Test Passkey', PDO::PARAM_STR);
        $st->execute();
        return $credIdBin;
    }
}
