/**
 * @lifecycle Inventory issue / transfer / adjust (P1-C)
 *
 * Residual gaps:
 * - Explicit reservation-conflict UI not present beyond available column; no-negative enforced by API flash/422.
 * - Adjust is two-step (role-persona-sod): staff requests, a different manager approves on the
 *   Stocks page (covered below); admin keeps the audited break-glass endpoint
 *   `/admin/stocks/adjust/direct` (`inventory.stock.adjust`).
 * - The Stocks page is receive-only since the Indonesian UI refresh (modal
 *   "Terima Barang"); issue/transfer/adjust have no modal UI anymore, so those
 *   journey legs drive the same guarded POST endpoints through the
 *   authenticated request context (X-XSRF-TOKEN pattern), while receive and
 *   movement evidence stay on real UI selectors.
 */
import { expect, test } from './support/fixtures';
import { inertiaProps, loginAs, logout, refreshSession, unique } from './support/demo';

const runId = unique('INV');

test.describe('@lifecycle inventory', () => {
    test.describe.configure({ mode: 'serial' });

    test('receive, issue, transfer, adjust with movement evidence', async ({ page }) => {
        await loginAs(page, 'admin');

        // Resolve real product/location ids from server-rendered props (no
        // fixed ids); the transfer leg needs a second, different location.
        const stocksProps = await inertiaProps(page, '/admin/stocks');
        const productId = stocksProps?.props?.products?.data?.[0]?.id;
        const locationId = stocksProps?.props?.locations?.data?.[0]?.id;
        const toLocationId = stocksProps?.props?.locations?.data?.[1]?.id;
        expect(productId).toBeTruthy();
        expect(locationId).toBeTruthy();
        expect(toLocationId).toBeTruthy();

        const receiveNote = `recv-${runId}`;
        const issueNote = `issue-${runId}`;
        const transferNote = `xfer-${runId}`;
        const adjustNote = `adj-${runId}`;

        await page.goto('/admin/stocks');
        await expect(page.getByRole('main').getByRole('heading', { name: 'Stok' })).toBeVisible();
        await page.waitForTimeout(500);

        // Receive through the current Indonesian UI.
        await page.getByRole('button', { name: 'Terima Barang' }).click();
        const dialog = page.getByRole('dialog', { name: 'Terima Barang' });
        await dialog.getByLabel('Produk').selectOption(String(productId));
        await dialog.getByLabel('Lokasi').selectOption(String(locationId));
        await dialog.getByLabel('Jumlah').fill('10');
        await dialog.getByLabel('Catatan').fill(receiveNote);
        await dialog.getByRole('button', { name: 'Simpan' }).click();
        await expect(dialog).toBeHidden();

        // Issue / transfer / adjust have no modal UI anymore; drive their
        // guarded endpoints with the live session. Laravel answers a successful
        // mutation with a redirect flash — validation/gate failures surface as
        // 4xx instead, so a 30x here is hard evidence the movement persisted.
        const csrf = await refreshSession(page, '/admin/stocks');
        const postMovement = async (path: string, form: Record<string, string>) => {
            const response = await page.request.post(path, {
                headers: { 'X-XSRF-TOKEN': csrf },
                maxRedirects: 0,
                form,
            });
            expect([302, 303]).toContain(response.status());

            return response;
        };

        await postMovement('/admin/stocks/issue', {
            product_id: String(productId),
            location_id: String(locationId),
            quantity: '2',
            note: issueNote,
        });

        // Transfer one unit to a location different from where this journey received.
        await postMovement('/admin/stocks/transfer', {
            product_id: String(productId),
            from_location_id: String(locationId),
            to_location_id: String(toLocationId!),
            quantity: '1',
            note: transferNote,
        });

        // Admin break-glass applies at once and is flagged in the audit log, which keeps this
        // journey's movement evidence deterministic; the two-step flow has its own test below.
        await postMovement('/admin/stocks/adjust/direct', {
            product_id: String(productId),
            location_id: String(locationId),
            new_quantity: '7',
            note: adjustNote,
        });

        // Movement evidence through the UI.
        await page.getByRole('link', { name: 'Mutasi Stok' }).click();
        await expect(
            page.getByRole('main').getByRole('heading', { name: 'Mutasi Stok' }),
        ).toBeVisible();

        for (const note of [receiveNote, issueNote, transferNote, adjustNote]) {
            await expect(page.getByRole('row').filter({ hasText: note }).first()).toBeVisible();
        }
    });

    test('staff requests an adjustment and a different manager approves it', async ({ page }) => {
        await loginAs(page, 'admin');
        const stocksProps = await inertiaProps(page, '/admin/stocks');
        const productId = stocksProps?.props?.products?.data?.[0]?.id;
        const locationId = stocksProps?.props?.locations?.data?.[0]?.id;
        expect(productId).toBeTruthy();
        expect(locationId).toBeTruthy();
        await logout(page);

        // Operator proposes; a redirect flash proves the request was stored, not applied.
        const requestNote = `adjreq-${runId}`;
        await loginAs(page, 'staff');
        const staffCsrf = await refreshSession(page, '/admin/stocks');
        const requested = await page.request.post('/admin/stocks/adjust', {
            headers: { 'X-XSRF-TOKEN': staffCsrf },
            maxRedirects: 0,
            form: {
                product_id: String(productId),
                location_id: String(locationId),
                new_quantity: '7',
                note: requestNote,
            },
        });
        expect([302, 303]).toContain(requested.status());

        // The requester sees their pending row but cannot approve it.
        await page.goto('/admin/stocks');
        const staffRow = page.getByRole('row').filter({ hasText: requestNote });
        await expect(staffRow).toBeVisible();
        await expect(staffRow.getByRole('button', { name: 'Setujui' })).toHaveCount(0);
        await logout(page);

        // A different controller approves it from the Stocks page.
        await loginAs(page, 'manager');
        await page.goto('/admin/stocks');
        await page
            .getByRole('row')
            .filter({ hasText: requestNote })
            .getByRole('button', { name: 'Setujui' })
            .click();
        const dialog = page.getByRole('dialog', { name: 'Setujui Penyesuaian Stok' });
        await dialog.getByRole('button', { name: 'Setujui' }).click();
        await expect(dialog).toBeHidden();
        await expect(page.getByRole('row').filter({ hasText: requestNote })).toHaveCount(0);

        // The approved movement carries the requester's reason as its note.
        await page.goto('/admin/stock-movements');
        await expect(page.getByRole('row').filter({ hasText: requestNote }).first()).toBeVisible();
        await logout(page);
    });

    test('issue more than available surfaces validation/error without soft-pass', async ({
        page,
    }) => {
        await loginAs(page, 'admin');

        const stocksProps = await inertiaProps(page, '/admin/stocks');
        const productId = stocksProps?.props?.products?.data?.[0]?.id;
        const locationId = stocksProps?.props?.locations?.data?.[0]?.id;
        expect(productId).toBeTruthy();
        expect(locationId).toBeTruthy();

        // No Issue modal exists anymore; over-issue must still fail loudly at
        // the guarded endpoint. With a JSON expectation InsufficientStock
        // renders as 422 {"message": "Insufficient stock..."} — never a pass.
        const csrf = await refreshSession(page, '/admin/stocks');
        const overissue = await page.request.post('/admin/stocks/issue', {
            headers: { 'X-XSRF-TOKEN': csrf, Accept: 'application/json' },
            form: {
                product_id: String(productId),
                location_id: String(locationId),
                quantity: '999999999',
                note: `overissue-${runId}`,
            },
        });
        expect(overissue.status()).toBe(422);
        const body = (await overissue.json()) as { message?: string };
        expect(body.message ?? '').toMatch(/insufficient/i);

        // Verify the rejected movement was never persisted: browse to Stock Movements
        // and search for the unique note, expecting zero rows.
        await page.goto('/admin/stock-movements');
        await expect(
            page.getByRole('main').getByRole('heading', { name: 'Mutasi Stok' }),
        ).toBeVisible({ timeout: 15_000 });
        const search = page.getByLabel(/search/i).first();
        await search.fill(`overissue-${runId}`);
        await page.getByRole('button', { name: 'Filter' }).click();
        await expect(page.getByRole('row').filter({ hasText: `overissue-${runId}` })).toHaveCount(
            0,
        );
    });
});
