<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for #762: ipam_backup_apply_retention() must accept
 * and forward $nowEpoch to ipam_gfs_select_for_deletion(), so retention
 * timing is aligned to the cron tick rather than drifting with PHP's
 * time() across a long request. The selector itself is exhaustively
 * tested by BackupRetentionTest; here we lock in the wrapper's contract.
 */
final class BackupRetentionWrapperTest extends TestCase
{
    public function testWrapperAcceptsNowEpochParameter(): void
    {
        $reflection = new ReflectionFunction('ipam_backup_apply_retention');
        $params = $reflection->getParameters();
        $this->assertCount(3, $params, 'expected (PDO, int, ?int) signature');
        $this->assertSame('nowEpoch', $params[2]->getName());
        $this->assertTrue($params[2]->isOptional(), 'nowEpoch must be optional');
        $this->assertTrue($params[2]->allowsNull(), 'nowEpoch must allow null');
        $type = $params[2]->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame('int', $type->getName());
    }

    public function testWrapperForwardsNowEpochToSelector(): void
    {
        // Static-source assertion: the call site at $toDelete = ipam_gfs_select_for_deletion(...)
        // must pass $nowEpoch as the third argument. Locks in the forward, which is
        // the entire point of the parameter — without it, the selector falls back
        // to time() and the wrapper's nowEpoch is silently ignored.
        $source = file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/lib.php');
        $this->assertIsString($source);
        $this->assertMatchesRegularExpression(
            '/ipam_gfs_select_for_deletion\s*\(\s*\$rows\s*,\s*\$gfsConfig\s*,\s*\$nowEpoch\s*\)/',
            $source,
            'ipam_backup_apply_retention must forward $nowEpoch to ipam_gfs_select_for_deletion'
        );
    }
}
