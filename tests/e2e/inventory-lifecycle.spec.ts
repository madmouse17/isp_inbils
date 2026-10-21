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
import type { APIRequestContext, APIResponse, Page } from '@playwright/test';
import { apiLogin } from './support/api';
import { expect, test } from './support/fixtures';
import { companyDateFormat, formatIsoAs } from './support/dateRange';
import {
    inertiaProps,
    loginAs,
    logout,
    refreshSession,
    selectSearchOption,
    unique,
} from './support/demo';

const runId = unique('INV');

/**
 * The options a picker would show for `search`. The page sends them only when asked (an optional Inertia prop),
 * so the spec asks the way `RemoteSearchSelect` does: a partial reload with the search param.
 */
async function pickerOptions(
    page: Page,
    prop: 'productOptions' | 'locationOptions',
    param: 'product_search' | 'location_search',
    search: string,
) {
    const loaded = (await inertiaProps(page, '/admin/stocks')) as unknown as { version?: string };
    const response = await page.request.get(
        `/admin/stocks?${new URLSearchParams({ [param]: search })}`,
        {
            headers: {
                Accept: 'application/json',
                'X-Inertia': 'true',
                'X-Inertia-Version': loaded.version ?? '',
                'X-Inertia-Partial-Component': 'Admin/Inventory/Stocks/Index',
                'X-Inertia-Partial-Data': prop,
            },
        },
    );
    expect(response.ok(), `${prop} for "${search}"`).toBeTruthy();
    const body = (await response.json()) as {
        props: Record<string, { value: string; label: string }[]>;
    };

    return body.props[prop];
}

/** A real product and two E2E locations, found through the pickers (no fixed ids). */
async function stockPickers(page: Page) {
    const [product] = await pickerOptions(
        page,
        'productOptions',
        'product_search',
        'E2E-STOCK-PRODUCT',
    );
    const locations = await pickerOptions(page, 'locationOptions', 'location_search', 'E2E');

    return { product, locations };
}

async function json<T>(response: APIResponse): Promise<T> {
    expect(response.ok(), `${response.url()} -> ${response.status()}`).toBeTruthy();

    return (await response.json()) as T;
}

test.describe('@lifecycle inventory', () => {
    test.describe.configure({ mode: 'serial' });

    test('receive, issue, transfer, adjust with movement evidence', async ({ page }) => {
        await loginAs(page, 'admin');

        // Resolve real product/location ids through the pickers (no fixed ids);
        // the transfer leg needs a second, different location.
        const { product, locations } = await stockPickers(page);
        const [from, to] = locations;
        const productId = product?.value;
        const locationId = from?.value;
        const toLocationId = to?.value;
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
        await selectSearchOption(page, 'Barang', 'E2E-STOCK-PRODUCT');
        await expect(dialog.getByRole('combobox', { name: 'Barang' })).toHaveValue(product!.label);
        await selectSearchOption(page, 'Lokasi', from!.label.split(' - ')[0]);
        await expect(dialog.getByRole('combobox', { name: 'Lokasi' })).toHaveValue(from!.label);
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
        const { product, locations } = await stockPickers(page);
        const productId = product?.value;
        const locationId = locations[0]?.value;
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

        const { product, locations } = await stockPickers(page);
        const productId = product?.value;
        const locationId = locations[0]?.value;
        expect(productId).toBeTruthy();
        expect(locationId).toBeTruthy();

        // No Issue modal exists anymore; over-issue must still fail loudly at
        // the guarded endpoint. With a JSON expectation InsufficientStock
        // renders as 422 {"message": "Stok <barang> di <lokasi> tidak cukup: tersedia ..., diminta ..."}, never a pass.
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
        expect(body.message ?? '').toMatch(/^Stok .+ di .+ tidak cukup: tersedia .+, diminta 999\.999\.999/);

        // Verify the rejected movement was never persisted: browse to Stock Movements
        // and search for the unique note, expecting zero rows.
        await page.goto('/admin/stock-movements');
        await expect(
            page.getByRole('main').getByRole('heading', { name: 'Mutasi Stok' }),
        ).toBeVisible({ timeout: 15_000 });
        const search = page.getByLabel('Cari mutasi');
        await search.fill(`overissue-${runId}`);
        await expect(page).toHaveURL(new RegExp(`search=overissue-${runId}`));
        await expect(page.getByText('Tidak ada mutasi yang cocok')).toBeVisible();
        await expect(page.getByRole('row').filter({ hasText: `overissue-${runId}` })).toHaveCount(
            0,
        );
    });

    test('request form takes the needed date, a product and a source type from pickers', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        await page.goto('/admin/inventory/requests/create');
        await expect(
            page.getByRole('main').getByRole('heading', { name: 'Buat Permintaan Barang' }),
        ).toBeVisible();

        const format = await companyDateFormat(page);
        const needed = page.getByLabel('Tanggal Dibutuhkan', { exact: true });
        await needed.fill(formatIsoAs('2030-03-15', format));
        await needed.press('Tab');

        await selectSearchOption(page, 'Barang 1', 'E2E-STOCK-PRODUCT');
        await expect(page.getByRole('combobox', { name: 'Barang 1' })).toHaveValue(
            /E2E-STOCK-PRODUCT/,
        );
        await page.getByLabel('Alasan Kebutuhan Barang').fill(`permintaan-${runId}`);

        // A source type brings its own picker; there is no id to type.
        await page.getByLabel('Rujukan (opsional)').selectOption('ticket');
        await expect(page.getByRole('combobox', { name: 'Tiket' })).toBeVisible();
        await expect(page.getByRole('spinbutton', { name: /ID/ })).toHaveCount(0);
        await page.getByLabel('Rujukan (opsional)').selectOption('');
        await expect(page.getByRole('combobox', { name: 'Tiket' })).toHaveCount(0);

        await page.getByRole('button', { name: 'Simpan sebagai Draf' }).click();
        await expect(page).toHaveURL(/\/admin\/inventory\/requests\/[0-9A-HJKMNP-TV-Z]{26}$/i);
        await expect(page.getByRole('main')).toContainText(formatIsoAs('2030-03-15', format));
    });

    test('movements are filtered by a product picked from the list', async ({ page }) => {
        await loginAs(page, 'admin');
        await page.goto('/admin/stock-movements');
        await selectSearchOption(page, 'Barang', 'E2E-STOCK-PRODUCT');
        await expect(page).toHaveURL(/product_id=[0-9A-HJKMNP-TV-Z]{26}/i);

        await page.getByLabel('Cari mutasi').fill('E2E Stock Card Movement 05');
        await expect(
            page.getByRole('row').filter({ hasText: 'E2E Stock Card Movement 05' }),
        ).toBeVisible();

        await page.getByLabel('Jenis', { exact: true }).selectOption('issue');
        await expect(page).toHaveURL(/movement_type=issue/);
        await expect(page.getByText('Tidak ada mutasi yang cocok')).toBeVisible();
    });

    test('the approver sees the stock free for each line and cannot reserve more than it', async ({
        page,
        request,
    }) => {
        // A technician asks for far more than any warehouse holds. The warehouse is stocked first, so the figure the
        // approver should see is a known, positive one.
        const technician = await apiLogin(request, 'technician');
        const product = (
            await json<{ data: { id: string }[] }>(
                await technician.get('technician/products?search=E2E-STOCK-PRODUCT'),
            )
        ).data[0];
        const warehouse = (
            await json<{ data: { id: string }[] }>(await technician.get('technician/warehouses'))
        ).data[0];
        expect(product, 'E2E stock product').toBeTruthy();
        expect(warehouse, 'a warehouse the technician can request from').toBeTruthy();

        await loginAs(page, 'admin');
        const csrf = await refreshSession(page, '/admin/stocks');
        const received = await page.request.post('/admin/stocks/receive', {
            headers: { 'X-XSRF-TOKEN': csrf },
            maxRedirects: 0,
            form: {
                product_id: product.id,
                location_id: warehouse.id,
                quantity: '10',
                note: `stock-${runId}`,
            },
        });
        expect([302, 303]).toContain(received.status());
        await logout(page);

        const created = await json<{ data: { id: string } }>(
            await technician.post('technician/inventory-requests', {
                warehouse_location_id: warehouse.id,
                reason: `approve-stock-${runId}`,
                items: [{ product_id: product.id, quantity_requested: 999999 }],
            }),
        );

        await loginAs(page, 'manager');
        const url = `/admin/inventory/requests/${created.data.id}`;
        await page.goto(url);
        await expect(page.getByRole('heading', { name: /^Permintaan Barang REQ-/ })).toBeVisible();

        // The page is sent the free stock of each line, keyed by the line; the dialog shows that figure.
        const props = (await inertiaProps(page, url)).props ?? {};
        const itemId = props.request.data.items[0].id as string;
        const available = Number(props.stockAvailable[itemId]);
        expect(available, 'the warehouse was stocked above').toBeGreaterThanOrEqual(10);

        await page.getByRole('button', { name: 'Setujui & Reservasi Stok' }).click();
        const dialog = page.getByRole('alertdialog', {
            name: 'Setujui Permintaan & Reservasi Stok',
        });
        await expect(dialog).toContainText(
            `Tersedia: ${new Intl.NumberFormat('id-ID').format(available)}`,
        );

        // The request asked for more than is free: the line says so and approving waits.
        const quantity = dialog.getByLabel(/^Jumlah disetujui untuk/);
        const approve = dialog.getByRole('button', { name: 'Setujui & Reservasi' });
        await expect(quantity).toHaveAttribute('aria-invalid', 'true');
        await expect(dialog).toContainText('Melebihi stok tersedia');
        await expect(dialog).toContainText('Kurangi jumlah yang melebihi stok.');
        await expect(approve).toBeDisabled();

        // Within the free stock the warning goes and the approval goes through.
        await quantity.fill('1');
        await expect(quantity).not.toHaveAttribute('aria-invalid', 'true');
        await expect(approve).toBeEnabled();
        await approve.click();
        await expect(dialog).toBeHidden();

        const approved = (await inertiaProps(page, url)).props ?? {};
        expect(approved.request.data.status).toBe('approved_reserved');
        expect(approved.request.data.items[0].quantity_approved).toBe(1);
        expect(approved.stockAvailable, 'no figure once the request is approved').toBeNull();
    });

    /**
     * A request the technician makes through the app API, for one unit of the stocked E2E product. The warehouse is
     * stocked first, so an approval has something to reserve.
     */
    async function requestInApp(page: Page, request: APIRequestContext, reason: string) {
        const technician = await apiLogin(request, 'technician');
        const product = (
            await json<{ data: { id: string }[] }>(
                await technician.get('technician/products?search=E2E-STOCK-PRODUCT'),
            )
        ).data[0];
        const warehouse = (
            await json<{ data: { id: string }[] }>(await technician.get('technician/warehouses'))
        ).data[0];

        await loginAs(page, 'admin');
        const csrf = await refreshSession(page, '/admin/stocks');
        const received = await page.request.post('/admin/stocks/receive', {
            headers: { 'X-XSRF-TOKEN': csrf },
            maxRedirects: 0,
            form: { product_id: product.id, location_id: warehouse.id, quantity: '5', note: `stock-${reason}` },
        });
        expect([302, 303]).toContain(received.status());
        await logout(page);

        const created = await json<{ data: { id: string } }>(
            await technician.post('technician/inventory-requests', {
                warehouse_location_id: warehouse.id,
                reason,
                items: [{ product_id: product.id, quantity_requested: 1 }],
            }),
        );

        return `/admin/inventory/requests/${created.data.id}`;
    }

    test('a waiting request says who decides, and a rejected one ends on its own step', async ({
        page,
        request,
    }) => {
        const url = await requestInApp(page, request, `reject-${runId}`);

        // The warehouse cannot decide: the page names who can, and offers no approve button.
        await loginAs(page, 'staff');
        await page.goto(url);
        await expect(page.getByText('Menunggu persetujuan Manajer/Admin')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Setujui & Reservasi Stok' })).toHaveCount(0);
        await logout(page);

        await loginAs(page, 'manager');
        const csrf = await refreshSession(page, '/admin/inventory/requests');
        const rejected = await page.request.post(`${url}/reject`, {
            headers: { 'X-XSRF-TOKEN': csrf },
            maxRedirects: 0,
            form: { rejection_reason: `bukan kebutuhan ${runId}` },
        });
        expect([302, 303]).toContain(rejected.status());

        // The flow stops at Ditolak: the approval steps it never reached are not listed.
        await page.goto(url);
        await expect(page.getByText('Ditolak', { exact: true }).first()).toBeVisible();
        await expect(page.getByText('Disetujui & Direservasi')).toHaveCount(0);
    });

    test('the warehouse sets the goods aside in one click and hands over with one confirmation', async ({
        page,
        request,
    }) => {
        const url = await requestInApp(page, request, `prepare-${runId}`);

        await loginAs(page, 'manager');
        const csrf = await refreshSession(page, '/admin/inventory/requests');
        const approved = await page.request.post(`${url}/approve`, {
            headers: { 'X-XSRF-TOKEN': csrf },
            maxRedirects: 0,
        });
        expect([302, 303]).toContain(approved.status());
        await logout(page);

        await loginAs(page, 'staff');
        await page.goto(url);
        await page.getByRole('button', { name: 'Siapkan barang' }).click();

        // One click took it to "Siap diambil": no dialog, no second step; only the hand-over asks.
        await expect(page.getByRole('button', { name: 'Siapkan barang' })).toHaveCount(0);
        const handover = page.getByRole('button', { name: 'Serah terima', exact: true });
        await expect(handover).toBeVisible();
        await handover.click();
        const dialog = page.getByRole('alertdialog', { name: 'Konfirmasi serah terima barang?' });
        await dialog.getByRole('button', { name: 'Konfirmasi serah terima' }).click();
        await expect(dialog).toBeHidden();
        await expect(handover).toHaveCount(0);
    });
});
