<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_role('admin');

$config = require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
}

$step = (int)($_GET['step'] ?? 1);
if ($step < 1 || $step > 4) $step = 1;

$err = '';
$msg = '';

$_SESSION['csv_import'] ??= [];
$wiz =& $_SESSION['csv_import'];

if (isset($_GET['reset'])) {
    if (!empty($wiz['tmp_path']) && is_file($wiz['tmp_path'])) @unlink($wiz['tmp_path']);
    if (!empty($wiz['plan_path']) && is_file($wiz['plan_path'])) @unlink($wiz['plan_path']);
    if (!empty($wiz['result_path']) && is_file($wiz['result_path'])) @unlink($wiz['result_path']);
    $wiz = [];
    header('Location: import_csv.php');
    exit;
}

function wiz_require_file(array $wiz): void {
    if (empty($wiz['tmp_path']) || !is_file($wiz['tmp_path'])) {
        header('Location: import_csv.php?step=1');
        exit;
    }
}

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

function load_result_file(string $path): array
{
    if (!is_file($path)) throw new RuntimeException('Import result file not found');
    $json = file_get_contents($path);
    if ($json === false) throw new RuntimeException('Failed to read import result');
    $data = json_decode($json, true);
    if (!is_array($data)) throw new RuntimeException('Invalid import result');
    return $data;
}

function analyze_import(PDO $db, array $wiz): array
{
    $delimiter = (string)$wiz['delimiter'];
    $hasHeader = (string)$wiz['has_header'];
    $map = $wiz['mapping'] ?? [];
    $dupMode = (string)($wiz['dup_mode'] ?? 'skip');

    $fh = fopen($wiz['tmp_path'], 'rb');
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

    $existingSubnets = $db->query("SELECT id, cidr FROM subnets")->fetchAll();
    $existingByCidr = [];
    foreach ($existingSubnets as $s) $existingByCidr[(string)$s['cidr']] = (int)$s['id'];

    $seenCsvKeys = []; // detect duplicate rows in CSV after resolution
    $overlapCache = []; // cidr => overlap result, avoid redundant DB queries per unique CIDR
    $maxProcessRows = 200000;

    while (!feof($fh) && $rowNum < $maxProcessRows) {
        $row = fgetcsv($fh, 0, $delimiter);
        if ($row === false) break;
        if (count($row) === 1 && trim((string)$row[0]) === '') continue;

        $rowNum++;
        if ($rowNum === 1 && $hasHeader === 'yes') continue;

        $summary['parsed']++;

        $get = function(string $key) use ($map, $row): ?string {
            $idx = $map[$key] ?? 'ignore';
            if ($idx === 'ignore' || $idx === '' || !is_numeric((string)$idx)) return null;
            $i = (int)$idx;
            return isset($row[$i]) ? (string)$row[$i] : null;
        };

        $entry = [
            'row_num' => $rowNum,
            'final_action' => 'invalid',
            'display_action' => 'invalid',
            'reason' => '',
            'ip' => null,
            'ip_raw' => (string)($get('ip') ?? ''),
            'version' => null,

            'resolved_cidr' => null,
            'resolved_subnet_id' => null,
            'subnet_must_be_created' => false,
            'existed_at_analysis' => null,

            'hostname' => trim((string)($get('hostname') ?? '')),
            'owner' => trim((string)($get('owner') ?? '')),
            'note' => trim((string)($get('note') ?? '')),
            'status' => normalize_status($get('status')),

            'subnet_description' => trim((string)($get('description') ?? '')),
            'prefix_hint' => trim((string)($get('prefix') ?? '')),
            'netmask_hint' => trim((string)($get('netmask') ?? '')),
        ];

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

        $cidrHint = trim((string)($get('cidr') ?? ''));

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
                $entry['resolved_subnet_id'] = (int)$s['id'];

                $st = $db->prepare("SELECT cidr FROM subnets WHERE id = :id");
                $st->execute([':id' => (int)$s['id']]);
                $cidrRow = $st->fetch();
                $entry['resolved_cidr'] = (string)($cidrRow['cidr'] ?? '');
            } else {
                // Determine inferred CIDR from hints/defaults now so plan is frozen
                $prefix = null;
                if ($entry['version'] === 4) {
                    if ($entry['prefix_hint'] !== '' && ctype_digit($entry['prefix_hint'])) {
                        $prefix = (int)$entry['prefix_hint'];
                        if ($prefix < 0 || $prefix > 32) $prefix = null;
                    } elseif ($entry['netmask_hint'] !== '') {
                        $pfx = netmask_to_prefix($entry['netmask_hint']);
                        if ($pfx !== null) $prefix = $pfx;
                    }
                    if ($prefix === null) $prefix = 24;
                } else {
                    if ($entry['prefix_hint'] !== '' && ctype_digit($entry['prefix_hint'])) {
                        $prefix = (int)$entry['prefix_hint'];
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

        if ($entry['resolved_cidr'] === null) {
            $entry['final_action'] = 'invalid';
            $entry['display_action'] = 'invalid';
            $entry['reason'] = 'Could not resolve subnet';
            $summary['invalid']++;
            $planRows[] = $entry;
            continue;
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
            $cidrToCheck = (string)$entry['resolved_cidr'];
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

page_header('Import CSV');
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
  <a class="action-pill" href="import_csv.php?reset=1" onclick="return confirm('Reset import wizard?');">↺ Reset Wizard</a>
</div>

<?php if ($err): ?><p class="danger"><?= e($err) ?></p><?php endif; ?>
<?php if ($msg): ?><p class="success"><?= e($msg) ?></p><?php endif; ?>

<?php
/* Step 1 */
if ($step === 1) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
        if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
            $err = "No file uploaded.";
        } else {
            $maxBytes = import_max_bytes($config);
            $size = (int)($_FILES['csv']['size'] ?? 0);
            if ($size > $maxBytes) {
                $mb = (int)round($maxBytes / 1024 / 1024);
                $err = "File too large (max {$mb}MB).";
            } else {
                ensure_tmp_dir();
                $dest = tmp_dir() . '/import-' . bin2hex(random_bytes(8)) . '.csv';

                if (!move_uploaded_file($_FILES['csv']['tmp_name'], $dest)) {
                    $err = "Failed to store uploaded file.";
                } else {
                    @chmod($dest, 0600);

                    $sample = file_get_contents($dest, false, null, 0, 4096);
                    if ($sample === false) $sample = '';
                    $delim = detect_csv_delimiter($sample);

                    $wiz = [
                        'tmp_path' => $dest,
                        'delimiter' => $delim,
                        'has_header' => 'yes',
                    ];

                    header("Location: import_csv.php?step=2");
                    exit;
                }
            }
        }
    }
    ?>

    <div class="card">
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
    if (empty($wiz['tmp_path']) || !is_file($wiz['tmp_path'])) {
        header("Location: import_csv.php?step=1");
        exit;
    }

    $delimiter = (string)($wiz['delimiter'] ?? ',');
    $hasHeader = (string)($wiz['has_header'] ?? 'yes');

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_mapping') {
        $delimiter = (string)($_POST['delimiter'] ?? ',');
        if (!in_array($delimiter, [',',';',"\\t",'|'], true)) $delimiter = ',';
        if ($delimiter === "\\t") $delimiter = "\t";

        $hasHeader = (string)($_POST['has_header'] ?? 'yes');
        if (!in_array($hasHeader, ['yes','no'], true)) $hasHeader = 'yes';

        $mapping = $_POST['map'] ?? [];
        if (!is_array($mapping)) $mapping = [];

        $ipMap = $mapping['ip'] ?? 'ignore';
        if ($ipMap === 'ignore' || $ipMap === '' || !is_numeric((string)$ipMap)) {
            $err = "You must map an IP column.";
        } else {
            $dupMode = (string)($_POST['dup_mode'] ?? 'skip');
            if (!in_array($dupMode, ['skip','overwrite','fill_empty'], true)) $dupMode = 'skip';

            $wiz['delimiter'] = $delimiter;
            $wiz['has_header'] = $hasHeader;
            $wiz['mapping'] = $mapping;
            $wiz['dup_mode'] = $dupMode;

            header("Location: import_csv.php?step=3");
            exit;
        }
    }

    $preview = csv_read_preview($wiz['tmp_path'], $delimiter, 15);

    $colCount = 0;
    foreach ($preview as $r) $colCount = max($colCount, count($r));

    $headerRow = $preview[0] ?? [];
    $headerNames = [];
    for ($i = 0; $i < $colCount; $i++) {
        $name = trim((string)($headerRow[$i] ?? ''));
        $headerNames[$i] = ($name !== '') ? $name : ("Column " . ($i+1));
    }

    $colOptions = [];
    for ($i = 0; $i < $colCount; $i++) {
        $colOptions[(string)$i] = $headerNames[$i] . " (col " . ($i+1) . ")";
    }

    $map = $wiz['mapping'] ?? [];
    $dupMode = $wiz['dup_mode'] ?? 'skip';
    ?>

    <div class="card">
      <h2>Step 2 — CSV settings + column mapping</h2>

      <h3>Preview</h3>
      <?php render_preview_table($preview); ?>

      <form method="post" action="import_csv.php?step=2" style="margin-top:16px">
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

        <h3 style="margin-top:18px">Map columns</h3>
        <p class="muted">Map CSV columns to fields. IP is required. Others can be ignored.</p>

        <?php
        $fields = [
            'ip' => 'IP (required)',
            'hostname' => 'Hostname',
            'owner' => 'Owner',
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
                $sel = (isset($map[$k]) && (string)$map[$k] === (string)$idx) ? "selected" : "";
                echo "<option value='" . e((string)$idx) . "' $sel>" . e($name) . "</option>";
            }
            echo "</select></td></tr>";
        }
        echo "</tbody></table>";
        ?>

        <p style="margin-top:12px"><button type="submit">Continue to Dry Run</button></p>
      </form>
    </div>

    <?php
    page_footer();
    exit;
}

/* Step 3 - Dry run / analyze */
if ($step === 3) {
    if (empty($wiz['tmp_path']) || !is_file($wiz['tmp_path'])) {
        header("Location: import_csv.php?step=1");
        exit;
    }

    $rebuildPlan = empty($wiz['plan_path']) || !is_file($wiz['plan_path']) || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'analyze');

    if ($rebuildPlan) {
        try {
            if (!empty($wiz['plan_path'])) delete_import_plan((string)$wiz['plan_path']);
            $plan = analyze_import($db, $wiz);
            $wiz['plan_path'] = save_import_plan($plan);
        } catch (Throwable $e) {
            $err = "Dry run failed: " . $e->getMessage();
            $plan = ['summary' => [], 'rows' => []];
        }
    } else {
        try {
            $plan = load_import_plan((string)$wiz['plan_path']);
        } catch (Throwable $e) {
            $err = "Could not load dry run plan: " . $e->getMessage();
            $plan = ['summary' => [], 'rows' => []];
        }
    }

    $summary = $plan['summary'] ?? [
        'parsed' => 0, 'invalid' => 0, 'create' => 0, 'update' => 0, 'skip' => 0,
        'needs_subnet_create' => 0, 'unknown_subnet_rows' => 0, 'duplicate_in_csv' => 0
    ];
    $rows = $plan['rows'] ?? [];
    ?>

    <div class="card">
      <h2>Step 3 — Dry Run / Analysis</h2>

      <div class="grid cols-3">
        <div class="metric"><div class="label">Parsed rows</div><div class="value"><?= e((string)$summary['parsed']) ?></div></div>
        <div class="metric"><div class="label">Invalid rows</div><div class="value"><?= e((string)$summary['invalid']) ?></div></div>
        <div class="metric"><div class="label">Creates</div><div class="value"><?= e((string)$summary['create']) ?></div></div>
        <div class="metric"><div class="label">Updates</div><div class="value"><?= e((string)$summary['update']) ?></div></div>
        <div class="metric"><div class="label">Skips</div><div class="value"><?= e((string)$summary['skip']) ?></div></div>
        <div class="metric"><div class="label">Subnets to create</div><div class="value"><?= e((string)$summary['needs_subnet_create']) ?></div></div>
      </div>

      <div class="page-actions" style="margin-top:16px">
        <form method="post" action="import_csv.php?step=3" style="display:inline">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="analyze">
          <button type="submit" class="button-secondary">Re-run Dry Run</button>
        </form>

        <form method="post" action="import_csv.php?step=4" style="display:inline" onsubmit="return confirm('Apply this import plan?');">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="apply">
          <button type="submit">Apply Import</button>
        </form>

        <a class="action-pill" href="export_import_report.php?mode=plan">⬇ Export Dry Run Report</a>
      </div>

      <h3 style="margin-top:18px">Row Report</h3>
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
            <?php $cls = action_class((string)($r['display_action'] ?? $r['final_action'] ?? '')); ?>
            <?php $ov = $r['subnet_overlap_warning'] ?? null; ?>
            <tr>
              <td><?= e((string)$r['row_num']) ?></td>
              <td><?= e((string)($r['ip'] ?? $r['ip_raw'] ?? '')) ?></td>
              <td><span class="<?= e($cls) ?>"><?= e((string)($r['display_action'] ?? $r['final_action'] ?? '')) ?></span></td>
              <td><?= e((string)($r['resolved_subnet_id'] ?? $r['resolved_cidr'] ?? '')) ?></td>
              <td>
                <?= e((string)($r['reason'] ?? '')) ?>
                <?php if ($ov): ?>
                  <?php
                    $ovParts = [];
                    if (!empty($ov['parents'])) $ovParts[] = 'nested inside: ' . implode(', ', $ov['parents']);
                    if (!empty($ov['children'])) $ovParts[] = 'parent of: ' . implode(', ', $ov['children']);
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
if ($step === 4) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'apply') {
        header("Location: import_csv.php?step=3");
        exit;
    }

    if (empty($wiz['plan_path']) || !is_file($wiz['plan_path'])) {
        $err = "No import plan found. Run dry run first.";
    } else {
        try {
            $plan = load_import_plan((string)$wiz['plan_path']);
            $rows = $plan['rows'] ?? [];
            $dupMode = (string)($plan['meta']['dup_mode'] ?? 'skip');

            $createdSubnets = 0;
            $createdAddresses = 0;
            $updatedAddresses = 0;
            $skippedRows = 0;
            $conflicts = 0;
            $resultRows = [];

            // preload current subnets map
            $existingSubnets = $db->query("SELECT id, cidr FROM subnets")->fetchAll();
            $existingByCidr = [];
            foreach ($existingSubnets as $s) $existingByCidr[(string)$s['cidr']] = (int)$s['id'];

            $db->beginTransaction();

            $sel = $db->prepare("SELECT id, ip, hostname, owner, note, status FROM addresses WHERE subnet_id=:sid AND ip=:ip");
            $ins = $db->prepare("INSERT INTO addresses (subnet_id, ip, ip_bin, hostname, owner, note, status)
                                 VALUES (:sid,:ip,:bin,:hn,:ow,:nt,:st)");
            $upd = $db->prepare("UPDATE addresses SET hostname=:hn, owner=:ow, note=:nt, status=:st WHERE id=:id");

            foreach ($rows as $r) {
                $result = [
                    'row_num' => $r['row_num'] ?? '',
                    'ip' => $r['ip'] ?? ($r['ip_raw'] ?? ''),
                    'final_result' => '',
                    'reason' => '',
                ];

                $finalAction = (string)($r['final_action'] ?? 'skip');

                if (in_array($finalAction, ['invalid','skip'], true)) {
                    $result['final_result'] = $finalAction;
                    $result['reason'] = (string)($r['reason'] ?? '');
                    $skippedRows++;
                    $resultRows[] = $result;
                    continue;
                }

                $ip = (string)($r['ip'] ?? '');
                $version = (int)($r['version'] ?? 0);
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
                $resolvedCidr = (string)($r['resolved_cidr'] ?? '');
                if ($resolvedCidr === '') {
                    $result['final_result'] = 'conflict';
                    $result['reason'] = 'Missing resolved CIDR in plan';
                    $conflicts++;
                    $resultRows[] = $result;
                    continue;
                }

                if (!isset($existingByCidr[$resolvedCidr])) {
                    if (!empty($r['subnet_must_be_created'])) {
                        $subnetId = ensure_subnet_exists($db, $resolvedCidr, (string)($r['subnet_description'] ?? ''));
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

                    $ins->execute([
                        ':sid' => $subnetId,
                        ':ip' => $ip,
                        ':bin' => $norm['bin'],
                        ':hn' => (string)($r['hostname'] ?? ''),
                        ':ow' => (string)($r['owner'] ?? ''),
                        ':nt' => (string)($r['note'] ?? ''),
                        ':st' => (string)($r['status'] ?? 'used'),
                    ]);
                    $aid = (int)$db->lastInsertId();

                    history_log_address($db, 'import_create', $subnetId, $ip, $aid, null, [
                        'hostname' => (string)($r['hostname'] ?? ''),
                        'owner' => (string)($r['owner'] ?? ''),
                        'note' => (string)($r['note'] ?? ''),
                        'status' => (string)($r['status'] ?? 'used'),
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

                    $newHn = (string)($r['hostname'] ?? '');
                    $newOw = (string)($r['owner'] ?? '');
                    $newNt = (string)($r['note'] ?? '');
                    $newSt = (string)($r['status'] ?? 'used');

                    // Fix semantics: fill_empty does NOT overwrite status
                    if ($dupMode === 'fill_empty') {
                        $newHn = ((string)$existing['hostname'] === '') ? $newHn : (string)$existing['hostname'];
                        $newOw = ((string)$existing['owner'] === '') ? $newOw : (string)$existing['owner'];
                        $newNt = ((string)$existing['note'] === '') ? $newNt : (string)$existing['note'];
                        $newSt = (string)$existing['status'];
                    }

                    $before = [
                        'hostname' => (string)$existing['hostname'],
                        'owner' => (string)$existing['owner'],
                        'note' => (string)$existing['note'],
                        'status' => (string)$existing['status'],
                    ];
                    $after = [
                        'hostname' => $newHn,
                        'owner' => $newOw,
                        'note' => $newNt,
                        'status' => $newSt,
                    ];

                    $upd->execute([
                        ':hn' => $newHn,
                        ':ow' => $newOw,
                        ':nt' => $newNt,
                        ':st' => $newSt,
                        ':id' => (int)$existing['id'],
                    ]);

                    history_log_address($db, 'import_update', $subnetId, $ip, (int)$existing['id'], $before, $after);
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

            if (!empty($wiz['tmp_path']) && is_file($wiz['tmp_path'])) @unlink($wiz['tmp_path']);
            if (!empty($wiz['plan_path']) && is_file($wiz['plan_path'])) @unlink($wiz['plan_path']);
            unset($wiz['tmp_path'], $wiz['plan_path']);

            $msg = "Import complete. Created subnets: $createdSubnets, created addresses: $createdAddresses, updated addresses: $updatedAddresses, skipped rows: $skippedRows, conflicts: $conflicts.";
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $err = "Import failed: " . $e->getMessage();
        }
    }

    $resultRows = [];
    $summary = [];
    if (!empty($wiz['result_path']) && is_file($wiz['result_path'])) {
        try {
            $res = load_result_file($wiz['result_path']);
            $resultRows = $res['rows'] ?? [];
            $summary = $res['summary'] ?? [];
        } catch (Throwable $e) {
            // ignore
        }
    }

    ?>
    <div class="card">
      <h2>Step 4 — Import Result</h2>
      <?php if ($err): ?><p class="danger"><?= e($err) ?></p><?php endif; ?>
      <?php if ($msg): ?><p class="success"><?= e($msg) ?></p><?php endif; ?>

      <?php if ($summary): ?>
        <div class="grid cols-3">
          <div class="metric"><div class="label">Created subnets</div><div class="value"><?= e((string)$summary['created_subnets']) ?></div></div>
          <div class="metric"><div class="label">Created addresses</div><div class="value"><?= e((string)$summary['created_addresses']) ?></div></div>
          <div class="metric"><div class="label">Updated addresses</div><div class="value"><?= e((string)$summary['updated_addresses']) ?></div></div>
          <div class="metric"><div class="label">Skipped rows</div><div class="value"><?= e((string)$summary['skipped_rows']) ?></div></div>
          <div class="metric"><div class="label">Conflicts</div><div class="value"><?= e((string)$summary['conflicts']) ?></div></div>
        </div>
      <?php endif; ?>

      <div class="page-actions" style="margin-top:16px">
        <a class="action-pill" href="import_csv.php">⬆ Start New Import</a>
        <?php if (!empty($wiz['result_path']) && is_file($wiz['result_path'])): ?>
          <a class="action-pill" href="export_import_report.php?mode=result">⬇ Export Result Report</a>
        <?php endif; ?>
      </div>

      <?php if ($resultRows): ?>
        <h3 style="margin-top:18px">Row Results</h3>
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
            <?php $cls = action_class((string)($r['final_result'] ?? '')); ?>
            <tr>
              <td><?= e((string)$r['row_num']) ?></td>
              <td><?= e((string)$r['ip']) ?></td>
              <td><span class="<?= e($cls) ?>"><?= e((string)$r['final_result']) ?></span></td>
              <td><?= e((string)$r['reason']) ?></td>
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

page_footer();
