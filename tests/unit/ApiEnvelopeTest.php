<?php

/*
 * B5 (#924): unit test for api_paginated_response() in api.php — the single
 * chokepoint for every list-endpoint response body.
 *
 * As of v3.33.0 the {data,meta} envelope (?envelope=1) is the canonical shape
 * and the legacy flat shape is soft-deprecated: the flat branch emits a
 * `Deprecation: true` HTTP header. Hard removal is tracked in #1252 (v4.0.0).
 *
 * Header-testing approach (the honest part):
 *   api.php cannot be require()'d in-process — it runs auth, header() and
 *   exit() at the top level — so, like ApiPaginatedQueryTest, this test
 *   extracts the single function body from source and eval()s it.
 *
 *   header() itself is a no-op under the CLI SAPI and headers_list() returns
 *   nothing there, so a plain eval into the global namespace cannot observe
 *   the header. Instead the function body is eval()'d INTO a dedicated
 *   namespace (Ipam\B5Test) where an unqualified `header()` call resolves to
 *   a namespaced stub defined below — PHP name resolution falls back to the
 *   global function only when no same-namespace function exists. The stub
 *   records every header() call, letting us assert the Deprecation header is
 *   emitted in flat mode and absent in envelope mode WITHOUT a real SAPI.
 *
 *   The response BODY shapes are also asserted directly (flat vs envelope),
 *   confirming this change is body-preserving. End-to-end header verification
 *   over real HTTP additionally lives in testing/scripts/test_api.sh.
 */

namespace Ipam\B5Test {
    /** @var list<string> recorded header() calls for the current test */
    $GLOBALS['__b5_headers'] = [];

    /**
     * Namespaced stub for header(); shadows the global header() for code that
     * lives in (or is eval()'d into) the Ipam\B5Test namespace.
     */
    function header(string $h): void
    {
        $GLOBALS['__b5_headers'][] = $h;
    }
}

namespace {

    use PHPUnit\Framework\TestCase;

    final class ApiEnvelopeTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            if (function_exists('Ipam\\B5Test\\api_paginated_response')) {
                return;
            }
            $apiPath = __DIR__ . '/../../Simple-PHP-IPAM/api.php';
            $src = file_get_contents($apiPath);
            self::assertNotFalse($src, 'api.php must be readable');

            $start = strpos($src, 'function api_paginated_response');
            self::assertNotFalse($start, 'api_paginated_response not found in api.php');
            $bodyStart = strpos($src, '{', $start);
            self::assertNotFalse($bodyStart);
            $depth = 0;
            $len = strlen($src);
            $end = $bodyStart;
            for ($i = $bodyStart; $i < $len; $i++) {
                if ($src[$i] === '{') {
                    $depth++;
                } elseif ($src[$i] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $end = $i;
                        break;
                    }
                }
            }
            self::assertSame(0, $depth, 'unbalanced braces extracting api_paginated_response');
            $fnSrc = substr($src, $start, $end - $start + 1);
            // eval into Ipam\B5Test so its unqualified header() calls resolve
            // to the recording stub above.
            eval('namespace Ipam\\B5Test;' . "\n" . $fnSrc);
            self::assertTrue(
                function_exists('Ipam\\B5Test\\api_paginated_response'),
                'helper not defined'
            );
        }

        protected function setUp(): void
        {
            $GLOBALS['__b5_headers'] = [];
            unset($_GET['envelope']);
        }

        /**
         * @return list<string>
         */
        private function headers(): array
        {
            /** @var list<string> $h */
            $h = $GLOBALS['__b5_headers'];
            return $h;
        }

        private function hasDeprecationHeader(): bool
        {
            foreach ($this->headers() as $h) {
                if (stripos($h, 'Deprecation:') === 0) {
                    return true;
                }
            }
            return false;
        }

        public function testFlatModeReturnsFlatBody(): void
        {
            $fn = 'Ipam\\B5Test\\api_paginated_response';
            $items = [['id' => 1], ['id' => 2]];
            $resp = $fn('subnets', $items, 42, 1, 200);

            // Flat shape: items under the list key, pagination at top level.
            $this->assertSame($items, $resp['subnets']);
            $this->assertSame(42, $resp['total']);
            $this->assertSame(1, $resp['page']);
            $this->assertSame(200, $resp['limit']);
            $this->assertArrayNotHasKey('data', $resp);
            $this->assertArrayNotHasKey('meta', $resp);
        }

        public function testFlatModeEmitsDeprecationHeader(): void
        {
            $fn = 'Ipam\\B5Test\\api_paginated_response';
            $fn('subnets', [], 0, 1, 200);

            $this->assertTrue(
                $this->hasDeprecationHeader(),
                'flat list response must emit a Deprecation header'
            );
            $this->assertContains('Deprecation: true', $this->headers());
            $this->assertContains(
                'Link: </docs/api.md#list-response-shape>; rel="deprecation"',
                $this->headers()
            );
            // The X-Total-Count header is unconditional and must still fire.
            $this->assertContains('X-Total-Count: 0', $this->headers());
        }

        public function testEnvelopeModeReturnsDataMetaBody(): void
        {
            $_GET['envelope'] = '1';
            $fn = 'Ipam\\B5Test\\api_paginated_response';
            $items = [['id' => 1], ['id' => 2]];
            $resp = $fn('subnets', $items, 42, 1, 20);

            $this->assertSame($items, $resp['data']);
            $this->assertSame(42, $resp['meta']['total']);
            $this->assertSame(1, $resp['meta']['page']);
            $this->assertSame(20, $resp['meta']['per_page']);
            $this->assertSame(3, $resp['meta']['pages']);
            $this->assertArrayNotHasKey('subnets', $resp);
        }

        public function testEnvelopeModeDoesNotEmitDeprecationHeader(): void
        {
            $_GET['envelope'] = '1';
            $fn = 'Ipam\\B5Test\\api_paginated_response';
            $fn('subnets', [], 0, 1, 20);

            $this->assertFalse(
                $this->hasDeprecationHeader(),
                'envelope (canonical) response must NOT emit a Deprecation header'
            );
            // X-Total-Count is still unconditional.
            $this->assertContains('X-Total-Count: 0', $this->headers());
        }

        public function testEnvelopeDisabledByFalseyValuesStillDeprecates(): void
        {
            // ?envelope=0 and ?envelope= are treated as "not requested" — the
            // flat (deprecated) shape is served, so the header must fire.
            foreach (['0', ''] as $val) {
                $GLOBALS['__b5_headers'] = [];
                $_GET['envelope'] = $val;
                $fn = 'Ipam\\B5Test\\api_paginated_response';
                $resp = $fn('subnets', [], 0, 1, 200);
                $this->assertArrayHasKey('subnets', $resp);
                $this->assertTrue(
                    $this->hasDeprecationHeader(),
                    "envelope='$val' must serve the flat shape and deprecate it"
                );
            }
        }
    }
}
