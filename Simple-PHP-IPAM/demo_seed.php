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

$db = ipam_db($config);
ipam_db_init($db);

echo "Resetting database to demo data...\n";
demo_reset_db($db);
file_put_contents(__DIR__ . '/data/demo_last_reset.txt', (string)time());
echo "Done. Demo data loaded successfully.\n";

// 2FA test user — seeded only when SEED_2FA_TEST_USER=1
if (getenv('SEED_2FA_TEST_USER') === '1') {
    $testSecret = 'JBSWY3DPEHPK3PXP'; // standard RFC 6238 test vector (base32)
    $appSecret  = to_str($config['app_secret'] ?? '');
    if ($appSecret === '') {
        echo "Warning: app_secret not set in config — 2FA test user skipped\n";
    } else {
        // Encrypt using AES-256-GCM, matching ipam_totp_encrypt_secret() in lib.php
        $iv  = random_bytes(12);
        $tag = '';
        $enc = openssl_encrypt(
            $testSecret,
            'aes-256-gcm',
            hash('sha256', $appSecret, true),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );
        if ($enc === false) {
            echo "Error: TOTP secret encryption failed\n";
            exit(1);
        }
        // '$2$' prefix identifies GCM format (same as ipam_totp_encrypt_secret in lib.php)
        $encSecret = '$2$' . base64_encode($iv . $tag . $enc);

        // Upsert the test user (INSERT OR REPLACE resets the id on conflict; handle below)
        $db->prepare(
            "INSERT OR REPLACE INTO users
                (username, password_hash, role, is_active, totp_enabled, totp_secret_enc, email, name)
             VALUES ('2fa_test_user', :ph, 'readonly', 1, 1, :ts, '2fa_test@example.com', '2FA Test User')"
        )->execute([
            ':ph' => password_hash('Password1!', PASSWORD_DEFAULT),
            ':ts' => $encSecret,
        ]);

        $uid = (int) $db->lastInsertId();
        if ($uid === 0) {
            // Was a REPLACE — fetch the id
            $stmt = $db->query("SELECT id FROM users WHERE username='2fa_test_user'");
            $uid = (int) (($stmt ? $stmt->fetchColumn() : false) ?: 0);
        }

        if ($uid === 0) {
            echo "Error: could not determine uid for 2fa_test_user\n";
            exit(1);
        }

        // Seed known backup codes (stored as password_hash, matching ipam_totp_save_backup_codes)
        $db->prepare("DELETE FROM totp_backup_codes WHERE user_id=:uid")->execute([':uid' => $uid]);
        $bkSt = $db->prepare(
            "INSERT INTO totp_backup_codes (user_id, code_hash) VALUES (:uid, :ch)"
        );
        foreach (['AAAA1111-BBBB2222', 'CCCC3333-DDDD4444', 'EEEE5555-FFFF6666',
                  'GGGG7777-HHHH8888', 'IIII9999-JJJJAAAA', 'KKKK1111-LLLL2222',
                  'MMMM3333-NNNN4444', 'OOOO5555-PPPP6666'] as $code) {
            $bkSt->execute([':uid' => $uid, ':ch' => password_hash($code, PASSWORD_DEFAULT)]);
        }
        echo "Seeded 2FA test user: 2fa_test_user / Password1! (TOTP secret: $testSecret)\n";
    }
}
