<?php
declare(strict_types=1);

namespace Ipam\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionFunction;

/**
 * ADR-004 Phase 5 Task 5.1 — verifies the presentation-layer extraction from
 * lib.php landed cleanly (#910). The functions must (a) still exist in the
 * global namespace and (b) be declared in Simple-PHP-IPAM/lib/presentation.php
 * rather than lib.php (proves the move was a real move, not a copy).
 *
 * page_header() / page_footer() and the DB-backed renderers are exercised
 * behaviourally by the 3-driver Playwright smoke. The pure helpers icon() and
 * paginate() are checked behaviourally below.
 */
final class PresentationExtractionParityTest extends TestCase
{
    /** @return list<string> */
    private function presentationFunctions(): array
    {
        return [
            'ipam_render',
            'ipam_render_string',
            'icon',
            'flash_set',
            'flash_get',
            'sort_th',
            'render_contact_badges',
            'render_tag_badges',
            'paginate',
            'render_security_banner',
            'render_install_key_banner',
            'page_header',
            'page_footer',
            'render_custom_field_inputs',
        ];
    }

    public function testPresentationFunctionsAreDefined(): void
    {
        foreach ($this->presentationFunctions() as $fn) {
            $this->assertTrue(function_exists($fn), "$fn should be defined");
        }
    }

    public function testPresentationFunctionsLiveInPresentationFile(): void
    {
        foreach ($this->presentationFunctions() as $fn) {
            $declarer = (new ReflectionFunction($fn))->getFileName();
            $this->assertNotFalse($declarer);
            $this->assertStringContainsString(
                '/lib/presentation.php',
                (string)$declarer,
                "$fn should be declared in lib/presentation.php, not " . (string)$declarer
            );
        }
    }

    public function testIconRendersAriaHiddenSvgUseElement(): void
    {
        $svg = \icon('home');
        $this->assertStringContainsString('<svg class="icon"', $svg);
        $this->assertStringContainsString('aria-hidden="true"', $svg);
        $this->assertStringContainsString('<use href="#icon-home">', $svg);
        // Extra classes are appended after the base 'icon' class.
        $this->assertStringContainsString('class="icon icon-lg"', \icon('cog', 'icon-lg'));
    }

    public function testPaginateClampsPageAndPageSize(): void
    {
        // Normal case: 100 rows, 25 per page, page 2.
        $p = \paginate(100, 2, 25);
        $this->assertSame(2, $p['page']);
        $this->assertSame(25, $p['page_size']);
        $this->assertSame(4, $p['pages']);
        $this->assertSame(25, $p['offset']);
        $this->assertSame(25, $p['limit']);

        // Page past the end clamps to the last page.
        $this->assertSame(4, \paginate(100, 99, 25)['page']);

        // Page size is clamped to [1, 500]; page floors at 1.
        $big = \paginate(10, 0, 9999);
        $this->assertSame(1, $big['page']);
        $this->assertSame(500, $big['page_size']);
    }
}
