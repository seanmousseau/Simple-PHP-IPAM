<?php
/**
 * Settings tab: Authentication — Sessions & lockout, Password policy, MFA,
 * OIDC / SSO, Login protection, reCAPTCHA Enterprise.
 *
 * @var \PDO                  $db
 * @var array<string, mixed>  $definitions
 * @var array<string, mixed>  $groups
 * @var array<string, string> $fieldErrors
 * @var array<string, string> $formOverrides
 */
declare(strict_types=1);

$tabGroups = ['security', 'password_policy', 'mfa', 'oidc', 'login_protection', 'recaptcha_enterprise'];
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
