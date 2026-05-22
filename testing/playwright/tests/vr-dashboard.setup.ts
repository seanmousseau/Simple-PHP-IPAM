/**
 * Setup step for the vr-dashboard Playwright project (#775).
 *
 * Runs serially before the vr-dashboard-* projects via Playwright project
 * dependencies. Responsibilities:
 *   1. Re-bootstrap the app against a freshly seeded SQLite DB so every
 *      dashboard widget shows known-quiet data (no suite-mutated rows).
 *   2. Log in as admin and persist the storage state so the vr-dashboard-*
 *      projects can reuse the authenticated session without a fresh login.
 *
 * The storage-state file is written to the same `tests/` directory as this
 * file and is gitignored (it contains session cookies — not committed).
 *
 * NOTE: bootstrap-app.sh is invoked with cwd set to the repo root so the
 * script's internal `./` paths resolve correctly. The IPAM_BASE_URL env var
 * must be set in the environment (or .env) before running this project;
 * the default http://localhost:8080 almost certainly does not match the
 * containerized port (8443).
 */
import { test as setup } from '@playwright/test';
import { execFileSync } from 'child_process';
import * as path from 'path';
import { login, ADMIN_USER, ADMIN_PASS } from '../fixtures/ipam';

// Where we persist the authenticated session for the vr-dashboard projects.
export const VR_DASHBOARD_STORAGE = path.join(__dirname, 'vr-dashboard.storage.json');

// Allowlist of valid driver names. bootstrap-app.sh accepts only these values.
const ALLOWED_DRIVERS = ['sqlite', 'mysql', 'pgsql'] as const;
type Driver = typeof ALLOWED_DRIVERS[number];

function resolveDriver(): Driver {
  const raw = (process.env.IPAM_DRIVER ?? 'sqlite').toLowerCase();
  if ((ALLOWED_DRIVERS as readonly string[]).includes(raw)) return raw as Driver;
  throw new Error(
    `vr-dashboard setup: IPAM_DRIVER="${raw}" is not a recognised driver. ` +
    `Allowed values: ${ALLOWED_DRIVERS.join(', ')}.`,
  );
}

setup('vr-dashboard: reseed quiescent DB and save auth storage', async ({ page, context }) => {
  // Give this step enough time for bootstrap-app.sh (Docker pull + DB seed).
  setup.setTimeout(300_000);

  // Re-bootstrap: spins up (or recycles) the test container with a fresh
  // demo_seed.php run so the DB contains only canonical seed rows.
  const driver = resolveDriver();
  const repoRoot = path.resolve(__dirname, '../../..');
  try {
    // Use execFileSync with an argument array (not a shell string) so there is
    // no path for shell injection, even if IPAM_DRIVER were somehow tainted.
    execFileSync('bash', ['testing/playwright/bootstrap-app.sh', driver], {
      cwd: repoRoot,
      stdio: 'inherit',
      env: { ...process.env },
    });
  } catch (err) {
    throw new Error(
      `vr-dashboard setup: bootstrap-app.sh failed for driver=${driver}. ` +
      `Ensure Docker is running and IPAM_BASE_URL is set correctly. ` +
      `Original error: ${(err as Error).message}`,
    );
  }

  // Log in and persist session cookies / localStorage so vr-dashboard-*
  // tests reuse the warm session without repeating the login round-trip.
  await login(page, ADMIN_USER, ADMIN_PASS);
  await context.storageState({ path: VR_DASHBOARD_STORAGE });
});
