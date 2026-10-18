/**
 * @lifecycle Midtrans online payment (mocked gateway)
 *
 * Midtrans is never reached. The server under test talks to a local Node mock that stands in for the
 * Snap and Status APIs (.env.e2e points MIDTRANS_BASE_URL at it, with a fake server key), and the
 * notification the real gateway would POST is delivered by this spec, signed with the same fake key.
 *
 * Flow: staff issue an invoice -> the linked customer pays it through the app API (twice, one payment
 * page) -> Midtrans "settles" -> the webhook books exactly one payment, replays change nothing
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
    selectSearchOption,
} from './support/demo';
import { companyDateFormat, formatIsoAs } from './support/dateRange';
import { apiLogin } from './support/api';

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
    id: string;
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
            const isApiCall =
                url.pathname.startsWith('/snap/v1/') || url.pathname.startsWith('/v2/');
            if (isApiCall && req.headers.authorization !== BASIC_AUTH) {
                json(res, 401, {
                    error_messages: ['Access denied due to unauthorized transaction'],
                });

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
    await selectSearchOption(page, 'Pelanggan', e2eCustomerName);
    const format = await companyDateFormat(page);
    const issue = page.getByLabel('Tanggal terbit', { exact: true });
    await issue.fill(formatIsoAs(isoDay(0), format));
    await issue.press('Tab');
    const due = page.getByLabel('Jatuh tempo', { exact: true });
    await due.fill(formatIsoAs(isoDay(14), format));
    await due.press('Tab');
    await page.getByLabel('Catatan').fill('Midtrans mocked payment');
    await page.getByRole('button', { name: 'Simpan', exact: true }).click();
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
    await page.getByRole('button', { name: 'Tambah item' }).click();
    const itemDialog = page.getByRole('dialog', { name: 'Tambah item' });
    await itemDialog.getByLabel('Deskripsi').fill('Internet 20 Mbps');
    await itemDialog.getByLabel('Kuantitas').fill('1');
    await itemDialog.getByLabel('Harga satuan').fill('100000');
    await itemDialog.getByLabel('Diskon').fill('0');
    await itemDialog.getByLabel('Pajak (%)').fill('0');
    await itemDialog.getByRole('button', { name: 'Simpan', exact: true }).click();
    await expect(page.getByRole('row').filter({ hasText: 'Internet 20 Mbps' })).toContainText(
        /Rp\s*100\.000/,
    );
    await page.getByRole('button', { name: 'Kirim tagihan' }).click();
    await page
        .getByRole('alertdialog', { name: /Kirim tagihan/ })
        .getByRole('button', { name: 'Kirim tagihan' })
        .click();
    await expectDetail(page, 'Status', 'Belum dibayar');

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

    test('the customer pays through the app API, the webhook books one payment, staff see the attempt', async ({
        page,
        request,
    }) => {
        const invoice = await issueInvoice(page);
        await logout(page);
        const api = await apiLogin(request, 'customer');

        // The invoice is in the customer's own unpaid list and may be paid.
        const list = await api.get('customer/invoices?status=unpaid');
        expect(list.status()).toBe(200);
        const listed = ((await list.json()) as { data: { id: string; number: string }[] }).data;
        expect(listed.map((row) => row.number)).toContain(invoice.number);
        const shown = await api.get(`customer/invoices/${invoice.id}`);
        expect(
            ((await shown.json()) as { data: { payment: { can_pay: boolean } } }).data.payment
                .can_pay,
        ).toBe(true);

        // Paying twice (a double tap) creates one payment page and returns the same address.
        const first = await api.post(`customer/invoices/${invoice.id}/pay`);
        expect(first.status()).toBe(200);
        const hostedUrl = ((await first.json()) as { data: { redirect_url: string } }).data
            .redirect_url;
        const second = await api.post(`customer/invoices/${invoice.id}/pay`);
        expect(second.status()).toBe(200);
        expect(
            ((await second.json()) as { data: { redirect_url: string } }).data.redirect_url,
        ).toBe(hostedUrl);
        const orderId = orderIdFrom(hostedUrl);
        expect(mock.snapRequests).toBe(1);
        expect(mock.orders.get(orderId)?.grossAmount).toBe(100000);

        await page.goto(hostedUrl);
        await expect(
            page.getByRole('heading', { name: 'Mock Midtrans hosted payment page' }),
        ).toBeVisible();

        // The return page is the only customer web page left; it needs the customer web session.
        await loginAs(page, 'customer');

        // Before the money arrives the return page tells the truth, whatever the address claims.
        await page.goto(
            `/portal/payments/return?order_id=${orderId}&transaction_status=settlement&status_code=200`,
        );
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
            const delivered = await page.request.post('/webhooks/midtrans', {
                data: mock.notification(orderId),
            });
            expect(delivered.status()).toBe(200);
        }

        await page.goto(`/portal/payments/return?order_id=${orderId}`);
        await expect(page.getByRole('heading', { name: 'Pembayaran diterima' })).toBeVisible();

        // The app sees the invoice paid and no longer payable.
        const paid = await api.get(`customer/invoices/${invoice.id}`);
        const paidBody = (
            (await paid.json()) as { data: { status: string; payment: { can_pay: boolean } } }
        ).data;
        expect(paidBody.status).toBe('paid');
        expect(paidBody.payment.can_pay).toBe(false);
        expect((await api.post(`customer/invoices/${invoice.id}/pay`)).status()).toBe(422);

        // At 320px nothing spills sideways on the return page, and the dark theme still renders it.
        await page.setViewportSize({ width: 320, height: 800 });
        for (const dark of [false, true]) {
            await page.evaluate((enabled) => {
                localStorage.setItem('darkMode', String(enabled));
            }, dark);
            const path = `/portal/payments/return?order_id=${orderId}`;
            await page.goto(path);
            await expect(page.getByRole('main')).toBeVisible();
            await expect(page.locator('html')).toHaveClass(dark ? /dark/ : /^(?!.*dark).*$/);
            const overflow = await page.evaluate(
                () => document.documentElement.scrollWidth - window.innerWidth,
            );
            expect(
                overflow,
                `${path} overflows horizontally at 320px (dark=${dark})`,
            ).toBeLessThanOrEqual(0);
        }
        await page.evaluate(() => localStorage.setItem('darkMode', 'false'));
        await page.setViewportSize({ width: 1280, height: 720 });

        // Staff see exactly one Midtrans payment and the attempt with its Midtrans references.
        await logout(page);
        await loginAs(page, 'admin');
        await page.goto(`/admin/invoices/${invoice.id}`);
        await expectDetail(page, 'Status', 'Lunas');
        await expect(page.getByRole('row').filter({ hasText: 'midtrans' })).toHaveCount(1);
        await expect(page.getByRole('heading', { name: 'Pembayaran online' })).toBeVisible();
        const attemptRow = page.getByRole('row').filter({ hasText: `e2e-trx-${orderId}` });
        await expect(attemptRow).toHaveCount(1);
        await expect(attemptRow).toContainText('Lunas');
        await expect(attemptRow).toContainText('bank_transfer');
    });

    test('money that does not match the attempt is quarantined and shown to staff', async ({
        page,
        request,
    }) => {
        const invoice = await issueInvoice(page);
        await logout(page);

        // Start the payment the way the app does and read where it sends the customer.
        const api = await apiLogin(request, 'customer');
        const started = await api.post(`customer/invoices/${invoice.id}/pay`);
        expect(started.status()).toBe(200);
        const hostedUrl = ((await started.json()) as { data: { redirect_url: string } }).data
            .redirect_url;
        const orderId = orderIdFrom(hostedUrl);

        mock.settle(orderId, 1000);
        const delivered = await page.request.post('/webhooks/midtrans', {
            data: mock.notification(orderId),
        });
        expect(delivered.status()).toBe(200);

        // The customer is told it is being checked, never shown the internal reason.
        await loginAs(page, 'customer');
        await page.goto(`/portal/payments/return?order_id=${orderId}`);
        await expect(
            page.getByRole('heading', { name: 'Pembayaran sedang diperiksa' }),
        ).toBeVisible();
        await expect(page.getByText('gross_amount')).toHaveCount(0);

        // Staff see the exception with its reason; no payment was booked and the invoice is still open.
        await logout(page);
        await loginAs(page, 'admin');
        await page.goto(`/admin/invoices/${invoice.id}`);
        await expectDetail(page, 'Status', 'Belum dibayar');
        const attemptRow = page.getByRole('row').filter({ hasText: `e2e-trx-${orderId}` });
        await expect(attemptRow).toContainText('Perlu dicek');
        await expect(attemptRow).toContainText('gross_amount');
        await expect(
            page.getByRole('row').filter({ hasText: 'midtrans' }).filter({ hasText: 'Aktif' }),
        ).toHaveCount(0);
    });
});
