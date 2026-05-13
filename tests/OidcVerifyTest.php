<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Coverage for oidc_verify_id_token() and jwk_rsa_to_pem() — the only two
 * load-bearing primitives behind OIDC sign-in. Closes finding D2 from
 * archive/code_quality_review.md (issue #867).
 *
 * Keypairs are generated in setUpBeforeClass so there is no committed
 * private-key material in the repo. The test class itself is the spec for
 * what oidc_verify_id_token() must accept and reject.
 */
final class OidcVerifyTest extends TestCase
{
    /** @var array<string, array{private: \OpenSSLAsymmetricKey, jwk: array<string, string>}> */
    private static array $keys = [];

    /** @var list<array<string, string>> */
    private static array $jwks = [];

    public static function setUpBeforeClass(): void
    {
        foreach (['RS256' => 'sha256', 'RS384' => 'sha384', 'RS512' => 'sha512'] as $alg => $digest) {
            $kp = openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
                'digest_alg'       => $digest,
            ]);
            if ($kp === false) {
                throw new RuntimeException('openssl_pkey_new failed for ' . $alg);
            }
            $details = openssl_pkey_get_details($kp);
            if ($details === false) {
                throw new RuntimeException('openssl_pkey_get_details failed for ' . $alg);
            }
            $jwk = [
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => $alg,
                'kid' => 'test-' . strtolower($alg),
                'n'   => self::base64url($details['rsa']['n']),
                'e'   => self::base64url($details['rsa']['e']),
            ];
            self::$keys[$alg] = ['private' => $kp, 'jwk' => $jwk];
            self::$jwks[]     = $jwk;
        }
    }

    private static function base64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function signToken(string $alg, array $payload, ?string $kidOverride = null): string
    {
        $algMap = ['RS256' => OPENSSL_ALGO_SHA256, 'RS384' => OPENSSL_ALGO_SHA384, 'RS512' => OPENSSL_ALGO_SHA512];
        $kid    = $kidOverride ?? self::$keys[$alg]['jwk']['kid'];
        $header = ['alg' => $alg, 'typ' => 'JWT', 'kid' => $kid];
        $hdrB64 = self::base64url(json_encode($header, JSON_UNESCAPED_SLASHES));
        $payB64 = self::base64url(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signing = $hdrB64 . '.' . $payB64;
        $sig = '';
        openssl_sign($signing, $sig, self::$keys[$alg]['private'], $algMap[$alg]);
        return $signing . '.' . self::base64url($sig);
    }

    /**
     * @return array<string, mixed>
     */
    private static function basePayload(): array
    {
        return [
            'iss'   => 'https://issuer.test/',
            'aud'   => 'ipam-client',
            'sub'   => 'user-1',
            'iat'   => time() - 5,
            'exp'   => time() + 600,
            'email' => 'alice@example.com',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function expect(): array
    {
        return ['iss' => 'https://issuer.test/', 'aud' => 'ipam-client'];
    }

    public function testValidRs256TokenPasses(): void
    {
        $token   = self::signToken('RS256', self::basePayload());
        $payload = oidc_verify_id_token($token, self::$jwks, self::expect());
        $this->assertSame('alice@example.com', $payload['email']);
        $this->assertSame('user-1', $payload['sub']);
    }

    public function testValidRs384TokenPasses(): void
    {
        $token   = self::signToken('RS384', self::basePayload());
        $payload = oidc_verify_id_token($token, self::$jwks, self::expect());
        $this->assertSame('user-1', $payload['sub']);
    }

    public function testValidRs512TokenPasses(): void
    {
        $token   = self::signToken('RS512', self::basePayload());
        $payload = oidc_verify_id_token($token, self::$jwks, self::expect());
        $this->assertSame('user-1', $payload['sub']);
    }

    public function testExpiredTokenRejected(): void
    {
        $payload        = self::basePayload();
        $payload['exp'] = time() - 3600;
        $token          = self::signToken('RS256', $payload);
        $this->expectExceptionMessageMatches('/expired/i');
        oidc_verify_id_token($token, self::$jwks, self::expect());
    }

    public function testFutureIatRejected(): void
    {
        $payload        = self::basePayload();
        $payload['iat'] = time() + 3600;
        $token          = self::signToken('RS256', $payload);
        $this->expectExceptionMessageMatches('/iat is in the future/i');
        oidc_verify_id_token($token, self::$jwks, self::expect());
    }

    public function testWrongAudienceRejected(): void
    {
        $token = self::signToken('RS256', self::basePayload());
        $this->expectExceptionMessageMatches('/audience mismatch/i');
        oidc_verify_id_token($token, self::$jwks, ['iss' => 'https://issuer.test/', 'aud' => 'someone-else']);
    }

    public function testWrongIssuerRejected(): void
    {
        $token = self::signToken('RS256', self::basePayload());
        $this->expectExceptionMessageMatches('/issuer mismatch/i');
        oidc_verify_id_token($token, self::$jwks, ['iss' => 'https://other.test/', 'aud' => 'ipam-client']);
    }

    public function testNonceMismatchRejected(): void
    {
        $payload          = self::basePayload();
        $payload['nonce'] = 'expected-nonce';
        $token            = self::signToken('RS256', $payload);
        $this->expectExceptionMessageMatches('/nonce mismatch/i');
        oidc_verify_id_token(
            $token,
            self::$jwks,
            ['iss' => 'https://issuer.test/', 'aud' => 'ipam-client', 'nonce' => 'different']
        );
    }

    public function testUnknownKidRejected(): void
    {
        $token = self::signToken('RS256', self::basePayload(), 'no-such-kid');
        $this->expectExceptionMessageMatches('/no matching rsa jwk/i');
        oidc_verify_id_token($token, self::$jwks, self::expect());
    }

    public function testTamperedSignatureRejected(): void
    {
        $token = self::signToken('RS256', self::basePayload());
        // Flip a byte in the payload section without re-signing
        $parts          = explode('.', $token);
        $parts[1]       = self::base64url(json_encode(['sub' => 'attacker'] + self::basePayload()));
        $tampered       = implode('.', $parts);
        $this->expectExceptionMessageMatches('/signature invalid/i');
        oidc_verify_id_token($tampered, self::$jwks, self::expect());
    }

    public function testMalformedJwtRejected(): void
    {
        $this->expectExceptionMessageMatches('/malformed jwt/i');
        oidc_verify_id_token('not.a.valid.token.at.all', self::$jwks, self::expect());
    }

    public function testUnsupportedAlgRejected(): void
    {
        // Build a header claiming HS256 (symmetric) — must be rejected
        $hdr = self::base64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => 'test-rs256']));
        $pay = self::base64url(json_encode(self::basePayload()));
        $sig = self::base64url(hash_hmac('sha256', $hdr . '.' . $pay, 'shared-secret', true));
        $this->expectExceptionMessageMatches('/unsupported jwt alg/i');
        oidc_verify_id_token($hdr . '.' . $pay . '.' . $sig, self::$jwks, self::expect());
    }

    public function testJwkLeadingBitModulusRoundTrips(): void
    {
        // Find a fixture with high bit set in modulus first byte (very common for
        // 2048-bit keys but not guaranteed). If none of the generated keys have it,
        // skip — the regression we care about is that jwk_rsa_to_pem does the
        // ASN.1 sign-padding correctly when it does occur, which the valid-token
        // tests above already exercise opportunistically.
        $found = false;
        foreach (self::$jwks as $jwk) {
            $n = base64_decode(strtr($jwk['n'], '-_', '+/') . str_repeat('=', (4 - strlen($jwk['n']) % 4) % 4));
            if ($n !== false && (ord($n[0]) & 0x80) !== 0) {
                $pem = jwk_rsa_to_pem($jwk);
                $this->assertNotFalse(openssl_pkey_get_public($pem), 'leading-bit modulus must round-trip via jwk_rsa_to_pem');
                $found = true;
                break;
            }
        }
        if (!$found) {
            $this->markTestSkipped('No generated key had the high-bit-set modulus this run; covered by valid-token tests.');
        }
    }

    public function testJwkMissingFieldsRejected(): void
    {
        $this->expectExceptionMessageMatches('/missing n or e/i');
        jwk_rsa_to_pem(['kty' => 'RSA']);
    }
}
