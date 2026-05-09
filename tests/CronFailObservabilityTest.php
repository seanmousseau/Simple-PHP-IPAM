<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * Observability fix O1 (Pass A 2026-05-08, v3.27.1).
 *
 * The cron `$fail` closure historically wrote ONLY to STDERR. On every
 * production cron entry of the shape `> /dev/null 2>&1` (which is what
 * IPAM's deploy-targets.md recommended), the failure message
 * disappeared entirely — letting the encrypt-write-path bug fail
 * silently for two weeks before the operator noticed missing backups.
 *
 * Fix: $fail must record across THREE channels:
 *   1. STDERR (preserved — for hosts that capture cron output)
 *   2. error_log() (lands in PHP/SAPI log file, survives stderr blackhole)
 *   3. audit_log row tagged action='cron.task_failed'
 *
 * Source-level contract test. Source-level rather than runtime because
 * cron.php is a top-level script, not a function — it requires init.php,
 * opens DB, etc. Reflection-based wiring assertion is the right shape
 * for this layer (same pattern as BackupNotifyWiringTest).
 */
final class CronFailObservabilityTest extends TestCase
{
    private function cronSource(): string
    {
        $path = __DIR__ . '/../Simple-PHP-IPAM/cron.php';
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, 'could not read cron.php');
        return $contents;
    }

    /**
     * Find the body of the `$fail = function (...) {...};` closure so
     * tests assert against THAT specific code, not anything else in the
     * file that happens to call audit() or error_log() for unrelated
     * reasons.
     */
    private function failClosureBody(string $cron): string
    {
        $startMarker = '$fail = function';
        $start = strpos($cron, $startMarker);
        $this->assertNotFalse($start, '$fail closure not found in cron.php');
        // Find the closing `};` of the closure. Use a balanced-brace walk.
        $i = strpos($cron, '{', $start);
        $this->assertNotFalse($i, 'opening brace of $fail closure not found');
        $depth = 1;
        $j = $i + 1;
        $len = strlen($cron);
        while ($j < $len && $depth > 0) {
            $c = $cron[$j];
            if ($c === '{') $depth++;
            elseif ($c === '}') $depth--;
            $j++;
        }
        return substr($cron, $start, $j - $start);
    }

    public function testFailClosureWritesToStderr(): void
    {
        $body = $this->failClosureBody($this->cronSource());
        $this->assertStringContainsString('fwrite(STDERR', $body, '$fail must continue to write to STDERR for hosts that capture cron output');
    }

    public function testFailClosureWritesToErrorLog(): void
    {
        $body = $this->failClosureBody($this->cronSource());
        $this->assertStringContainsString('error_log(', $body, '$fail must call error_log() so messages survive `> /dev/null 2>&1` cron entries');
    }

    public function testFailClosureWritesAuditRow(): void
    {
        $body = $this->failClosureBody($this->cronSource());
        $this->assertStringContainsString("audit(\$db, 'cron.task_failed'", $body, "\$fail must write a 'cron.task_failed' audit row for operator-facing forensics");
    }

    public function testFailClosureCapturesDbForAudit(): void
    {
        $body = $this->failClosureBody($this->cronSource());
        // Accept both `use ($exitCode, $db)` and `use (&$exitCode, $db)` —
        // by-reference vs by-value on $exitCode is orthogonal to $db scope.
        $this->assertMatchesRegularExpression(
            '/use\s*\(\s*&?\$exitCode\s*,\s*\$db\s*\)/',
            $body,
            '$fail closure must capture $db so audit() has a connection'
        );
    }

    public function testFailClosureWrapsAuditInTryCatch(): void
    {
        $body = $this->failClosureBody($this->cronSource());
        // An audit insert failure must not block the cron run nor mask
        // the original cause. Wrap audit in try/catch and continue.
        $this->assertMatchesRegularExpression(
            '/try\s*\{\s*audit\(\$db,\s*\'cron\.task_failed\'/s',
            $body,
            'audit call inside $fail must be wrapped in try{} so audit failure does not break cron'
        );
    }
}
