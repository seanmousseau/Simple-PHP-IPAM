<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_role('admin');
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = to_str($_POST['action'] ?? '');

    if (demo_mode_enabled() && in_array($action, ['create_pool', 'delete_pool', 'create_delegation', 'delete_delegation'], true)) {
        $err = 'This action is disabled in demo mode.';
        $action = '';
    }

    if ($action === 'create_pool') {
        $subnetId   = to_int($_POST['parent_subnet_id'] ?? 0);
        $delPrefix  = to_int($_POST['delegation_prefix'] ?? 0);
        $desc       = trim(to_str($_POST['description'] ?? ''));
        $siteId     = to_int($_POST['site_id'] ?? 0) ?: null;

        if ($subnetId <= 0) {
            $err = 'Parent subnet is required.';
        } elseif ($delPrefix < 1 || $delPrefix > 128) {
            $err = 'Delegation prefix must be between 1 and 128.';
        } else {
            // Validate parent is IPv6
            $st = $db->prepare("SELECT ip_version FROM subnets WHERE id = :id");
            $st->execute([':id' => $subnetId]);
            /** @var array<string,mixed>|false $parentRow */
            $parentRow = $st->fetch();
            if (!$parentRow || to_int($parentRow['ip_version']) !== 6) {
                $err = 'Parent subnet must be an IPv6 subnet.';
            } else {
                try {
                    $st = $db->prepare("INSERT INTO pd_pools (parent_subnet_id, delegation_prefix, description, site_id) VALUES (:sid,:pfx,:desc,:siteid)");
                    $st->execute([':sid' => $subnetId, ':pfx' => $delPrefix, ':desc' => $desc, ':siteid' => $siteId]);
                    $newId = ipam_last_insert_id($db, 'pd_pools');
                    audit($db, 'pd_pool.create', 'pd_pool', $newId, "subnet=$subnetId prefix=/$delPrefix");
                    flash_set("PD pool created.");
                    header('Location: pd_pools.php');
                    exit;
                } catch (PDOException $e) {
                    $err = 'Could not create PD pool (one pool per subnet).';
                }
            }
        }
    } elseif ($action === 'delete_pool') {
        $id = to_int($_POST['pool_id'] ?? 0);
        if ($id > 0) {
            $db->prepare("DELETE FROM pd_pools WHERE id = :id")->execute([':id' => $id]);
            audit($db, 'pd_pool.delete', 'pd_pool', $id, '');
            flash_set('PD pool deleted.');
            header('Location: pd_pools.php');
            exit;
        }
    } elseif ($action === 'create_delegation') {
        $poolId       = to_int($_POST['pool_id'] ?? 0);
        $cidr         = trim(to_str($_POST['cidr'] ?? ''));
        $contactId    = to_int($_POST['subscriber_id'] ?? 0) ?: null;
        $expiresAt    = trim(to_str($_POST['expires_at'] ?? ''));
        $notes        = trim(to_str($_POST['notes'] ?? ''));

        $expiresAt = $expiresAt !== '' ? $expiresAt : null;

        if ($poolId <= 0 || $cidr === '') {
            $err = 'Pool and CIDR are required.';
        } else {
            $parsed = parse_cidr($cidr);
            if ($parsed === null || $parsed['version'] !== 6) {
                $err = 'Invalid IPv6 CIDR.';
            } else {
                try {
                    $st = $db->prepare("INSERT INTO pd_delegations (pool_id, cidr, subscriber_id, expires_at, notes) VALUES (:pid,:cidr,:sub,:exp,:notes)");
                    $st->execute([':pid' => $poolId, ':cidr' => $parsed['network'] . '/' . $parsed['prefix'], ':sub' => $contactId, ':exp' => $expiresAt, ':notes' => $notes]);
                    $newId = ipam_last_insert_id($db, 'pd_delegations');
                    audit($db, 'pd_pool.delegate', 'pd_delegation', $newId, "pool=$poolId cidr=$cidr");
                    flash_set("Prefix delegated.");
                    header('Location: pd_pools.php?pool_id=' . $poolId);
                    exit;
                } catch (PDOException $e) {
                    $err = 'Could not record delegation.';
                }
            }
        }
    } elseif ($action === 'delete_delegation') {
        $delId = to_int($_POST['delegation_id'] ?? 0);
        $poolId = to_int($_POST['pool_id'] ?? 0);
        if ($delId > 0) {
            $db->prepare("DELETE FROM pd_delegations WHERE id = :id")->execute([':id' => $delId]);
            audit($db, 'pd_pool.revoke', 'pd_delegation', $delId, '');
            flash_set('Delegation revoked.');
            header('Location: pd_pools.php' . ($poolId > 0 ? '?pool_id=' . $poolId : ''));
            exit;
        }
    }
}

// Load IPv6 subnets for pool creation picker
/** @var list<array<string,mixed>> $ipv6Subnets */
$ipv6Subnets = ($db->query("
    SELECT id, cidr, description
    FROM subnets
    WHERE ip_version = 6
    ORDER BY network_bin
") ?: throw new \RuntimeException('Query failed'))->fetchAll();

// Sites for picker
/** @var list<array<string,mixed>> $sites */
$sites = ($db->query("SELECT id, name FROM sites ORDER BY name") ?: throw new \RuntimeException('Query failed'))->fetchAll();

// Contacts for subscriber picker
/** @var list<array<string,mixed>> $contacts */
$contacts = ($db->query("SELECT id, name, email FROM contacts ORDER BY name") ?: throw new \RuntimeException('Query failed'))->fetchAll();

// Load all pools with parent subnet info
/** @var list<array<string,mixed>> $pools */
$pools = ($db->query("
    SELECT p.id, p.parent_subnet_id, p.delegation_prefix, p.description, p.site_id, p.created_at,
           s.cidr AS subnet_cidr,
           (SELECT COUNT(*) FROM pd_delegations d WHERE d.pool_id = p.id) AS delegation_count
    FROM pd_pools p
    JOIN subnets s ON s.id = p.parent_subnet_id
    ORDER BY s.network_bin
") ?: throw new \RuntimeException('Query failed'))->fetchAll();

// Selected pool for delegation view
$selectedPoolId = to_int($_GET['pool_id'] ?? 0);
/** @var list<array<string,mixed>> $delegations */
$delegations = [];
/** @var array<string,mixed>|null $selectedPool */
$selectedPool = null;
if ($selectedPoolId > 0) {
    foreach ($pools as $p) {
        if (to_int($p['id']) === $selectedPoolId) {
            $selectedPool = $p;
            break;
        }
    }
    if ($selectedPool !== null) {
        $st = $db->prepare("
            SELECT d.id, d.cidr, d.expires_at, d.notes, d.delegated_at,
                   c.name AS subscriber_name, c.email AS subscriber_email
            FROM pd_delegations d
            LEFT JOIN contacts c ON c.id = d.subscriber_id
            WHERE d.pool_id = :pid
            ORDER BY d.delegated_at DESC
        ");
        $st->execute([':pid' => $selectedPoolId]);
        $delegations = $st->fetchAll();
    }
}

page_header('PD Pools');
?>
<div class="breadcrumbs">
  <a href="dashboard.php">Dashboard</a><span class="sep">›</span>
  <a href="#">Admin</a><span class="sep">›</span>
  <span>PD Pools</span>
</div>

<div class="toolbar">
  <div>
    <h1>IPv6 Prefix Delegation Pools</h1>
    <div class="muted">Manage RFC 3633 prefix delegation pools — allocate /48, /56, or /64 prefixes to subscribers from an IPv6 parent subnet.</div>
  </div>
</div>

<?php if ($err): ?><p class="danger"><?= e($err) ?></p><?php endif; ?>

<div class="grid cols-2">
  <div class="card" id="add-pool">
    <h2>Add PD Pool</h2>
    <?php if (!$ipv6Subnets): ?>
      <p class="muted">No IPv6 subnets available. <a href="subnets.php">Add an IPv6 subnet</a> first.</p>
    <?php else: ?>
    <form method="post" action="pd_pools.php">
      <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="create_pool">
      <div class="row">
        <label class="flex-1">Parent IPv6 Subnet<br>
          <select name="parent_subnet_id" required class="w-full">
            <option value="">— select —</option>
            <?php foreach ($ipv6Subnets as $sn): ?>
              <option value="<?= to_int($sn['id']) ?>">
                <?= e(to_str($sn['cidr'])) ?><?= to_str($sn['description']) !== '' ? ' — ' . e(to_str($sn['description'])) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Delegation Prefix <span class="muted" data-tooltip="Prefix length to delegate to each subscriber, e.g. 48 for /48">ⓘ</span><br>
          <input type="number" name="delegation_prefix" min="1" max="128" required placeholder="48" style="width:70px">
        </label>
      </div>
      <div class="row">
        <label class="flex-1">Description<br><input name="description" class="w-full"></label>
        <?php if ($sites): ?>
        <label>Site<br>
          <select name="site_id">
            <option value="">— Global —</option>
            <?php foreach ($sites as $site): ?>
              <option value="<?= to_int($site['id']) ?>"><?= e(to_str($site['name'])) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php endif; ?>
      </div>
      <p><button type="submit">Create PD Pool</button></p>
    </form>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Overview</h2>
    <div class="grid cols-2">
      <div class="metric">
        <div class="label">PD Pools</div>
        <div class="value"><?= e((string)count($pools)) ?></div>
      </div>
      <div class="metric">
        <div class="label">Active delegations</div>
        <div class="value"><?= e((string)array_sum(array_map(fn($p) => to_int($p['delegation_count']), $pools))) ?></div>
      </div>
    </div>
    <p class="muted mt-8">Click a pool below to view and manage its delegations.</p>
  </div>
</div>

<div class="card mt-16">
  <h2>PD Pools</h2>

  <?php if (!$pools): ?>
    <div class="empty-state">No PD pools yet. <a class="action-pill" href="#add-pool">+ Add Pool</a></div>
  <?php else: ?>
    <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Parent Subnet</th>
          <th>Delegation Prefix</th>
          <th>Description</th>
          <th>Delegations</th>
          <th>Created</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($pools as $p): ?>
        <tr<?= to_int($p['id']) === $selectedPoolId ? ' class="row-selected"' : '' ?>>
          <td><b><?= e(to_str($p['subnet_cidr'])) ?></b></td>
          <td>/<?= to_int($p['delegation_prefix']) ?></td>
          <td><?= to_str($p['description']) !== '' ? e(to_str($p['description'])) : '<span class="muted">—</span>' ?></td>
          <td>
            <a href="pd_pools.php?pool_id=<?= to_int($p['id']) ?>"><?= to_int($p['delegation_count']) ?> delegation<?= to_int($p['delegation_count']) !== 1 ? 's' : '' ?></a>
          </td>
          <td class="muted"><?= e(ipam_format_datetime(to_str($p['created_at']))) ?></td>
          <td>
            <form method="post" action="pd_pools.php"
                  data-confirm="Delete this PD pool and all its delegations?">
              <input type="hidden" name="csrf"    value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action"  value="delete_pool">
              <input type="hidden" name="pool_id" value="<?= to_int($p['id']) ?>">
              <button type="submit" class="button-danger">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<?php if ($selectedPool !== null): ?>
<div class="card mt-16">
  <h2>Delegations — <?= e(to_str($selectedPool['subnet_cidr'])) ?> (/$<?= to_int($selectedPool['delegation_prefix']) ?>)</h2>

  <form method="post" action="pd_pools.php" class="row" style="flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:16px">
    <input type="hidden" name="csrf"    value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action"  value="create_delegation">
    <input type="hidden" name="pool_id" value="<?= to_int($selectedPoolId) ?>">
    <label class="flex-1">Delegated CIDR <span class="muted" data-tooltip="IPv6 prefix to delegate, e.g. 2001:db8:1::/48">ⓘ</span><br>
      <input name="cidr" required placeholder="e.g. 2001:db8:1::/48" class="w-full">
    </label>
    <?php if ($contacts): ?>
    <label>Subscriber<br>
      <select name="subscriber_id">
        <option value="">— None —</option>
        <?php foreach ($contacts as $c): ?>
          <option value="<?= to_int($c['id']) ?>"><?= e(to_str($c['name'])) ?><?= to_str($c['email']) !== '' ? ' &lt;' . e(to_str($c['email'])) . '&gt;' : '' ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php endif; ?>
    <label>Expires<br><input type="date" name="expires_at" style="width:140px"></label>
    <label class="flex-1">Notes<br><input name="notes" class="w-full"></label>
    <div><button type="submit">Delegate Prefix</button></div>
  </form>

  <?php if (!$delegations): ?>
    <div class="empty-state">No delegations in this pool yet.</div>
  <?php else: ?>
    <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Delegated Prefix</th>
          <th>Subscriber</th>
          <th>Delegated At</th>
          <th>Expires</th>
          <th>Notes</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($delegations as $d):
            $expired = to_str($d['expires_at']) !== '' && to_str($d['expires_at']) < date('Y-m-d');
      ?>
        <tr class="<?= $expired ? 'danger' : '' ?>">
          <td><b><?= e(to_str($d['cidr'])) ?></b></td>
          <td><?= to_str($d['subscriber_name']) !== '' ? e(to_str($d['subscriber_name'])) : '<span class="muted">—</span>' ?></td>
          <td class="muted"><?= e(ipam_format_datetime(to_str($d['delegated_at']))) ?></td>
          <td class="<?= $expired ? 'danger' : ($d['expires_at'] === null ? 'muted' : '') ?>">
            <?= $d['expires_at'] !== null ? e(to_str($d['expires_at'])) : '—' ?>
            <?= $expired ? '<span class="badge" style="background:var(--danger);color:#fff">Expired</span>' : '' ?>
          </td>
          <td><?= to_str($d['notes']) !== '' ? e(to_str($d['notes'])) : '<span class="muted">—</span>' ?></td>
          <td>
            <form method="post" action="pd_pools.php"
                  data-confirm="Revoke delegation <?= e(to_str($d['cidr'])) ?>?">
              <input type="hidden" name="csrf"          value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action"        value="delete_delegation">
              <input type="hidden" name="delegation_id" value="<?= to_int($d['id']) ?>">
              <input type="hidden" name="pool_id"       value="<?= to_int($selectedPoolId) ?>">
              <button type="submit" class="button-danger">Revoke</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php page_footer(); ?>
