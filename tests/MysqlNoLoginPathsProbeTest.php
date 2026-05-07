<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Simple-PHP-IPAM/lib/backup.php';

/**
 * #1081 — `--no-login-paths` probe + call-site wiring.
 *
 * The probe shells out to mysqldump/mysql and inspects --help for the
 * flag. Behavioural assertion is best-effort because the host's installed
 * client may or may not have the flag. What we *can* pin without depending
 * on the host's client version:
 *
 *   - The function takes a single binary argument and rejects unknown
 *     values (no attacker-controlled argv leak).
 *   - The result type is always bool.
 *   - The result is cached per-binary across calls.
 *
 * Plus a source-scan guard (BackupNotifyWiringTest pattern) that pins the
 * three call sites listed in the issue acceptance.
 */
class MysqlNoLoginPathsProbeTest extends TestCase
{
    public function testReturnsBoolForKnownBinaries(): void
    {
        $r1 = ipam_mysql_client_supports_no_login_paths('mysqldump');
        $r2 = ipam_mysql_client_supports_no_login_paths('mysql');
        $this->assertIsBool($r1);
        $this->assertIsBool($r2);
    }

    public function testRejectsUnknownBinary(): void
    {
        // Anything other than mysqldump/mysql must short-circuit to false
        // — guards against a caller forwarding user input as the binary
        // and turning the helper into an argv-smuggling vector.
        $this->assertFalse(ipam_mysql_client_supports_no_login_paths('cat'));
        $this->assertFalse(ipam_mysql_client_supports_no_login_paths(''));
        $this->assertFalse(ipam_mysql_client_supports_no_login_paths('/bin/sh'));
    }

    public function testIsCachedAcrossCalls(): void
    {
        // Two back-to-back calls must agree (the cache is per-process; we
        // assert consistency, not the cached value itself).
        $a = ipam_mysql_client_supports_no_login_paths('mysqldump');
        $b = ipam_mysql_client_supports_no_login_paths('mysqldump');
        $this->assertSame($a, $b);
    }

    public function testNativeCmdBuilderCallsProbe(): void
    {
        $src = $this->fnSource('ipam_backup_native_cmd');
        $this->assertStringContainsString(
            "ipam_mysql_client_supports_no_login_paths('mysqldump')",
            $src,
            'lib/backup.php native_cmd must conditionally append --no-login-paths'
        );
        $this->assertStringContainsString(
            "'--no-login-paths'",
            $src,
            'native_cmd must add the literal flag to the argv when supported'
        );
    }

    // v3.26.0 (#1059): the legacy v3.7 run_db_backup_if_due() runner was
    // retired. The unified ipam_backup_native_cmd() builder above is now
    // the single mysqldump entry point, so probe-call coverage there is
    // sufficient.

    public function testRestorePhpCallsProbeForMysqlClient(): void
    {
        // restore.php is procedural top-level (no enclosing function), so
        // pin via a literal-bytes source scan rather than ReflectionFunction.
        $contents = (string) file_get_contents(
            __DIR__ . '/../Simple-PHP-IPAM/restore.php'
        );
        $this->assertStringContainsString(
            "ipam_mysql_client_supports_no_login_paths('mysql')",
            $contents,
            'restore.php mysql path must call the probe with the mysql binary'
        );
    }

    private function fnSource(string $name): string
    {
        $rfn  = new ReflectionFunction($name);
        $file = $rfn->getFileName();
        $a    = $rfn->getStartLine();
        $b    = $rfn->getEndLine();
        $this->assertNotFalse($file);
        $body = (string) file_get_contents((string) $file);
        $lines = explode("\n", $body);
        return implode("\n", array_slice($lines, $a - 1, $b - $a + 1));
    }
}
