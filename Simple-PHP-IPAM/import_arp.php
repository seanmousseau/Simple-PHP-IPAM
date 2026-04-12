<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_write_access();

if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

$err    = '';
$msg    = '';
/** @var list<array{ip:string,mac:string}> $preview */
$preview   = [];
$previewFor = 0; // subnet_id the preview is for
$subnetId   = 0;

// Load all subnets for the selector
/** @var list<array<string, mixed>> $subnetList */
$subnetList = ($db->query("
    SELECT id, cidr, description, ip_version
    FROM subnets
    ORDER BY ip_version, network_bin
") ?: throw new \RuntimeException('Query failed'))->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = to_str($_POST['action'] ?? '');
    $subnetId = to_int($_POST['subnet_id'] ?? 0);
    $raw      = to_str($_POST['raw'] ?? '');

    if ($subnetId <= 0) {
        $err = 'Please select a subnet.';
    } elseif (trim($raw) === '') {
        $err = 'Paste your ARP / neighbour table in the text area.';
    } else {
        $entries = ipam_parse_arp_table($raw);

        if (count($entries) === 0) {
            $err = 'No valid IP+MAC pairs found. Expected lines like: <code>192.168.1.1 aa:bb:cc:dd:ee:ff</code>';
        } elseif ($action === 'preview') {
            $preview    = $entries;
            $previewFor = $subnetId;
        } elseif ($action === 'apply') {
            if (demo_mode_enabled()) {
                $err = 'This action is disabled in demo mode.';
            } else {
                $stats = ipam_apply_arp_import($db, $entries, $subnetId);
                audit($db, 'address.arp_import', 'subnet', $subnetId,
                    "matched={$stats['matched']} updated={$stats['updated']} skipped={$stats['skipped']}");
                flash_set("ARP import complete: {$stats['updated']} MAC address(es) updated ({$stats['matched']} matched, {$stats['skipped']} skipped).");
                header('Location: import_arp.php');
                exit;
            }
        }
    }
}

page_header('ARP Import');
render_security_banner('import_arp', 'ARP import overwrites MAC address fields on matched addresses. Review the preview before applying.');
?>
<div class="container">
  <div class="row" style="align-items:center;margin-bottom:8px">
    <h1 style="margin:0">ARP / Neighbour Table Import</h1>
  </div>
  <p class="muted">Paste the output of <code>arp -a</code>, <code>ip neigh</code>, or any IP+MAC text to bulk-populate MAC address fields.</p>

  <?php if ($err !== ''): ?>
    <div class="card" style="border-color:var(--danger);color:var(--danger)"><?= $err ?></div>
  <?php endif ?>

  <div class="card">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

      <div class="row" style="margin-bottom:16px;flex-wrap:wrap;gap:12px">
        <div>
          <label for="subnet_id"><strong>Subnet</strong></label><br>
          <select name="subnet_id" id="subnet_id" required style="min-width:240px;margin-top:4px">
            <option value="">— select —</option>
            <?php foreach ($subnetList as $s): ?>
              <option value="<?= e(to_str($s['id'])) ?>"<?= to_int($s['id']) === ($previewFor ?: $subnetId) ? ' selected' : '' ?>>
                IPv<?= to_int($s['ip_version']) ?> — <?= e(to_str($s['cidr'])) ?>
                <?php if (to_str($s['description']) !== ''): ?> (<?= e(to_str($s['description'])) ?>)<?php endif ?>
              </option>
            <?php endforeach ?>
          </select>
        </div>
      </div>

      <div style="margin-bottom:16px">
        <label for="raw"><strong>ARP / Neighbour Data</strong></label><br>
        <textarea name="raw" id="raw" rows="10" style="width:100%;margin-top:4px;font-family:monospace;resize:vertical"
          placeholder="192.168.1.1  aa:bb:cc:dd:ee:ff&#10;192.168.1.2  11:22:33:44:55:66"><?= e(to_str($_POST['raw'] ?? '')) ?></textarea>
        <div class="muted" style="font-size:.8rem;margin-top:4px">
          Accepts space, tab, or comma-separated lines. IP can appear before or after MAC. Extra columns are ignored.
        </div>
      </div>

      <div style="display:flex;gap:8px">
        <button type="submit" name="action" value="preview" class="button-secondary">Preview</button>
        <?php if (count($preview) > 0 && $previewFor === $subnetId): ?>
          <button type="submit" name="action" value="apply">Apply <?= count($preview) ?> entries</button>
        <?php endif ?>
      </div>
    </form>
  </div>

  <?php if (count($preview) > 0): ?>
  <div class="card" style="margin-top:16px">
    <h3 style="margin:0 0 12px">Preview — <?= count($preview) ?> entries parsed</h3>
    <div class="table-wrap">
    <table>
      <thead>
        <tr><th>IP</th><th>MAC</th><th>In subnet?</th><th>Current MAC</th></tr>
      </thead>
      <tbody>
        <?php
        // Fetch current MACs for matching IPs in the selected subnet for comparison
        $ipList = array_column($preview, 'ip');
        /** @var array<string, string> $currentMacs */
        $currentMacs = [];
        $placeholders = implode(',', array_fill(0, count($ipList), '?'));
        $stMac = $db->prepare("SELECT ip, mac FROM addresses WHERE subnet_id = ? AND ip IN ($placeholders)");
        $stMac->execute(array_merge([$previewFor], $ipList));
        foreach ($stMac->fetchAll() as $r) {
            $currentMacs[to_str($r['ip'])] = to_str($r['mac']);
        }
        foreach ($preview as $entry):
            $inSubnet = array_key_exists($entry['ip'], $currentMacs);
            $currentMac = $currentMacs[$entry['ip']] ?? '';
            $macChanged = $inSubnet && $currentMac !== $entry['mac'];
        ?>
        <tr>
          <td><?= e($entry['ip']) ?></td>
          <td><code><?= e($entry['mac']) ?></code></td>
          <td><?= $inSubnet ? '<span class="success">Yes</span>' : '<span class="muted">No — skipped</span>' ?></td>
          <td class="muted">
            <?php if ($inSubnet): ?>
              <?= $currentMac !== '' ? '<code>' . e($currentMac) . '</code>' : '<em>empty</em>' ?>
              <?= $macChanged ? ' <span class="badge" style="background:var(--warn)">will update</span>' : '' ?>
            <?php else: ?>
              —
            <?php endif ?>
          </td>
        </tr>
        <?php endforeach ?>
      </tbody>
    </table>
    </div>
  </div>
  <?php endif ?>

</div>
<?php page_footer(); ?>
