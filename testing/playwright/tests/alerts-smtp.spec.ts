/**
 * Utilization alert SMTP delivery (#458) — end-to-end test using MailHog.
 *
 * Prerequisites: bootstrap with IPAM_TEST_MAILHOG=1 so bootstrap-app.sh starts
 * a MailHog container on the docker network alongside Apache. The MailHog HTTP
 * API is exposed on the host at IPAM_TEST_MAILHOG_WEB_PORT (default 8026).
 * Apache can reach MailHog SMTP at mailhog:1025 inside the docker network.
 *
 * All tests are automatically skipped when MailHog is not reachable, so this
 * spec is always safe to include in a full suite run.
 */

import { test, expect } from '@playwright/test';
import { spawnSync } from 'child_process';

const MAILHOG_PORT = process.env.IPAM_TEST_MAILHOG_WEB_PORT || '8026';
const MAILHOG_URL  = `http://127.0.0.1:${MAILHOG_PORT}`;
const CONTAINER    = process.env.IPAM_TEST_NAME || 'ipam-pw-test';

// ── MailHog helpers ───────────────────────────────────────────────────────────

async function isMailHogAvailable(): Promise<boolean> {
  try {
    const r = await fetch(`${MAILHOG_URL}/api/v2/messages`, { signal: AbortSignal.timeout(2000) });
    return r.ok;
  } catch {
    return false;
  }
}

async function clearMailHog(): Promise<void> {
  await fetch(`${MAILHOG_URL}/api/v1/messages`, { method: 'DELETE' });
}

interface MailHogMessage {
  Content: { Headers: Record<string, string[]>; Body: string };
}

async function getMessages(): Promise<MailHogMessage[]> {
  const r = await fetch(`${MAILHOG_URL}/api/v2/messages`);
  const data = await r.json() as { items: MailHogMessage[] };
  return data.items || [];
}

async function waitForMessages(count: number, timeoutMs = 10000): Promise<MailHogMessage[]> {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const msgs = await getMessages();
    if (msgs.length >= count) return msgs;
    await new Promise(res => setTimeout(res, 300));
  }
  return getMessages();
}

// ── docker exec PHP helper ────────────────────────────────────────────────────

// Uses spawnSync (array args, no shell) — not vulnerable to injection.
function dockerPhp(code: string): string {
  const r = spawnSync('docker', ['exec', CONTAINER, 'php', '-r', code], { encoding: 'utf8' });
  if (r.status !== 0) throw new Error(`docker php: ${(r.stderr || r.stdout || '').trim()}`);
  return r.stdout.trim();
}

const BOOT = `
$config = require '/var/www/html/config.php';
require '/var/www/html/lib.php';
$db = ipam_db($config);
`;

// ── Suite ─────────────────────────────────────────────────────────────────────

test.describe('Utilization alerts SMTP delivery (#458)', () => {
  let mailhogAvailable = false;
  let restoredWarnPct  = '80';
  let restoredCritPct  = '95';
  let restoredRecips   = '[]';
  let silencedSubnetId = 0;

  test.beforeAll(async () => {
    mailhogAvailable = await isMailHogAvailable();
    if (!mailhogAvailable) return;

    await clearMailHog();

    // Snapshot original alert settings for teardown restore
    restoredWarnPct  = dockerPhp(`${BOOT} echo (string)ipam_setting('alert.util_warn_pct', 80);`);
    restoredCritPct  = dockerPhp(`${BOOT} echo (string)ipam_setting('alert.util_crit_pct', 95);`);
    restoredRecips   = dockerPhp(`${BOOT} echo json_encode(ipam_setting('alert.recipient_user_ids', []));`);

    // Configure SMTP → MailHog (reachable inside docker network as "mailhog")
    dockerPhp(`${BOOT}
      ipam_setting_set($db, 'smtp.enabled', true);
      ipam_setting_set($db, 'smtp.host', 'mailhog');
      ipam_setting_set($db, 'smtp.port', 1025);
      ipam_setting_set($db, 'smtp.encryption', 'none');
      ipam_setting_set($db, 'smtp.from_address', 'ipam@test.local');
      ipam_setting_set($db, 'smtp.from_name', 'IPAM Test');
    `);

    // Lower thresholds so demo subnets with any addresses trigger alerts
    dockerPhp(`${BOOT}
      ipam_setting_set($db, 'alert.util_warn_pct', 5);
      ipam_setting_set($db, 'alert.util_crit_pct', 10);
      ipam_setting_set($db, 'alert.recipient_user_ids', [1]);
    `);

    // Ensure user 1 (demo admin) has an email address
    dockerPhp(`${BOOT}
      $db->exec("UPDATE users SET email = 'admin@test.local' WHERE id = 1 AND (email IS NULL OR email = '')");
    `);

    // Clear stale alert_state so alerts fire on the first run
    dockerPhp(`${BOOT} $db->exec('DELETE FROM alert_state');`);
  });

  test.afterAll(async () => {
    if (!mailhogAvailable) return;

    // Restore SMTP to off
    dockerPhp(`${BOOT}
      ipam_setting_set($db, 'smtp.enabled', false);
      $db->prepare("DELETE FROM settings WHERE key LIKE 'smtp.%'")->execute();
    `);

    // Restore alert settings
    const warnPct = parseInt(restoredWarnPct, 10) || 80;
    const critPct = parseInt(restoredCritPct, 10) || 95;
    const recips  = JSON.stringify(JSON.parse(restoredRecips || '[]'));
    dockerPhp(`${BOOT}
      ipam_setting_set($db, 'alert.util_warn_pct', ${warnPct});
      ipam_setting_set($db, 'alert.util_crit_pct', ${critPct});
      ipam_setting_set($db, 'alert.recipient_user_ids', json_decode('${recips}', true) ?: []);
    `);

    // Re-enable alerts on any subnet that was silenced
    if (silencedSubnetId > 0) {
      dockerPhp(`${BOOT}
        $db->exec("UPDATE subnets SET alerts_enabled = 1 WHERE id = ${silencedSubnetId}");
      `);
    }

    dockerPhp(`${BOOT} $db->exec('DELETE FROM alert_state');`);
    await clearMailHog();
  });

  test('alert fires to MailHog for high-utilization subnet', async () => {
    test.skip(!mailhogAvailable, 'MailHog not running — bootstrap with IPAM_TEST_MAILHOG=1');

    await clearMailHog();
    dockerPhp(`${BOOT} check_utilization_alerts($db, $config);`);

    const msgs = await waitForMessages(1);
    expect(msgs.length).toBeGreaterThanOrEqual(1);

    const msg     = msgs[0];
    const subject = msg.Content?.Headers?.Subject?.[0] ?? '';
    const body    = msg.Content?.Body ?? '';

    // Subject format: "[AppName] WARNING: subnet X.X.X.X/Y at N% utilization"
    expect(subject).toMatch(/WARNING|CRITICAL/);
    expect(subject).toContain('% utilization');
    // Body contains usage stats
    expect(body).toContain('Assigned:');
  });

  test('24-hour cooldown prevents duplicate alerts within the same run', async () => {
    test.skip(!mailhogAvailable, 'MailHog not running — bootstrap with IPAM_TEST_MAILHOG=1');

    // alert_state rows written by the previous test act as the cooldown gate
    await clearMailHog();
    dockerPhp(`${BOOT} check_utilization_alerts($db, $config);`);

    await new Promise(res => setTimeout(res, 1500));
    const msgs = await getMessages();
    expect(msgs.length).toBe(0);
  });

  test('alerts_enabled=0 silences a specific subnet', async () => {
    test.skip(!mailhogAvailable, 'MailHog not running — bootstrap with IPAM_TEST_MAILHOG=1');

    // Pick a subnet with assigned addresses that would otherwise trigger an alert
    const rawId = dockerPhp(`${BOOT}
      $row = $db->query(
        "SELECT s.id FROM subnets s
         JOIN addresses a ON a.subnet_id = s.id
         WHERE s.ip_version = 4 AND s.alerts_enabled = 1
         GROUP BY s.id HAVING COUNT(a.id) > 0
         LIMIT 1"
      )->fetch(PDO::FETCH_ASSOC);
      echo $row ? (string)$row['id'] : '0';
    `);
    const subnetId = parseInt(rawId, 10);
    if (subnetId === 0) {
      test.skip(true, 'No IPv4 subnets with addresses found in demo data');
      return;
    }
    silencedSubnetId = subnetId;

    const cidr = dockerPhp(`${BOOT}
      $row = $db->query("SELECT cidr FROM subnets WHERE id = ${subnetId}")->fetch(PDO::FETCH_ASSOC);
      echo $row ? (string)$row['cidr'] : '';
    `);

    // Clear cooldown and disable alerts on this subnet
    dockerPhp(`${BOOT}
      $db->exec('DELETE FROM alert_state');
      $db->exec("UPDATE subnets SET alerts_enabled = 0 WHERE id = ${subnetId}");
    `);

    await clearMailHog();
    dockerPhp(`${BOOT} check_utilization_alerts($db, $config);`);
    await new Promise(res => setTimeout(res, 1500));

    const msgs = await getMessages();
    // No message should reference the silenced subnet's CIDR in its subject
    for (const msg of msgs) {
      const subject = msg.Content?.Headers?.Subject?.[0] ?? '';
      expect(subject).not.toContain(cidr);
    }
  });
});
