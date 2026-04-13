<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_role('admin');
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = to_str($_POST['action'] ?? '');

    if (demo_mode_enabled() && in_array($action, ['create', 'update', 'delete'], true)) {
        $err = 'This action is disabled in demo mode.';
        $action = '';
    }

    if ($action === 'create') {
        $cidr  = trim(to_str($_POST['cidr'] ?? ''));
        $desc  = trim(to_str($_POST['description'] ?? ''));
        $rir   = trim(to_str($_POST['rir'] ?? ''));
        $notes = trim(to_str($_POST['notes'] ?? ''));

        $parsed = $cidr !== '' ? parse_cidr($cidr) : null;
        if ($parsed === null) {
            $err = 'Invalid CIDR notation.';
        } else {
            try {
                $st = $db->prepare("INSERT INTO aggregates (cidr, ip_version, network, network_bin, prefix, description, rir, notes) VALUES (:cidr,:ver,:net,:bin,:pfx,:desc,:rir,:notes)");
                $st->execute([
                    ':cidr'  => $parsed['network'] . '/' . $parsed['prefix'],
                    ':ver'   => $parsed['version'],
                    ':net'   => $parsed['network'],
                    ':bin'   => $parsed['net_bin'],
                    ':pfx'   => $parsed['prefix'],
                    ':desc'  => $desc,
                    ':rir'   => $rir,
                    ':notes' => $notes,
                ]);
                $newId = (int)$db->lastInsertId();
                audit($db, 'aggregate.create', 'aggregate', $newId, "cidr={$cidr}");
                flash_set("Aggregate $cidr created.");
                header('Location: aggregates.php');
                exit;
            } catch (PDOException $e) {
                $err = 'Could not create aggregate (duplicate CIDR?).';
            }
        }
    } elseif ($action === 'update') {
        $id    = to_int($_POST['id'] ?? 0);
        $desc  = trim(to_str($_POST['description'] ?? ''));
        $rir   = trim(to_str($_POST['rir'] ?? ''));
        $notes = trim(to_str($_POST['notes'] ?? ''));

        if ($id <= 0) {
            $err = 'Invalid aggregate.';
        } else {
            $st = $db->prepare("UPDATE aggregates SET description=:desc, rir=:rir, notes=:notes, updated_at=datetime('now') WHERE id=:id");
            $st->execute([':desc' => $desc, ':rir' => $rir, ':notes' => $notes, ':id' => $id]);
            audit($db, 'aggregate.update', 'aggregate', $id, '');
            flash_set('Aggregate updated.');
            header('Location: aggregates.php');
            exit;
        }
    } elseif ($action === 'delete') {
        $id = to_int($_POST['id'] ?? 0);
        if ($id > 0) {
            $st = $db->prepare("SELECT cidr FROM aggregates WHERE id = :id");
            $st->execute([':id' => $id]);
            /** @var array<string,mixed>|false $row */
            $row = $st->fetch();
            $db->prepare("DELETE FROM aggregates WHERE id = :id")->execute([':id' => $id]);
            audit($db, 'aggregate.delete', 'aggregate', $id, $row ? 'cidr=' . to_str($row['cidr']) : '');
            flash_set('Aggregate deleted.');
            header('Location: aggregates.php');
            exit;
        }
    }
}

$sortCols = ['cidr' => 'a.cidr', 'rir' => 'a.rir', 'created' => 'a.created_at'];
$sort = parse_sort($sortCols, 'cidr');

/** @var list<array<string,mixed>> $aggregates */
$aggregates = ($db->query("
    SELECT id, cidr, ip_version, network, prefix, description, rir, notes, date_added, created_at
    FROM aggregates a
    ORDER BY {$sort['sql']}
") ?: throw new \RuntimeException('Query failed'))->fetchAll();

// Subnet coverage: count subnets whose network falls within each aggregate
/** @var array<int,int> $coverageMap */
$coverageMap = [];
foreach ($aggregates as $agg) {
    $st = $db->prepare("SELECT COUNT(*) FROM subnets WHERE ip_version = :ver AND prefix >= :pfx");
    $st->execute([':ver' => to_int($agg['ip_version']), ':pfx' => to_int($agg['prefix'])]);
    $coverageMap[to_int($agg['id'])] = (int)$st->fetchColumn();
}

page_header('Aggregates');
?>
<div class="breadcrumbs">
  <a href="dashboard.php">Dashboard</a><span class="sep">›</span>
  <a href="#">Admin</a><span class="sep">›</span>
  <span>Aggregates</span>
</div>

<div class="toolbar">
  <div>
    <h1>Aggregates</h1>
    <div class="muted">Supernet/aggregate tracking — top-level blocks assigned by RIRs or internal allocation policy.</div>
  </div>
</div>

<?php if ($err): ?><p class="danger"><?= e($err) ?></p><?php endif; ?>

<div class="grid cols-2">
  <div class="card" id="add-aggregate">
    <h2>Add Aggregate</h2>
    <form method="post" action="aggregates.php">
      <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="create">
      <div class="row">
        <label class="flex-1">CIDR <span class="muted" data-tooltip="e.g. 10.0.0.0/8 or 2001:db8::/32">ⓘ</span><br>
          <input name="cidr" required placeholder="e.g. 10.0.0.0/8" class="w-full">
        </label>
        <label>RIR <span class="muted" data-tooltip="Regional Internet Registry, e.g. ARIN, RIPE, APNIC">ⓘ</span><br>
          <select name="rir">
            <option value="">— None —</option>
            <?php foreach (['ARIN', 'RIPE', 'APNIC', 'LACNIC', 'AFRINIC', 'Internal'] as $r): ?>
              <option value="<?= e($r) ?>"><?= e($r) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <div class="row">
        <label class="flex-1">Description<br><input name="description" class="w-full"></label>
      </div>
      <div class="row">
        <label class="flex-1">Notes<br><textarea name="notes" rows="2" class="w-full"></textarea></label>
      </div>
      <p><button type="submit">Create Aggregate</button></p>
    </form>
  </div>

  <div class="card">
    <h2>Overview</h2>
    <div class="grid cols-2">
      <div class="metric">
        <div class="label">Aggregates</div>
        <div class="value"><?= e((string)count($aggregates)) ?></div>
      </div>
      <div class="metric">
        <div class="label">IPv4</div>
        <div class="value"><?= e((string)count(array_filter($aggregates, fn($a) => to_int($a['ip_version']) === 4))) ?></div>
      </div>
      <div class="metric">
        <div class="label">IPv6</div>
        <div class="value"><?= e((string)count(array_filter($aggregates, fn($a) => to_int($a['ip_version']) === 6))) ?></div>
      </div>
    </div>
  </div>
</div>

<div class="card mt-16">
  <h2>Existing Aggregates</h2>

  <?php if (!$aggregates): ?>
    <div class="empty-state">No aggregates yet. <a class="action-pill" href="#add-aggregate">+ Add Aggregate</a></div>
  <?php else: ?>
    <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <?php $qs = '?';
                echo sort_th('cidr',    'CIDR',    $sort['col'], $sort['dir'], $qs);
                echo sort_th('rir',     'RIR',     $sort['col'], $sort['dir'], $qs);
          ?>
          <th>Ver</th>
          <th>Description</th>
          <th>Subnets</th>
          <?php echo sort_th('created', 'Created', $sort['col'], $sort['dir'], $qs); ?>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($aggregates as $a): ?>
        <tr>
          <td><b><?= e(to_str($a['cidr'])) ?></b></td>
          <td><?= to_str($a['rir']) !== '' ? e(to_str($a['rir'])) : '<span class="muted">—</span>' ?></td>
          <td><span class="badge"><?= to_int($a['ip_version']) === 6 ? 'IPv6' : 'IPv4' ?></span></td>
          <td><?= to_str($a['description']) !== '' ? e(to_str($a['description'])) : '<span class="muted">—</span>' ?></td>
          <td><?= $coverageMap[to_int($a['id'])] ?? 0 ?></td>
          <td class="muted"><?= e(display_datetime(to_str($a['created_at']))) ?></td>
          <td>
            <details>
              <summary>Edit/Delete</summary>
              <form method="post" action="aggregates.php" class="mt-8">
                <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id"     value="<?= to_int($a['id']) ?>">
                <div class="row">
                  <label>RIR<br>
                    <select name="rir">
                      <option value="">— None —</option>
                      <?php foreach (['ARIN', 'RIPE', 'APNIC', 'LACNIC', 'AFRINIC', 'Internal'] as $r): ?>
                        <option value="<?= e($r) ?>"<?= to_str($a['rir']) === $r ? ' selected' : '' ?>><?= e($r) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label class="flex-1">Description<br><input name="description" value="<?= e(to_str($a['description'])) ?>" class="w-full"></label>
                </div>
                <div class="row mt-8">
                  <label class="flex-1">Notes<br><textarea name="notes" rows="2" class="w-full"><?= e(to_str($a['notes'])) ?></textarea></label>
                </div>
                <div class="row mt-8"><button type="submit">Save</button></div>
              </form>
              <form method="post" action="aggregates.php" class="mt-8"
                    data-confirm="Delete aggregate <?= e(to_str($a['cidr'])) ?>?">
                <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id"     value="<?= to_int($a['id']) ?>">
                <button type="submit" class="button-danger">Delete</button>
              </form>
            </details>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<?php page_footer(); ?>
