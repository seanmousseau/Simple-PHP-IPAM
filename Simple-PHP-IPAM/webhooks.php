<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
/** @var array<string, mixed> $config */
require_role('admin');
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

$err = '';

/** @var list<string> $allEvents */
$allEvents = [
    'subnet.create', 'subnet.update', 'subnet.delete',
    'address.create', 'address.update', 'address.delete',
];

// ── POST handlers ────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = to_str($_POST['action'] ?? '');

    if (demo_mode_enabled() && in_array($action, ['create', 'edit', 'delete', 'toggle', 'retry'], true)) {
        $err = 'This action is disabled in demo mode.';
        $action = '';
    }

    // Generate secret (AJAX)
    if ($action === 'gen_secret') {
        header('Content-Type: application/json');
        echo json_encode(['secret' => bin2hex(random_bytes(32))]);
        exit;
    }

    // Test-fire (AJAX — returns JSON, no delivery row written)
    if ($action === 'test_fire') {
        $id  = to_int($_POST['id'] ?? '0');
        $row = false;
        if ($id > 0) {
            $st = $db->prepare("SELECT * FROM webhooks WHERE id=:id");
            $st->execute([':id' => $id]);
            $row = $st->fetch();
        }
        header('Content-Type: application/json');
        if (!is_array($row)) {
            echo json_encode(['ok' => false, 'error' => 'Webhook not found.']);
            exit;
        }
        /** @var array<string, mixed> $row */
        if (!ipam_validate_webhook_url(to_str($row['url']), $config)) {
            echo json_encode(['ok' => false, 'error' => 'URL failed SSRF validation (private IP or invalid scheme).']);
            exit;
        }
        $payload = json_encode([
            'event'     => 'test.ping',
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'version'   => IPAM_VERSION,
            'actor'     => ['user_id' => current_user()['id'], 'username' => current_user()['username']],
            'data'      => ['message' => 'This is a test delivery from Simple PHP IPAM.'],
        ], JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            echo json_encode(['ok' => false, 'error' => 'JSON encode failed.']);
            exit;
        }
        $sig    = ipam_webhook_sign($payload, to_str($row['secret']));
        $result = ipam_webhook_deliver($row, 'test.ping', $payload, $sig);
        audit($db, 'webhook.test_fire', 'webhook', $id, "url=" . to_str($row['url']));
        echo json_encode([
            'ok'        => $result['status'] !== null && $result['status'] >= 200 && $result['status'] < 300,
            'status'    => $result['status'],
            'body'      => $result['body'],
            'signature' => $sig,
            'error'     => $result['error'],
        ]);
        exit;
    }

    if ($action === 'create') {
        $name   = trim(to_str($_POST['name']   ?? ''));
        $url    = trim(to_str($_POST['url']    ?? ''));
        $secret = trim(to_str($_POST['secret'] ?? ''));
        /** @var list<string> $events */
        $events = array_values(array_filter(
            array_map(static fn(mixed $v): string => to_str($v), (array)($_POST['events'] ?? [])),
            static fn(string $e): bool => in_array($e, $allEvents, true)
        ));

        if ($name === '') {
            $err = 'Name is required.';
        } elseif ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            $err = 'A valid URL is required.';
        } elseif (!ipam_validate_webhook_url($url, $config)) {
            $err = 'URL failed SSRF validation. Only public http/https URLs are allowed (enable Allow private IPs in Settings to override).';
        } elseif ($secret === '') {
            $err = 'Secret is required.';
        } elseif (count($events) === 0) {
            $err = 'At least one event must be selected.';
        } else {
            $st = $db->prepare("INSERT INTO webhooks (name, url, secret, events) VALUES (:n,:u,:s,:ev)");
            $st->execute([':n' => $name, ':u' => $url, ':s' => $secret, ':ev' => json_encode($events)]);
            $newId = ipam_last_insert_id($db, 'webhooks');
            audit($db, 'webhook.create', 'webhook', $newId, "name=$name url=$url");
            flash_set("Webhook \"$name\" created.");
            header('Location: webhooks.php');
            exit;
        }
    }

    if ($action === 'edit') {
        $id     = to_int($_POST['id'] ?? '0');
        $name   = trim(to_str($_POST['name']   ?? ''));
        $url    = trim(to_str($_POST['url']    ?? ''));
        $secret = trim(to_str($_POST['secret'] ?? ''));
        /** @var list<string> $events */
        $events = array_values(array_filter(
            array_map(static fn(mixed $v): string => to_str($v), (array)($_POST['events'] ?? [])),
            static fn(string $e): bool => in_array($e, $allEvents, true)
        ));

        if ($id <= 0) {
            $err = 'Invalid webhook.';
        } elseif ($name === '') {
            $err = 'Name is required.';
        } elseif ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            $err = 'A valid URL is required.';
        } elseif (!ipam_validate_webhook_url($url, $config)) {
            $err = 'URL failed SSRF validation. Only public http/https URLs are allowed.';
        } elseif ($secret === '') {
            $err = 'Secret is required.';
        } elseif (count($events) === 0) {
            $err = 'At least one event must be selected.';
        } else {
            $st = $db->prepare("UPDATE webhooks SET name=:n, url=:u, secret=:s, events=:ev WHERE id=:id");
            $st->execute([':n' => $name, ':u' => $url, ':s' => $secret, ':ev' => json_encode($events), ':id' => $id]);
            audit($db, 'webhook.update', 'webhook', $id, "name=$name url=$url");
            flash_set("Webhook updated.");
            header('Location: webhooks.php');
            exit;
        }
    }

    if ($action === 'toggle') {
        $id  = to_int($_POST['id'] ?? '0');
        $row = false;
        if ($id > 0) {
            $st = $db->prepare("SELECT id, name, is_active FROM webhooks WHERE id=:id");
            $st->execute([':id' => $id]);
            $row = $st->fetch();
        }
        if (is_array($row)) {
            /** @var array<string, mixed> $row */
            $newState = $row['is_active'] ? 0 : 1;
            $db->prepare("UPDATE webhooks SET is_active=:s WHERE id=:id")
               ->execute([':s' => $newState, ':id' => $id]);
            audit($db, 'webhook.toggle', 'webhook', $id, "active=$newState name=" . to_str($row['name']));
            flash_set('Webhook ' . ($newState ? 'enabled' : 'disabled') . '.');
        }
        header('Location: webhooks.php');
        exit;
    }

    if ($action === 'delete') {
        $id  = to_int($_POST['id'] ?? '0');
        $row = false;
        if ($id > 0) {
            $st = $db->prepare("SELECT id, name FROM webhooks WHERE id=:id");
            $st->execute([':id' => $id]);
            $row = $st->fetch();
        }
        if (is_array($row)) {
            /** @var array<string, mixed> $row */
            $db->prepare("DELETE FROM webhooks WHERE id=:id")->execute([':id' => $id]);
            audit($db, 'webhook.delete', 'webhook', $id, "name=" . to_str($row['name']));
            flash_set('Webhook deleted.');
        }
        header('Location: webhooks.php');
        exit;
    }

    if ($action === 'retry') {
        $delId = to_int($_POST['delivery_id'] ?? '0');
        $row   = false;
        if ($delId > 0) {
            $st = $db->prepare(
                "SELECT d.*, w.url, w.secret FROM webhook_deliveries d
                 JOIN webhooks w ON w.id = d.webhook_id
                 WHERE d.id=:id"
            );
            $st->execute([':id' => $delId]);
            $row = $st->fetch();
        }
        if (is_array($row) && ipam_validate_webhook_url(to_str($row['url']), $config)) {
            /** @var array<string, mixed> $row */
            $result  = ipam_webhook_deliver($row, to_str($row['event_type']), to_str($row['payload']), to_str($row['signature']));
            $attempt = to_int($row['attempt']) + 1;
            $ok      = $result['status'] !== null && $result['status'] >= 200 && $result['status'] < 300;
            $upd = $db->prepare(
                "UPDATE webhook_deliveries
                 SET attempt=:att, http_status=:st, response_body=:body, error=:err,
                     delivered_at=CASE WHEN :ok THEN datetime('now') ELSE NULL END
                 WHERE id=:id"
            );
            $upd->execute([
                ':att'  => $attempt,
                ':st'   => $result['status'],
                ':body' => $result['body'],
                ':err'  => $result['error'],
                ':ok'   => $ok ? 1 : 0,
                ':id'   => $delId,
            ]);
            $db->prepare("UPDATE webhooks SET last_delivery_at=datetime('now'), last_delivery_status=:st WHERE id=:id")
               ->execute([':st' => $result['status'], ':id' => $row['webhook_id']]);
            audit($db, 'webhook.retry', 'webhook', to_int($row['webhook_id']), "delivery_id=$delId status=" . to_str((string)($result['status'] ?? 'null')));
            flash_set($ok ? 'Delivery retried successfully.' : 'Retry attempted — delivery still failed.');
        }
        $whId = to_int($_POST['webhook_id'] ?? '0');
        header('Location: webhooks.php?view=deliveries&webhook_id=' . $whId);
        exit;
    }
}

// ── GET: determine view ──────────────────────────────────────────────────────

$view     = to_str($_GET['view'] ?? '');
$whId     = to_int($_GET['webhook_id'] ?? '0');
$editId   = to_int($_GET['edit'] ?? '0');
$editRow  = null;

if ($editId > 0) {
    $st = $db->prepare("SELECT * FROM webhooks WHERE id=:id");
    $st->execute([':id' => $editId]);
    $editRow = $st->fetch() ?: null;
}

// Fetch all webhooks for list view
$webhooks = $db->query(
    "SELECT w.*,
            (SELECT COUNT(*) FROM webhook_deliveries d WHERE d.webhook_id = w.id) AS delivery_count
     FROM webhooks w
     ORDER BY w.created_at DESC"
);
/** @var list<array<string, mixed>> $webhookList */
$webhookList = $webhooks ? $webhooks->fetchAll() : [];

// Delivery log view
/** @var list<array<string, mixed>> $deliveries */
$deliveries  = [];
/** @var array<string, mixed>|null $whRow */
$whRow       = null;
if ($view === 'deliveries' && $whId > 0) {
    $wSt = $db->prepare("SELECT * FROM webhooks WHERE id=:id");
    $wSt->execute([':id' => $whId]);
    $whRow = $wSt->fetch() ?: null;
    if (is_array($whRow)) {
        $dSt = $db->prepare(
            "SELECT * FROM webhook_deliveries WHERE webhook_id=:wid ORDER BY created_at DESC LIMIT 50"
        );
        $dSt->execute([':wid' => $whId]);
        /** @var list<array<string, mixed>> $deliveries */
        $deliveries = $dSt->fetchAll();
    }
}

$flash = flash_get();
page_header('Webhooks');

function wh_status_badge(mixed $status): string
{
    if ($status === null || $status === '') {
        return "<span class='badge' style='background:var(--badge-bg);color:var(--muted)'>pending</span>";
    }
    $code = to_int($status);
    if ($code >= 200 && $code < 300) {
        return "<span class='badge' style='background:#d1fae5;color:#065f46'>{$code}</span>";
    }
    if ($code >= 400 && $code < 500) {
        return "<span class='badge' style='background:#fef3c7;color:#92400e'>{$code}</span>";
    }
    return "<span class='badge' style='background:#fee2e2;color:#991b1b'>{$code}</span>";
}
?>
<main id='main-content'>
<div class='container'>

<?php if ($flash): ?>
  <div class='card <?= $flash['type'] === 'success' ? 'success' : 'danger' ?>' style='margin-bottom:1rem'>
    <?= e($flash['msg']) ?>
  </div>
<?php endif; ?>

<?php if ($err !== ''): ?>
  <div class='card danger' style='margin-bottom:1rem'><?= e($err) ?></div>
<?php endif; ?>

<?php if ($view === 'deliveries' && is_array($whRow)): ?>
  <?php /** @var array<string, mixed> $whRow */ ?>
  <!-- Delivery log view -->
  <div class='row' style='align-items:center;margin-bottom:1rem;gap:.5rem'>
    <h1 style='margin:0;flex:1'>Delivery log — <?= e(to_str($whRow['name'])) ?></h1>
    <a class='action-pill' href='webhooks.php'>&#8592; Back to webhooks</a>
  </div>
  <div style='overflow-x:auto'>
    <table class='data-table'>
      <thead>
        <tr>
          <th>Event</th>
          <th>Sent</th>
          <th>Attempt</th>
          <th>Status</th>
          <th>Error</th>
          <th>Delivered</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($deliveries === []): ?>
        <tr><td colspan='7' class='muted' style='text-align:center;padding:2rem'>No deliveries yet.</td></tr>
      <?php else: ?>
        <?php foreach ($deliveries as $d): ?>
        <tr>
          <td><code><?= e(to_str($d['event_type'])) ?></code></td>
          <td><?= e(ipam_format_datetime(to_str($d['created_at']))) ?></td>
          <td><?= e(to_str(to_int($d['attempt']))) ?></td>
          <td><?= wh_status_badge($d['http_status']) ?></td>
          <td><?php $dErr = to_str($d['error'] ?? ''); echo $dErr !== '' ? "<span title='" . e($dErr) . "' class='muted'>" . e(substr($dErr, 0, 60)) . (strlen($dErr) > 60 ? '…' : '') . '</span>' : ''; ?></td>
          <td><?= $d['delivered_at'] ? e(ipam_format_datetime(to_str($d['delivered_at']))) : '<span class="muted">—</span>' ?></td>
          <td>
            <?php if (!$d['delivered_at'] && to_int($d['attempt']) < 3): ?>
            <form method='post' style='display:inline'>
              <input type='hidden' name='csrf' value='<?= e(csrf_token()) ?>'>
              <input type='hidden' name='action' value='retry'>
              <input type='hidden' name='delivery_id' value='<?= to_int($d['id']) ?>'>
              <input type='hidden' name='webhook_id' value='<?= e(to_str((string)$whId)) ?>'>
              <button type='submit' class='action-pill'>Retry</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

<?php else: ?>
  <!-- Webhook list view -->
  <div class='row' style='align-items:center;margin-bottom:1rem;gap:.5rem'>
    <h1 style='margin:0;flex:1'>Webhooks</h1>
    <button type='button' class='action-pill' id='add-wh-btn'>+ Add webhook</button>
  </div>

  <div style='overflow-x:auto'>
    <table class='data-table'>
      <thead>
        <tr>
          <th>Name</th>
          <th>URL</th>
          <th>Events</th>
          <th>Active</th>
          <th>Last delivery</th>
          <th>Deliveries</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($webhookList === []): ?>
        <tr><td colspan='7' class='muted' style='text-align:center;padding:2rem'>No webhooks configured. Click <strong>+ Add webhook</strong> to create one.</td></tr>
      <?php else: ?>
        <?php foreach ($webhookList as $wh): ?>
        <tr>
          <td><?= e(to_str($wh['name'])) ?></td>
          <td class='muted' style='max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap' title='<?= e(to_str($wh['url'])) ?>'>
            <?= e(strlen(to_str($wh['url'])) > 50 ? substr(to_str($wh['url']), 0, 50) . '…' : to_str($wh['url'])) ?>
          </td>
          <td>
            <?php
            /** @var list<string> $evList */
            $evList = json_decode(to_str($wh['events']), true) ?? [];
            foreach ($evList as $ev):
            ?>
              <span class='badge'><?= e($ev) ?></span>
            <?php endforeach; ?>
          </td>
          <td><?= $wh['is_active'] ? "<span class='badge' style='background:#d1fae5;color:#065f46'>active</span>" : "<span class='badge' style='background:var(--badge-bg);color:var(--muted)'>inactive</span>" ?></td>
          <td>
            <?php if ($wh['last_delivery_at']): ?>
              <?= wh_status_badge($wh['last_delivery_status']) ?>
              <span class='muted'><?= e(ipam_format_datetime(to_str($wh['last_delivery_at']))) ?></span>
            <?php else: ?>
              <span class='muted'>—</span>
            <?php endif; ?>
          </td>
          <td>
            <a class='action-pill' href='webhooks.php?view=deliveries&webhook_id=<?= to_int($wh['id']) ?>'>
              <?= to_int($wh['delivery_count']) ?> log
            </a>
          </td>
          <td class='row' style='gap:.25rem;flex-wrap:wrap'>
            <button type='button' class='action-pill wh-edit-btn'
              data-id='<?= to_int($wh['id']) ?>'
              data-name='<?= e(to_str($wh['name'])) ?>'
              data-url='<?= e(to_str($wh['url'])) ?>'
              data-events='<?= e(to_str($wh['events'])) ?>'
            >Edit</button>

            <form method='post' style='display:inline'>
              <input type='hidden' name='csrf' value='<?= e(csrf_token()) ?>'>
              <input type='hidden' name='action' value='toggle'>
              <input type='hidden' name='id' value='<?= to_int($wh['id']) ?>'>
              <button type='submit' class='action-pill'><?= $wh['is_active'] ? 'Disable' : 'Enable' ?></button>
            </form>

            <button type='button' class='action-pill wh-testfire-btn'
              data-id='<?= to_int($wh['id']) ?>'
              data-name='<?= e(to_str($wh['name'])) ?>'
            >Test</button>

            <form method='post' style='display:inline'
              data-wh-name='<?= e(to_str($wh['name'])) ?>'
              onsubmit="return confirm('Delete webhook ' + this.dataset.whName + '? This will also delete all delivery history.')">
              <input type='hidden' name='csrf' value='<?= e(csrf_token()) ?>'>
              <input type='hidden' name='action' value='delete'>
              <input type='hidden' name='id' value='<?= to_int($wh['id']) ?>'>
              <button type='submit' class='action-pill button-danger'>Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

</div>
</main>

<!-- Add/Edit webhook drawer -->
<div id='wh-form-overlay' style='display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:var(--z-overlay)'></div>
<div id='wh-form-drawer' style='display:none;position:fixed;top:0;right:0;width:min(480px,100vw);height:100vh;background:var(--card);border-left:1px solid var(--border);z-index:var(--z-drawer);overflow-y:auto;padding:1.5rem'>
  <div class='row' style='align-items:center;margin-bottom:1.25rem'>
    <strong id='wh-drawer-title' style='flex:1;font-size:1.1rem'>Add webhook</strong>
    <button type='button' id='wh-drawer-close' aria-label='Close'>&times;</button>
  </div>
  <form method='post' id='wh-form'>
    <input type='hidden' name='csrf' value='<?= e(csrf_token()) ?>'>
    <input type='hidden' name='action' value='create' id='wh-form-action'>
    <input type='hidden' name='id' value='' id='wh-form-id'>

    <?php if ($err !== '' && ($editId > 0 || isset($_POST['action']))): ?>
    <div class='card danger' style='margin-bottom:1rem'><?= e($err) ?></div>
    <?php endif; ?>

    <label>Name
      <input type='text' name='name' id='wh-f-name' required maxlength='100'
        value='<?= e(to_str($_POST['name'] ?? '')) ?>' placeholder='My webhook'>
    </label>

    <label style='margin-top:.75rem'>Target URL
      <input type='url' name='url' id='wh-f-url' required
        value='<?= e(to_str($_POST['url'] ?? '')) ?>' placeholder='https://example.com/hook'>
    </label>

    <label style='margin-top:.75rem'>Secret (HMAC-SHA256 key)
      <div class='row' style='gap:.5rem'>
        <input type='text' name='secret' id='wh-f-secret' required
          value='<?= e(to_str($_POST['secret'] ?? '')) ?>' placeholder='hex secret'
          style='flex:1;font-family:var(--font-mono);font-size:.8rem'>
        <button type='button' id='wh-gen-secret' class='action-pill'>Generate</button>
      </div>
    </label>

    <fieldset style='margin-top:.75rem;border:1px solid var(--border);border-radius:var(--radius-md);padding:.75rem'>
      <legend style='padding:0 .5rem;font-weight:600'>Events</legend>
      <?php foreach ($allEvents as $ev): ?>
      <label style='display:flex;align-items:center;gap:.5rem;margin:.25rem 0;cursor:pointer'>
        <input type='checkbox' name='events[]' value='<?= e($ev) ?>' class='wh-event-cb'
          <?= in_array($ev, (array)($_POST['events'] ?? []), true) ? 'checked' : '' ?>>
        <code style='font-size:.82rem'><?= e($ev) ?></code>
      </label>
      <?php endforeach; ?>
    </fieldset>

    <div class='row' style='margin-top:1.25rem;gap:.5rem'>
      <button type='submit' class='action-pill'>Save</button>
      <button type='button' id='wh-drawer-close2' class='action-pill button-secondary'>Cancel</button>
    </div>
  </form>

  <!-- Test-fire result panel (shown after clicking Test) -->
  <div id='wh-test-panel' style='display:none;margin-top:1.5rem'>
    <hr>
    <strong>Test result</strong>
    <div id='wh-test-result' style='margin-top:.5rem'></div>
  </div>
</div>

<?php /* Webhook drawer JS lives in assets/app.js (CSP fix #645) */ ?>

<?php page_footer(); ?>
