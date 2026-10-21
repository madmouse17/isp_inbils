/**
 * @lifecycle Billing journeys (P1-C)
 *
 * Residual gaps:
 * - Payment reversal UI not present (only invoice cancel) — cancel covered, reverse skipped.
 * - Recurring generate depends on active subscriptions in period; empty preview still proves UI path.
 * - Duplicate-period skip asserted only when preview returns skipped count > 0 or re-preview stable.
 *
 * Notes for maintainers: the invoices index searches only by invoice number
 * (INV-...); newly created invoices redirect to the index, so this suite opens
 * the target invoice by its customer column (e2eCustomerName) filtered to the
 * draft status. Within a single serialized run exactly one draft exists for
 * the linked customer at each lookup point.
 */
import type { Page } from '@playwright/test';
import { expect, test } from './support/fixtures';
import {
    expectDetail,
    e2eCustomerName,
    inertiaProps,
    loginAs,
    logout,
    refreshSession,
    selectSearchOption,
    unique,
    unitPriceForTotal,
} from './support/demo';
import { companyDateFormat, formatIsoAs } from './support/dateRange';

const runId = unique('BILL');

/** Type a day into a DatePicker in the company format and commit it with Tab. */
async function typeDate(page: Page, label: string, iso: string) {
    const format = await companyDateFormat(page);
    const field = page.getByLabel(label, { exact: true });
    await field.fill(formatIsoAs(iso, format));
    await field.press('Tab');
}

/** Today on the company clock as ISO `yyyy-mm-dd`: the day "Tanggal bayar" starts on. */
async function companyToday(page: Page): Promise<string> {
    return page.evaluate(() => {
        const raw = document.getElementById('app')?.getAttribute('data-page');
        const zone = raw ? (JSON.parse(raw).props?.company?.timezone as string | undefined) : undefined;

        return new Intl.DateTimeFormat('en-CA', {
            timeZone: zone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        }).format(new Date());
    });
}

/**
 * The company-calendar day a payment is booked on, read from the invoice page props: the payments table writes
 * dates in the browser's clock, which is not always the company's.
 */
async function paymentDay(page: Page, reference: string): Promise<string | undefined> {
    const { props } = await inertiaProps(page, new URL(page.url()).pathname);
    const payments = props?.invoice?.data?.payments as
        | Array<{ reference?: string | null; paid_at: string }>
        | undefined;
    const payment = payments?.find((entry) => entry.reference === reference);

    return payment
        ? new Intl.DateTimeFormat('en-CA', {
              timeZone: props?.company?.timezone as string | undefined,
              year: 'numeric',
              month: '2-digit',
              day: '2-digit',
          }).format(new Date(payment.paid_at))
        : undefined;
}

test.describe('@lifecycle billing', () => {
    test.describe.configure({ mode: 'serial' });

    test('partial then full payment, cancel draft sibling, generate preview', async ({ page }) => {
        await loginAs(page, 'admin');
        const customerName = e2eCustomerName;
        const line = `E2E bill line ${runId}`;
        const partialRef = `E2E-PARTIAL-${runId}`;
        const fullRef = `E2E-FULL-${runId}`;

        await page.goto('/admin/invoices/create');
        await selectSearchOption(page, 'Pelanggan', customerName);
        await typeDate(page, 'Tanggal terbit', '2026-07-01');
        await typeDate(page, 'Jatuh tempo', '2026-07-31');
        await page.getByLabel('Catatan').fill('P1-C partial/full payment.');
        await page.getByRole('button', { name: 'Simpan', exact: true }).click();
        await expect(page).toHaveURL(/\/admin\/invoices$/);
        await openDraftInvoice(page, customerName);
        await expect(page).toHaveURL(/\/admin\/invoices\/[0-9A-HJKMNP-TV-Z]{26}$/i);
        await expectDetail(page, 'Status', 'Draf');
        // A line takes the company's PPN (the dialog has no tax field): the price is the one that makes the invoice 100.000.
        const unitPrice = await unitPriceForTotal(page, 100000);
        await page.getByRole('button', { name: 'Tambah item' }).click();
        const itemDialog = page.getByRole('dialog', { name: 'Tambah item' });
        await itemDialog.getByLabel('Deskripsi').fill(line);
        await itemDialog.getByLabel('Kuantitas').fill('1');
        await itemDialog.getByLabel('Harga satuan').fill(String(unitPrice));
        await itemDialog.getByLabel('Diskon').fill('0');
        await itemDialog.getByRole('button', { name: 'Simpan', exact: true }).click();
        await expect(page.getByRole('row').filter({ hasText: line })).toBeVisible();
        await expectDetail(page, 'Total', /Rp\s*100\.000/);

        await page.getByRole('button', { name: 'Kirim tagihan' }).click();
        const sendDialog = page.getByRole('alertdialog', { name: /Kirim tagihan/ });
        // The confirmation says who gets the invoice, for how much and by when, inside its description.
        const dateFormat = await companyDateFormat(page);
        await expect(sendDialog).toContainText(`Pelanggan: ${customerName}`);
        await expect(sendDialog).toContainText(/Jumlah: Rp\s*100\.000/);
        await expect(sendDialog).toContainText(
            `Jatuh tempo: ${formatIsoAs('2026-07-31', dateFormat)}`,
        );
        await expect(sendDialog).toHaveAccessibleDescription(/Jumlah: Rp\s*100\.000/);
        await sendDialog.getByRole('button', { name: 'Kirim tagihan' }).click();
        await expectDetail(page, 'Status', 'Belum dibayar');

        await page.getByRole('button', { name: 'Catat pembayaran' }).click();
        const pay1 = page.getByRole('dialog', { name: 'Catat pembayaran' });
        const amount = pay1.getByLabel('Jumlah');
        // The amount starts at the whole balance, so paying in full needs no typing and no "Lunas penuh".
        await expect(amount).toHaveValue(/100\.000/);
        await expect(pay1.getByText(/Sisa tagihan:\s*Rp\s*100\.000/)).toBeVisible();
        await expect(pay1.getByRole('button', { name: 'Lunas penuh' })).toHaveCount(0);
        // The day starts at today on the company clock.
        const paidAt = pay1.getByLabel('Tanggal bayar', { exact: true });
        await expect(paidAt).toHaveValue(formatIsoAs(await companyToday(page), dateFormat));
        await amount.fill('40000');
        await pay1.getByRole('button', { name: 'Lunas penuh' }).click();
        await expect(amount).toHaveValue(/100\.000/);
        await expect(pay1.getByRole('button', { name: 'Lunas penuh' })).toHaveCount(0);
        await amount.fill('40000');
        // Money that arrived earlier is booked on its own day.
        await paidAt.fill(formatIsoAs('2026-07-15', dateFormat));
        await paidAt.press('Tab');
        await pay1.getByLabel('Metode').selectOption('transfer');
        await pay1.getByLabel('Referensi').fill(partialRef);
        await pay1.getByRole('button', { name: 'Simpan', exact: true }).click();
        await expectDetail(page, 'Status', 'Dibayar sebagian');
        await expect(page.locator('p').filter({ hasText: 'Terbayar:' }).first()).toContainText(
            /Rp\s*40\.000/,
        );
        await expect(page.getByRole('row').filter({ hasText: partialRef })).toContainText('Aktif');
        expect(await paymentDay(page, partialRef)).toBe('2026-07-15');

        await page.getByRole('button', { name: 'Catat pembayaran' }).click();
        const pay2 = page.getByRole('dialog', { name: 'Catat pembayaran' });
        // Only 60.000 is left, and the method used a moment ago is back.
        await expect(pay2.getByLabel('Jumlah')).toHaveValue(/60\.000/);
        await expect(pay2.getByLabel('Metode')).toHaveValue('transfer');
        await pay2.getByLabel('Jumlah').fill('60000');
        await pay2.getByLabel('Metode').selectOption('cash');
        await pay2.getByLabel('Referensi').fill(fullRef);
        await pay2.getByRole('button', { name: 'Simpan', exact: true }).click();
        await expectDetail(page, 'Status', 'Lunas');
        await expect(page.locator('p').filter({ hasText: 'Terbayar:' }).first()).toContainText(
            /Rp\s*100\.000/,
        );
        await expect(page.getByRole('row').filter({ hasText: fullRef })).toContainText('Aktif');

        // Cancel path on a separate draft invoice — the first invoice is now
        // paid, so the only remaining draft for the customer is the sibling.
        await page.goto('/admin/invoices/create');
        await selectSearchOption(page, 'Pelanggan', customerName);
        await typeDate(page, 'Tanggal terbit', '2026-07-02');
        await typeDate(page, 'Jatuh tempo', '2026-08-01');
        await page.getByLabel('Catatan').fill('cancel me');
        await page.getByRole('button', { name: 'Simpan', exact: true }).click();
        await expect(page).toHaveURL(/\/admin\/invoices$/);
        await openDraftInvoice(page, customerName);
        await expect(page).toHaveURL(/\/admin\/invoices\/[0-9A-HJKMNP-TV-Z]{26}$/i);
        const siblingPath = new URL(page.url()).pathname;

        // Separation of duties (role-persona-sod): the creator cannot cancel the
        // invoice they created. Raw JSON POST keeps the expected 422 out of the
        // browser fail-closed watcher and Inertia's error modal.
        const creatorCsrf = await refreshSession(page, siblingPath);
        const selfCancel = await page.request.post(`${siblingPath}/cancel`, {
            headers: { 'X-XSRF-TOKEN': creatorCsrf, Accept: 'application/json' },
            form: { reason: `self-cancel-${runId}` },
        });
        expect(selfCancel.status()).toBe(422);
        const selfCancelBody = (await selfCancel.json()) as { message?: string };
        expect(selfCancelBody.message).toBe(
            'Tagihan yang Anda buat sendiri tidak bisa Anda batalkan.',
        );
        await page.goto(siblingPath);
        await expectDetail(page, 'Status', 'Draf');

        // A different actor holding billing.cancel (manager) cancels it.
        await logout(page);
        await loginAs(page, 'manager');
        await page.goto(siblingPath);
        await expectDetail(page, 'Status', 'Draf');
        await page.getByRole('button', { name: 'Batalkan tagihan', exact: true }).click();
        const cancelDialog = page.getByRole('dialog', { name: 'Batalkan tagihan' });
        await cancelDialog.getByLabel('Alasan').fill(`cancel-${runId}`);
        await cancelDialog.getByRole('button', { name: 'Konfirmasi pembatalan' }).click();
        await expectDetail(page, 'Status', 'Dibatalkan');

        // Recurring generate preview UI
        await page.goto('/admin/invoices');
        await page.getByRole('button', { name: 'Buat tagihan bulanan', exact: true }).click();
        const gen = page.getByRole('dialog', { name: 'Buat tagihan bulanan' });
        await expect(gen).toBeVisible();
        const period = gen.getByLabel('Periode', { exact: true });
        await period.fill('2026-06');
        await period.press('Tab');
        await gen.getByRole('button', { name: 'Lihat pratinjau' }).click();
        await expect(
            gen.getByRole('paragraph').filter({ hasText: /tagihan|dilewati/ }),
        ).toBeVisible();
        // Second preview same period — still responds (duplicate skip lives in backend skipped count)
        await gen.getByRole('button', { name: 'Lihat pratinjau' }).click();
        await expect(
            gen.getByRole('paragraph').filter({ hasText: /tagihan|dilewati/ }),
        ).toBeVisible();
        await gen.getByRole('button', { name: 'Batal' }).click();
    });

    test('invoice list: export offers CSV and PDF only, the customer filter keeps its label after reload', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        await page.goto('/admin/invoices');

        await page.getByRole('button', { name: 'Ekspor', exact: true }).click();
        await expect(page.getByRole('menuitem', { name: 'Ekspor CSV' })).toBeVisible();
        await expect(page.getByRole('menuitem', { name: 'Ekspor PDF' })).toBeVisible();
        await expect(page.getByRole('menuitem', { name: 'Ekspor Excel' })).toHaveCount(0);
        await page.keyboard.press('Escape');

        await selectSearchOption(page, 'Pelanggan', e2eCustomerName);
        await expect
            .poll(() => new URL(page.url()).searchParams.get('customer_id'))
            .toMatch(/^[0-9A-HJKMNP-TV-Z]{26}$/i);

        await page.reload();
        await expect(page.getByRole('combobox', { name: 'Pelanggan' })).toHaveValue(
            e2eCustomerName,
        );
    });

    test('invoice list: the online-pending chip shows while the filter is on and takes it off', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        await page.goto('/admin/invoices?online_pending=1');

        const chip = page.getByRole('button', {
            name: 'Hapus filter Pembayaran online tertunda > 1 jam',
        });
        await expect(chip).toBeVisible();
        await chip.click();
        await expect(chip).toHaveCount(0);
        await expect.poll(() => new URL(page.url()).searchParams.has('online_pending')).toBe(false);
    });
});

async function openDraftInvoice(page: Page, customerName: string) {
    // The invoices index defaults to issue_date desc and 10 rows/page; the E2E
    // customer's drafts use fixed 2026-07 issue dates, so after repeated full
    // runs they no longer sit on page 1 (accumulated E2E PAY invoices with
    // later issue dates win the sort). The backend search matches invoice
    // number only, so narrow the index server-side by the exact number of the
    // just-created invoice — that guarantees the row on page 1 regardless of
    // accumulated state or ordering, then open its Show link.
    const number = await createdInvoiceNumber(page, customerName);
    await page.goto(`/admin/invoices?search=${encodeURIComponent(number)}`);
    const row = page
        .getByRole('row')
        .filter({ hasText: number })
        .filter({ hasText: 'Draf' })
        .first();
    await expect(row).toBeVisible({ timeout: 15_000 });
    await row.getByRole('link', { name: 'Lihat', exact: true }).click();
}

/**
 * Resolve the newest invoice number for the given customer from the invoices
 * index Inertia page props (canonical support/demo.ts inertiaProps helper).
 * Invoice numbers are sequential, so the highest number is the just-created
 * invoice. Falls back to a non-empty number if the customer has none yet.
 */
async function createdInvoiceNumber(page: Page, customerName: string) {
    // Request the largest supported page size so the E2E customer's invoices
    // (fixed 2026-07 issue dates, older than accumulated E2E PAY rows) are
    // present in the Inertia props even when the default 10-row page 1 does
    // not include them.
    const { props } = await inertiaProps(page, '/admin/invoices?per_page=50');
    const invoices = props?.['invoices'] as
        { data?: Array<{ id: string; number: string; customer?: { name: string } }> } | undefined;
    const matches = invoices?.data?.filter((entry) => entry.customer?.name === customerName) ?? [];
    const newest = matches
        .map((entry) => entry.number)
        .sort()
        .at(-1);
    if (!newest) {
        throw new Error(`Could not resolve an invoice number for customer ${customerName}`);
    }

    return newest;
}
