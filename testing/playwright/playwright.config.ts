import { defineConfig, devices } from '@playwright/test';

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
