/**
 * @smoke Ops + API non-exposure (P1-C)
 */
import { expect, test } from './support/fixtures';
import { loginAs } from './support/demo';
import { FORMER_SCAFFOLD_API_PATHS } from './support/former-api-paths';

/**
 * Disable trace/video/screenshot for this file: the former scaffold API paths
 * are actual request URLs that would be recorded in HAR entries inside
 * trace.zip, violating the "retained diagnostics" non-disclosure clause.
 * Other test files retain their normal failure-only diagnostics.
 */
test.use({ trace: 'off', video: 'off', screenshot: 'off' });

test.describe('@smoke ops and api containment', () => {
    test('/up liveness contract', async ({ page }) => {
        const response = await page.request.get('/up');
        const body = await response.text();

        expect(response.status()).toBe(200);
        expect(response.headers()['content-type']).toContain('text/html');
        expect(body).toContain('Application up');
        expect(body).toContain('HTTP request received.');
    });

    test('/ready public-safe JSON contract', async ({ page }) => {
        const response = await page.request.get('/ready');
        const body = await response.text();
        const json = JSON.parse(body) as {
            status?: string;
            checks?: Record<string, string>;
        };

        expect(response.status()).toBe(json.status === 'not_ready' ? 503 : 200);
        expect(response.headers()['content-type']).toContain('application/json');

        expect(json).toEqual(
            expect.objectContaining({
                status: expect.stringMatching(/^(ready|degraded|not_ready)$/),
                checks: expect.any(Object),
            }),
        );
        expect(Object.keys(json).sort()).toEqual(['checks', 'status']);
        expect(Object.keys(json.checks ?? {}).sort()).toEqual([
            'backup',
            'cache',
            'database',
            'failed_jobs',
            'queue',
            'scheduler',
            'storage_link',
        ]);

        for (const value of Object.values(json.checks ?? {})) {
            expect(['ok', 'fail', 'unknown']).toContain(value);
        }

        for (const leak of ['password', '127.0.0.1', 'mysql', 'PDO', 'SQLSTATE', 'secret']) {
            expect(body.toLowerCase()).not.toContain(leak.toLowerCase());
        }
    });

    test('/ready exposes only the scheduler smoke state', async ({ page }) => {
        const response = await page.request.get('/ready');
        const json = (await response.json()) as { checks?: Record<string, string> };

        expect(['ok', 'fail', 'unknown']).toContain(json.checks?.scheduler);
    });

    test('former scaffold API paths return 404 for guest and auth', async ({ page }) => {
        for (const path of FORMER_SCAFFOLD_API_PATHS) {
            const response = await page.request.get(path);
            expect(response.status()).toBe(404);
        }

        await loginAs(page, 'admin');
        for (const path of FORMER_SCAFFOLD_API_PATHS) {
            const response = await page.request.get(path);
            expect(response.status()).toBe(404);
        }
    });
});
