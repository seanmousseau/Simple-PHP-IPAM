<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tests\Helpers\InMemoryDb;

/**
 * v3.32.0 #891 — coverage for the site hierarchy and subnet→site
 * inheritance resolver.
 *
 * As of v3.32.0 the enforcement gap described in earlier versions of
 * this file is CLOSED. `ipam_site_validate_parent()` (lib.php) now
 * provides a complete app-layer guard wired into both the `create` and
 * `update` branches of sites.php:
 *
 *   - Self-parent on update is rejected ("A site cannot be its own
 *     parent.").
 *   - A non-existent parent is rejected ("Selected parent site does
 *     not exist.").
 *   - A parent that itself has a parent is rejected ("Parent site must
 *     be a top-level site (hierarchy is limited to 2 levels).").
 *   - A site that already has sub-sites cannot be given a parent
 *     ("A site that has sub-sites cannot itself become a sub-site.").
 *   - A null parent (root site) is always allowed.
 *
 * This file covers:
 *   1. `find_parent_site_id()` (lib.php) — the subnet→site inheritance
 *      resolver (#891 original scope).
 *   2. `ipam_site_validate_parent()` (lib.php) — the new hierarchy
 *      guard added in v3.32.0 (#891 closure).
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
    // (b) ipam_site_validate_parent() — cycle and self-parent rejection
    //
    // As of v3.32.0 #891, ipam_site_validate_parent() is the sole
    // app-layer guard for parent assignments. These tests verify it
    // rejects invalid assignments and permits valid ones.
    // ----------------------------------------------------------------

    public function testValidateParentRejectsSelfParentOnUpdate(): void
    {
        $id  = $this->makeSite('Loop');
        $err = ipam_site_validate_parent($this->db, $id, $id);
        $this->assertNotNull($err, 'self-parent must be rejected');
        $this->assertStringContainsString('its own parent', $err);
    }

    public function testValidateParentRejectsNonExistentParent(): void
    {
        $nonExistentParentId = PHP_INT_MAX;
        $err = ipam_site_validate_parent($this->db, $nonExistentParentId, null);
        $this->assertNotNull($err, 'non-existent parent must be rejected');
        $this->assertStringContainsString('does not exist', $err);
    }

    public function testValidateParentRejectsDepthThreeParent(): void
    {
        // `$site` already has `$region` as its parent — making $site a
        // parent would create a 3rd level.
        $region = $this->makeSite('Region');
        $site   = $this->makeSite('Site', $region);
        $err    = ipam_site_validate_parent($this->db, $site, null);
        $this->assertNotNull($err, 'non-root parent must be rejected (depth > 2)');
        $this->assertStringContainsString('top-level', $err);
    }

    public function testValidateParentRejectsSiteWithSubSitesBeingReparented(): void
    {
        // `$region` has a child; trying to give it a parent is rejected.
        $region = $this->makeSite('Region');
        $this->makeSite('Child', $region);
        $other  = $this->makeSite('Other');
        $err    = ipam_site_validate_parent($this->db, $other, $region);
        $this->assertNotNull($err, 'a site with sub-sites cannot become a sub-site');
        $this->assertStringContainsString('sub-sites', $err);
    }

    public function testValidateParentAllowsNullParent(): void
    {
        $this->assertNull(
            ipam_site_validate_parent($this->db, null, null),
            'null parent (root site) is always valid'
        );
    }

    public function testValidateParentAllowsValidRootParentForChildlessSite(): void
    {
        $region = $this->makeSite('Region');
        $site   = $this->makeSite('Orphan');
        $this->assertNull(
            ipam_site_validate_parent($this->db, $region, $site),
            'assigning a root site as parent to a childless site is valid'
        );
    }

    // ----------------------------------------------------------------
    // (c) boundary depth — enforced by ipam_site_validate_parent()
    //
    // region→site (2 levels) is allowed; a grandchild (3 levels) is
    // rejected by the app-layer guard added in v3.32.0 #891.
    // ----------------------------------------------------------------

    public function testTwoLevelHierarchyAtTheLimitIsStorable(): void
    {
        $region = $this->makeSite('Region');                 // depth 0
        $site   = $this->makeSite('Site', $region);          // depth 1 — at the limit
        $row = $this->db->query("SELECT parent_id FROM sites WHERE id = $site")->fetch();
        $this->assertSame($region, (int)$row['parent_id'], 'region→site (2 levels) is valid');
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
