<?php
/**
 * Settings tab: Data & Maintenance — Database backup, Housekeeping, Upload limits.
 *
 * @var \PDO                  $db
 * @var array<string, mixed>  $definitions
 * @var array<string, mixed>  $groups
 * @var array<string, string> $fieldErrors
 * @var array<string, string> $formOverrides
 */
declare(strict_types=1);

$tabGroups = ['backup', 'housekeeping', 'limits'];
foreach ($tabGroups as $groupKey) {
    if (!isset($groups[$groupKey])) continue;
    ipam_render('settings_group_form', [
        'db'            => $db,
        'groupKey'      => $groupKey,
        'groupMeta'     => $groups[$groupKey],
        'definitions'   => $definitions,
        'fieldErrors'   => $fieldErrors,
        'formOverrides' => $formOverrides,
    ]);
}
