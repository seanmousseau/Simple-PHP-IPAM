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
        $name  = trim(to_str($_POST['name']  ?? ''));
        $email = trim(to_str($_POST['email'] ?? ''));
        $phone = trim(to_str($_POST['phone'] ?? ''));
        $org   = trim(to_str($_POST['org']   ?? ''));
        $note  = trim(to_str($_POST['note']  ?? ''));

        if ($name === '') {
            $err = 'Contact name is required.';
        } else {
            $st = $db->prepare("INSERT INTO contacts (name, email, phone, org, note) VALUES (:n,:e,:p,:o,:nt)");
            $st->execute([':n' => $name, ':e' => $email, ':p' => $phone, ':o' => $org, ':nt' => $note]);
            $newId = (int)$db->lastInsertId();
            audit($db, 'contact.create', 'contact', $newId, "name=$name");
            flash_set("Contact \"$name\" created.");
            header('Location: contacts.php');
            exit;
        }
    } elseif ($action === 'update') {
        $id    = to_int($_POST['id']    ?? 0);
        $name  = trim(to_str($_POST['name']  ?? ''));
        $email = trim(to_str($_POST['email'] ?? ''));
        $phone = trim(to_str($_POST['phone'] ?? ''));
        $org   = trim(to_str($_POST['org']   ?? ''));
        $note  = trim(to_str($_POST['note']  ?? ''));

        if ($id <= 0 || $name === '') {
            $err = 'Contact name is required.';
        } else {
            $st = $db->prepare("UPDATE contacts SET name=:n, email=:e, phone=:p, org=:o, note=:nt, updated_at=" . ipam_dialect()->now() . " WHERE id=:id");
            $st->execute([':n' => $name, ':e' => $email, ':p' => $phone, ':o' => $org, ':nt' => $note, ':id' => $id]);
            audit($db, 'contact.update', 'contact', $id, "name=$name");
            flash_set("Contact \"$name\" updated.");
            header('Location: contacts.php');
            exit;
        }
    } elseif ($action === 'delete') {
        $id = to_int($_POST['id'] ?? 0);
        if ($id > 0) {
            $nameSt = $db->prepare("SELECT name FROM contacts WHERE id = :id");
            $nameSt->execute([':id' => $id]);
            /** @var array<string, mixed>|false $row */
            $row = $nameSt->fetch();
            // ON DELETE SET NULL on addresses.owner_contact_id handles FK cleanup
            $db->prepare("DELETE FROM contacts WHERE id = :id")->execute([':id' => $id]);
            audit($db, 'contact.delete', 'contact', $id, $row ? 'name=' . to_str($row['name']) : '');
            flash_set('Contact deleted.');
            header('Location: contacts.php');
            exit;
        }
    }
}

$sortCols = ['name' => 'c.name', 'email' => 'c.email', 'org' => 'c.org', 'created' => 'c.created_at'];
$sort = parse_sort($sortCols, 'name');

/** @var list<array<string, mixed>> $contacts */
$contacts = ($db->query("
    SELECT c.id, c.name, c.email, c.phone, c.org, c.note, c.created_at,
           (SELECT COUNT(*) FROM addresses a WHERE a.owner_contact_id = c.id) AS address_count
    FROM contacts c
    ORDER BY {$sort['sql']}
") ?: throw new \RuntimeException('Query failed'))->fetchAll();

page_header('Contacts');
?>
<div class="breadcrumbs">
  <a href="dashboard.php">Dashboard</a><span class="sep">›</span>
  <a href="#">Admin</a><span class="sep">›</span>
  <span>Contacts</span>
</div>

<div class="toolbar">
  <div>
    <h1>Contacts</h1>
    <div class="muted">Reusable contact records that can be linked to address entries as the owner.</div>
  </div>
</div>

<?php if ($err): ?><p class="danger"><?= e($err) ?></p><?php endif; ?>

<div class="grid cols-2">
  <div class="card" id="add-contact">
    <h2>Add Contact</h2>
    <form method="post" action="contacts.php">
      <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="create">
      <div class="row">
        <label class="flex-1">Name (required)<br><input name="name" required placeholder="e.g. Jane Smith" class="w-full"></label>
        <label class="flex-1">Email<br><input name="email" type="email" placeholder="jane@example.com" class="w-full"></label>
      </div>
      <div class="row">
        <label class="mw-160">Phone<br><input name="phone" placeholder="+1 555 0100" class="w-full"></label>
        <label class="flex-1">Organisation<br><input name="org" placeholder="Acme Corp" class="w-full"></label>
      </div>
      <div class="row">
        <label class="flex-1">Note<br><input name="note" class="w-full"></label>
      </div>
      <p><button type="submit">Create Contact</button></p>
    </form>
  </div>

  <div class="card">
    <h2>Overview</h2>
    <div class="grid cols-2">
      <div class="metric">
        <div class="label">Contacts</div>
        <div class="value"><?= e((string)count($contacts)) ?></div>
      </div>
      <div class="metric">
        <div class="label">Linked addresses</div>
        <div class="value"><?= e((string)array_sum(array_map(fn($c) => to_int($c['address_count']), $contacts))) ?></div>
      </div>
    </div>
    <p class="muted mt-8">Contacts are linked to addresses via the Owner field. Deleting a contact unlinks it from all addresses (free-text owner value is retained).</p>
  </div>
</div>

<div class="card mt-16">
  <h2>Existing Contacts</h2>

  <?php if (!$contacts): ?>
    <div class="empty-state">No contacts yet. <a class="action-pill" href="#add-contact">+ Add Contact</a></div>
  <?php else: ?>
    <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <?php $qs = '?';
                echo sort_th('name',    'Name',         $sort['col'], $sort['dir'], $qs);
                echo sort_th('email',   'Email',        $sort['col'], $sort['dir'], $qs);
          ?>
          <th>Phone</th>
          <?php echo sort_th('org', 'Organisation', $sort['col'], $sort['dir'], $qs); ?>
          <th>Note</th>
          <th>Addresses</th>
          <?php echo sort_th('created', 'Created', $sort['col'], $sort['dir'], $qs); ?>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($contacts as $c): ?>
        <tr>
          <td><b><?= e(to_str($c['name'])) ?></b></td>
          <td><?php if ($c['email'] !== ''): ?><a href="mailto:<?= e(to_str($c['email'])) ?>"><?= e(to_str($c['email'])) ?></a><?php else: ?><span class="muted">—</span><?php endif; ?></td>
          <td><?= $c['phone'] !== '' ? e(to_str($c['phone'])) : '<span class="muted">—</span>' ?></td>
          <td><?= $c['org']   !== '' ? e(to_str($c['org']))   : '<span class="muted">—</span>' ?></td>
          <td><?= $c['note']  !== '' ? e(to_str($c['note']))  : '<span class="muted">—</span>' ?></td>
          <td><?= to_int($c['address_count']) ?></td>
          <td class="muted"><?= e(display_datetime(to_str($c['created_at']))) ?></td>
          <td>
            <details>
              <summary>Edit/Delete</summary>
              <form method="post" action="contacts.php" class="mt-8">
                <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id"     value="<?= to_int($c['id']) ?>">
                <div class="row">
                  <label>Name<br><input name="name"  value="<?= e(to_str($c['name'])) ?>" required></label>
                  <label>Email<br><input name="email" type="email" value="<?= e(to_str($c['email'])) ?>"></label>
                  <label>Phone<br><input name="phone" value="<?= e(to_str($c['phone'])) ?>"></label>
                  <label>Organisation<br><input name="org" value="<?= e(to_str($c['org'])) ?>"></label>
                  <label class="flex-1">Note<br><input name="note" value="<?= e(to_str($c['note'])) ?>" class="w-full"></label>
                  <button type="submit">Save</button>
                </div>
              </form>
              <form method="post" action="contacts.php" class="mt-8"
                    data-confirm="Delete this contact? The owner field on linked addresses will be kept but the contact link will be removed.">
                <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id"     value="<?= to_int($c['id']) ?>">
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
