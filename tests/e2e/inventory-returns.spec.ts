/**
 * @lifecycle Material return custody in the browser
 *
 * After a warehouse hands materials to a technician, the request page runs three actions on the return case:
 * receive the physical return, reject the handover, resolve a discrepancy. They post JSON with `fetch`; the page once
 * read a `meta[name="csrf-token"]` tag the layout never renders, so every click answered "CSRF token mismatch." (419)
 * and returned stock could not be booked from the browser. Everything before the return (request, approval, pick,
 * issue, declaration) runs through the guarded endpoints and the technician API; each test then drives the broken
 * leg through the real dialogs and reads the result back from the technician API.
 */
import type { APIRequestContext, APIResponse, Page } from '@playwright/test';
import { apiLogin } from './support/api';
import { expect, test } from './support/fixtures';
import { loginAs, logout, refreshSession, unique } from './support/demo';

const runId = unique('RET');

type Json = Record<string, any>;

async function json(response: APIResponse): Promise<Json> {
    expect(response.ok(), `${response.url()} -> ${response.status()}`).toBeTruthy();

    return (await response.json()) as Json;
}

/** As `role`, posts the guarded request-flow endpoints in order; a redirect proves each step was stored. */
async function postSteps(
    page: Page,
    role: 'admin' | 'manager' | 'staff',
    paths: string[],
    form: Json = {},
) {
    await loginAs(page, role);
    const csrf = await refreshSession(page, '/admin/inventory/requests');
    for (const path of paths) {
        const response = await page.request.post(path, {
            headers: { 'X-XSRF-TOKEN': csrf },
            maxRedirects: 0,
            form,
        });
        expect([302, 303], `${role} POST ${path}`).toContain(response.status());
    }
    await logout(page);
}

/**
 * Stocks a warehouse, lets the technician request two units, then takes the request through approval (manager),
 * pick and issue (staff, a different person) and the technician's declaration. Returns the pieces the tests need.
 */
async function declaredReturn(
    page: Page,
    request: APIRequestContext,
    declaration: { used: number; good: number; discrepancy: number },
) {
    const technician = await apiLogin(request, 'technician');
    const product = (
        await json(await technician.get('technician/products?search=E2E-STOCK-PRODUCT'))
    ).data[0];
    const warehouse = (await json(await technician.get('technician/warehouses'))).data[0];
    expect(product, 'E2E stock product').toBeTruthy();
    expect(warehouse, 'a warehouse the technician can request from').toBeTruthy();

    await postSteps(page, 'admin', ['/admin/stocks/receive'], {
        product_id: product.id,
        location_id: warehouse.id,
        quantity: '10',
        note: `stock-${runId}`,
    });

    const created = await json(
        await technician.post('technician/inventory-requests', {
            warehouse_location_id: warehouse.id,
            reason: `return-${runId}`,
            items: [{ product_id: product.id, quantity_requested: 2 }],
        }),
    );
    const requestId: string = created.data.id;

    await postSteps(page, 'manager', [`/admin/inventory/requests/${requestId}/approve`]);
    await postSteps(page, 'staff', [
        `/admin/inventory/requests/${requestId}/prepare`,
        `/admin/inventory/requests/${requestId}/issue`,
    ]);

    const cases = (await json(await technician.get('technician/material-returns?per_page=50')))
        .data as Json[];
    const returnCase = cases.find((row) => row.inventory_request.id === requestId);
    expect(returnCase, 'return case opened by the issue').toBeTruthy();

    await json(
        await technician.post(`technician/material-returns/${returnCase!.id}/declare`, {
            reason: `declare-${runId}`,
            lines: returnCase!.lines.map((line: Json) => ({
                id: line.id,
                actual_used_qty: declaration.used,
                declared_good_return_qty: declaration.good,
                declared_discrepancy_qty: declaration.discrepancy,
                ...(declaration.discrepancy > 0
                    ? { discrepancy_code: 'damaged', discrepancy_reason: `damaged-${runId}` }
                    : {}),
            })),
        }),
    );

    const statusOf = async () =>
        (await json(await technician.get(`technician/material-returns/${returnCase!.id}`))).data
            .status as string;

    return { requestId, statusOf };
}

async function openRequest(page: Page, role: 'manager' | 'staff', requestId: string) {
    await loginAs(page, role);
    await page.goto(`/admin/inventory/requests/${requestId}`);
    await expect(page.getByRole('heading', { name: /^Permintaan Barang REQ-/ })).toBeVisible();
}

test.describe('@lifecycle material return custody', () => {
    test.describe.configure({ mode: 'serial' });

    test('the warehouse books a clean return from the request page', async ({ page, request }) => {
        const { requestId, statusOf } = await declaredReturn(page, request, {
            used: 1,
            good: 1,
            discrepancy: 0,
        });
        expect(await statusOf()).toBe('awaiting_receipt');

        await openRequest(page, 'staff', requestId);
        await page.getByRole('button', { name: 'Terima Pengembalian Fisik' }).click();
        const dialog = page.getByRole('alertdialog', {
            name: 'Terima Pengembalian Fisik Material',
        });
        await dialog.getByRole('button', { name: 'Konfirmasi Penerimaan Fisik' }).click();

        await expect(dialog).toBeHidden();
        await expect(page.getByRole('button', { name: 'Terima Pengembalian Fisik' })).toHaveCount(
            0,
        );
        expect(await statusOf()).toBe('reconciled');
    });

    test('the warehouse rejects a handover and gives the reason', async ({ page, request }) => {
        const { requestId, statusOf } = await declaredReturn(page, request, {
            used: 1,
            good: 1,
            discrepancy: 0,
        });

        await openRequest(page, 'staff', requestId);
        await page.getByRole('button', { name: 'Tolak Fisik Barang' }).click();
        const dialog = page.getByRole('alertdialog', { name: 'Tolak Penyerahan Fisik Barang' });
        await dialog.getByLabel('Alasan Penolakan Fisik *').fill(`belum diserahkan ${runId}`);
        await dialog.getByRole('button', { name: 'Tolak Penyerahan' }).click();

        await expect(dialog).toBeHidden();
        expect(await statusOf()).toBe('correction_required');
    });

    test('a different manager resolves a discrepancy the warehouse booked', async ({
        page,
        request,
    }) => {
        const { requestId, statusOf } = await declaredReturn(page, request, {
            used: 1,
            good: 0,
            discrepancy: 1,
        });

        // The accept dialog starts from the declaration: the good quantity as declared and the difference in its own
        // column, so the warehouse only confirms what it counts (and keys in what it finds otherwise).
        await openRequest(page, 'staff', requestId);
        await page.getByRole('button', { name: 'Terima Pengembalian Fisik' }).click();
        const accept = page.getByRole('alertdialog', {
            name: 'Terima Pengembalian Fisik Material',
        });
        await expect(accept.getByLabel('Baik')).toHaveValue('0');
        await expect(accept.getByLabel('Rusak')).toHaveValue('1');
        await expect(accept.getByLabel('Hilang')).toHaveValue('0');
        await expect(accept.getByLabel('Lainnya')).toHaveValue('0');
        await expect(accept).toContainText('Jumlah cocok');
        await accept.getByRole('button', { name: 'Konfirmasi Penerimaan Fisik' }).click();
        await expect(accept).toBeHidden();
        expect(await statusOf()).toBe('exception_open');
        await logout(page);

        await openRequest(page, 'manager', requestId);
        await page.getByRole('button', { name: 'Selesaikan Selisih' }).click();
        const resolve = page.getByRole('alertdialog', { name: 'Selesaikan Selisih Material' });
        await resolve.getByLabel('Catatan Penyelesaian *').fill(`dihapuskan ${runId}`);
        await resolve.getByRole('button', { name: 'Konfirmasi Penyelesaian' }).click();

        await expect(resolve).toBeHidden();
        expect(await statusOf()).toBe('resolved');
    });

    test('the accept dialog adds the columns up live and books a unit the warehouse cannot find', async ({
        page,
        request,
    }) => {
        const { requestId, statusOf } = await declaredReturn(page, request, {
            used: 1,
            good: 1,
            discrepancy: 0,
        });

        await openRequest(page, 'staff', requestId);
        await page.getByRole('button', { name: 'Terima Pengembalian Fisik' }).click();
        const accept = page.getByRole('alertdialog', {
            name: 'Terima Pengembalian Fisik Material',
        });
        const confirm = accept.getByRole('button', { name: 'Konfirmasi Penerimaan Fisik' });

        // Two were handed over, one used and one declared back: it adds up as the dialog opens.
        await expect(accept).toContainText('Diserahkan 2');
        await expect(accept).toContainText('terpakai 1');
        await expect(accept).toContainText('Jumlah cocok');
        await expect(confirm).toBeEnabled();

        // One short: the line says by how much, and the button waits with the reason beside it.
        await accept.getByLabel('Baik').fill('0');
        await expect(accept).toContainText('Kurang 1');
        await expect(accept).toContainText('Samakan jumlah setiap barang dengan yang diserahkan.');
        await expect(confirm).toBeDisabled();

        // One too many: one used and two counted good, against two handed over.
        await accept.getByLabel('Baik').fill('2');
        await expect(accept).toContainText('Lebih 1');
        await expect(confirm).toBeDisabled();

        // The missing unit was lost: Hilang takes it and the line adds up again.
        await accept.getByLabel('Baik').fill('0');
        await accept.getByLabel('Hilang').fill('1');
        await expect(accept).toContainText('Jumlah cocok');
        await expect(confirm).toBeEnabled();
        await confirm.click();

        await expect(accept).toBeHidden();
        expect(await statusOf()).toBe('exception_open');
    });
});
