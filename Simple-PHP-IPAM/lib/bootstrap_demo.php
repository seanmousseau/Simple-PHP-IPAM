<?php
declare(strict_types=1);

/**
 * @module bootstrap_demo
 *
 * Demo-mode bootstrap extracted from init.php in v3.35.0 (#1293).
 * Responsibility: run the nightly demo-data reset when due and enforce
 * the demo gate (redirect unauthenticated visitors to demo_gate.php).
 *
 * Must be called AFTER ipam_db_init() (DB is required for the reset) and
 * AFTER session_start() (gate checks $_SESSION['demo_gate_passed']).
 * Must be called BEFORE any page-level logic that assumes authenticated state.
 *
 * ADR-003: no `global $config;` — caller passes $config and $db explicitly.
 * No sibling lib/*.php requires — all helpers resolve at call time.
 *
 * @param IpamConfig $config
 * @param PDO        $db
 */
function ipam_bootstrap_demo_mode(array $config, PDO $db): void
{
    // Demo nightly reset — independent of housekeeping schedule; never crashes the page
    if (!empty($config['demo_mode']['enabled'])) {
        run_demo_reset_if_due($db);
    }

    // Demo gate (#125): redirect to challenge page if gate is configured and not yet passed
    if (!empty($config['demo_mode']['enabled'])
        && !empty($config['demo_mode']['gate'])
        && empty($_SESSION['demo_gate_passed'])
    ) {
        $gateExempt = ['demo_gate.php', 'status.php', 'api.php'];
        $thisScript = basename(to_str($_SERVER['SCRIPT_FILENAME'] ?? ''));
        if (!in_array($thisScript, $gateExempt, true)) {
            header('Location: demo_gate.php');
            exit;
        }
    }
}
