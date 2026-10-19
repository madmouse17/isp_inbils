/**
 * @integrity Integrity hardening in the browser (.scratch/integrity-hardening/design.md, section 6, W3-QA).
 *
 * A second tab, a stale form or a repeated submit must never write twice or over a newer save, and the
 * person is told. The tab that is refused is a second one from `context.newPage()`, never the fixture
 * page: the fail-closed network watcher guards only the fixture page.
 *
 * Besides the canonical setup this needs:
 * - A cache that outlives a request (`CACHE_STORE=database` or `file` in .env.e2e). `php artisan serve`
 *   drops the PHP process state after every request, so under `array` the duplicate-submit guard never
 *   sees the first submit and test 1 books the payment twice.
 * - The Midtrans integration of the DEMO company (the tenant-integrations lane imports it from .env.e2e
 *   in tests/e2e/setup.php), which test 4 and midtrans-payment.spec.ts need.
 *
 * Every invoice a test issues ends paid: other specs total the receivables. Midtrans is never reached;
 * test 4 serves the Snap and Status APIs from a local stand-in, as midtrans-payment.spec.ts does.
 */
import { createHash } from 'node:crypto';
import { createServer } from 'node:http';
import type { IncomingMessage, ServerResponse } from 'node:http';
import type { Locator, Page } from '@playwright/test';
import { expect, test } from './support/fixtures';
import { apiLogin } from './support/api';
import { companyDateFormat, formatIsoAs } from './support/dateRange';
import {
    e2eCustomerName,
    expectDetail,
    inertiaProps,
    loginAs,
    selectSearchOption,
    unique,
} from './support/demo';

const head = 'Inbils Demo Company';
const subsidiary = 'E2E Subsidiary Company';
const DUPLICATE_TOAST = 'Permintaan yang sama baru saja dikirim.';
const COMPANY_MOVED_TOAST = 'Perusahaan aktif berubah di tab lain.';
const CONFLICT_COPY = 'Data diubah pengguna lain; muat ulang.';

const MOCK_PORT = 18911;
const MOCK_ORIGIN = `http://127.0.0.1:${MOCK_PORT}`;
// Fake. Mirrors MIDTRANS_SERVER_KEY in .env.e2e; the real key never appears in the test tree.
const FAKE_SERVER_KEY = 'SB-Mid-server-E2E-FAKE-KEY';

type Invoice = { id: string; number: string };

/** A danger toast (role alert). A banner on the page may say the same, so the first match counts. */
const toast = (page: Page, text: string) =>
    page.getByRole('alert').filter({ hasText: text }).first();

/** Staff issue and send a 100.000 invoice to the E2E customer; it is named by id and number. */
async function issueInvoice(page: Page): Promise<Invoice> {
    await loginAs(page, 'admin');
    await page.goto('/admin/invoices/create');
    await selectSearchOption(page, 'Pelanggan', e2eCustomerName);
    const format = await companyDateFormat(page);
    for (const [label, offset] of [
        ['Tanggal terbit', 0],
        ['Jatuh tempo', 14],
    ] as const) {
        const field = page.getByLabel(label, { exact: true });
        const day = new Date(Date.now() + offset * 86_400_000).toISOString().slice(0, 10);
        await field.fill(formatIsoAs(day, format));
        await field.press('Tab');
    }
    // Unique, so two invoices issued within the 10 s duplicate-submit window never share a request body.
    await page.getByLabel('Catatan').fill(unique('Integrity hardening'));
    await page.getByRole('button', { name: 'Simpan', exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/invoices$/);

    const { props } = await inertiaProps(page, '/admin/invoices?per_page=50&status=draft');
    const drafts: Array<Invoice & { customer?: { name: string } }> = props?.invoices?.data ?? [];
    const invoice = drafts
        .filter((row) => row.customer?.name === e2eCustomerName)
        .sort((a, b) => a.number.localeCompare(b.number))
        .at(-1);
    if (!invoice) throw new Error('The invoice just created was not among the drafts.');

    await page.goto(`/admin/invoices/${invoice.id}`);
    await page.getByRole('button', { name: 'Tambah item' }).click();
    const item = page.getByRole('dialog', { name: 'Tambah item' });
    await item.getByLabel('Deskripsi').fill('Internet 20 Mbps');
    await item.getByLabel('Kuantitas').fill('1');
    await item.getByLabel('Harga satuan').fill('100000');
    await item.getByLabel('Diskon').fill('0');
    await item.getByLabel('Pajak (%)').fill('0');
    await item.getByRole('button', { name: 'Simpan', exact: true }).click();
    await expect(page.getByRole('row').filter({ hasText: 'Internet 20 Mbps' })).toContainText(
        /Rp\s*100\.000/,
    );
    await page.getByRole('button', { name: 'Kirim tagihan' }).click();
    await page
        .getByRole('alertdialog', { name: /Kirim tagihan/ })
        .getByRole('button', { name: 'Kirim tagihan' })
        .click();
    await expectDetail(page, 'Status', 'Belum dibayar');

    return { id: invoice.id, number: invoice.number };
}

/** Opens "Catat pembayaran" on the invoice page and fills it; the dialog is returned unsent. */
async function fillPayment(page: Page, amount: string, reference: string): Promise<Locator> {
    await page.getByRole('button', { name: 'Catat pembayaran' }).click();
    const dialog = page.getByRole('dialog', { name: 'Catat pembayaran' });
    await dialog.getByLabel('Jumlah').fill(amount);
    await dialog.getByLabel('Metode').selectOption('cash');
    await dialog.getByLabel('Referensi').fill(reference);

    return dialog;
}

const save = (dialog: Locator) => dialog.getByRole('button', { name: 'Simpan', exact: true });

async function switchTo(page: Page, company: string) {
    await page.getByRole('button', { name: /^Ganti perusahaan, saat ini:/ }).click();
    await page.getByRole('menuitemradio', { name: new RegExp(`^${company}`) }).click();
    await expect(
        page.getByRole('status').filter({ hasText: `Sekarang bekerja di ${company}.` }),
    ).toBeVisible();
    await expect(
        page.getByRole('button', { name: `Ganti perusahaan, saat ini: ${company}` }),
    ).toBeVisible();
}

async function unitNamed(page: Page, name: string): Promise<boolean> {
    const { props } = await inertiaProps(
        page,
        `/admin/units?per_page=100&search=${encodeURIComponent(name)}`,
    );

    return (props?.units?.data ?? []).some((unit: { name: string }) => unit.name === name);
}

test('a payment sent twice is booked once: the second tab gets a toast, a double click adds nothing', async ({
    page,
    context,
}) => {
    test.slow();
    const invoice = await issueInvoice(page);
    const [twice, doubled, rest] = [unique('E2E-DUP'), unique('E2E-DBL'), unique('E2E-REST')];
    const second = await context.newPage();
    await second.goto(`/admin/invoices/${invoice.id}`);

    // Both tabs hold the dialog filled with the same 30.000 and reference; the first to send books it.
    const sentFirst = await fillPayment(page, '30000', twice);
    const sentSecond = await fillPayment(second, '30000', twice);
    await save(sentFirst).click();
    await expectDetail(page, 'Status', 'Sebagian');
    await save(sentSecond).click();
    await expect(toast(second, DUPLICATE_TOAST)).toBeVisible();

    await page.reload();
    await expect(page.getByRole('row').filter({ hasText: twice })).toHaveCount(1);
    const paid = page.locator('p').filter({ hasText: 'Terbayar:' }).first();
    await expect(paid).toContainText(/Rp\s*30\.000/);

    // A double click on "Simpan" books 10.000 once.
    await save(await fillPayment(page, '10000', doubled)).dblclick();
    await expect(paid).toContainText(/Rp\s*40\.000/);
    await page.reload();
    await expect(page.getByRole('row').filter({ hasText: doubled })).toHaveCount(1);
    await expect(paid).toContainText(/Rp\s*40\.000/);

    // The rest is a body of its own, so it is booked: the invoice ends settled, no open receivable stays.
    await save(await fillPayment(page, '60000', rest)).click();
    await expectDetail(page, 'Status', 'Lunas');
});

test('a stale customer form is refused with the reload banner, and the reload shows the newer save', async ({
    page,
    context,
}) => {
    await loginAs(page, 'admin');
    const found = await inertiaProps(
        page,
        `/admin/customers?search=${encodeURIComponent(e2eCustomerName)}`,
    );
    const rows: Array<{ id: string; name: string }> = found.props?.customers?.data ?? [];
    const customer = rows.find((row) => row.name === e2eCustomerName);
    if (!customer) throw new Error('E2E linked customer fixture not found');
    const editUrl = `/admin/customers/${customer.id}/edit`;
    const newer = unique('Saved First');
    const stale = await context.newPage();

    try {
        await stale.goto(editUrl);
        await expect(stale.getByLabel('Nama')).toHaveValue(e2eCustomerName);
        const held: string = (await inertiaProps(page, editUrl)).props?.customer?.data?.updated_at;
        // A row's version keeps whole seconds: a save in the second of the version a tab holds
        // would not conflict with it.
        await expect.poll(() => Date.now() - Date.parse(held)).toBeGreaterThan(1000);

        // The other tab saves a newer name first.
        await page.goto(editUrl);
        await page.getByLabel('Nama').fill(newer);
        await page.getByRole('button', { name: 'Simpan pelanggan' }).click();
        await expect(page).toHaveURL(/\/admin\/customers\/?$/);

        // The stale tab still shows the old row: its save is refused, with a banner and a toast.
        await stale.getByLabel('Nama').fill(unique('Saved Second'));
        await stale.getByRole('button', { name: 'Simpan pelanggan' }).click();
        await expect(stale.getByRole('button', { name: 'Muat ulang' })).toBeVisible();
        await expect(toast(stale, CONFLICT_COPY)).toBeVisible();

        // Reloading drops the stale draft and shows what was saved first.
        await stale.getByRole('button', { name: 'Muat ulang' }).click();
        await expect(stale.getByLabel('Nama')).toHaveValue(newer);
        await expect(stale.getByRole('button', { name: 'Muat ulang' })).toHaveCount(0);
    } finally {
        // Later specs look the shared fixture customer up by its name.
        await page.goto(editUrl);
        await page.getByLabel('Nama').fill(e2eCustomerName);
        await page.getByRole('button', { name: 'Simpan pelanggan' }).click();
        await expect(page).toHaveURL(/\/admin\/customers\/?$/);
    }
});

test('a tab opened before another tab switched company is refused, and nothing is written in either company', async ({
    page,
    context,
}) => {
    await loginAs(page, 'admin');
    const unit = unique('Satuan Tab');
    const stale = await context.newPage();
    await stale.goto('/admin/units');
    await stale.getByRole('button', { name: 'Buat Satuan' }).click();
    const dialog = stale.getByRole('dialog', { name: 'Buat Satuan' });
    await dialog.getByLabel('Nama').fill(unit);
    await dialog.getByLabel('Simbol').fill('tb');

    // Another tab moves the session to the subsidiary while the form is open.
    await switchTo(page, subsidiary);

    await dialog.getByRole('button', { name: 'Buat', exact: true }).click();
    await expect(toast(stale, COMPANY_MOVED_TOAST)).toBeVisible();

    expect(await unitNamed(page, unit), `${unit} written in ${subsidiary}`).toBe(false);
    await switchTo(page, head);
    expect(await unitNamed(page, unit), `${unit} written in ${head}`).toBe(false);
});

test('Tunggakan lists an online payment that could not be booked, until it is booked by hand', async ({
    page,
    request,
}) => {
    test.slow();
    const paid = new Map<string, number>();
    const mock = createServer((req: IncomingMessage, res: ServerResponse) => {
        const chunks: Buffer[] = [];
        req.on('data', (chunk: Buffer) => chunks.push(chunk));
        req.on('end', () => {
            const url = new URL(req.url ?? '/', MOCK_ORIGIN);
            const status = url.pathname.match(/^\/v2\/([^/]+)\/status$/);
            const reply = (code: number, body: unknown) => {
                res.writeHead(code, { 'content-type': 'application/json' });
                res.end(JSON.stringify(body));
            };

            if (req.method === 'POST' && url.pathname === '/snap/v1/transactions') {
                const body = JSON.parse(Buffer.concat(chunks).toString('utf8'));
                const orderId: string = body.transaction_details.order_id;
                reply(201, {
                    token: `e2e-token-${orderId}`,
                    redirect_url: `${MOCK_ORIGIN}/snap/pay/${orderId}`,
                });
            } else if (req.method === 'GET' && status && paid.has(status[1])) {
                reply(200, settlement(status[1], paid.get(status[1]) ?? 0));
            } else {
                reply(404, {
                    status_code: '404',
                    status_message: 'The requested resource is not found',
                });
            }
        });
    });
    await new Promise<void>((resolve, reject) => {
        mock.once('error', reject);
        mock.listen(MOCK_PORT, '127.0.0.1', () => resolve());
    });

    try {
        const invoice = await issueInvoice(page);

        // The customer starts paying in the app: one payment page, one open attempt.
        const app = await apiLogin(request, 'customer');
        const started = await app.post(`customer/invoices/${invoice.id}/pay`);
        expect(started.status()).toBe(200);
        const body: { data: { redirect_url: string } } = await started.json();
        const redirectUrl = body.data.redirect_url;
        const orderId = new URL(redirectUrl).pathname.split('/').pop() ?? '';
        expect(orderId).not.toBe('');

        // Midtrans took 1.000 on an order of 100.000: the amount does not match, so nothing is booked.
        paid.set(orderId, 1000);
        const delivered = await page.request.post('/webhooks/midtrans', {
            data: settlement(orderId, 1000),
        });
        expect(delivered.status()).toBe(200);

        await page.goto('/admin/billing/receivables');
        const alert = page.getByRole('alert').filter({ hasText: 'pembayaran online perlu dicek' });
        await expect(alert).toBeVisible();
        await expect(alert.getByRole('link', { name: invoice.number })).toBeVisible();
        await expect(alert).toContainText(orderId);
        // It shows the order and what the page was charged for (100.000), not the 1.000 Midtrans took.
        await expect(alert).toContainText(/Rp\s*100\.000/);

        // Booked by hand with the order id as its reference, the payment is accounted for and the
        // alert lets go.
        await page.goto(`/admin/invoices/${invoice.id}`);
        const dialog = await fillPayment(page, '100000', orderId);
        await save(dialog).click();
        await expectDetail(page, 'Status', 'Lunas');
        await page.goto('/admin/billing/receivables');
        await expect(page.getByRole('heading', { name: 'Tunggakan', level: 1 })).toBeVisible();
        await expect(page.getByRole('link', { name: invoice.number })).toHaveCount(0);
    } finally {
        await new Promise<void>((resolve) => mock.close(() => resolve()));
    }
});

/** What Midtrans reports for an order whose customer paid `amount`: the Status API says it, the webhook carries it. */
function settlement(orderId: string, amount: number) {
    const grossAmount = `${amount}.00`;

    return {
        transaction_time: '2026-10-18 10:00:00',
        transaction_status: 'settlement',
        transaction_id: `e2e-trx-${orderId}`,
        status_code: '200',
        settlement_time: '2026-10-18 10:00:20',
        payment_type: 'bank_transfer',
        order_id: orderId,
        merchant_id: 'G000000000',
        gross_amount: grossAmount,
        fraud_status: 'accept',
        currency: 'IDR',
        signature_key: createHash('sha512')
            .update(`${orderId}200${grossAmount}${FAKE_SERVER_KEY}`)
            .digest('hex'),
    };
}
