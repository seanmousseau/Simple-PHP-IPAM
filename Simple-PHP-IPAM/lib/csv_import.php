<?php
declare(strict_types=1);

/**
 * @module csv_import
 *
 * CSV-import wizard business logic extracted from import_csv.php in v3.33.0
 * (ADR-004, B13 — #925). import_csv.php is a 4-step wizard state machine
 * (1 upload/parse, 2 column-mapping/preview, 3 dry-run plan, 4 apply); this
 * module holds the *logic* layer — dry-run analysis, plan application, and the
 * result-file I/O — leaving import_csv.php as render glue (step routing, HTML,
 * redirects, CSRF, request reading).
 *
 * Functions stay in the global namespace per ADR-004 Option E and match the
 * naming convention of sibling modules (ipam_csv_import_*).
 *
 * Dependencies (all already loaded by lib.php before this module):
 *  - lib/utils.php          to_str(), to_int()
 *  - lib/ip.php             normalize_ip(), parse_cidr(), ip_in_cidr(),
 *                           netmask_to_prefix(), cidr_from_ip_and_prefix()
 *  - lib.php                ensure_tmp_dir(), tmp_dir(), find_containing_subnet(),
 *                           detect_subnet_overlaps(), ensure_subnet_exists(),
 *                           custom_field_def_list(), validate_custom_fields_payload(),
 *                           serialize_custom_fields_row(), normalize_status(),
 *                           ipam_bind_binary(), ipam_last_insert_id(),
 *                           audit(), history_log_address()
 *
 * The PDO handle arrives as an explicit parameter — no `global $db`. Per
 * ADR-003 this module never uses `global $config;`; it has no config reads
 * (the only config-dependent call, import_max_bytes(), stays in import_csv.php
 * and lib.php).
 *
 * CSV-temp-file provenance (#1166): $wiz['tmp_path'] / ['plan_path'] /
 * ['result_path'] are *server-set* by import_csv.php's upload / dry-run / apply
 * handlers to random filenames under data/tmp/. They are never user-controlled.
 * This module receives those paths via the $wiz / $plan arrays it is handed and
 * does not weaken any path or upload validation.
 *
 * Designed to be require()-able and unit-testable: the public functions are
 * pure-ish, take an injected PDO, and read nothing from $_POST / $_SESSION.
 */

/* ---------------- Result-file I/O ---------------- */

/**
 * Build a random, unguessable path for an apply-step result file under data/tmp/.
 *
 * @return string Absolute path; the file is not created here.
 */
function ipam_csv_import_result_path(): string
{
    return tmp_dir() . '/import-result-' . bin2hex(random_bytes(8)) . '.json';
}

/**
 * Persist an apply-step result payload as pretty-printed JSON under data/tmp/
 * with mode 0600.
 *
 * @param array<string, mixed> $result Result payload (summary + rows).
 * @return string Absolute path to the written file.
 * @throws RuntimeException When encoding or writing fails.
 */
function ipam_csv_import_save_result(array $result): string
{
    ensure_tmp_dir();
    $path = ipam_csv_import_result_path();
    $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Failed to encode import result');
    }
    if (file_put_contents($path, $json) === false) {
        throw new RuntimeException('Failed to write import result');
    }
    // best-effort: tighten perms on the import-result file after writing
    @chmod($path, 0600);
    return $path;
}

/**
 * Load and decode a previously-saved apply-step result file.
 *
 * @param string $path Server-set path under data/tmp/ (see #1166 note above).
 * @return array<string, mixed> Decoded result payload.
 * @throws RuntimeException When the file is missing, unreadable, or invalid JSON.
 */
function ipam_csv_import_load_result(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Import result file not found');
    }
    $json = file_get_contents($path);
    if ($json === false) {
        throw new RuntimeException('Failed to read import result');
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid import result');
    }
    /** @var array<string, mixed> $data */
    return $data;
}

/* ---------------- Step 3: dry-run analysis ---------------- */

/**
 * Build the frozen import plan from the uploaded CSV (step-3 dry run).
 *
 * Parses every row through the wizard column mapping, validates fields,
 * resolves each row's subnet/CIDR, detects in-CSV duplicates and DB
 * duplicates, and decides each row's final action under the configured
 * duplicate-handling mode. The returned plan is frozen and consumed verbatim
 * by ipam_csv_import_apply_plan().
 *
 * @param PDO $db Live database handle.
 * @param array<string, mixed> $wiz Wizard session state: tmp_path (server-set
 *        path under data/tmp/, see #1166), delimiter, has_header, mapping,
 *        dup_mode.
 * @return array{
 *     meta: array{dup_mode: string, analyzed_at: string},
 *     summary: array<string, int>,
 *     rows: list<array<string, mixed>>
 * }
 * @throws RuntimeException When the uploaded file cannot be opened.
 */
function ipam_csv_import_analyze(PDO $db, array $wiz): array
{
    $delimiter = to_str($wiz['delimiter']);
    $hasHeader = to_str($wiz['has_header']);
    $map = is_array($wiz['mapping']) ? $wiz['mapping'] : [];
    $dupMode = to_str($wiz['dup_mode'] ?? 'skip');

    $fh = fopen(to_str($wiz['tmp_path']), 'rb');
    if (!$fh) {
        throw new RuntimeException("Cannot open uploaded file");
    }

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

    /** @var list<array<string,mixed>> $addressDefs */
    $addressDefs = custom_field_def_list($db, 'address');

    /** @var list<array<string, mixed>> $existingSubnets */
    $existingSubnets = ($db->query("SELECT id, cidr FROM subnets")
        ?: throw new \RuntimeException('Query failed'))->fetchAll();
    $existingByCidr = [];
    foreach ($existingSubnets as $s) {
        $existingByCidr[to_str($s['cidr'])] = to_int($s['id']);
    }

    $seenCsvKeys = []; // detect duplicate rows in CSV after resolution
    $overlapCache = []; // cidr => overlap result, avoid redundant DB queries per unique CIDR
    $maxProcessRows = 200000;

    while (!feof($fh) && $rowNum < $maxProcessRows) {
        $row = fgetcsv($fh, 0, $delimiter, '"', '');
        if ($row === false) {
            break;
        }
        if (count($row) === 1 && trim(to_str($row[0])) === '') {
            continue;
        }

        $rowNum++;
        if ($rowNum === 1 && $hasHeader === 'yes') {
            continue;
        }

        $summary['parsed']++;

        $get = function (string $key) use ($map, $row): ?string {
            $idx = $map[$key] ?? 'ignore';
            if ($idx === 'ignore' || $idx === '' || !is_numeric(to_str($idx))) {
                return null;
            }
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
            'device_name' => $get('device_name') !== null ? substr(trim(to_str($get('device_name'))), 0, 255) : null,
            'interface_name' => $get('interface_name') !== null ? substr(trim(to_str($get('interface_name'))), 0, 255) : null,
            'custom_fields' => '{}',
        ];

        $rawExpires = trim(to_str($get('expires_at') ?? ''));
        if ($rawExpires !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawExpires)) {
            $entry['expires_at'] = $rawExpires;
        }

        $rawCf = trim(to_str($get('custom_fields') ?? ''));
        if ($rawCf !== '' && $rawCf !== '{}') {
            $parsedCf = json_decode($rawCf, true);
            if (!is_array($parsedCf)) {
                $entry['reason'] = 'custom_fields: invalid JSON';
                $summary['invalid']++;
                $planRows[] = $entry;
                continue;
            }
            try {
                validate_custom_fields_payload($addressDefs, $parsedCf);
                $entry['custom_fields'] = serialize_custom_fields_row($parsedCf);
            } catch (\InvalidArgumentException $ex) {
                $entry['reason'] = 'custom_fields: ' . $ex->getMessage();
                $summary['invalid']++;
                $planRows[] = $entry;
                continue;
            }
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
                        if ($prefix < 0 || $prefix > 32) {
                            $prefix = null;
                        }
                    } elseif ($entry['netmask_hint'] !== '') {
                        $pfx = netmask_to_prefix($entry['netmask_hint']);
                        if ($pfx !== null) {
                            $prefix = $pfx;
                        }
                    }
                    if ($prefix === null) {
                        $prefix = 24;
                    }
                } else {
                    if ($entry['prefix_hint'] !== '' && ctype_digit($entry['prefix_hint'])) {
                        $prefix = to_int($entry['prefix_hint']);
                        if ($prefix < 0 || $prefix > 128) {
                            $prefix = null;
                        }
                    }
                    if ($prefix === null) {
                        $prefix = 64;
                    }
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

/* ---------------- Step 4: apply ---------------- */

/**
 * Resolve (creating if necessary) a device and optional interface by name,
 * auditing any rows it creates. Used inside the apply-step row loop.
 *
 * @param PDO $db Live database handle (transaction already open).
 * @param string|null $devName Device name from the plan row, or null when the
 *        CSV had no device_name column for this row.
 * @param string|null $ifaceName Interface name, or null.
 * @param PDOStatement $selDev Prepared SELECT id FROM devices WHERE name=:name.
 * @param PDOStatement $insDev Prepared INSERT INTO devices.
 * @param PDOStatement $selIf Prepared SELECT id FROM device_interfaces.
 * @param PDOStatement $insIf Prepared INSERT INTO device_interfaces.
 * @return array{device_id: int|null, interface_id: int|null}
 */
function ipam_csv_import_resolve_device(
    PDO $db,
    ?string $devName,
    ?string $ifaceName,
    PDOStatement $selDev,
    PDOStatement $insDev,
    PDOStatement $selIf,
    PDOStatement $insIf
): array {
    if ($devName === null || $devName === '') {
        return ['device_id' => null, 'interface_id' => null];
    }
    $selDev->execute([':name' => $devName]);
    /** @var array<string,mixed>|false $dr */
    $dr = $selDev->fetch();
    $createdDevice = false;
    if (!$dr) {
        $insDev->execute([':name' => $devName]);
        $createdDevice = true;
        $selDev->execute([':name' => $devName]);
        /** @var array<string,mixed>|false $dr */
        $dr = $selDev->fetch();
    }
    if (!$dr) {
        return ['device_id' => null, 'interface_id' => null];
    }
    $deviceId = to_int($dr['id']);
    if ($createdDevice) {
        audit($db, 'device.create', 'device', $deviceId, "name=$devName source=csv_import");
    }
    $interfaceId = null;
    if ($ifaceName !== null && $ifaceName !== '') {
        $selIf->execute([':did' => $deviceId, ':name' => $ifaceName]);
        /** @var array<string,mixed>|false $ir */
        $ir = $selIf->fetch();
        if (!$ir) {
            $insIf->execute([':did' => $deviceId, ':name' => $ifaceName]);
            $selIf->execute([':did' => $deviceId, ':name' => $ifaceName]);
            /** @var array<string,mixed>|false $ir */
            $ir = $selIf->fetch();
            if ($ir) {
                audit($db, 'device_interface.create', 'device_interface', to_int($ir['id']),
                    "device_id=$deviceId name=$ifaceName source=csv_import");
            }
        }
        if ($ir) {
            $interfaceId = to_int($ir['id']);
        }
    }
    return ['device_id' => $deviceId, 'interface_id' => $interfaceId];
}

/**
 * Apply a frozen dry-run plan: create/update addresses (and any required
 * subnets, devices, interfaces) inside a single transaction, detecting DB
 * drift since the dry run. Writes history rows, emits an import.csv audit
 * entry, and persists a result file under data/tmp/.
 *
 * Behaviour-preserving extraction of import_csv.php step-4. The caller is
 * responsible for demo-mode and CSRF gating; this function performs the
 * database work and returns the same summary/result-row data the page used
 * to compute inline.
 *
 * @param PDO $db Live database handle.
 * @param array<string, mixed> $plan Frozen plan from ipam_csv_import_analyze()
 *        (typically reloaded from a saved plan file).
 * @return array{
 *     summary: array{
 *         created_subnets: int, created_addresses: int,
 *         updated_addresses: int, skipped_rows: int, conflicts: int
 *     },
 *     rows: list<array<string, mixed>>,
 *     result_path: string,
 *     message: string
 * }
 * @throws Throwable On any failure; the transaction is rolled back before
 *         the exception propagates.
 */
function ipam_csv_import_apply_plan(PDO $db, array $plan): array
{
    /** @var list<array<string, mixed>> $rows */
    $rows = $plan['rows'] ?? [];
    $planMeta = is_array($plan['meta'] ?? null) ? $plan['meta'] : [];
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
    foreach ($existingSubnets as $s) {
        $existingByCidr[to_str($s['cidr'])] = to_int($s['id']);
    }

    try {
        $db->beginTransaction();

        $sel = $db->prepare("SELECT id, ip, hostname, owner, note, grp, mac, expires_at, status, device_id, interface_id, custom_fields FROM addresses WHERE subnet_id=:sid AND ip=:ip");
        $ins = $db->prepare("INSERT INTO addresses (subnet_id, ip, ip_bin, hostname, owner, note, grp, mac, expires_at, status, device_id, interface_id, custom_fields)
                             VALUES (:sid,:ip,:bin,:hn,:ow,:nt,:grp,:mac,:exp,:st,:did,:iid,:cf)");
        $upd = $db->prepare("UPDATE addresses SET hostname=:hn, owner=:ow, note=:nt, grp=:grp, mac=:mac, expires_at=:exp, status=:st, device_id=:did, interface_id=:iid, custom_fields=:cf WHERE id=:id");

        // Device/interface lookup+create helpers used inside the row loop
        $selDevice = $db->prepare("SELECT id FROM devices WHERE name=:name");
        $insDevice = $db->prepare("INSERT INTO devices (name, type) VALUES (:name, 'other')");
        $selIface  = $db->prepare("SELECT id FROM device_interfaces WHERE device_id=:did AND name=:name");
        $insIface  = $db->prepare("INSERT INTO device_interfaces (device_id, name) VALUES (:did, :name)");

        foreach ($rows as $r) {
            $result = [
                'row_num' => $r['row_num'] ?? '',
                'ip' => $r['ip'] ?? ($r['ip_raw'] ?? ''),
                'final_result' => '',
                'reason' => '',
            ];

            $finalAction = to_str($r['final_action'] ?? 'skip');

            if (in_array($finalAction, ['invalid', 'skip'], true)) {
                $result['final_result'] = $finalAction;
                $result['reason'] = to_str($r['reason'] ?? '');
                $skippedRows++;
                $resultRows[] = $result;
                continue;
            }

            $ip = to_str($r['ip'] ?? '');
            $version = to_int($r['version'] ?? 0);
            if ($ip === '' || !in_array($version, [4, 6], true)) {
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

                $devLink = ipam_csv_import_resolve_device(
                    $db,
                    isset($r['device_name']) ? to_str($r['device_name']) : null,
                    isset($r['interface_name']) ? to_str($r['interface_name']) : null,
                    $selDevice, $insDevice, $selIface, $insIface
                );

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
                $ins->bindValue(':did',  $devLink['device_id'], $devLink['device_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $ins->bindValue(':iid',  $devLink['interface_id'], $devLink['interface_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $ins->bindValue(':cf',   to_str($r['custom_fields'] ?? '{}'));
                $ins->execute();
                $aid = ipam_last_insert_id($db, 'addresses');

                history_log_address($db, 'import_create', $subnetId, $ip, $aid, null, [
                    'hostname'     => to_str($r['hostname'] ?? ''),
                    'owner'        => to_str($r['owner'] ?? ''),
                    'note'         => to_str($r['note'] ?? ''),
                    'grp'          => to_str($r['grp'] ?? ''),
                    'mac'          => to_str($r['mac'] ?? ''),
                    'expires_at'   => $insExpAt,
                    'status'       => to_str($r['status'] ?? 'used'),
                    'device_id'    => $devLink['device_id'],
                    'interface_id' => $devLink['interface_id'],
                    'custom_fields' => to_str($r['custom_fields'] ?? '{}'),
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
                $newCf = to_str($r['custom_fields'] ?? '{}');

                $devLink = ipam_csv_import_resolve_device(
                    $db,
                    isset($r['device_name']) ? to_str($r['device_name']) : null,
                    isset($r['interface_name']) ? to_str($r['interface_name']) : null,
                    $selDevice, $insDevice, $selIface, $insIface
                );
                // If device_name column was absent (null), keep existing device linkage
                $rawExistDevId   = $existing['device_id'];
                $rawExistIfaceId = $existing['interface_id'];
                if (($r['device_name'] ?? null) !== null) {
                    $newDevId   = $devLink['device_id'];
                    $newIfaceId = $devLink['interface_id'];
                } else {
                    $newDevId   = $rawExistDevId   !== null ? to_int($rawExistDevId)   : null;
                    $newIfaceId = $rawExistIfaceId !== null ? to_int($rawExistIfaceId) : null;
                }

                // Fix semantics: fill_empty does NOT overwrite status
                if ($dupMode === 'fill_empty') {
                    $newHn = (to_str($existing['hostname']) === '') ? $newHn : to_str($existing['hostname']);
                    $newOw = (to_str($existing['owner']) === '') ? $newOw : to_str($existing['owner']);
                    $newNt = (to_str($existing['note']) === '') ? $newNt : to_str($existing['note']);
                    $newGrp = (to_str($existing['grp']) === '') ? $newGrp : to_str($existing['grp']);
                    $newMac = (to_str($existing['mac']) === '') ? $newMac : to_str($existing['mac']);
                    $newExpAt = ($existing['expires_at'] === null) ? $newExpAt : to_str($existing['expires_at']);
                    $newSt = to_str($existing['status']);
                    // fill_empty: only set device linkage if not already linked
                    if ($existing['device_id'] !== null) {
                        $newDevId = to_int($existing['device_id']);
                        $newIfaceId = $existing['interface_id'] !== null ? to_int($existing['interface_id']) : null;
                    }
                    // fill_empty: keep existing custom_fields if already populated
                    $existingCf = to_str($existing['custom_fields'] ?? '{}');
                    if ($existingCf !== '' && $existingCf !== '{}') {
                        $newCf = $existingCf;
                    }
                }

                $before = [
                    'hostname'      => to_str($existing['hostname']),
                    'owner'         => to_str($existing['owner']),
                    'note'          => to_str($existing['note']),
                    'grp'           => to_str($existing['grp']),
                    'mac'           => to_str($existing['mac']),
                    'expires_at'    => $existing['expires_at'] !== null ? to_str($existing['expires_at']) : null,
                    'status'        => to_str($existing['status']),
                    'device_id'     => $existing['device_id'] !== null ? to_int($existing['device_id']) : null,
                    'interface_id'  => $existing['interface_id'] !== null ? to_int($existing['interface_id']) : null,
                    'custom_fields' => to_str($existing['custom_fields'] ?? '{}'),
                ];
                $after = [
                    'hostname'      => $newHn,
                    'owner'         => $newOw,
                    'note'          => $newNt,
                    'grp'           => $newGrp,
                    'mac'           => $newMac,
                    'expires_at'    => $newExpAt,
                    'status'        => $newSt,
                    'device_id'     => $newDevId,
                    'interface_id'  => $newIfaceId,
                    'custom_fields' => $newCf,
                ];

                $upd->bindValue(':hn',  $newHn);
                $upd->bindValue(':ow',  $newOw);
                $upd->bindValue(':nt',  $newNt);
                $upd->bindValue(':grp', $newGrp);
                $upd->bindValue(':mac', $newMac);
                $upd->bindValue(':exp', $newExpAt, $newExpAt === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $upd->bindValue(':st',  $newSt);
                $upd->bindValue(':did', $newDevId, $newDevId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $upd->bindValue(':iid', $newIfaceId, $newIfaceId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $upd->bindValue(':cf',  $newCf);
                $upd->bindValue(':id',  to_int($existing['id']), PDO::PARAM_INT);
                $upd->execute();

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
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    audit($db, 'import.csv', 'system', null,
        "created_subnets=$createdSubnets created_addresses=$createdAddresses updated_addresses=$updatedAddresses skipped=$skippedRows conflicts=$conflicts"
    );

    $summary = [
        'created_subnets' => $createdSubnets,
        'created_addresses' => $createdAddresses,
        'updated_addresses' => $updatedAddresses,
        'skipped_rows' => $skippedRows,
        'conflicts' => $conflicts,
    ];

    $resultFile = ipam_csv_import_save_result([
        'summary' => $summary,
        'rows' => $resultRows,
    ]);

    return [
        'summary' => $summary,
        'rows' => $resultRows,
        'result_path' => $resultFile,
        'message' => "Import complete. Created subnets: $createdSubnets, created addresses: $createdAddresses,"
            . " updated addresses: $updatedAddresses, skipped rows: $skippedRows, conflicts: $conflicts.",
    ];
}
