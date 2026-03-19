<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_role('admin');

$config = require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
}

$step = (int)($_GET['step'] ?? 1);
if ($step < 1 || $step > 3) $step = 1;

$err = '';
$msg = '';

$_SESSION['csv_import'] ??= [];
$wiz =& $_SESSION['csv_import'];

if (isset($_GET['reset'])) {
    if (!empty($wiz['tmp_path']) && is_file($wiz['tmp_path'])) @unlink($wiz['tmp_path']);
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
    if (!$rows) { echo "<p class='muted'>No preview rows.</p>"; return; }
    echo "<table><tbody>";
    foreach ($rows as $r) {
        echo "<tr>";
        foreach ($r as $cell) echo "<td>" . e((string)$cell) . "</td>";
        echo "</tr>";
    }
    echo "</tbody></table>";
}

page_header('Import CSV');

echo "<h1>Import CSV</h1>";
echo "<p class='muted'>Wizard: upload → map columns → analyze/create subnets → import</p>";
echo "<p><a href='import_csv.php?reset=1' onclick='return confirm(\"Reset import wizard?\");'>Reset wizard</a></p>";

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
    <h2>Step 1 — Upload</h2>
    <?php if ($err): ?><p class="danger"><?= e($err) ?></p><?php endif; ?>

    <form method="post" enctype="multipart/form-data" action="import_csv.php?step=1">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="upload">
      <p><input type="file" name="csv" accept=".csv,text/csv" required></p>
      <p class="muted">Max upload size: <?= e((string)((int)round(import_max_bytes($config)/1024/1024))) ?>MB</p>
      <p><button type="submit">Upload</button></p>
    </form>
    <?php
    page_footer();
    exit;
}

/* Step 2 */
if ($step === 2) {
    wiz_require_file($wiz);

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

        // FIX: do not use empty() because "0" (first column) is considered empty
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
    <h2>Step 2 — CSV settings + column mapping</h2>

    <h3>Preview</h3>
    <?php render_preview_table($preview); ?>

    <?php if ($err): ?><p class="danger"><?= e($err) ?></p><?php endif; ?>

    <form method="post" action="import_csv.php?step=2">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="set_mapping">

      <h3>CSV settings</h3>
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
      </div>

      <h3>Duplicate handling</h3>
      <div class="row">
        <label>When IP already exists in subnet<br>
          <select name="dup_mode">
            <option value="skip" <?= $dupMode==='skip'?'selected':'' ?>>Skip existing</option>
            <option value="overwrite" <?= $dupMode==='overwrite'?'selected':'' ?>>Update (overwrite)</option>
            <option value="fill_empty" <?= $dupMode==='fill_empty'?'selected':'' ?>>Update only empty fields</option>
          </select>
        </label>
      </div>

      <h3>Map columns</h3>
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

      <p><button type="submit">Continue</button></p>
    </form>
    <?php
    page_footer();
    exit;
}

/* Step 3 */
if ($step === 3) {
    wiz_require_file($wiz);

    $delimiter = (string)$wiz['delimiter'];
    $hasHeader = (string)$wiz['has_header'];
    $map = $wiz['mapping'] ?? [];
    $dupMode = (string)($wiz['dup_mode'] ?? 'skip');

    // FIX: do not use empty() because "0" is considered empty
    $ipMap = is_array($map) ? ($map['ip'] ?? 'ignore') : 'ignore';
    if (!is_array($map) || $ipMap === 'ignore' || $ipMap === '' || !is_numeric((string)$ipMap)) {
        header("Location: import_csv.php?step=2");
        exit;
    }

    $fh = fopen($wiz['tmp_path'], 'rb');
    if (!$fh) { http_response_code(500); exit("Cannot open uploaded file"); }

    $rowNum = 0;
    $validRows = [];
    $invalid = 0;
    $unknownSubnet = [];
    $missingCidrs = [];

    $existingSubnets = $db->query("SELECT id, cidr FROM subnets")->fetchAll();
    $existingByCidr = [];
    foreach ($existingSubnets as $s) $existingByCidr[(string)$s['cidr']] = (int)$s['id'];

    $maxProcessRows = 200000;
    while (!feof($fh) && $rowNum < $maxProcessRows) {
        $row = fgetcsv($fh, 0, $delimiter);
        if ($row === false) break;
        if (count($row) === 1 && trim((string)$row[0]) === '') continue;

        $rowNum++;
        if ($rowNum === 1 && $hasHeader === 'yes') continue;

        $get = function(string $key) use ($map, $row): ?string {
            $idx = $map[$key] ?? 'ignore';
            if ($idx === 'ignore' || $idx === '' || !is_numeric((string)$idx)) return null;
            $i = (int)$idx;
            return isset($row[$i]) ? (string)$row[$i] : null;
        };

        $ipRaw = $get('ip');
        $norm = $ipRaw ? normalize_ip($ipRaw) : null;
        if (!$norm) { $invalid++; continue; }

        $status = normalize_status($get('status'));
        $hostname = trim((string)($get('hostname') ?? ''));
        $owner = trim((string)($get('owner') ?? ''));
        $note = trim((string)($get('note') ?? ''));
        $subDesc = trim((string)($get('description') ?? ''));

        $cidrHint = trim((string)($get('cidr') ?? ''));
        $prefixHint = trim((string)($get('prefix') ?? ''));
        $netmaskHint = trim((string)($get('netmask') ?? ''));

        $subnetId = null;
        $createCidr = null;

        if ($cidrHint !== '' && parse_cidr($cidrHint)) {
            $p = parse_cidr($cidrHint);
            $normalizedCidr = $p['network'] . '/' . $p['prefix'];
            $subnetId = $existingByCidr[$normalizedCidr] ?? null;
            if ($subnetId === null) {
                $missingCidrs[$normalizedCidr] ??= ['description' => $subDesc, 'count' => 0];
                $missingCidrs[$normalizedCidr]['count']++;
                $createCidr = $normalizedCidr;
            }
        } else {
            $s = find_containing_subnet($db, $norm);
            if ($s) $subnetId = (int)$s['id'];
            else $unknownSubnet[(string)$norm['version']][] = true;
        }

        $validRows[] = [
            'ip' => $norm['ip'],
            'ip_bin' => $norm['bin'],
            'version' => $norm['version'],
            'hostname' => $hostname,
            'owner' => $owner,
            'note' => $note,
            'status' => $status,
            'subnet_id' => $subnetId,
            'create_cidr' => $createCidr,
            'subnet_description' => $subDesc,
            'prefix_hint' => $prefixHint,
            'netmask_hint' => $netmaskHint,
        ];
    }
    fclose($fh);

    $unknownV4 = count($unknownSubnet['4'] ?? []);
    $unknownV6 = count($unknownSubnet['6'] ?? []);
    $missingCidrCount = count($missingCidrs);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'do_import') {
        require_write_access();

        $createMissingCidrs = !empty($_POST['create_missing_cidrs']);
        $createUnknown = (string)($_POST['create_unknown'] ?? 'no');
        if (!in_array($createUnknown, ['no','per_ip'], true)) $createUnknown = 'no';

        $defaultPrefixV4 = (int)($_POST['default_prefix_v4'] ?? 24);
        $defaultPrefixV6 = (int)($_POST['default_prefix_v6'] ?? 64);
        if ($defaultPrefixV4 < 0 || $defaultPrefixV4 > 32) $defaultPrefixV4 = 24;
        if ($defaultPrefixV6 < 0 || $defaultPrefixV6 > 128) $defaultPrefixV6 = 64;

        $createdSubnets = 0;
        $createdAddresses = 0;
        $updatedAddresses = 0;
        $skippedDuplicates = 0;
        $skippedUnknown = 0;

        try {
            $db->beginTransaction();

            if ($createMissingCidrs && $missingCidrs) {
                foreach ($missingCidrs as $cidr => $info) {
                    ensure_subnet_exists($db, $cidr, (string)($info['description'] ?? ''));
                    $createdSubnets++;
                }
            }

            $existingSubnets = $db->query("SELECT id, cidr FROM subnets")->fetchAll();
            $existingByCidr = [];
            foreach ($existingSubnets as $s) $existingByCidr[(string)$s['cidr']] = (int)$s['id'];

            $sel = $db->prepare("SELECT id, ip, hostname, owner, note, status FROM addresses WHERE subnet_id=:sid AND ip=:ip");
            $ins = $db->prepare("INSERT INTO addresses (subnet_id, ip, ip_bin, hostname, owner, note, status)
                                 VALUES (:sid,:ip,:bin,:hn,:ow,:nt,:st)");
            $upd = $db->prepare("UPDATE addresses SET hostname=:hn, owner=:ow, note=:nt, status=:st WHERE id=:id");

            foreach ($validRows as $r) {
                $sid = $r['subnet_id'];

                if ($sid === null && !empty($r['create_cidr'])) {
                    $sid = $existingByCidr[$r['create_cidr']] ?? null;
                }

                if ($sid === null) {
                    if ($createUnknown !== 'per_ip') { $skippedUnknown++; continue; }

                    $prefix = null;
                    if ($r['version'] === 4) {
                        if ($r['prefix_hint'] !== '' && ctype_digit($r['prefix_hint'])) $prefix = (int)$r['prefix_hint'];
                        elseif ($r['netmask_hint'] !== '') {
                            $pfx = netmask_to_prefix($r['netmask_hint']);
                            if ($pfx !== null) $prefix = $pfx;
                        }
                        if ($prefix === null) $prefix = $defaultPrefixV4;
                    } else {
                        if ($r['prefix_hint'] !== '' && ctype_digit($r['prefix_hint'])) $prefix = (int)$r['prefix_hint'];
                        if ($prefix === null) $prefix = $defaultPrefixV6;
                    }

                    $cidr = cidr_from_ip_and_prefix(['ip'=>$r['ip'], 'bin'=>$r['ip_bin'], 'version'=>$r['version']], $prefix);
                    $sid = ensure_subnet_exists($db, $cidr, (string)$r['subnet_description']);
                    $createdSubnets++;
                }

                $sel->execute([':sid' => $sid, ':ip' => $r['ip']]);
                $existing = $sel->fetch();

                if (!$existing) {
                    $ins->execute([
                        ':sid' => $sid,
                        ':ip' => $r['ip'],
                        ':bin' => $r['ip_bin'],
                        ':hn' => $r['hostname'],
                        ':ow' => $r['owner'],
                        ':nt' => $r['note'],
                        ':st' => $r['status'],
                    ]);
                    $aid = (int)$db->lastInsertId();

                    history_log_address($db, 'import_create', $sid, $r['ip'], $aid, null, [
                        'hostname' => $r['hostname'],
                        'owner' => $r['owner'],
                        'note' => $r['note'],
                        'status' => $r['status'],
                    ]);

                    $createdAddresses++;
                } else {
                    if ($dupMode === 'skip') { $skippedDuplicates++; continue; }

                    $newHn = $r['hostname'];
                    $newOw = $r['owner'];
                    $newNt = $r['note'];

                    if ($dupMode === 'fill_empty') {
                        $newHn = ((string)$existing['hostname'] === '') ? $newHn : (string)$existing['hostname'];
                        $newOw = ((string)$existing['owner'] === '') ? $newOw : (string)$existing['owner'];
                        $newNt = ((string)$existing['note'] === '') ? $newNt : (string)$existing['note'];
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
                        'status' => (string)$r['status'],
                    ];

                    $upd->execute([
                        ':hn' => $newHn,
                        ':ow' => $newOw,
                        ':nt' => $newNt,
                        ':st' => $r['status'],
                        ':id' => (int)$existing['id'],
                    ]);

                    history_log_address($db, 'import_update', $sid, (string)$existing['ip'], (int)$existing['id'], $before, $after);

                    $updatedAddresses++;
                }
            }

            $db->commit();

            audit($db, 'import.csv', 'system', null,
                "created_subnets=$createdSubnets created_addresses=$createdAddresses updated_addresses=$updatedAddresses skipped_dups=$skippedDuplicates skipped_unknown=$skippedUnknown invalid=$invalid"
            );

            if (!empty($wiz['tmp_path']) && is_file($wiz['tmp_path'])) @unlink($wiz['tmp_path']);
            $wiz = [];

            $msg = "Import complete. Created subnets: $createdSubnets, created addresses: $createdAddresses, updated: $updatedAddresses, skipped dups: $skippedDuplicates, skipped unknown-subnet rows: $skippedUnknown, invalid rows: $invalid.";
        } catch (Throwable $e) {
            $db->rollBack();
            $err = "Import failed: " . $e->getMessage();
        }
    }

    ?>
    <h2>Step 3 — Analyze + Import</h2>

    <h3>Analysis</h3>
    <ul>
      <li>Total parsed rows (excluding header if set): <b><?= e((string)count($validRows)) ?></b></li>
      <li>Invalid IP rows skipped: <b><?= e((string)$invalid) ?></b></li>
      <li>Missing CIDR subnets (from CSV CIDR column): <b><?= e((string)$missingCidrCount) ?></b></li>
      <li>IPs not in any existing subnet (no CIDR provided): IPv4 <b><?= e((string)$unknownV4) ?></b>, IPv6 <b><?= e((string)$unknownV6) ?></b></li>
      <li>Duplicate handling selected: <b><?= e($dupMode) ?></b></li>
    </ul>

    <?php if ($missingCidrs): ?>
      <h4>Missing CIDR subnets (from CSV)</h4>
      <table>
        <thead><tr><th>CIDR</th><th>Rows</th><th>Description (first seen)</th></tr></thead>
        <tbody>
          <?php foreach ($missingCidrs as $cidr => $info): ?>
            <tr>
              <td><?= e($cidr) ?></td>
              <td><?= e((string)$info['count']) ?></td>
              <td><?= e((string)$info['description']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <?php if ($err): ?><p class="danger"><?= e($err) ?></p><?php endif; ?>
    <?php if ($msg): ?><p><?= e($msg) ?></p><?php endif; ?>

    <h3>Import options</h3>
    <form method="post" action="import_csv.php?step=3" onsubmit="return confirm('Proceed with import?');">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="do_import">

      <p>
        <label>
          <input type="checkbox" name="create_missing_cidrs" value="1" <?= $missingCidrs ? 'checked' : '' ?>>
          Create missing subnets that are explicitly provided in CSV (CIDR column)
        </label>
      </p>

      <h4>IPs not in any existing subnet (no CIDR provided)</h4>
      <div class="row">
        <label>Action<br>
          <select name="create_unknown">
            <option value="no">Do not create; skip these rows</option>
            <option value="per_ip" <?= ($unknownV4+$unknownV6)>0 ? 'selected' : '' ?>>Create subnet per IP (masked by prefix)</option>
          </select>
        </label>
      </div>

      <div class="row">
        <label>Default IPv4 prefix<br>
          <input name="default_prefix_v4" value="24" inputmode="numeric">
        </label>
        <label>Default IPv6 prefix<br>
          <input name="default_prefix_v6" value="64" inputmode="numeric">
        </label>
      </div>

      <p><button type="submit">Run Import</button></p>
    </form>
    <?php
    page_footer();
    exit;
}

page_footer();
