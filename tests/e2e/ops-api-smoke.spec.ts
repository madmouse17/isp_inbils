/**
 * @smoke Ops + API non-exposure (P1-C)
 */
import { expect, test } from './support/fixtures';
import { loginAs } from './support/demo';

const FORMER_SCAFFOLD_API_PATHS = [
    '/api/v1/billings',
    '/api/v1/customers',
    '/api/v1/inventories',
    '/api/v1/networkassets',
    '/api/v1/reportings',
    '/api/v1/services',
    '/api/v1/spks',
    '/api/v1/ticketings',
];

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
            expect((await page.request.get(path)).status(), path).toBe(404);
        }

        await loginAs(page, 'admin');
        for (const path of FORMER_SCAFFOLD_API_PATHS) {
            expect((await page.request.get(path)).status(), `auth ${path}`).toBe(404);
        }
    });
});
