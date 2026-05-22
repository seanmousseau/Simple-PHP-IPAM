<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Simple-PHP-IPAM/dialects/Dialect.php';
require_once __DIR__ . '/../../Simple-PHP-IPAM/dialects/DialectValidator.php';
require_once __DIR__ . '/../../Simple-PHP-IPAM/dialects/SqliteDialect.php';
require_once __DIR__ . '/../../Simple-PHP-IPAM/dialects/PgsqlDialect.php';
require_once __DIR__ . '/../../Simple-PHP-IPAM/dialects/MysqlDialect.php';

/**
 * v3.29.0 #1105 — Dialect::upsert_or_ignore() contract tightening.
 *
 * #1100 retro: column names must be bare safe identifiers; quoted /
 * backticked / SQL-injection-shaped input is rejected up-front via
 * DialectValidator::assertBareIdentifiers() rather than interpolated
 * raw into the emitted ON CONFLICT / ON DUPLICATE KEY UPDATE clause.
 *
 * Engine-level emission shapes (`testSqliteEmitsExpectedFragment` etc.)
 * pin the current output so a future refactor that accidentally
 * reformats the suffix fails noisily.
 */
final class DialectUpsertOrIgnoreContractTest extends TestCase
{
    /** @return iterable<string, array{class-string<Dialect>}> */
    public static function dialectsProvider(): iterable
    {
        yield 'sqlite' => [SqliteDialect::class];
        yield 'pgsql'  => [PgsqlDialect::class];
        yield 'mysql'  => [MysqlDialect::class];
    }

    /**
     * @dataProvider dialectsProvider
     * @param class-string<Dialect> $cls
     */
    public function testEmptyColumnsListRejected(string $cls): void
    {
        /** @var Dialect $d */
        $d = new $cls();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least one conflict column/');
        $d->upsert_or_ignore('settings', []);
    }

    /**
     * @dataProvider dialectsProvider
     * @param class-string<Dialect> $cls
     */
    public function testQuotedColumnRejected(string $cls): void
    {
        /** @var Dialect $d */
        $d = new $cls();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/bare identifier/');
        $d->upsert_or_ignore('settings', ['"key"']);
    }

    /**
     * @dataProvider dialectsProvider
     * @param class-string<Dialect> $cls
     */
    public function testBacktickedColumnRejected(string $cls): void
    {
        /** @var Dialect $d */
        $d = new $cls();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/bare identifier/');
        $d->upsert_or_ignore('settings', ['`key`']);
    }

    /**
     * @dataProvider dialectsProvider
     * @param class-string<Dialect> $cls
     */
    public function testSqlInjectionShapeRejected(string $cls): void
    {
        /** @var Dialect $d */
        $d = new $cls();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/bare identifier/');
        $d->upsert_or_ignore('settings', ['1; DROP TABLE settings; --']);
    }

    /**
     * @dataProvider dialectsProvider
     * @param class-string<Dialect> $cls
     */
    public function testSpaceInColumnRejected(string $cls): void
    {
        /** @var Dialect $d */
        $d = new $cls();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/bare identifier/');
        $d->upsert_or_ignore('settings', ['col name']);
    }

    /**
     * @dataProvider dialectsProvider
     * @param class-string<Dialect> $cls
     */
    public function testBareIdentifierAccepted(string $cls): void
    {
        /** @var Dialect $d */
        $d = new $cls();
        $sql = $d->upsert_or_ignore('settings', ['key', 'value']);
        $this->assertIsString($sql);
        $this->assertNotSame('', $sql);
    }

    public function testSqliteEmitsExpectedFragment(): void
    {
        $d = new SqliteDialect();
        $this->assertSame(
            'ON CONFLICT(subnet_id, ip) DO NOTHING',
            $d->upsert_or_ignore('addresses', ['subnet_id', 'ip'])
        );
    }

    public function testPgsqlEmitsExpectedFragment(): void
    {
        $d = new PgsqlDialect();
        $this->assertSame(
            'ON CONFLICT (subnet_id, ip) DO NOTHING',
            $d->upsert_or_ignore('addresses', ['subnet_id', 'ip'])
        );
    }

    public function testMysqlEmitsExpectedFragment(): void
    {
        $d = new MysqlDialect();
        $this->assertSame(
            'ON DUPLICATE KEY UPDATE `subnet_id` = `subnet_id`',
            $d->upsert_or_ignore('addresses', ['subnet_id', 'ip'])
        );
    }
}
