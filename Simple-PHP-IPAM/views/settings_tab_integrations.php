<?php
/**
 * Settings tab: Integrations — API, Webhooks.
 *
 * @var \PDO                  $db
 * @var array<string, mixed>  $definitions
 * @var array<string, mixed>  $groups
 * @var list<string>          $tabGroups
 * @var array<string, string> $fieldErrors
 * @var array<string, string> $formOverrides
 */
declare(strict_types=1);

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
