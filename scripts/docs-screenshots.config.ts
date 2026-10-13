import { defineConfig } from '@playwright/test';

import base from '../playwright.config';

/** Same canonical server (8010, inbils_test) as the E2E suite; only the test file differs. */
export default defineConfig({
    ...base,
    testDir: '.',
    testMatch: 'docs-screenshots.spec.ts',
    testIgnore: undefined,
    reporter: [['list']],
    timeout: 20 * 60_000,
});
