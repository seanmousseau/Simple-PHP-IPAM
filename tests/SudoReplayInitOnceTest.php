<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * #1131 (v3.27.3) — Pass-A-found regression. The first cut of
 * sudo_replay.php did `require_once __DIR__ . '/step_up.php'` to import
 * ipam_step_up_validate_return_to(). step_up.php is a page entry point
 * that itself does `require __DIR__ . '/init.php'`, so loading it from
 * another page-entry-point caused a "Cannot redeclare function to_str()"
 * fatal at init.php:154.
 *
 * Fix: relocate ipam_step_up_validate_return_to() into
 * lib/auth_step_up.php (which is library code, no init.php require) and
 * call sites use that. step_up.php now imports the function from the
 * lib via require_once.
 *
 * This source-level test prevents anyone from ever wiring step_up.php
 * (a page) into another page's request lifecycle again.
 */
final class SudoReplayInitOnceTest extends TestCase
{
    public function testSudoReplayDoesNotRequireStepUpPagePhp(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/sudo_replay.php');
        $this->assertDoesNotMatchRegularExpression(
            '/require(_once)?\s+__DIR__\s*\.\s*[\'"]\/step_up\.php[\'"]/',
            $src,
            '#1131: sudo_replay.php must not require step_up.php (page entry point) — '
          . 'use lib/auth_step_up.php for shared helpers instead, or init.php loads twice.'
        );
    }

    public function testValidatorLivesInLibNotPage(): void
    {
        $libSrc  = (string) file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/lib/auth_step_up.php');
        $pageSrc = (string) file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/step_up.php');

        $this->assertMatchesRegularExpression(
            '/function\s+ipam_step_up_validate_return_to\s*\(/',
            $libSrc,
            '#1131: ipam_step_up_validate_return_to() must live in lib/auth_step_up.php so '
          . 'non-page entry points can import it without re-loading init.php.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function\s+ipam_step_up_validate_return_to\s*\(/',
            $pageSrc,
            '#1131: step_up.php (page) must NOT define ipam_step_up_validate_return_to() — '
          . 'it would cause a "Cannot redeclare" fatal when both lib/auth_step_up.php and '
          . 'step_up.php are loaded in the same request.'
        );
    }
}
