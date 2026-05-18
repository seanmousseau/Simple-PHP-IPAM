<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
}

$err = '';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = to_str($_POST['action'] ?? '');

    if ($action === 'create') {
        $name     = trim(to_str($_POST['name'] ?? ''));
        $desc     = trim(to_str($_POST['description'] ?? ''));
        $parentId = to_int($_POST['parent_id'] ?? 0) ?: null;

        if ($name === '') {
            $err = 'Site name is required.';
        } elseif (($parentErr = ipam_site_validate_parent($db, $parentId, null)) !== null) {
            $err = $parentErr;
        } else {
            try {
                $st = $db->prepare("INSERT INTO sites (name, description, parent_id) VALUES (:n, :d, :pid)");
                $st->execute([':n' => $name, ':d' => $desc, ':pid' => $parentId]);
                $newSiteId = ipam_last_insert_id($db, 'sites');
                save_contacts_for_entity($db, 'site', $newSiteId, parse_contact_assignments($_POST));
                audit($db, 'site.create', 'site', $newSiteId, "name=$name");
                flash_set('Site created.');
                header('Location: sites.php');
                exit;
            } catch (PDOException $e) {
                $err = 'Could not create site (duplicate name?).';
            }
        }
    } elseif ($action === 'update') {
        $id       = to_int($_POST['id'] ?? 0);
        $name     = trim(to_str($_POST['name'] ?? ''));
        $desc     = trim(to_str($_POST['description'] ?? ''));
        $parentId = to_int($_POST['parent_id'] ?? 0) ?: null;

        if ($id <= 0 || $name === '') {
            $err = 'Valid site id and name are required.';
        } elseif (($parentErr = ipam_site_validate_parent($db, $parentId, $id)) !== null) {
            $err = $parentErr;
        } else {
            try {
                $st = $db->prepare("UPDATE sites SET name = :n, description = :d, parent_id = :pid WHERE id = :id");
                $st->execute([':n' => $name, ':d' => $desc, ':pid' => $parentId, ':id' => $id]);
                save_contacts_for_entity($db, 'site', $id, parse_contact_assignments($_POST));
                audit($db, 'site.update', 'site', $id, "name=$name");
                $msg = 'Site updated.';
            } catch (PDOException $e) {
                $err = 'Could not update site (duplicate name?).';
            }
        }
    } elseif ($action === 'delete') {
        $id = to_int($_POST['id'] ?? 0);
        if ($id > 0) {
            // Promote children: clear their parent_id before deleting
            $db->prepare("UPDATE sites SET parent_id = NULL WHERE parent_id = :id")->execute([':id' => $id]);
            // Detach subnets
            $db->prepare("UPDATE subnets SET site_id = NULL WHERE site_id = :id")->execute([':id' => $id]);
            $db->prepare("DELETE FROM sites WHERE id = :id")->execute([':id' => $id]);

            audit($db, 'site.delete', 'site', $id, '');
            flash_set('Site deleted.');
            header('Location: sites.php');
            exit;
        }
    }
}

$sortCols = ['name' => 's.name', 'created' => 's.created_at'];
$siteSort = parse_sort($sortCols, 'name');

$st = $db->prepare("
    SELECT s.id, s.name, s.description, s.created_at, s.parent_id,
           p.name AS parent_name,
           (SELECT COUNT(*) FROM subnets sn WHERE sn.site_id = s.id) AS subnet_count
    FROM sites s
    LEFT JOIN sites p ON p.id = s.parent_id
    ORDER BY {$siteSort['sql']}
");
$st->execute();
/** @var list<array<string, mixed>> $sites */
$sites = $st->fetchAll();

$_cSt = $db->query("SELECT id, name, email FROM contacts ORDER BY name");
/** @var list<array<string, mixed>> $allContacts */
$allContacts = $_cSt !== false ? $_cSt->fetchAll() : [];

// Build a quick map for the parent picker — only root sites are valid parents (depth limit = 2)
/** @var list<array<string, mixed>> $rootSites */
$rootSites = array_values(array_filter($sites, fn($s) => $s['parent_id'] === null));

// Build display tree: parents first, children indented
/** @var list<array<string, mixed>> $displayRows */
$displayRows = [];
foreach ($rootSites as $root) {
    $root['_depth'] = 0;
    $displayRows[]  = $root;
    foreach ($sites as $child) {
        if (to_int($child['parent_id']) === to_int($root['id'])) {
            $child['_depth'] = 1;
            $displayRows[]   = $child;
        }
    }
}

// Build child-count map for collapsible toggles
/** @var array<int, int> $childCounts */
$childCounts = [];
foreach ($displayRows as $row) {
    $pid = to_int($row['parent_id'] ?? 0);
    if ($pid > 0) {
        $childCounts[$pid] = ($childCounts[$pid] ?? 0) + 1;
    }
}
/** @var array<int, int> $parentsWithChildren */
$parentsWithChildren = array_filter($childCounts, fn($c) => $c > 0);

page_header('Sites');
?>
<div class="breadcrumbs">
  <a href="dashboard.php">Dashboard</a><span class="sep">›</span>
  <a href="#">Admin</a><span class="sep">›</span>
  <span>Sites</span>
</div>

<div class="toolbar">
  <div>
    <h1>Sites</h1>
    <div class="muted">Group subnets by site. Parent sites act as regions; depth is limited to 2 levels.</div>
  </div>
</div>

<?php if ($err): ?><p class="danger"><?= e($err) ?></p><?php endif; ?>
<?php if ($msg): ?><p class="success"><?= e($msg) ?></p><?php endif; ?>

<div class="grid cols-2">
  <div class="card" id="add-site">
    <h2>Add Site</h2>
    <form method="post" action="sites.php">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="create">

      <div class="row">
        <label>Site name<br><input name="name" required></label>
      </div>
      <div class="row">
        <label class="flex-1">Description<br><input name="description" class="w-full"></label>
        <?php if ($rootSites): ?>
        <label>Parent site (region)<br>
          <select name="parent_id">
            <option value="">— None (root) —</option>
            <?php foreach ($rootSites as $rs): ?>
              <option value="<?= to_int($rs['id']) ?>"><?= e(to_str($rs['name'])) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php endif; ?>
      </div>

      <?php if ($allContacts): ?>
      <div class="row">
        <label>Contacts</label>
      </div>
      <div class="contact-picker" data-contacts='<?= e(json_encode(array_map(fn($c) => ['id' => to_int($c['id']), 'name' => to_str($c['name']), 'email' => to_str($c['email'])], $allContacts), JSON_UNESCAPED_SLASHES) ?: '[]') ?>'>
        <div class="contact-picker-rows"></div>
        <button type="button" class="button-secondary btn-sm contact-picker-add">+ Add contact</button>
      </div>
      <?php endif; ?>

      <p><button type="submit">Create Site</button></p>
    </form>
  </div>

  <div class="card">
    <h2>Overview</h2>
    <div class="grid cols-2">
      <div class="metric">
        <div class="label">Sites</div>
        <div class="value"><?= e((string)count($sites)) ?></div>
      </div>
      <div class="metric">
        <div class="label">Subnets grouped</div>
        <div class="value"><?= e((string)array_sum(array_map(fn($s) => to_int($s['subnet_count']), $sites))) ?></div>
      </div>
    </div>
  </div>
</div>

<div class="card mt-16">
  <h2>Existing Sites</h2>

  <?php if (!$sites): ?>
    <div class="empty-state">No sites yet. <a class="action-pill" href="#add-site">+ Add Site</a></div>
  <?php else: ?>
    <?php if (count($parentsWithChildren) >= 2): ?>
    <div class="toolbar" style="margin-bottom:var(--space-3)">
      <div></div>
      <div>
        <button type="button" class="button-secondary btn-sm" data-collapsible-expand-all><?= icon('plus') ?> Expand all</button>
        <button type="button" class="button-secondary btn-sm" data-collapsible-collapse-all><?= icon('x') ?> Collapse all</button>
      </div>
    </div>
    <?php endif; ?>
    <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <?php $siteQs = '?';
                echo sort_th('name',    'Name',    $siteSort['col'], $siteSort['dir'], $siteQs);
                echo sort_th('created', 'Created', $siteSort['col'], $siteSort['dir'], $siteQs);
          ?>
          <th>Parent</th>
          <th>Description</th>
          <th>Contacts</th>
          <th>Subnets</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($displayRows as $site): ?>
        <?php
          $depth   = to_int($site['_depth'] ?? 0);
          $siteId  = to_int($site['id']);
          $pid     = to_int($site['parent_id'] ?? 0);
          $hasKids = ($childCounts[$siteId] ?? 0) > 0;
        ?>
        <tr<?= ($depth === 1 && $pid > 0) ? ' data-collapsible-child="' . $pid . '"' : '' ?>>
          <td style="padding-left: <?= 16 + $depth * 20 ?>px">
            <?php if ($depth > 0): ?><span class="muted">↳ </span><?php endif; ?>
            <b><?= e(to_str($site['name'])) ?></b>
            <?php if ($hasKids): ?>
              <button type="button" class="collapsible-toggle" data-collapsible-toggle
                      data-collapsible-group-id="<?= $siteId ?>"
                      aria-expanded="true"
                      aria-label="Toggle child sites for <?= e(to_str($site['name'])) ?>"><?= icon('chevron-down') ?></button>
              <span class="badge muted" style="font-size:.75rem"><?= $childCounts[$siteId] ?> <?= $childCounts[$siteId] === 1 ? 'site' : 'sites' ?></span>
            <?php endif; ?>
          </td>
          <td class="muted"><?= e(ipam_format_datetime(to_str($site['created_at']))) ?></td>
          <td><?= $site['parent_name'] ? e(to_str($site['parent_name'])) : '<span class="muted">—</span>' ?></td>
          <td><?= e(to_str($site['description'])) ?></td>
          <td><?= render_contact_badges($db, 'site', to_int($site['id'])) ?: '<span class="muted">—</span>' ?></td>
          <td><?= e(to_str($site['subnet_count'])) ?></td>
          <td>
            <details>
              <summary>Edit/Delete</summary>

              <form method="post" action="sites.php" class="row mt-8">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= to_int($site['id']) ?>">
                <label>Name<br><input name="name" value="<?= e(to_str($site['name'])) ?>" required></label>
                <label>Description<br><input name="description" value="<?= e(to_str($site['description'])) ?>"></label>
                <?php if ($rootSites): ?>
                <label>Parent<br>
                  <select name="parent_id">
                    <option value="">— None (root) —</option>
                    <?php foreach ($rootSites as $rs):
                          if (to_int($rs['id']) === to_int($site['id'])) continue; // can't be own parent
                    ?>
                      <option value="<?= to_int($rs['id']) ?>"
                        <?= to_int($site['parent_id']) === to_int($rs['id']) ? 'selected' : '' ?>>
                        <?= e(to_str($rs['name'])) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <?php endif; ?>
                <?php if ($allContacts):
                  $existing = get_contacts_for_entity($db, 'site', to_int($site['id']));
                ?>
                <div class="contact-picker" data-contacts='<?= e(json_encode(array_map(fn($c) => ['id' => to_int($c['id']), 'name' => to_str($c['name']), 'email' => to_str($c['email'])], $allContacts), JSON_UNESCAPED_SLASHES) ?: '[]') ?>' data-existing='<?= e(json_encode($existing, JSON_UNESCAPED_SLASHES) ?: '[]') ?>'>
                  <div class="contact-picker-rows"></div>
                  <button type="button" class="button-secondary btn-sm contact-picker-add">+ Add contact</button>
                </div>
                <?php endif; ?>
                <button type="submit">Save</button>
              </form>

              <form method="post" action="sites.php" class="mt-8"
                    data-confirm="Delete this site? Child sites become root sites. Subnets will be ungrouped.">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= to_int($site['id']) ?>">
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

<?php page_footer();
