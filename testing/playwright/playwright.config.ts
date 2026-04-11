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
  retries: 0,
  timeout: 30_000,
  reporter: [
    ['list'],
    ['html', { open: 'never', outputFolder: 'playwright-report' }],
  ],
  use: {
    baseURL: APP_BASE + '/',
    httpCredentials: basicUser
      ? { username: basicUser, password: basicPass, send: 'always' }
      : undefined,
    screenshot: 'only-on-failure',
    video: 'off',
    headless: true,
    ignoreHTTPSErrors: true,   // dev server uses self-signed cert
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
