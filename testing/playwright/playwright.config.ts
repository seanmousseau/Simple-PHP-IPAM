import { defineConfig, devices } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

// Load .env file written by bootstrap-app.sh into process.env so tests can
// read SEED_2FA_TEST_USER and any other bootstrap-time flags without requiring
// the caller to pass them explicitly on the command line.
const dotenvPath = path.join(__dirname, '.env');
if (fs.existsSync(dotenvPath)) {
    for (const line of fs.readFileSync(dotenvPath, 'utf-8').split('\n')) {
        const m = line.match(/^([^#=\s][^=]*)=(.*)$/);
        if (m && !(m[1] in process.env)) process.env[m[1]] = m[2];
    }
}

// Normalize base URL: ensure no trailing slash so we can append '/page.php' cleanly.
const rawBase = process.env.IPAM_BASE_URL || 'http://localhost:8080';
export const APP_BASE = rawBase.replace(/\/$/, '');

// HTTP Basic Auth protecting the /claude/ gateway on the dev server.
const basicUser = process.env.IPAM_BASIC_USER || '';
const basicPass = process.env.IPAM_BASIC_PASS || '';

export default defineConfig({
  testDir: './tests',
  fullyParallel: false,
  workers: 1,        // sequential — tests share a single SQLite DB
  // 2 retries in CI (containerized Apache has cold caches, variable startup,
  // and slower I/O than a warm dev-direct target); 0 locally so flaky tests
  // surface immediately during development.
  retries: process.env.CI ? 2 : 0,
  maxFailures: process.env.CI ? 10 : 0,
  timeout: 60_000,
  expect: {
    timeout: 10_000,
  },
  reporter: [
    ['list'],
    ['html', { open: 'never', outputFolder: 'playwright-report' }],
    ['json', { outputFile: 'playwright-report/results.json' }],
  ],
  use: {
    baseURL: APP_BASE + '/',
    httpCredentials: basicUser
      ? { username: basicUser, password: basicPass, send: 'always' }
      : undefined,
    actionTimeout: 15_000,
    screenshot: 'only-on-failure',
    trace: process.env.CI ? 'retain-on-failure' : 'off',
    video: 'off',
    headless: true,
    ignoreHTTPSErrors: true,   // dev-direct + containerized Apache both use self-signed certs
  },
  projects: [
    {
      name: 'chromium',
      testIgnore: /visual-regression/,
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'vr-1440',
      testMatch: /visual-regression/,
      use: {
        ...devices['Desktop Chrome'],
        viewport: { width: 1440, height: 900 },
        deviceScaleFactor: 1,
      },
    },
    {
      name: 'vr-1024',
      testMatch: /visual-regression/,
      use: {
        ...devices['Desktop Chrome'],
        viewport: { width: 1024, height: 768 },
        deviceScaleFactor: 1,
      },
    },
    {
      name: 'vr-768',
      testMatch: /visual-regression/,
      use: {
        ...devices['Desktop Chrome'],
        viewport: { width: 768, height: 1024 },
        deviceScaleFactor: 1,
      },
    },
    {
      name: 'vr-375',
      testMatch: /visual-regression/,
      use: {
        ...devices['Desktop Chrome'],
        viewport: { width: 375, height: 812 },
        deviceScaleFactor: 1,
      },
    },
  ],
});
