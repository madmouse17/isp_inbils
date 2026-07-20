import { defineConfig, devices } from '@playwright/test';

const baseURL = 'http://127.0.0.1:8010';

export default defineConfig({
    testDir: './tests/e2e',
    testIgnore: '**/fixture-policy.spec.ts',
    fullyParallel: false,
    workers: 1,
    forbidOnly: !!process.env.CI,
    reporter: [['list'], ['html', { open: 'never' }]],
    outputDir: 'test-results',
    use: {
        baseURL,
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
        video: 'retain-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
    webServer: {
        command: 'node scripts/e2e-webserver.mjs',
        url: baseURL,
        reuseExistingServer: false,
        timeout: 30_000,
    },
});
