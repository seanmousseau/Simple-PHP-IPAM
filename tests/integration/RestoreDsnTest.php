<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Simple-PHP-IPAM/lib.php'; // for to_str()
require_once __DIR__ . '/../../Simple-PHP-IPAM/lib/restore_dsn.php';

final class RestoreDsnTest extends TestCase
{
    public function testParsesMysqlDsn(): void
    {
        $conn = ipam_restore_resolve_db_conn([
            'db_dsn'  => 'mysql:host=db.internal;port=3307;dbname=ipam_prod',
            'db_user' => 'ipam',
            'db_pass' => 'p',
        ]);
        $this->assertSame('mysql', $conn['driver']);
        $this->assertSame('db.internal', $conn['host']);
        $this->assertSame('3307', $conn['port']);
        $this->assertSame('ipam_prod', $conn['dbname']);
        $this->assertSame('ipam', $conn['user']);
        $this->assertSame('p', $conn['pass']);
    }

    public function testParsesPgsqlDsn(): void
    {
        $conn = ipam_restore_resolve_db_conn([
            'db_dsn'  => 'pgsql:host=pg.internal;port=5433;dbname=ipam',
            'db_user' => 'ipam',
            'db_pass' => 'p',
        ]);
        $this->assertSame('pgsql', $conn['driver']);
        $this->assertSame('pg.internal', $conn['host']);
        $this->assertSame('5433', $conn['port']);
        $this->assertSame('ipam', $conn['dbname']);
    }

    public function testFallsBackToDiscreteKeysWhenNoDsn(): void
    {
        $conn = ipam_restore_resolve_db_conn([
            'db_host' => '10.0.0.5',
            'db_port' => '3306',
            'db_name' => 'ipam',
            'db_user' => 'root',
            'db_pass' => '',
        ]);
        $this->assertSame('10.0.0.5', $conn['host']);
        $this->assertSame('3306', $conn['port']);
        $this->assertSame('ipam', $conn['dbname']);
        $this->assertSame('root', $conn['user']);
    }

    public function testDefaultsWhenEverythingMissing(): void
    {
        $conn = ipam_restore_resolve_db_conn([]);
        $this->assertSame('127.0.0.1', $conn['host']);
        $this->assertSame('3306', $conn['port']); // mysql default since no driver hint
        $this->assertSame('ipam', $conn['dbname']);
        $this->assertSame('', $conn['unix_socket']);
    }

    public function testExtractsUnixSocketDsn(): void
    {
        // restore.php cannot pipe mysql/psql through a unix socket; the
        // parser must surface the value so the caller can detect and abort
        // with a clear error rather than silently fall back to TCP.
        $conn = ipam_restore_resolve_db_conn([
            'db_dsn' => 'mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=ipam',
        ]);
        $this->assertSame('/var/run/mysqld/mysqld.sock', $conn['unix_socket']);
        $this->assertSame('ipam', $conn['dbname']);
    }

    public function testExtraDsnKeysAreIgnored(): void
    {
        // Forward-compat: unknown keys (charset, sslmode, ...) must not break
        // host/port/dbname extraction.
        $conn = ipam_restore_resolve_db_conn([
            'db_dsn' => 'mysql:host=db;port=3306;dbname=ipam;charset=utf8mb4;sslmode=require',
        ]);
        $this->assertSame('db', $conn['host']);
        $this->assertSame('3306', $conn['port']);
        $this->assertSame('ipam', $conn['dbname']);
    }
}
