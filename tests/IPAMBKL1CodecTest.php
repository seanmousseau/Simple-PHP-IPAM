<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Simple-PHP-IPAM/lib/backup.php';

/**
 * Round-trip tests for the IPAMBKL1 abstract-type codec.
 *
 * Spec: docs/internal/ipambkl1-format.md → "Abstract type encoding".
 * Functions under test: ipam_logical_encode_value() / ipam_logical_decode_value().
 *
 * The codec is the bottom of the IPAMBKL1 stack — every body row's columns
 * pass through it on dump and again on restore. Round-trip fidelity is the
 * load-bearing invariant the rest of the format depends on.
 *
 * Binary blob test vectors mirror CLAUDE.md "Round-trip test vectors that
 * any new driver binding must handle correctly":
 *   - inet_pton('10.0.0.0')         — \x0A\x00\x00\x00, null bytes after first
 *   - inet_pton('2001:db8::')       — mostly null bytes
 *   - inet_pton('255.255.255.255')  — all high bytes
 *
 * The codec works on raw byte strings (engine-agnostic); the per-driver
 * binding (PARAM_LOB) is asserted separately in BinaryBindTest.
 */
class IPAMBKL1CodecTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Scalars
    // -----------------------------------------------------------------------

    public function testIntegerRoundTrip(): void
    {
        foreach ([0, 1, -1, 42, 2147483647, -2147483648, PHP_INT_MAX] as $n) {
            $encoded = ipam_logical_encode_value($n);
            $this->assertSame($n, ipam_logical_decode_value($encoded), "int $n round-trip");
        }
    }

    public function testStringRoundTrip(): void
    {
        $cases = [
            'plain ascii',
            '',
            "embedded\nnewline",
            "embedded\ttab",
            "embedded \"quote\" and 'single'",
            'unicode: café — résumé — 日本語 — 🎉',
            "null byte in middle: \x00 still text",
        ];
        foreach ($cases as $s) {
            $encoded = ipam_logical_encode_value($s);
            $this->assertSame($s, ipam_logical_decode_value($encoded), 'string round-trip');
        }
    }

    public function testBoolRoundTrip(): void
    {
        $this->assertTrue(ipam_logical_decode_value(ipam_logical_encode_value(true)));
        $this->assertFalse(ipam_logical_decode_value(ipam_logical_encode_value(false)));
    }

    public function testNullRoundTrip(): void
    {
        $this->assertNull(ipam_logical_decode_value(ipam_logical_encode_value(null)));
    }

    // -----------------------------------------------------------------------
    // Boolean / null disambiguation — these are easy to fumble in PHP because
    // (bool) 0 === false, (string) null === '', etc. The encode/decode pair
    // must preserve the distinct types verbatim.
    // -----------------------------------------------------------------------

    public function testBoolDistinctFromIntZeroOne(): void
    {
        $this->assertNotSame(0, ipam_logical_decode_value(ipam_logical_encode_value(false)));
        $this->assertNotSame(1, ipam_logical_decode_value(ipam_logical_encode_value(true)));
    }

    public function testNullDistinctFromEmptyString(): void
    {
        $this->assertNotSame('', ipam_logical_decode_value(ipam_logical_encode_value(null)));
    }

    public function testEmptyStringDistinctFromNull(): void
    {
        $this->assertNotNull(ipam_logical_decode_value(ipam_logical_encode_value('')));
    }

    // -----------------------------------------------------------------------
    // Binary blobs — the load-bearing case. CLAUDE.md test vectors.
    // -----------------------------------------------------------------------

    public function testBinaryIPv4LowBytesRoundTrip(): void
    {
        $bin = inet_pton('10.0.0.0');
        $this->assertNotFalse($bin);
        $encoded = ipam_logical_encode_value($bin, /* isBinary */ true);
        $decoded = ipam_logical_decode_value($encoded);
        $this->assertSame($bin, $decoded, 'IPv4 \x0A\x00\x00\x00 round-trip');
    }

    public function testBinaryIPv4AllHighBytesRoundTrip(): void
    {
        $bin = inet_pton('255.255.255.255');
        $this->assertNotFalse($bin);
        $encoded = ipam_logical_encode_value($bin, /* isBinary */ true);
        $this->assertSame($bin, ipam_logical_decode_value($encoded), 'IPv4 \xFF\xFF\xFF\xFF round-trip');
    }

    public function testBinaryIPv6MostlyNullRoundTrip(): void
    {
        $bin = inet_pton('2001:db8::');
        $this->assertNotFalse($bin);
        $encoded = ipam_logical_encode_value($bin, /* isBinary */ true);
        $this->assertSame($bin, ipam_logical_decode_value($encoded), 'IPv6 mostly-null round-trip');
    }

    public function testBinaryEnvelopeShape(): void
    {
        // Per spec §"Abstract type encoding", binary uses {"$bin": "<base64>"}.
        $bin = "\x01\x02\x03\xFF";
        $encoded = ipam_logical_encode_value($bin, /* isBinary */ true);
        $this->assertIsArray($encoded, 'binary encodes to array (JSON object after json_encode)');
        $this->assertArrayHasKey('$bin', $encoded);
        $this->assertSame(base64_encode($bin), $encoded['$bin']);
        $this->assertCount(1, $encoded, 'envelope has exactly one key');
    }

    public function testEmptyBinaryRoundTrip(): void
    {
        $encoded = ipam_logical_encode_value('', /* isBinary */ true);
        $decoded = ipam_logical_decode_value($encoded);
        $this->assertSame('', $decoded, 'empty binary blob round-trip');
    }

    // -----------------------------------------------------------------------
    // Timestamps — ISO-8601 with explicit Z. The codec accepts engine-native
    // datetime strings on encode (different drivers emit slightly different
    // formats) and always normalises to ISO-8601 UTC on output.
    // -----------------------------------------------------------------------

    public function testTimestampRoundTripFromSqliteFormat(): void
    {
        // SQLite's datetime('now') format: 'YYYY-MM-DD HH:MM:SS' (UTC implied).
        $encoded = ipam_logical_encode_value('2026-04-12 09:00:00', /* isBinary */ false, /* isTimestamp */ true);
        $this->assertSame('2026-04-12T09:00:00Z', $encoded);

        // Decode round-trip yields the same canonical form.
        $this->assertSame('2026-04-12T09:00:00Z', ipam_logical_decode_value($encoded));
    }

    public function testTimestampRoundTripFromMysqlFormat(): void
    {
        // MySQL TIMESTAMP fetch: 'YYYY-MM-DD HH:MM:SS' — same as sqlite.
        $encoded = ipam_logical_encode_value('2026-04-12 09:00:00', false, true);
        $this->assertSame('2026-04-12T09:00:00Z', $encoded);
    }

    public function testTimestampPreservesIsoInputUnchanged(): void
    {
        $iso = '2026-04-12T09:00:00Z';
        $this->assertSame($iso, ipam_logical_encode_value($iso, false, true));
    }

    public function testNullTimestampStaysNull(): void
    {
        $this->assertNull(ipam_logical_encode_value(null, false, true));
        $this->assertNull(ipam_logical_decode_value(null));
    }

    // -----------------------------------------------------------------------
    // JSON-serialisability of every encoded value. A row is encoded by
    // running each column's value through encode_value and then json_encode'ing
    // the whole row object. Every encode output must json_encode without
    // information loss (no PHP resources, no closures, no recursive refs).
    // -----------------------------------------------------------------------

    public function testEveryEncodeOutputIsJsonSerialisable(): void
    {
        $samples = [
            ipam_logical_encode_value(42),
            ipam_logical_encode_value('text'),
            ipam_logical_encode_value(true),
            ipam_logical_encode_value(null),
            ipam_logical_encode_value("\x00\x01\x02", true),
            ipam_logical_encode_value('2026-04-12 09:00:00', false, true),
        ];
        foreach ($samples as $i => $v) {
            $j = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->assertNotFalse($j, "sample $i json_encode'd cleanly");
            $this->assertSame($v, json_decode($j, true), "sample $i round-trips through JSON");
        }
    }
}
