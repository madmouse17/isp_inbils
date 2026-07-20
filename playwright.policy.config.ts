import { defineConfig, devices } from '@playwright/test';

const baseURL = 'http://127.0.0.1:8010';

export default defineConfig({
    testDir: './tests/e2e',
    testMatch: 'fixture-policy.spec.ts',
    reporter: 'list',
    use: {
        baseURL,
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
