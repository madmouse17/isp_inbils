/**
 * @lifecycle Midtrans online payment (mocked gateway)
 *
 * Midtrans is never reached. The server under test talks to a local Node mock that stands in for the
 * Snap and Status APIs (.env.e2e points MIDTRANS_BASE_URL at it, with a fake server key), and the
 * notification the real gateway would POST is delivered by this spec, signed with the same fake key.
 *
 * Flow: staff issue an invoice -> the linked customer pays it from the portal (double click, then a
 * second visit) -> Midtrans "settles" -> the webhook books exactly one payment, replays change nothing
 * -> the return page and the invoice show the truth -> staff see the attempt. A second invoice shows
 * the amount-mismatch quarantine to staff.
 */
import { createHash } from 'node:crypto';
import { createServer } from 'node:http';
import type { IncomingMessage, Server, ServerResponse } from 'node:http';
import type { Page } from '@playwright/test';
import { expect, test } from './support/fixtures';
import {
    e2eCustomerName,
    expectDetail,
    inertiaProps,
    loginAs,
    logout,
    refreshSession,
    selectOptionByText,
} from './support/demo';

const MOCK_PORT = 18911;
const MOCK_ORIGIN = `http://127.0.0.1:${MOCK_PORT}`;
// Fake. Mirrors MIDTRANS_SERVER_KEY in .env.e2e; the real key never appears in the test tree.
const FAKE_SERVER_KEY = 'SB-Mid-server-E2E-FAKE-KEY';
const BASIC_AUTH = `Basic ${Buffer.from(`${FAKE_SERVER_KEY}:`).toString('base64')}`;

interface MockOrder {
    grossAmount: number;
    settledWith: number | null;
}

interface MockMidtrans {
    orders: Map<string, MockOrder>;
    snapRequests: number;
    /** Midtrans "settles" the order, optionally for another amount than was charged. */
    settle: (orderId: string, paidAmount?: number) => void;
    /** The signed notification Midtrans would POST for the order. */
    notification: (orderId: string) => Record<string, string>;
    stop: () => Promise<void>;
}

interface InvoiceRow {
    id: number;
    number: string;
    customer?: { name: string };
}

const sign = (orderId: string, statusCode: string, grossAmount: string) =>
    createHash('sha512')
        .update(`${orderId}${statusCode}${grossAmount}${FAKE_SERVER_KEY}`)
        .digest('hex');

async function startMockMidtrans(): Promise<MockMidtrans> {
    const orders = new Map<string, MockOrder>();
    const mock: MockMidtrans = {
        orders,
        snapRequests: 0,
        settle: (orderId, paidAmount) => {
            const order = orders.get(orderId);
            if (!order) throw new Error(`Mock Midtrans has no order ${orderId}`);
            order.settledWith = paidAmount ?? order.grossAmount;
        },
        notification: (orderId) => {
            const order = orders.get(orderId);
            if (!order || order.settledWith === null) {
                throw new Error(`Order ${orderId} is not settled in the mock`);
            }
            const grossAmount = `${order.settledWith}.00`;

            return {
                transaction_time: '2026-10-12 10:00:00',
                transaction_status: 'settlement',
                transaction_id: `e2e-trx-${orderId}`,
                status_message: 'midtrans payment notification',
                status_code: '200',
                settlement_time: '2026-10-12 10:00:20',
                payment_type: 'bank_transfer',
                order_id: orderId,
                merchant_id: 'G000000000',
                gross_amount: grossAmount,
                fraud_status: 'accept',
                currency: 'IDR',
                signature_key: sign(orderId, '200', grossAmount),
            };
        },
        stop: () => {
            const { promise, resolve } = Promise.withResolvers<void>();
            server.close(() => resolve());

            return promise;
        },
    };

    const json = (res: ServerResponse, status: number, body: unknown) => {
        res.writeHead(status, { 'content-type': 'application/json' });
        res.end(JSON.stringify(body));
    };

    const server: Server = createServer((req: IncomingMessage, res: ServerResponse) => {
        const url = new URL(req.url ?? '/', MOCK_ORIGIN);
        const chunks: Buffer[] = [];
        req.on('data', (chunk: Buffer) => chunks.push(chunk));
        req.on('end', () => {
            const isApiCall = url.pathname.startsWith('/snap/v1/') || url.pathname.startsWith('/v2/');
            if (isApiCall && req.headers.authorization !== BASIC_AUTH) {
                json(res, 401, { error_messages: ['Access denied due to unauthorized transaction'] });

                return;
            }

            if (req.method === 'POST' && url.pathname === '/snap/v1/transactions') {
                // The only caller is the app under test, which sends the documented Snap body.
                const body: { transaction_details: { order_id: string; gross_amount: number } } =
                    JSON.parse(Buffer.concat(chunks).toString('utf8'));
                const { order_id: orderId, gross_amount: grossAmount } = body.transaction_details;
                mock.snapRequests += 1;
                orders.set(orderId, { grossAmount, settledWith: null });
                json(res, 201, {
                    token: `e2e-token-${orderId}`,
                    redirect_url: `${MOCK_ORIGIN}/snap/pay/${orderId}`,
                });

                return;
            }

            const status = url.pathname.match(/^\/v2\/([^/]+)\/status$/);
            if (req.method === 'GET' && status) {
                const order = orders.get(status[1]);
                if (!order || order.settledWith === null) {
                    // Like the real API: nothing to report until the customer has paid.
                    json(res, 404, {
                        status_code: '404',
                        status_message: 'The requested resource is not found',
                    });

                    return;
                }
                json(res, 200, mock.notification(status[1]));

                return;
            }

            if (req.method === 'GET' && url.pathname.startsWith('/snap/pay/')) {
                res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
                res.end(
                    '<!doctype html><html lang="en"><head><title>Mock Midtrans</title></head><body><main><h1>Mock Midtrans hosted payment page</h1></main></body></html>',
                );

                return;
            }

            // The browser asks for a favicon on every origin; answer so no failed request is logged.
            res.writeHead(url.pathname === '/favicon.ico' ? 204 : 404);
            res.end();
        });
    });

    const { promise: listening, resolve, reject } = Promise.withResolvers<void>();
    server.once('error', reject);
    server.listen(MOCK_PORT, '127.0.0.1', () => resolve());
    await listening;

    return mock;
}

const isoDay = (offsetDays: number) =>
    new Date(Date.now() + offsetDays * 86_400_000).toISOString().slice(0, 10);

function orderIdFrom(url: string): string {
    const orderId = new URL(url).pathname.split('/').pop();
    if (!orderId) throw new Error(`No order id in ${url}`);

    return orderId;
}

/** Staff issue a 100.000 invoice for the E2E customer; returns the newest one (numbers are sequential). */
async function issueInvoice(page: Page): Promise<InvoiceRow> {
    await loginAs(page, 'admin');
    await page.goto('/admin/invoices/create');
    await selectOptionByText(page.getByLabel('Customer'), e2eCustomerName);
    await page.getByLabel('Issue Date').fill(isoDay(0));
    await page.getByLabel('Due Date').fill(isoDay(14));
    await page.getByLabel('Notes').fill('Midtrans mocked payment');
    await page.getByRole('button', { name: 'Create', exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/invoices$/);

    const { props } = await inertiaProps(page, '/admin/invoices?per_page=50&status=draft');
    const listed = props?.invoices;
    if (typeof listed !== 'object' || listed === null || !Array.isArray(listed.data)) {
        throw new Error('The invoices page carried no data list.');
    }
    const rows: InvoiceRow[] = listed.data;
    const invoice = rows
        .filter((row) => row.customer?.name === e2eCustomerName)
        .sort((a, b) => a.number.localeCompare(b.number))
        .at(-1);
    if (!invoice) throw new Error('The invoice just created was not among the drafts.');

    await page.goto(`/admin/invoices/${invoice.id}`);
    await page.getByRole('button', { name: 'Add Item' }).click();
    const itemDialog = page.getByRole('dialog', { name: 'Add Item' });
    await itemDialog.getByLabel('Description').fill('Internet 20 Mbps');
    await itemDialog.getByLabel('Quantity').fill('1');
    await itemDialog.getByLabel('Unit Price').fill('100000');
    await itemDialog.getByLabel('Discount').fill('0');
    await itemDialog.getByLabel('Tax Rate (%)').fill('0');
    await itemDialog.getByRole('button', { name: 'Add' }).click();
    await expect(page.getByRole('row').filter({ hasText: 'Internet 20 Mbps' })).toContainText('100000.00');
    await page.getByRole('button', { name: 'Send' }).click();
    await expectDetail(page, 'Status', 'sent');

    return invoice;
}

test.describe('@lifecycle midtrans payment (mocked gateway)', () => {
    test.describe.configure({ mode: 'serial' });

    let mock: MockMidtrans;

    test.beforeAll(async () => {
        mock = await startMockMidtrans();
    });

    test.afterAll(async () => {
        await mock.stop();
    });

    test('the customer pays from the portal, the webhook books one payment, staff see the attempt', async ({
        page,
        expectHttpError,
    }) => {
        // Inertia answers a POST that must leave the app with 409 + X-Inertia-Location.
        expectHttpError({ url: /\/portal\/invoices\/\d+\/pay$/, status: 409, method: 'POST' });

        const invoice = await issueInvoice(page);
        await logout(page);
        await loginAs(page, 'customer');

        // The customer finds the invoice in their own list and opens it.
        await page.goto('/portal/invoices');
        await expect(page.getByRole('heading', { name: 'Tagihan Saya', level: 1 })).toBeVisible();
        await page.getByRole('link', { name: new RegExp(`Bayar.*${invoice.number}`) }).click();
        await expect(page.getByRole('heading', { name: `Tagihan ${invoice.number}`, level: 1 })).toBeVisible();

        // A double click on Pay still creates one payment page and lands on it.
        const pay = page.getByRole('button', { name: /^Bayar Rp\s*100\.000$/ });
        await expect(pay).toBeEnabled();
        await pay.dblclick();
        await page.waitForURL(`${MOCK_ORIGIN}/snap/pay/**`);
        await expect(page.getByRole('heading', { name: 'Mock Midtrans hosted payment page' })).toBeVisible();
        const hostedUrl = page.url();
        const orderId = orderIdFrom(hostedUrl);
        expect(mock.snapRequests).toBe(1);
        expect(mock.orders.get(orderId)?.grossAmount).toBe(100000);

        // Paying again reuses the open page instead of creating another one. Asked through the API:
        // the fail-closed browser watcher admits exactly one expected 409 per test.
        const token = await refreshSession(page, `/portal/invoices/${invoice.id}`);
        const again = await page.request.post(`/portal/invoices/${invoice.id}/pay`, {
            headers: { 'X-XSRF-TOKEN': token, 'X-Inertia': 'true' },
        });
        expect(again.status()).toBe(409);
        expect(again.headers()['x-inertia-location']).toBe(hostedUrl);
        expect(mock.snapRequests).toBe(1);

        // Before the money arrives the return page tells the truth, whatever the address claims.
        await page.goto(`/portal/payments/return?order_id=${orderId}&transaction_status=settlement&status_code=200`);
        await expect(page.getByRole('heading', { name: 'Menunggu konfirmasi' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Periksa status' })).toBeVisible();

        // A forged notification is refused and books nothing.
        mock.settle(orderId);
        const forged = await page.request.post('/webhooks/midtrans', {
            data: { ...mock.notification(orderId), signature_key: 'f'.repeat(128) },
        });
        expect(forged.status()).toBe(403);
        await page.reload();
        await expect(page.getByRole('heading', { name: 'Menunggu konfirmasi' })).toBeVisible();

        // The real notification books the payment; replays change nothing.
        for (let delivery = 0; delivery < 3; delivery += 1) {
            const delivered = await page.request.post('/webhooks/midtrans', { data: mock.notification(orderId) });
            expect(delivered.status()).toBe(200);
        }

        await page.goto(`/portal/payments/return?order_id=${orderId}`);
        await expect(page.getByRole('heading', { name: 'Pembayaran diterima' })).toBeVisible();

        await page.goto(`/portal/invoices/${invoice.id}`);
        await expect(page.getByText('Lunas', { exact: true }).first()).toBeVisible();
        await expect(page.getByRole('button', { name: 'Bayar', exact: true })).toBeDisabled();
        await expect(page.getByText('Tagihan sudah lunas.')).toBeVisible();
        await expect(page.getByText('Pembayaran online', { exact: true })).toBeVisible();

        // At 320px nothing spills sideways on any portal page, and the dark theme still renders them.
        await page.setViewportSize({ width: 320, height: 800 });
        for (const dark of [false, true]) {
            await page.evaluate((enabled) => {
                localStorage.setItem('darkMode', String(enabled));
            }, dark);
            for (const path of [
                '/portal/invoices',
                `/portal/invoices/${invoice.id}`,
                `/portal/payments/return?order_id=${orderId}`,
            ]) {
                await page.goto(path);
                await expect(page.getByRole('main')).toBeVisible();
                await expect(page.locator('html')).toHaveClass(dark ? /dark/ : /^(?!.*dark).*$/);
                const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
                expect(overflow, `${path} overflows horizontally at 320px (dark=${dark})`).toBeLessThanOrEqual(0);
            }
        }
        await page.evaluate(() => localStorage.setItem('darkMode', 'false'));
        await page.setViewportSize({ width: 1280, height: 720 });

        // Staff see exactly one Midtrans payment and the attempt with its Midtrans references.
        await logout(page);
        await loginAs(page, 'admin');
        await page.goto(`/admin/invoices/${invoice.id}`);
        await expectDetail(page, 'Status', 'paid');
        await expect(page.getByRole('row').filter({ hasText: 'midtrans' })).toHaveCount(1);
        await expect(page.getByRole('heading', { name: 'Online payments' })).toBeVisible();
        const attemptRow = page.getByRole('row').filter({ hasText: `e2e-trx-${orderId}` });
        await expect(attemptRow).toHaveCount(1);
        await expect(attemptRow).toContainText('paid');
        await expect(attemptRow).toContainText('bank_transfer');
    });

    test('money that does not match the attempt is quarantined and shown to staff', async ({ page }) => {
        const invoice = await issueInvoice(page);
        await logout(page);
        await loginAs(page, 'customer');

        // Start the payment the way the button does and read where the server sends the browser.
        const token = await refreshSession(page, `/portal/invoices/${invoice.id}`);
        const started = await page.request.post(`/portal/invoices/${invoice.id}/pay`, {
            headers: { 'X-XSRF-TOKEN': token, 'X-Inertia': 'true' },
        });
        expect(started.status()).toBe(409);
        const hostedUrl = started.headers()['x-inertia-location'];
        if (!hostedUrl) throw new Error('The pay endpoint did not send the browser anywhere.');
        const orderId = orderIdFrom(hostedUrl);

        mock.settle(orderId, 1000);
        const delivered = await page.request.post('/webhooks/midtrans', { data: mock.notification(orderId) });
        expect(delivered.status()).toBe(200);

        // The customer is told it is being checked, never shown the internal reason.
        await page.goto(`/portal/payments/return?order_id=${orderId}`);
        await expect(page.getByRole('heading', { name: 'Pembayaran sedang diperiksa' })).toBeVisible();
        await expect(page.getByText('gross_amount')).toHaveCount(0);

        // Staff see the exception with its reason; no payment was booked and the invoice is still open.
        await logout(page);
        await loginAs(page, 'admin');
        await page.goto(`/admin/invoices/${invoice.id}`);
        await expectDetail(page, 'Status', 'sent');
        const attemptRow = page.getByRole('row').filter({ hasText: `e2e-trx-${orderId}` });
        await expect(attemptRow).toContainText('exception');
        await expect(attemptRow).toContainText('gross_amount');
        await expect(page.getByRole('row').filter({ hasText: 'midtrans' }).filter({ hasText: 'Active' })).toHaveCount(0);
    });
});
