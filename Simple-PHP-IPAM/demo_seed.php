<?php
/**
 * CLI seeder: populates the database with demo data.
 * Usage: php demo_seed.php
 *
 * This script truncates all data tables and seeds fresh demo data.
 * Only intended for development/demo environments.
 *
 * This fixture is also the canonical test data for the containerized Playwright
 * harness (testing/playwright/bootstrap-app.sh). Seed contents, maintained in
 * demo_seed_data() in lib.php:
 *
 *   Users   — 3:  demo/demo (admin), readonly-user + netops-user (locked accounts,
 *                 password_hash='!disabled' — for UI display only; tests that need
 *                 a working readonly login create pw-readonly via ensureRoUser()
 *                 in fixtures/ipam.ts).
 *   Sites   — 6:  2 regions (EMEA, Americas) and 4 sites under them, exercising
 *                 the parent-site hierarchy.
 *   VRFs    — 2:  DEFAULT and MGMT-VRF, both with RDs.
 *   VLANs   — 4:  Management, Servers, DMZ, Cloud-Connect.
 *   Contacts— 3:  Alice/Bob/Carol, linked to addresses below.
 *   Tags    — 4:  Production, Development, Critical, Monitored.
 *   Subnets — 13: 10 IPv4 (including /8 supernet, /16 corporate, /24 segments,
 *                 /27 DMZ) and 3 IPv6 (2001:db8::/32 hierarchy).
 *   Subnet tags — 5 tag attachments covering Production/Critical/Monitored/Dev.
 *   Addresses — 50+ across 6 subnets with a mix of used/reserved/free statuses,
 *                 hostnames, owner strings, contact links, MAC addresses, and a
 *                 handful of expiry dates (some future, some already expired for
 *                 the "expired addresses" UI test).
 *   Audit log — pre-populated historical entries spanning auth and CRUD actions.
 *
 * When adding a new Playwright test that needs specific data, first try to use
 * existing fixtures from this list; only extend demo_seed_data() in lib.php if
 * there is no alternative. Keep the seed fast (<2s total). Tests that create
 * their own fixtures use the 10.99.0.0/24, 10.88.0.0/24, 10.77.99.0/24,
 * 10.66.0.0/24, 10.55.0.0/24 and 10.44.0.0/28 ranges, none of which overlap
 * the seeded subnets above. The IPv6 range 2001:db8:1::/120 used by tests
 * falls under the seeded 2001:db8:1::/48 parent; the hierarchy view test
 * accounts for this.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require __DIR__ . '/lib.php';

$config = require __DIR__ . '/config.php';

if (!($config['demo_mode']['enabled'] ?? false)) {
    echo "Demo mode is not enabled in config.php. Aborting.\n";
    echo "Set 'demo_mode' => ['enabled' => true] to use this script.\n";
    exit(1);
}

$db = ipam_db($config['db_path']);
ipam_db_init($db);

echo "Resetting database to demo data...\n";
demo_reset_db($db);
file_put_contents(__DIR__ . '/data/demo_last_reset.txt', (string)time());
echo "Done. Demo data loaded successfully.\n";
