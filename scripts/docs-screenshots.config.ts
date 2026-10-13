import { defineConfig } from '@playwright/test';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import base from '../playwright.config';

/** Same canonical server (8010, inbils_test) as the E2E suite; only the test file differs. */
export default defineConfig({
    ...base,
    testDir: '.',
    testMatch: 'docs-screenshots.spec.ts',
    testIgnore: undefined,
    reporter: [['list']],
    timeout: 20 * 60_000,
    // Playwright resolves `command` and the output dir from this file's folder, not the repo root.
    webServer: base.webServer ? { ...base.webServer, cwd: path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..') } : undefined,
    outputDir: path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', 'test-results'),
});
