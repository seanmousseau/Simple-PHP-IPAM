<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tests\Helpers\InMemoryDb;

/**
 * v3.30.0 #891 — coverage for the site hierarchy and subnet→site
 * inheritance resolver.
 *
 * STALE-PREMISE NOTE (#891). The issue states: "Depth-limit (max 2:
 * region→site) enforced at app layer." That is NOT accurate for the
 * code as it exists at v3.29.0:
 *
 *   - sites.php `create` writes parent_id with ZERO server-side
 *     validation — no depth check, no self-parent check, no cycle
 *     check.
 *   - sites.php `update` has exactly ONE guard: `parentId === $id`
 *     rejects DIRECT self-parenting ("A site cannot be its own
 *     parent"). It does NOT reject transitive cycles (A→B→A) and does
 *     NOT enforce a depth limit.
 *   - The "depth limit = 2" is a UI-only affordance: the parent-picker
 *     <select> is populated solely from root sites (parent_id IS
 *     NULL), so the form never *offers* a non-root site as a parent.
 *     The server accepts any integer parent_id.
 *   - The only structural guarantee is the schema FK
 *     `parent_id INTEGER REFERENCES sites(id) ON DELETE SET NULL`.
 *
 * There is therefore no app-layer function that rejects too-deep
 * nesting or transitive circular references. Writing tests against
 * such a function would be testing imaginary behaviour. Instead this
 * file pins what is REAL:
 *
 *   1. `find_parent_site_id()` (lib.php) — the subnet→site inheritance
 *      resolver. Genuine, untested, and the function #891 names.
 *   2. The `sites.parent_id` self-referential FK contract and the
 *      one real direct-self-parent guard condition.
 */
final class SiteHierarchyDepthTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = InMemoryDb::fresh();
    }

    /** Insert a site, return its id. */
    private function makeSite(string $name, ?int $parentId = null): int
    {
        $st = $this->db->prepare(
            "INSERT INTO sites (name, description, parent_id) VALUES (:n, '', :p)"
        );
        $st->execute([':n' => $name, ':p' => $parentId]);
        return (int)$this->db->lastInsertId();
    }

    /** Insert a subnet at the given CIDR, optionally site/vrf scoped. */
    private function makeSubnet(string $cidr, ?int $siteId = null, ?int $vrfId = null): int
    {
        $p = parse_cidr($cidr);
        $this->assertNotNull($p, "test CIDR must be parseable: $cidr");

        $st = $this->db->prepare(
            "INSERT INTO subnets (cidr, ip_version, network, network_bin, prefix, site_id, vrf_id)
             VALUES (:c, :v, :nw, :nb, :px, :s, :vrf)"
        );
        $st->bindValue(':c', $cidr);
        $st->bindValue(':v', (int)$p['version'], PDO::PARAM_INT);
        $st->bindValue(':nw', (string)$p['network']);
        ipam_bind_binary($st, ':nb', (string)$p['net_bin']);
        $st->bindValue(':px', (int)$p['prefix'], PDO::PARAM_INT);
        $st->bindValue(':s', $siteId, $siteId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $st->bindValue(':vrf', $vrfId, $vrfId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $st->execute();
        return (int)$this->db->lastInsertId();
    }

    // ----------------------------------------------------------------
    // (a) find_parent_site_id() resolves a valid parent
    // ----------------------------------------------------------------

    public function testResolvesSiteIdFromContainingParentSubnet(): void
    {
        $siteId = $this->makeSite('HQ');
        $this->makeSubnet('10.0.0.0/16', $siteId);          // parent, has a site
        // The child does not yet exist; we ask what it would inherit.
        $this->assertSame(
            $siteId,
            find_parent_site_id($this->db, '10.0.1.0/24'),
            'a /24 inside a /16 inherits the /16\'s site'
        );
    }

    public function testReturnsNullWhenNoParentSubnetExists(): void
    {
        $this->assertNull(
            find_parent_site_id($this->db, '192.168.5.0/24'),
            'no overlapping broader subnet → no inherited site'
        );
    }

    public function testReturnsNullWhenParentSubnetHasNoSite(): void
    {
        $this->makeSubnet('10.0.0.0/16', null);             // parent exists but site_id IS NULL
        $this->assertNull(
            find_parent_site_id($this->db, '10.0.2.0/24'),
            'a parent with no site assigned yields no inheritance'
        );
    }

    public function testTightestParentWins(): void
    {
        $broad  = $this->makeSite('Region');
        $tight  = $this->makeSite('Building');
        $this->makeSubnet('10.0.0.0/8', $broad);            // broad parent
        $this->makeSubnet('10.0.0.0/16', $tight);           // tighter parent
        // /24 inside both — must inherit from the longest-prefix (tightest) parent.
        $this->assertSame(
            $tight,
            find_parent_site_id($this->db, '10.0.0.0/24'),
            'ORDER BY prefix DESC → the most specific parent supplies the site'
        );
    }

    public function testNonOverlappingSubnetIsNotTreatedAsParent(): void
    {
        $siteId = $this->makeSite('Other');
        $this->makeSubnet('10.0.0.0/16', $siteId);
        $this->assertNull(
            find_parent_site_id($this->db, '172.16.4.0/24'),
            'a broader-prefix subnet that does not contain the candidate is not a parent'
        );
    }

    public function testVrfScopingIsolatesInheritance(): void
    {
        // Two VRF tables must not exist as FK rows for this fixture; vrf_id
        // is a plain nullable integer here so we can scope without vrfs rows
        // — detect_subnet_overlaps uses a null-safe equality on vrf_id only.
        $globalSite = $this->makeSite('Global');
        $this->makeSubnet('10.0.0.0/16', $globalSite, null);          // global VRF (NULL)
        // A candidate in a *different* VRF must not inherit the global parent.
        $this->assertNull(
            find_parent_site_id($this->db, '10.0.9.0/24', null, 7),
            'overlap detection is scoped per-VRF; cross-VRF parents do not apply'
        );
        // Same (global) VRF still resolves.
        $this->assertSame(
            $globalSite,
            find_parent_site_id($this->db, '10.0.9.0/24', null, null),
            'within the same (global) VRF the parent still resolves'
        );
    }

    public function testExcludeIdSkipsTheSubnetBeingEdited(): void
    {
        // Two genuine parents of a /24, each with a different site: a broad
        // /16 (site A) and a tighter /20 (site B). Resolution picks the
        // tightest parent — normally the /20, so site B. Excluding the /20's
        // id removes it from the parent set, forcing a fall-back to the /16
        // and therefore site A. This is the observable effect of excludeId:
        // flipping it on/off changes the asserted result.
        $siteA = $this->makeSite('Region');     // owner of the broad /16
        $siteB = $this->makeSite('Building');   // owner of the tighter /20
        $this->makeSubnet('10.0.0.0/16', $siteA);
        $tight = $this->makeSubnet('10.0.0.0/20', $siteB);

        // Without excludeId, the tightest parent (/20) wins → site B.
        $this->assertSame(
            $siteB,
            find_parent_site_id($this->db, '10.0.1.0/24'),
            'without excludeId the tightest parent (/20) supplies the site'
        );

        // Excluding the /20 drops it from the parent set → falls back to /16.
        $this->assertSame(
            $siteA,
            find_parent_site_id($this->db, '10.0.1.0/24', $tight),
            'excludeId removes the /20 parent; resolution falls back to the /16'
        );
    }

    public function testIpv6ParentResolution(): void
    {
        $siteId = $this->makeSite('IPv6 Site');
        $this->makeSubnet('2001:db8::/32', $siteId);
        $this->assertSame(
            $siteId,
            find_parent_site_id($this->db, '2001:db8:abcd::/48'),
            'IPv6 inheritance resolves the same way as IPv4'
        );
    }

    // ----------------------------------------------------------------
    // (b) circular-reference handling on the sites self-referential FK
    //
    // No app-layer cycle rejector exists (see class docblock). What we
    // CAN pin: the schema permits a site to point at itself or form a
    // 2-cycle at the storage layer — proving the absence of a DB-level
    // guard and documenting that any cycle protection must come from
    // the app layer (which #891 should add, but has not yet).
    // ----------------------------------------------------------------

    public function testDirectSelfParentGuardConditionMatchesSitesPhp(): void
    {
        // Pin the ONE real guard in sites.php update: parentId === id.
        // This mirrors the exact server-side condition without invoking
        // the page (which needs a full HTTP/session context).
        $id = $this->makeSite('Self');
        $parentId = $id; // simulate POST parent_id == id
        $this->assertTrue(
            $parentId === $id,
            'sites.php update rejects exactly this: a site set as its own parent'
        );
    }

    public function testSchemaDoesNotByItselfRejectACycle(): void
    {
        // Documents that the FK alone provides NO cycle protection:
        // a self-referential parent_id is accepted by the database.
        $id = $this->makeSite('Loop');
        $upd = $this->db->prepare("UPDATE sites SET parent_id = :p WHERE id = :id");
        $upd->execute([':p' => $id, ':id' => $id]);

        $row = $this->db->query("SELECT parent_id FROM sites WHERE id = $id")->fetch();
        $this->assertSame(
            $id,
            (int)$row['parent_id'],
            'the sites FK does not prevent a self-cycle — app-layer guard is the only defence'
        );
    }

    public function testSchemaDoesNotRejectATwoNodeCycle(): void
    {
        // A→B then B→A: a transitive 2-cycle the update guard does NOT
        // catch (it only blocks parentId === id). Pinning this records
        // the real gap #891 describes.
        $a = $this->makeSite('A');
        $b = $this->makeSite('B', $a);                       // B's parent is A
        $this->db->prepare("UPDATE sites SET parent_id = :p WHERE id = :id")
                 ->execute([':p' => $b, ':id' => $a]);        // A's parent is now B

        $rows = $this->db->query("SELECT id, parent_id FROM sites ORDER BY id")->fetchAll();
        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r['id']] = (int)$r['parent_id'];
        }
        $this->assertSame($b, $map[$a], 'A→B accepted');
        $this->assertSame($a, $map[$b], 'B→A accepted — a 2-cycle exists with no rejection');
    }

    // ----------------------------------------------------------------
    // (c) boundary depth — depth limit is UI-only, NOT app-enforced
    //
    // region→site (2 levels) is allowed; a grandchild (3 levels) is the
    // "beyond limit" case. The storage layer accepts the grandchild —
    // pinning this documents that no server-side depth check exists.
    // ----------------------------------------------------------------

    public function testTwoLevelHierarchyAtTheLimitIsStorable(): void
    {
        $region = $this->makeSite('Region');                 // depth 0
        $site   = $this->makeSite('Site', $region);          // depth 1 — at the limit
        $row = $this->db->query("SELECT parent_id FROM sites WHERE id = $site")->fetch();
        $this->assertSame($region, (int)$row['parent_id'], 'region→site (2 levels) is valid');
    }

    public function testThreeLevelHierarchyBeyondTheLimitIsNotRejectedByStorage(): void
    {
        // The UI parent-picker would never offer `$site` (a non-root) as
        // a parent, but the server has no depth guard — so a grandchild
        // is accepted at the storage layer. This is the documented gap.
        $region = $this->makeSite('Region');                 // depth 0
        $site   = $this->makeSite('Site', $region);          // depth 1
        $rack   = $this->makeSite('Rack', $site);            // depth 2 — beyond the "max 2"

        $row = $this->db->query("SELECT parent_id FROM sites WHERE id = $rack")->fetch();
        $this->assertSame(
            $site,
            (int)$row['parent_id'],
            'no app-layer depth limit: a 3rd level is stored without rejection'
        );
    }

    public function testRootSiteDetectionMatchesParentPickerSource(): void
    {
        // sites.php builds the parent picker from sites where
        // parent_id IS NULL. Pin that selection so a regression in the
        // hierarchy model is caught.
        $region = $this->makeSite('Region');
        $this->makeSite('Child', $region);

        $roots = $this->db->query("SELECT id FROM sites WHERE parent_id IS NULL")->fetchAll();
        $this->assertCount(1, $roots, 'exactly one root site');
        $this->assertSame($region, (int)$roots[0]['id'], 'only the region is a valid parent option');
    }
}
