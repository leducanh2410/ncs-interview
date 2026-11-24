import { defineConfig, devices } from '@playwright/test';
import { BASE_URL, STORAGE_STATE } from './e2e/constants';

// e2e foundation. Requires the dev stack running:
//   - backend on docker (api at https://api.dgf.localhost:1355 / :8085)
//   - frontend `pnpm dev` (portless https://dgf.localhost:1355)
// Uses the system Google Chrome (channel: 'chrome') so no browser download is needed.
export default defineConfig({
    testDir: './e2e',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    reporter: process.env.CI ? 'github' : 'html',
    use: {
        baseURL: BASE_URL,
        ignoreHTTPSErrors: true, // dev self-signed certs
        channel: 'chrome', // use system Google Chrome — no Playwright browser download needed
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
    },
    projects: [
        { name: 'setup', testMatch: /.*\.setup\.ts/ },
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'], storageState: STORAGE_STATE },
            dependencies: ['setup'],
        },
        {
            name: 'mobile',
            use: { ...devices['Pixel 7'], storageState: STORAGE_STATE },
            dependencies: ['setup'],
        },
    ],
});
