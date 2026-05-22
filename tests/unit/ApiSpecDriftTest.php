<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * v3.29.0 #893 — Detect drift between `docs/api-spec.yaml`'s documented
 * paths and `Simple-PHP-IPAM/api.php`'s actual resource dispatcher.
 *
 * The API uses a `?resource=<name>` query convention rather than URL
 * path versioning. `api-spec.yaml` documents each resource as a top-
 * level path entry (`/subnets:`, `/addresses:`, etc.); `api.php`
 * dispatches via a `match ($resource) { … }` block whose top-level
 * arm strings name the supported resources.
 *
 * This test:
 *   1. Parses every `^\s+/[a-z_]+:` line in api-spec.yaml's `paths:`
 *      section.
 *   2. Parses every top-level `'<name>' =>` arm of api.php's outer
 *      `match ($resource)` block.
 *   3. Asserts the symmetric difference is empty — minus a documented
 *      allowlist of pre-existing drift (issue #1202).
 *
 * Future PRs that add a new resource MUST either:
 *   - Add the matching arm to api.php AND the matching path to
 *     api-spec.yaml; OR
 *   - Extend the allowlist with a documented rationale (gated by
 *     reviewer judgement; should be rare).
 *
 * Pre-existing drift recorded in the allowlist is tracked for cleanup
 * in #1202.
 */
final class ApiSpecDriftTest extends TestCase
{
    /**
     * Resources that exist in api.php but are intentionally absent from
     * api-spec.yaml. Drift closed in v3.32.0 / #1202 — subnet_tags,
     * address_tags, and scan_history were added to the spec; this list
     * must remain empty going forward.
     */
    private const ALLOWLIST_API_ONLY = [];

    /**
     * Resources that exist in api-spec.yaml but are intentionally absent
     * from api.php. Drift closed in v3.32.0 / #1202 — custom_field_defs
     * was removed from the spec (feature never built, #894 deferred); this
     * list must remain empty going forward.
     */
    private const ALLOWLIST_SPEC_ONLY = [];

    /** @return list<string> */
    private function extractSpecPaths(): array
    {
        $yaml = (string) file_get_contents(__DIR__ . '/../../docs/api-spec.yaml');
        $this->assertNotEmpty($yaml, 'docs/api-spec.yaml must be readable');

        // Find the `paths:` block, then every `  /<name>:` line under it.
        $inPaths = false;
        $names = [];
        foreach (preg_split('/\R/', $yaml) ?: [] as $line) {
            if (preg_match('/^paths:\s*$/', $line)) {
                $inPaths = true;
                continue;
            }
            if (!$inPaths) {
                continue;
            }
            // A new top-level key terminates the paths block.
            if (preg_match('/^[a-zA-Z_]/', $line)) {
                break;
            }
            // Match `  /resource_name:` exactly — 2-space indent, leading slash, no nesting.
            if (preg_match('/^\s{2}\/([a-z_]+):\s*$/', $line, $m)) {
                $names[] = $m[1];
            }
        }
        sort($names);
        return $names;
    }

    /** @return list<string> */
    private function extractApiResources(): array
    {
        $php = (string) file_get_contents(__DIR__ . '/../../Simple-PHP-IPAM/api.php');
        $this->assertNotEmpty($php, 'api.php must be readable');

        // Find the outer match ($resource) block.
        $start = strpos($php, 'match ($resource)');
        $this->assertNotFalse($start, 'api.php must contain a match ($resource) dispatcher');

        // The block ends at the first `};` at the same depth — naive scan
        // is sufficient because the project's match indentation is consistent.
        $end = strpos($php, '};', $start);
        $this->assertNotFalse($end, 'api.php match ($resource) block must close');
        $body = substr($php, $start, $end - $start);

        // Match top-level arms: `    'name' =>` (4-space indent, single-
        // quoted identifier). The nested method-match arms use the same
        // shape but indented further — exclude those by anchoring on
        // exactly 4 spaces of indent.
        preg_match_all("/^\s{4}'([a-z_]+)'\s*=>/m", $body, $matches);

        $names = array_unique($matches[1]);
        sort($names);
        return $names;
    }

    public function testNoUndocumentedResourcesBeyondAllowlist(): void
    {
        $spec   = $this->extractSpecPaths();
        $apiset = $this->extractApiResources();
        $apiNotInSpec = array_diff($apiset, $spec, self::ALLOWLIST_API_ONLY);
        $this->assertSame(
            [],
            array_values($apiNotInSpec),
            "api.php has resources that are neither in api-spec.yaml nor in the documented allowlist. "
            . "Either add them to api-spec.yaml or extend the ALLOWLIST_API_ONLY constant with a "
            . "rationale + link to #1202 followup."
        );
    }

    public function testNoOrphanSpecPathsBeyondAllowlist(): void
    {
        $spec   = $this->extractSpecPaths();
        $apiset = $this->extractApiResources();
        $specNotInApi = array_diff($spec, $apiset, self::ALLOWLIST_SPEC_ONLY);
        $this->assertSame(
            [],
            array_values($specNotInApi),
            "api-spec.yaml documents paths that have no matching dispatcher arm in api.php. "
            . "Either add the dispatcher or remove the spec entry. Allowlist for known gaps: "
            . "#1202."
        );
    }

    public function testParserFindsKnownPaths(): void
    {
        $spec = $this->extractSpecPaths();
        // Smoke check that the parser actually works — pin a small set
        // of paths that absolutely must be in api-spec.yaml at all times.
        $this->assertContains('subnets',   $spec, 'parser regression — /subnets must always be in api-spec.yaml');
        $this->assertContains('addresses', $spec, 'parser regression — /addresses must always be in api-spec.yaml');
        $this->assertContains('spec',      $spec, 'parser regression — /spec must always be documented');
    }

    public function testParserFindsKnownApiResources(): void
    {
        $apiset = $this->extractApiResources();
        $this->assertContains('subnets',   $apiset, 'parser regression — api.php must dispatch subnets');
        $this->assertContains('addresses', $apiset, 'parser regression — api.php must dispatch addresses');
        $this->assertContains('spec',      $apiset, 'parser regression — api.php must dispatch spec');
    }
}
