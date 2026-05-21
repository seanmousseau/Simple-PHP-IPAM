<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
/** @var IpamConfig $config */
require_role('admin');

// B13 (#925, ADR-004): the CSV-import wizard state machine — dry-run analysis,
// plan application, result-file I/O — lives in lib/csv_import.php (loaded by
// lib.php). This page is render glue: step routing, HTML, redirects, CSRF,
// and request reading. The import-plan helpers (save/load/delete_import_plan)
// remain in lib.php.

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
}

$step = to_int($_GET['step'] ?? 1);
if ($step < 1 || $step > 4) $step = 1;

$err = '';
$msg = '';

$_SESSION['csv_import'] ??= [];
$wiz =& $_SESSION['csv_import'];
/** @var array<string, mixed> $wiz */

if (isset($_GET['reset'])) {
    // #1166 v3.29.0: $wiz['*_path'] entries are server-set under data/tmp/
    // by the wizard's earlier upload/dry-run/apply steps. Not user-controlled.
    // nosemgrep: php.lang.security.unlink-use.unlink-use -- session-stored server-controlled path under data/tmp/
    if (!empty($wiz['tmp_path']) && is_file(to_str($wiz['tmp_path']))) @unlink(to_str($wiz['tmp_path']));
    // nosemgrep: php.lang.security.unlink-use.unlink-use -- session-stored server-controlled path under data/tmp/
    if (!empty($wiz['plan_path']) && is_file(to_str($wiz['plan_path']))) @unlink(to_str($wiz['plan_path']));
    // nosemgrep: php.lang.security.unlink-use.unlink-use -- session-stored server-controlled path under data/tmp/
    if (!empty($wiz['result_path']) && is_file(to_str($wiz['result_path']))) @unlink(to_str($wiz['result_path']));
    $wiz = [];
    header('Location: import_csv.php');
    exit;
}

/** @param list<list<string>> $rows */
function render_preview_table(array $rows): void {
    if (!$rows) {
        echo "<div class='empty-state'>No preview rows.</div>";
        return;
    }

    echo "<table><tbody>";
    foreach ($rows as $r) {
        echo "<tr>";
        foreach ($r as $cell) echo "<td>" . e((string)$cell) . "</td>";
        echo "</tr>";
    }
    echo "</tbody></table>";
}

function action_class(string $action): string {
    return match($action) {
        'create', 'create_with_subnet' => 'report-create',
        'update' => 'report-update',
        'skip', 'duplicate_in_csv' => 'report-skip',
        'invalid', 'conflict' => 'report-invalid',
        'needs_subnet' => 'report-needs-subnet',
        default => ''
    };
}

// ── Pre-render: all POST handlers and guards that may redirect ────────────────
// header() cannot be called after page_header() emits HTML, so every redirect
// path must complete (or set $err and fall through) before any output is sent.

if ($step === 1 && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    $uploadRaw = $_FILES['csv'] ?? null;
    $upload = is_array($uploadRaw) ? $uploadRaw : null;
    if ($upload === null || empty($upload['tmp_name']) || !is_uploaded_file(to_str($upload['tmp_name']))) {
        $err = 'No file uploaded.';
    } else {
        $maxBytes = import_max_bytes();
        $size = to_int($upload['size'] ?? 0);
        if ($size > $maxBytes) {
            $err = 'File too large (max ' . (int)round($maxBytes / 1024 / 1024) . 'MB).';
        } else {
            ensure_tmp_dir();
            $dest = tmp_dir() . '/import-' . bin2hex(random_bytes(8)) . '.csv';
            if (!move_uploaded_file(to_str($upload['tmp_name']), $dest)) {
                $err = 'Failed to store uploaded file.';
            } else {
                @chmod($dest, 0600);
                $sample = file_get_contents($dest, false, null, 0, 4096);
                if ($sample === false) $sample = '';
                $wiz = ['tmp_path' => $dest, 'delimiter' => detect_csv_delimiter($sample), 'has_header' => 'yes'];
                header('Location: import_csv.php?step=2');
                exit;
            }
        }
    }
}

if ($step === 2) {
    if (empty($wiz['tmp_path']) || !is_file(to_str($wiz['tmp_path']))) {
        header('Location: import_csv.php?step=1');
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_mapping') {
        $d = to_str($_POST['delimiter'] ?? ',');
        if (!in_array($d, [',', ';', "\\t", '|'], true)) $d = ',';
        if ($d === "\\t") $d = "\t";
        $hdr = to_str($_POST['has_header'] ?? 'yes');
        if (!in_array($hdr, ['yes', 'no'], true)) $hdr = 'yes';
        $mapping = $_POST['map'] ?? [];
        if (!is_array($mapping)) $mapping = [];
        $ipMap = to_str($mapping['ip'] ?? 'ignore');
        if ($ipMap === 'ignore' || $ipMap === '' || !is_numeric($ipMap)) {
            $err = 'You must map an IP column.';
            // Preserve submitted values so the form re-renders with user's choices
            $wiz['delimiter'] = $d;
            $wiz['has_header'] = $hdr;
        } else {
            $dupMode = to_str($_POST['dup_mode'] ?? 'skip');
            if (!in_array($dupMode, ['skip', 'overwrite', 'fill_empty'], true)) $dupMode = 'skip';
            $wiz['delimiter'] = $d;
            $wiz['has_header'] = $hdr;
            $wiz['mapping'] = $mapping;
            $wiz['dup_mode'] = $dupMode;
            header('Location: import_csv.php?step=3');
            exit;
        }
    }
}

// #1166 v3.29.0: $wiz['tmp_path'] is server-set by the step-1 upload handler
// to a random filename under Simple-PHP-IPAM/data/tmp/. Not user-controlled at
// any call site that reaches is_file() here.
// nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- session-stored server-controlled path under data/tmp/
if ($step === 3 && (empty($wiz['tmp_path']) || !is_file(to_str($wiz['tmp_path'])))) {
    header('Location: import_csv.php?step=1');
    exit;
}

if ($step === 4 && ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'apply')) {
    header('Location: import_csv.php?step=3');
    exit;
}

page_header('Import CSV');
render_security_banner('import_csv', 'CSV import will create or update address records. Review the preview carefully before applying.');
?>

<div class="breadcrumbs">
  <a href="dashboard.php"><?= icon('home') ?> Dashboard</a><span class="sep">›</span><span><?= icon('import') ?> Import CSV</span>
</div>

<div class="toolbar">
  <div>
    <h1>Import CSV</h1>
    <div class="muted">Wizard: upload → map columns → dry run → apply import</div>
  </div>
</div>

<div class="page-actions">
  <a class="action-pill" href="import_csv.php?reset=1" data-confirm="Reset import wizard?">↺ Reset Wizard</a>
</div>

<?php if ($err): ?><p class="danger"><?= e($err) ?></p><?php endif; ?>

<?php
/* Step 1 */
if ($step === 1) {
    ?>

    <div class="card mt-16">
      <h2>Step 1 — Upload</h2>
      <form method="post" enctype="multipart/form-data" action="import_csv.php?step=1">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="upload">
        <p><input type="file" name="csv" accept=".csv,text/csv" required></p>
        <p class="muted">Max upload size: <?= e((string)((int)round(import_max_bytes()/1024/1024))) ?>MB</p>
        <p><button type="submit">Upload</button></p>
      </form>
    </div>

    <?php
    page_footer();
    exit;
}

/* Step 2 */
if ($step === 2) {
    $delimiter = to_str($wiz['delimiter'] ?? ',');
    $hasHeader = to_str($wiz['has_header'] ?? 'yes');

    $preview = csv_read_preview(to_str($wiz['tmp_path']), $delimiter, 15);

    $colCount = 0;
    foreach ($preview as $r) $colCount = max($colCount, count($r));

    $headerRow = $preview[0] ?? [];
    $headerNames = [];
    for ($i = 0; $i < $colCount; $i++) {
        $name = trim(to_str($headerRow[$i] ?? ''));
        $headerNames[$i] = ($name !== '') ? $name : ("Column " . ($i+1));
    }

    $colOptions = [];
    for ($i = 0; $i < $colCount; $i++) {
        $colOptions[(string)$i] = $headerNames[$i] . " (col " . ($i+1) . ")";
    }

    $map = is_array($wiz['mapping']) ? $wiz['mapping'] : [];
    $dupMode = $wiz['dup_mode'] ?? 'skip';
    ?>

    <div class="card mt-16">
      <h2>Step 2 — CSV settings + column mapping</h2>

      <h3>Preview</h3>
      <?php render_preview_table($preview); ?>

      <form method="post" action="import_csv.php?step=2" class="mt-16">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="set_mapping">

        <div class="row">
          <label>Delimiter<br>
            <select name="delimiter">
              <option value="," <?= $delimiter===','?'selected':'' ?>>comma (,)</option>
              <option value=";" <?= $delimiter===';'?'selected':'' ?>>semicolon (;)</option>
              <option value="\\t" <?= $delimiter==="\t"?'selected':'' ?>>tab</option>
              <option value="|" <?= $delimiter==='|'?'selected':'' ?>>pipe (|)</option>
            </select>
          </label>

          <label>CSV has headers?<br>
            <select name="has_header">
              <option value="yes" <?= $hasHeader==='yes'?'selected':'' ?>>yes (ignore first row)</option>
              <option value="no"  <?= $hasHeader==='no'?'selected':'' ?>>no</option>
            </select>
          </label>

          <label>Duplicate handling<br>
            <select name="dup_mode">
              <option value="skip" <?= $dupMode==='skip'?'selected':'' ?>>Skip existing</option>
              <option value="overwrite" <?= $dupMode==='overwrite'?'selected':'' ?>>Update (overwrite)</option>
              <option value="fill_empty" <?= $dupMode==='fill_empty'?'selected':'' ?>>Update only empty fields</option>
            </select>
          </label>
        </div>

        <h3 class="mt-18">Map columns</h3>
        <p class="muted">Map CSV columns to fields. IP is required. Others can be ignored.</p>

        <?php
        $fields = [
            'ip' => 'IP (required)',
            'hostname' => 'Hostname',
            'owner' => 'Owner',
            'group' => 'Group',
            'mac' => 'MAC address',
            'expires_at' => 'Expires (YYYY-MM-DD)',
            'status' => 'Status (used/reserved/free)',
            'note' => 'Note',
            'cidr' => 'Subnet CIDR (optional)',
            'prefix' => 'Prefix length (optional)',
            'netmask' => 'IPv4 netmask (optional)',
            'description' => 'Subnet description (optional, used only when creating subnet)',
            'device_name' => 'Device name (optional)',
            'interface_name' => 'Interface name (optional, requires device name)',
            'custom_fields' => 'Custom fields (JSON, optional)',
        ];

        echo "<table><tbody>";
        foreach ($fields as $k => $label) {
            echo "<tr><th>" . e($label) . "</th><td><select name='map[" . e($k) . "]'>";
            echo "<option value='ignore'>-- ignore --</option>";
            foreach ($colOptions as $idx => $name) {
                $sel = (isset($map[$k]) && to_str($map[$k]) === (string)$idx) ? "selected" : "";
                // #1166 v3.29.0: both interpolated values are e()-escaped above; $sel is the
                // local 'selected' literal. semgrep's taint flow doesn't recognise e() as the
                // escape boundary at this call site.
                // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag -- both $idx and $name pass through e() in the same expression
                echo "<option value='" . e((string)$idx) . "' $sel>" . e($name) . "</option>";
            }
            echo "</select></td></tr>";
        }
        echo "</tbody></table>";
        ?>

        <p class="mt-12"><button type="submit">Continue to Dry Run</button></p>
      </form>
    </div>

    <?php
    page_footer();
    exit;
}

/* Step 3 - Dry run / analyze */
if ($step === 3) {
    // #1166 v3.29.0: $wiz['plan_path'] is server-set by the dry-run step to a path
    // under data/tmp/. Same provenance as $wiz['tmp_path'] above.
    // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- session-stored server-controlled path under data/tmp/
    $rebuildPlan = empty($wiz['plan_path']) || !is_file(to_str($wiz['plan_path'])) || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'analyze');

    if ($rebuildPlan) {
        try {
            if (!empty($wiz['plan_path'])) delete_import_plan(to_str($wiz['plan_path']));
            $plan = ipam_csv_import_analyze($db, $wiz);
            $wiz['plan_path'] = save_import_plan($plan);
        } catch (Throwable $e) {
            $err = "Dry run failed: " . $e->getMessage();
            $plan = ['summary' => [], 'rows' => []];
        }
    } else {
        try {
            $plan = load_import_plan(to_str($wiz['plan_path']));
        } catch (Throwable $e) {
            $err = "Could not load dry run plan: " . $e->getMessage();
            $plan = ['summary' => [], 'rows' => []];
        }
    }

    $planSummary = $plan['summary'] ?? null;
    $summary = is_array($planSummary) ? $planSummary : [
        'parsed' => 0, 'invalid' => 0, 'create' => 0, 'update' => 0, 'skip' => 0,
        'needs_subnet_create' => 0, 'unknown_subnet_rows' => 0, 'duplicate_in_csv' => 0
    ];
    /** @var list<array<string, mixed>> $rows */
    $rows = $plan['rows'] ?? [];
    ?>

    <div class="card mt-16">
      <h2>Step 3 — Dry Run / Analysis</h2>

      <div class="grid cols-3">
        <div class="metric"><div class="label">Parsed rows</div><div class="value"><?= e(to_str($summary['parsed'])) ?></div></div>
        <div class="metric"><div class="label">Invalid rows</div><div class="value"><?= e(to_str($summary['invalid'])) ?></div></div>
        <div class="metric"><div class="label">Creates</div><div class="value"><?= e(to_str($summary['create'])) ?></div></div>
        <div class="metric"><div class="label">Updates</div><div class="value"><?= e(to_str($summary['update'])) ?></div></div>
        <div class="metric"><div class="label">Skips</div><div class="value"><?= e(to_str($summary['skip'])) ?></div></div>
        <div class="metric"><div class="label">Subnets to create</div><div class="value"><?= e(to_str($summary['needs_subnet_create'])) ?></div></div>
      </div>

      <div class="page-actions mt-16">
        <form method="post" action="import_csv.php?step=3" class="d-inline">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="analyze">
          <button type="submit" class="button-secondary">Re-run Dry Run</button>
        </form>

        <form method="post" action="import_csv.php?step=4" class="d-inline" data-confirm="Apply this import plan?">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="apply">
          <button type="submit">Apply Import</button>
        </form>

        <a class="action-pill" href="export_import_report.php?mode=plan"><?= icon('download') ?> Export Dry Run Report</a>
      </div>

      <h3 class="mt-18">Row Report</h3>
      <?php if (!$rows): ?>
        <div class="empty-state">No rows analyzed.</div>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Row #</th>
              <th>IP / Raw</th>
              <th>Action</th>
              <th>Subnet / CIDR</th>
              <th>Reason</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <?php $cls = action_class(to_str($r['display_action'] ?? $r['final_action'] ?? '')); ?>
            <?php $ov = $r['subnet_overlap_warning'] ?? null; ?>
            <tr>
              <td><?= e(to_str($r['row_num'])) ?></td>
              <td><?= e(to_str($r['ip'] ?? $r['ip_raw'] ?? '')) ?></td>
              <td><span class="<?= e($cls) ?>"><?= e(to_str($r['display_action'] ?? $r['final_action'] ?? '')) ?></span></td>
              <td><?= e(to_str($r['resolved_subnet_id'] ?? $r['resolved_cidr'] ?? '')) ?></td>
              <td>
                <?= e(to_str($r['reason'] ?? '')) ?>
                <?php if (is_array($ov)): ?>
                  <?php
                    $ovParts = [];
                    $ovParents = $ov['parents'] ?? null;
                    $ovChildren = $ov['children'] ?? null;
                    if (is_array($ovParents) && !empty($ovParents)) $ovParts[] = 'nested inside: ' . implode(', ', array_map(static fn(mixed $v): string => to_str($v), $ovParents));
                    if (is_array($ovChildren) && !empty($ovChildren)) $ovParts[] = 'parent of: ' . implode(', ', array_map(static fn(mixed $v): string => to_str($v), $ovChildren));
                  ?>
                  <br><span class="warning">Hierarchy: <?= e(implode('; ', $ovParts)) ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
    <?php
    page_footer();
    exit;
}

/* Step 4 - Apply import from saved plan */
if (demo_mode_enabled()) {
    $err = "Import apply is disabled in demo mode.";
    $step = 3; // Fall back to dry-run results display
// #1166 v3.29.0: $wiz['plan_path'] same provenance as above (server-set under data/tmp/).
// nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- session-stored server-controlled path under data/tmp/
} elseif (empty($wiz['plan_path']) || !is_file(to_str($wiz['plan_path']))) {
    $err = "No import plan found. Run dry run first.";
} else {
    try {
        $plan = load_import_plan(to_str($wiz['plan_path']));

        // All address/subnet/device DB work happens in lib/csv_import.php; the
        // page only handles the result-file path bookkeeping and cleanup.
        $applyResult = ipam_csv_import_apply_plan($db, $plan);
        $wiz['result_path'] = to_str($applyResult['result_path']);

        // #1166 v3.29.0: post-apply cleanup of server-staged wizard files.
        // $wiz['tmp_path']/['plan_path'] are server-set under data/tmp/, not user-controlled
        // (the ipam-unlink-user-path rule's own docs exempt session-stored paths; field-
        // insensitive taint flags $wiz because step 2 also stores $_POST['map'] into it).
        // nosemgrep: ipam-unlink-user-path, php.lang.security.unlink-use.unlink-use, php.lang.security.injection.tainted-filename.tainted-filename -- session-stored server-controlled path under data/tmp/
        if (!empty($wiz['tmp_path']) && is_file(to_str($wiz['tmp_path']))) @unlink(to_str($wiz['tmp_path']));
        // nosemgrep: ipam-unlink-user-path, php.lang.security.unlink-use.unlink-use, php.lang.security.injection.tainted-filename.tainted-filename -- session-stored server-controlled path under data/tmp/
        if (is_file(to_str($wiz['plan_path']))) @unlink(to_str($wiz['plan_path']));
        unset($wiz['tmp_path'], $wiz['plan_path']);

        $msg = to_str($applyResult['message']);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $err = "Import failed: " . $e->getMessage();
    }
}

$resultRows = [];
$summary = [];
// #1166 v3.29.0: $wiz['result_path'] same provenance as $wiz['tmp_path'] / ['plan_path'] —
// server-set under data/tmp/ when the apply step runs.
// nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- session-stored server-controlled path under data/tmp/
if (!empty($wiz['result_path']) && is_file(to_str($wiz['result_path']))) {
    try {
        $res = ipam_csv_import_load_result(to_str($wiz['result_path']));
        $resRows = $res['rows'] ?? null;
        /** @var list<array<string, mixed>> $resultRows */
        $resultRows = is_array($resRows) ? $resRows : [];
        $resSummary = $res['summary'] ?? null;
        $summary = is_array($resSummary) ? $resSummary : [];
    } catch (Throwable $e) {
        // ignore
    }
}

?>
<div class="card mt-16">
  <h2>Step 4 — Import Result</h2>
  <?php if ($err): ?><p class="danger"><?= e($err) ?></p><?php endif; ?>
  <?php if ($msg): ?><p class="success"><?= e($msg) ?></p><?php endif; ?>

  <?php if ($summary): ?>
    <div class="grid cols-3">
      <div class="metric"><div class="label">Created subnets</div><div class="value"><?= e(to_str($summary['created_subnets'])) ?></div></div>
      <div class="metric"><div class="label">Created addresses</div><div class="value"><?= e(to_str($summary['created_addresses'])) ?></div></div>
      <div class="metric"><div class="label">Updated addresses</div><div class="value"><?= e(to_str($summary['updated_addresses'])) ?></div></div>
      <div class="metric"><div class="label">Skipped rows</div><div class="value"><?= e(to_str($summary['skipped_rows'])) ?></div></div>
      <div class="metric"><div class="label">Conflicts</div><div class="value"><?= e(to_str($summary['conflicts'])) ?></div></div>
    </div>
  <?php endif; ?>

  <div class="page-actions mt-16">
    <a class="action-pill" href="import_csv.php"><?= icon('import') ?> Start New Import</a>
    <?php
    // #1166 v3.29.0: same server-controlled-path provenance.
    // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- session-stored server-controlled path under data/tmp/
    if (!empty($wiz['result_path']) && is_file(to_str($wiz['result_path']))): ?>
      <a class="action-pill" href="export_import_report.php?mode=result"><?= icon('download') ?> Export Result Report</a>
    <?php endif; ?>
  </div>

  <?php if ($resultRows): ?>
    <h3 class="mt-18">Row Results</h3>
    <table>
      <thead>
        <tr>
          <th>Row #</th>
          <th>IP</th>
          <th>Result</th>
          <th>Reason</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($resultRows as $r): ?>
        <?php $cls = action_class(to_str($r['final_result'] ?? '')); ?>
        <tr>
          <td><?= e(to_str($r['row_num'])) ?></td>
          <td><?= e(to_str($r['ip'])) ?></td>
          <td><span class="<?= e($cls) ?>"><?= e(to_str($r['final_result'])) ?></span></td>
          <td><?= e(to_str($r['reason'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php
page_footer();
exit;
