<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
/** @var IpamConfig $config */
require_role('admin');

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
    if (!empty($wiz['tmp_path']) && is_file(to_str($wiz['tmp_path']))) @unlink(to_str($wiz['tmp_path']));
    if (!empty($wiz['plan_path']) && is_file(to_str($wiz['plan_path']))) @unlink(to_str($wiz['plan_path']));
    if (!empty($wiz['result_path']) && is_file(to_str($wiz['result_path']))) @unlink(to_str($wiz['result_path']));
    $wiz = [];
    header('Location: import_csv.php');
    exit;
}

/** @param array<string, mixed> $wiz */
function wiz_require_file(array $wiz): void {
    if (empty($wiz['tmp_path']) || !is_file(to_str($wiz['tmp_path']))) {
        header('Location: import_csv.php?step=1');
        exit;
    }
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

function import_plan_result_path(): string
{
    return tmp_dir() . '/import-result-' . bin2hex(random_bytes(8)) . '.json';
}

/** @param array<string, mixed> $result */
function save_import_result(array $result): string
{
    ensure_tmp_dir();
    $path = import_plan_result_path();
    $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) throw new RuntimeException('Failed to encode import result');
    if (file_put_contents($path, $json) === false) {
        throw new RuntimeException('Failed to write import result');
    }
    @chmod($path, 0600);
    return $path;
}

/** @return array<string, mixed> */
function load_result_file(string $path): array
{
    if (!is_file($path)) throw new RuntimeException('Import result file not found');
    $json = file_get_contents($path);
    if ($json === false) throw new RuntimeException('Failed to read import result');
    $data = json_decode($json, true);
    if (!is_array($data)) throw new RuntimeException('Invalid import result');
    /** @var array<string, mixed> $data */
    return $data;
}

/**
 * @param array<string, mixed> $wiz
 * @return array<string, mixed>
 */
function analyze_import(PDO $db, array $wiz): array
{
    $delimiter = to_str($wiz['delimiter']);
    $hasHeader = to_str($wiz['has_header']);
    $map = is_array($wiz['mapping']) ? $wiz['mapping'] : [];
    $dupMode = to_str($wiz['dup_mode'] ?? 'skip');

    $fh = fopen(to_str($wiz['tmp_path']), 'rb');
    if (!$fh) throw new RuntimeException("Cannot open uploaded file");

    $rowNum = 0;
    $planRows = [];
    $summary = [
        'parsed' => 0,
        'invalid' => 0,
        'create' => 0,
        'update' => 0,
        'skip' => 0,
        'needs_subnet_create' => 0,
        'unknown_subnet_rows' => 0,
        'duplicate_in_csv' => 0,
    ];

    /** @var list<array<string, mixed>> $existingSubnets */
    $existingSubnets = ($db->query("SELECT id, cidr FROM subnets")
        ?: throw new \RuntimeException('Query failed'))->fetchAll();
    $existingByCidr = [];
    foreach ($existingSubnets as $s) $existingByCidr[to_str($s['cidr'])] = to_int($s['id']);

    $seenCsvKeys = []; // detect duplicate rows in CSV after resolution
    $overlapCache = []; // cidr => overlap result, avoid redundant DB queries per unique CIDR
    $maxProcessRows = 200000;

    while (!feof($fh) && $rowNum < $maxProcessRows) {
        $row = fgetcsv($fh, 0, $delimiter, '"', '');
        if ($row === false) break;
        if (count($row) === 1 && trim(to_str($row[0])) === '') continue;

        $rowNum++;
        if ($rowNum === 1 && $hasHeader === 'yes') continue;

        $summary['parsed']++;

        $get = function(string $key) use ($map, $row): ?string {
            $idx = $map[$key] ?? 'ignore';
            if ($idx === 'ignore' || $idx === '' || !is_numeric(to_str($idx))) return null;
            $i = to_int($idx);
            return isset($row[$i]) ? to_str($row[$i]) : null;
        };

        $entry = [
            'row_num' => $rowNum,
            'final_action' => 'invalid',
            'display_action' => 'invalid',
            'reason' => '',
            'ip' => null,
            'ip_raw' => to_str($get('ip') ?? ''),
            'version' => null,

            'resolved_cidr' => null,
            'resolved_subnet_id' => null,
            'subnet_must_be_created' => false,
            'existed_at_analysis' => null,

            'hostname' => trim(to_str($get('hostname') ?? '')),
            'owner' => trim(to_str($get('owner') ?? '')),
            'grp' => trim(to_str($get('group') ?? '')),
            'mac' => substr(trim(to_str($get('mac') ?? '')), 0, 64),
            'expires_at' => null,
            'note' => trim(to_str($get('note') ?? '')),
            'status' => normalize_status($get('status')),

            'subnet_description' => trim(to_str($get('description') ?? '')),
            'prefix_hint' => trim(to_str($get('prefix') ?? '')),
            'netmask_hint' => trim(to_str($get('netmask') ?? '')),
        ];

        $rawExpires = trim(to_str($get('expires_at') ?? ''));
        if ($rawExpires !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawExpires)) {
            $entry['expires_at'] = $rawExpires;
        }

        // Field length validation
        if (strlen($entry['hostname']) > 255) {
            $entry['reason'] = 'Hostname exceeds 255 characters';
            $summary['invalid']++;
            $planRows[] = $entry;
            continue;
        }
        if (strlen($entry['owner']) > 255) {
            $entry['reason'] = 'Owner exceeds 255 characters';
            $summary['invalid']++;
            $planRows[] = $entry;
            continue;
        }
        if (strlen($entry['note']) > 4000) {
            $entry['reason'] = 'Note exceeds 4000 characters';
            $summary['invalid']++;
            $planRows[] = $entry;
            continue;
        }

        $norm = $entry['ip_raw'] !== '' ? normalize_ip($entry['ip_raw']) : null;
        if (!$norm) {
            $entry['reason'] = 'Invalid IP';
            $summary['invalid']++;
            $planRows[] = $entry;
            continue;
        }

        $entry['ip'] = $norm['ip'];
        $entry['version'] = $norm['version'];

        $cidrHint = trim(to_str($get('cidr') ?? ''));

        if ($cidrHint !== '') {
            $p = parse_cidr($cidrHint);
            if (!$p) {
                $entry['reason'] = 'Invalid CIDR';
                $summary['invalid']++;
                $planRows[] = $entry;
                continue;
            }

            $normalizedCidr = $p['network'] . '/' . $p['prefix'];

            // Critical fix: validate IP belongs to CIDR
            if (!ip_in_cidr($entry['ip'], $p['network'], $p['prefix'])) {
                $entry['reason'] = 'IP does not belong to provided CIDR';
                $summary['invalid']++;
                $planRows[] = $entry;
                continue;
            }

            $entry['resolved_cidr'] = $normalizedCidr;

            $subnetId = $existingByCidr[$normalizedCidr] ?? null;
            if ($subnetId !== null) {
                $entry['resolved_subnet_id'] = $subnetId;
                $entry['subnet_must_be_created'] = false;
            } else {
                $entry['subnet_must_be_created'] = true;
                $summary['needs_subnet_create']++;
            }
        } else {
            $s = find_containing_subnet($db, $norm);
            if ($s) {
                $entry['resolved_subnet_id'] = to_int($s['id']);

                $st = $db->prepare("SELECT cidr FROM subnets WHERE id = :id");
                $st->execute([':id' => to_int($s['id'])]);
                /** @var array<string, mixed>|false $cidrRow */
                $cidrRow = $st->fetch();
                $entry['resolved_cidr'] = to_str($cidrRow['cidr'] ?? '');
            } else {
                // Determine inferred CIDR from hints/defaults now so plan is frozen
                $prefix = null;
                if ($entry['version'] === 4) {
                    if ($entry['prefix_hint'] !== '' && ctype_digit($entry['prefix_hint'])) {
                        $prefix = to_int($entry['prefix_hint']);
                        if ($prefix < 0 || $prefix > 32) $prefix = null;
                    } elseif ($entry['netmask_hint'] !== '') {
                        $pfx = netmask_to_prefix($entry['netmask_hint']);
                        if ($pfx !== null) $prefix = $pfx;
                    }
                    if ($prefix === null) $prefix = 24;
                } else {
                    if ($entry['prefix_hint'] !== '' && ctype_digit($entry['prefix_hint'])) {
                        $prefix = to_int($entry['prefix_hint']);
                        if ($prefix < 0 || $prefix > 128) $prefix = null;
                    }
                    if ($prefix === null) $prefix = 64;
                }

                $cidr = cidr_from_ip_and_prefix($norm, $prefix);
                $entry['resolved_cidr'] = $cidr;
                if (isset($existingByCidr[$cidr])) {
                    $entry['resolved_subnet_id'] = (int)$existingByCidr[$cidr];
                    $entry['subnet_must_be_created'] = false;
                } else {
                    $entry['subnet_must_be_created'] = true;
                    $summary['unknown_subnet_rows']++;
                    $summary['needs_subnet_create']++;
                }
            }
        }

        // Detect duplicate rows inside same CSV using resolved CIDR + IP
        $csvKey = $entry['resolved_cidr'] . '|' . $entry['ip'];
        if (isset($seenCsvKeys[$csvKey])) {
            $entry['final_action'] = 'skip';
            $entry['display_action'] = 'duplicate_in_csv';
            $entry['reason'] = 'Duplicate row in CSV';
            $summary['skip']++;
            $summary['duplicate_in_csv']++;
            $planRows[] = $entry;
            continue;
        }
        $seenCsvKeys[$csvKey] = true;

        // Determine duplicate state at analysis time
        if ($entry['resolved_subnet_id'] !== null) {
            $sel = $db->prepare("SELECT id FROM addresses WHERE subnet_id=:sid AND ip=:ip");
            $sel->execute([':sid' => $entry['resolved_subnet_id'], ':ip' => $entry['ip']]);
            /** @var array<string, mixed>|false $existing */
            $existing = $sel->fetch();
            $entry['existed_at_analysis'] = $existing ? true : false;
        } else {
            $entry['existed_at_analysis'] = false;
        }

        if ($entry['subnet_must_be_created']) {
            $entry['final_action'] = 'create';
            $entry['display_action'] = 'create_with_subnet';
            $entry['reason'] = 'Will create subnet and address';
            $summary['create']++;

            // Check if the new subnet would nest inside or contain existing subnets
            $cidrToCheck = to_str($entry['resolved_cidr']);
            if (!isset($overlapCache[$cidrToCheck])) {
                $overlapCache[$cidrToCheck] = detect_subnet_overlaps($db, $cidrToCheck);
            }
            $ov = $overlapCache[$cidrToCheck];
            if (!empty($ov['parents']) || !empty($ov['children'])) {
                $entry['subnet_overlap_warning'] = $ov;
            }
        } else {
            if ($entry['existed_at_analysis']) {
                if ($dupMode === 'skip') {
                    $entry['final_action'] = 'skip';
                    $entry['display_action'] = 'skip';
                    $entry['reason'] = 'Duplicate exists; configured to skip';
                    $summary['skip']++;
                } else {
                    $entry['final_action'] = 'update';
                    $entry['display_action'] = 'update';
                    $entry['reason'] = ($dupMode === 'fill_empty')
                        ? 'Duplicate exists; will fill empty fields'
                        : 'Duplicate exists; will overwrite';
                    $summary['update']++;
                }
            } else {
                $entry['final_action'] = 'create';
                $entry['display_action'] = 'create';
                $entry['reason'] = 'Will create address';
                $summary['create']++;
            }
        }

        $planRows[] = $entry;
    }

    fclose($fh);

    return [
        'meta' => [
            'dup_mode' => $dupMode,
            'analyzed_at' => date('c'),
        ],
        'summary' => $summary,
        'rows' => $planRows,
    ];
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
        $maxBytes = import_max_bytes($config);
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
  <a href="dashboard.php">🏠 Dashboard</a><span class="sep">›</span><span>⬆ Import CSV</span>
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
        <p class="muted">Max upload size: <?= e((string)((int)round(import_max_bytes($config)/1024/1024))) ?>MB</p>
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
        ];

        echo "<table><tbody>";
        foreach ($fields as $k => $label) {
            echo "<tr><th>" . e($label) . "</th><td><select name='map[" . e($k) . "]'>";
            echo "<option value='ignore'>-- ignore --</option>";
            foreach ($colOptions as $idx => $name) {
                $sel = (isset($map[$k]) && to_str($map[$k]) === (string)$idx) ? "selected" : "";
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
    $rebuildPlan = empty($wiz['plan_path']) || !is_file(to_str($wiz['plan_path'])) || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'analyze');

    if ($rebuildPlan) {
        try {
            if (!empty($wiz['plan_path'])) delete_import_plan(to_str($wiz['plan_path']));
            $plan = analyze_import($db, $wiz);
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

        <a class="action-pill" href="export_import_report.php?mode=plan">⬇ Export Dry Run Report</a>
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
} elseif (empty($wiz['plan_path']) || !is_file(to_str($wiz['plan_path']))) {
    $err = "No import plan found. Run dry run first.";
} else {
    try {
        $plan = load_import_plan(to_str($wiz['plan_path']));
        /** @var list<array<string, mixed>> $rows */
        $rows = $plan['rows'] ?? [];
        $planMeta = is_array($plan['meta']) ? $plan['meta'] : [];
        $dupMode = to_str($planMeta['dup_mode'] ?? 'skip');

        $createdSubnets = 0;
        $createdAddresses = 0;
        $updatedAddresses = 0;
        $skippedRows = 0;
        $conflicts = 0;
        $resultRows = [];

        // preload current subnets map
        /** @var list<array<string, mixed>> $existingSubnets */
        $existingSubnets = ($db->query("SELECT id, cidr FROM subnets")
            ?: throw new \RuntimeException('Query failed'))->fetchAll();
        $existingByCidr = [];
        foreach ($existingSubnets as $s) $existingByCidr[to_str($s['cidr'])] = to_int($s['id']);

        $db->beginTransaction();

        $sel = $db->prepare("SELECT id, ip, hostname, owner, note, grp, mac, expires_at, status FROM addresses WHERE subnet_id=:sid AND ip=:ip");
        $ins = $db->prepare("INSERT INTO addresses (subnet_id, ip, ip_bin, hostname, owner, note, grp, mac, expires_at, status)
                             VALUES (:sid,:ip,:bin,:hn,:ow,:nt,:grp,:mac,:exp,:st)");
        $upd = $db->prepare("UPDATE addresses SET hostname=:hn, owner=:ow, note=:nt, grp=:grp, mac=:mac, expires_at=:exp, status=:st WHERE id=:id");

        foreach ($rows as $r) {
            $result = [
                'row_num' => $r['row_num'] ?? '',
                'ip' => $r['ip'] ?? ($r['ip_raw'] ?? ''),
                'final_result' => '',
                'reason' => '',
            ];

            $finalAction = to_str($r['final_action'] ?? 'skip');

            if (in_array($finalAction, ['invalid','skip'], true)) {
                $result['final_result'] = $finalAction;
                $result['reason'] = to_str($r['reason'] ?? '');
                $skippedRows++;
                $resultRows[] = $result;
                continue;
            }

            $ip = to_str($r['ip'] ?? '');
            $version = to_int($r['version'] ?? 0);
            if ($ip === '' || !in_array($version, [4,6], true)) {
                $result['final_result'] = 'invalid';
                $result['reason'] = 'Invalid planned IP/version';
                $skippedRows++;
                $resultRows[] = $result;
                continue;
            }

            $norm = normalize_ip($ip);
            if (!$norm) {
                $result['final_result'] = 'invalid';
                $result['reason'] = 'Invalid IP during apply';
                $skippedRows++;
                $resultRows[] = $result;
                continue;
            }

            // Resolve subnet from frozen plan
            $resolvedCidr = to_str($r['resolved_cidr'] ?? '');
            if ($resolvedCidr === '') {
                $result['final_result'] = 'conflict';
                $result['reason'] = 'Missing resolved CIDR in plan';
                $conflicts++;
                $resultRows[] = $result;
                continue;
            }

            if (!isset($existingByCidr[$resolvedCidr])) {
                if (!empty($r['subnet_must_be_created'])) {
                    $subnetId = ensure_subnet_exists($db, $resolvedCidr, to_str($r['subnet_description'] ?? ''));
                    $existingByCidr[$resolvedCidr] = $subnetId;
                    $createdSubnets++;
                } else {
                    $result['final_result'] = 'conflict';
                    $result['reason'] = 'Resolved subnet missing at apply time';
                    $conflicts++;
                    $resultRows[] = $result;
                    continue;
                }
            }
            $subnetId = (int)$existingByCidr[$resolvedCidr];

            // Detect DB drift vs analysis
            $sel->execute([':sid' => $subnetId, ':ip' => $ip]);
            /** @var array<string, mixed>|false $existing */
            $existing = $sel->fetch();
            $existsNow = $existing ? true : false;
            $existedAtAnalysis = (bool)($r['existed_at_analysis'] ?? false);

            if ($existsNow !== $existedAtAnalysis) {
                $result['final_result'] = 'conflict';
                $result['reason'] = 'DB changed since dry run';
                $conflicts++;
                $resultRows[] = $result;
                continue;
            }

            if ($finalAction === 'create') {
                if ($existsNow) {
                    $result['final_result'] = 'conflict';
                    $result['reason'] = 'Address now exists';
                    $conflicts++;
                    $resultRows[] = $result;
                    continue;
                }

                // #410/#388: bind ip_bin via ipam_bind_binary() (PARAM_LOB).
                $insExpAt = isset($r['expires_at']) && to_str($r['expires_at']) !== '' ? to_str($r['expires_at']) : null;
                $ins->bindValue(':sid',  $subnetId, PDO::PARAM_INT);
                $ins->bindValue(':ip',   $ip);
                ipam_bind_binary($ins, ':bin', to_str($norm['bin']));
                $ins->bindValue(':hn',   to_str($r['hostname'] ?? ''));
                $ins->bindValue(':ow',   to_str($r['owner'] ?? ''));
                $ins->bindValue(':nt',   to_str($r['note'] ?? ''));
                $ins->bindValue(':grp',  to_str($r['grp'] ?? ''));
                $ins->bindValue(':mac',  to_str($r['mac'] ?? ''));
                $ins->bindValue(':exp',  $insExpAt, $insExpAt === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $ins->bindValue(':st',   to_str($r['status'] ?? 'used'));
                $ins->execute();
                $aid = ipam_last_insert_id($db, 'addresses');

                history_log_address($db, 'import_create', $subnetId, $ip, $aid, null, [
                    'hostname' => to_str($r['hostname'] ?? ''),
                    'owner' => to_str($r['owner'] ?? ''),
                    'note' => to_str($r['note'] ?? ''),
                    'grp' => to_str($r['grp'] ?? ''),
                    'mac' => to_str($r['mac'] ?? ''),
                    'expires_at' => $insExpAt,
                    'status' => to_str($r['status'] ?? 'used'),
                ]);
                $createdAddresses++;

                $result['final_result'] = 'created';
                $result['reason'] = 'Address created';
                $resultRows[] = $result;
                continue;
            }

            if ($finalAction === 'update') {
                if (!$existing) {
                    $result['final_result'] = 'conflict';
                    $result['reason'] = 'Address missing at apply time';
                    $conflicts++;
                    $resultRows[] = $result;
                    continue;
                }

                $newHn = to_str($r['hostname'] ?? '');
                $newOw = to_str($r['owner'] ?? '');
                $newNt = to_str($r['note'] ?? '');
                $newGrp = to_str($r['grp'] ?? '');
                $newMac = to_str($r['mac'] ?? '');
                $newExpAt = isset($r['expires_at']) && to_str($r['expires_at']) !== '' ? to_str($r['expires_at']) : null;
                $newSt = to_str($r['status'] ?? 'used');

                // Fix semantics: fill_empty does NOT overwrite status
                if ($dupMode === 'fill_empty') {
                    $newHn = (to_str($existing['hostname']) === '') ? $newHn : to_str($existing['hostname']);
                    $newOw = (to_str($existing['owner']) === '') ? $newOw : to_str($existing['owner']);
                    $newNt = (to_str($existing['note']) === '') ? $newNt : to_str($existing['note']);
                    $newGrp = (to_str($existing['grp']) === '') ? $newGrp : to_str($existing['grp']);
                    $newMac = (to_str($existing['mac']) === '') ? $newMac : to_str($existing['mac']);
                    $newExpAt = ($existing['expires_at'] === null) ? $newExpAt : to_str($existing['expires_at']);
                    $newSt = to_str($existing['status']);
                }

                $before = [
                    'hostname' => to_str($existing['hostname']),
                    'owner' => to_str($existing['owner']),
                    'note' => to_str($existing['note']),
                    'grp' => to_str($existing['grp']),
                    'mac' => to_str($existing['mac']),
                    'expires_at' => $existing['expires_at'] !== null ? to_str($existing['expires_at']) : null,
                    'status' => to_str($existing['status']),
                ];
                $after = [
                    'hostname' => $newHn,
                    'owner' => $newOw,
                    'note' => $newNt,
                    'grp' => $newGrp,
                    'mac' => $newMac,
                    'expires_at' => $newExpAt,
                    'status' => $newSt,
                ];

                $upd->execute([
                    ':hn'  => $newHn,
                    ':ow'  => $newOw,
                    ':nt'  => $newNt,
                    ':grp' => $newGrp,
                    ':mac' => $newMac,
                    ':exp' => $newExpAt,
                    ':st'  => $newSt,
                    ':id'  => to_int($existing['id']),
                ]);

                history_log_address($db, 'import_update', $subnetId, $ip, to_int($existing['id']), $before, $after);
                $updatedAddresses++;

                $result['final_result'] = 'updated';
                $result['reason'] = 'Address updated';
                $resultRows[] = $result;
                continue;
            }

            $result['final_result'] = 'skip';
            $result['reason'] = 'Unhandled action';
            $skippedRows++;
            $resultRows[] = $result;
        }

        $db->commit();

        audit($db, 'import.csv', 'system', null,
            "created_subnets=$createdSubnets created_addresses=$createdAddresses updated_addresses=$updatedAddresses skipped=$skippedRows conflicts=$conflicts"
        );

        $resultFile = save_import_result([
            'summary' => [
                'created_subnets' => $createdSubnets,
                'created_addresses' => $createdAddresses,
                'updated_addresses' => $updatedAddresses,
                'skipped_rows' => $skippedRows,
                'conflicts' => $conflicts,
            ],
            'rows' => $resultRows,
        ]);
        $wiz['result_path'] = $resultFile;

        if (!empty($wiz['tmp_path']) && is_file(to_str($wiz['tmp_path']))) @unlink(to_str($wiz['tmp_path']));
        if (is_file(to_str($wiz['plan_path']))) @unlink(to_str($wiz['plan_path']));
        unset($wiz['tmp_path'], $wiz['plan_path']);

        $msg = "Import complete. Created subnets: $createdSubnets, created addresses: $createdAddresses, updated addresses: $updatedAddresses, skipped rows: $skippedRows, conflicts: $conflicts.";
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $err = "Import failed: " . $e->getMessage();
    }
}

$resultRows = [];
$summary = [];
if (!empty($wiz['result_path']) && is_file(to_str($wiz['result_path']))) {
    try {
        $res = load_result_file(to_str($wiz['result_path']));
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
    <a class="action-pill" href="import_csv.php">⬆ Start New Import</a>
    <?php if (!empty($wiz['result_path']) && is_file(to_str($wiz['result_path']))): ?>
      <a class="action-pill" href="export_import_report.php?mode=result">⬇ Export Result Report</a>
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
