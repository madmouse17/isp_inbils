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
        await expect(page).toHaveURL(/\/admin\/invoices\/\d+$/);
        await expectDetail(page, 'Status', 'Draf');
        await page.getByRole('button', { name: 'Tambah item' }).click();
        const itemDialog = page.getByRole('dialog', { name: 'Tambah item' });
        await itemDialog.getByLabel('Deskripsi').fill(line);
        await itemDialog.getByLabel('Kuantitas').fill('1');
        await itemDialog.getByLabel('Harga satuan').fill('100000');
        await itemDialog.getByLabel('Diskon').fill('0');
        await itemDialog.getByLabel('Pajak (%)').fill('0');
        await itemDialog.getByRole('button', { name: 'Simpan', exact: true }).click();
        await expect(page.getByRole('row').filter({ hasText: line })).toContainText(
            /Rp\s*100\.000/,
        );

        await page.getByRole('button', { name: 'Kirim tagihan' }).click();
        await expectDetail(page, 'Status', 'Belum dibayar');

        await page.getByRole('button', { name: 'Catat pembayaran' }).click();
        const pay1 = page.getByRole('dialog', { name: 'Catat pembayaran' });
        await pay1.getByLabel('Jumlah').fill('40000');
        await pay1.getByLabel('Metode').selectOption('transfer');
        await pay1.getByLabel('Referensi').fill(partialRef);
        await pay1.getByRole('button', { name: 'Simpan', exact: true }).click();
        await expectDetail(page, 'Status', 'Sebagian');
        await expect(page.locator('p').filter({ hasText: 'Terbayar:' }).first()).toContainText(
            /Rp\s*40\.000/,
        );
        await expect(page.getByRole('row').filter({ hasText: partialRef })).toContainText('Aktif');

        await page.getByRole('button', { name: 'Catat pembayaran' }).click();
        const pay2 = page.getByRole('dialog', { name: 'Catat pembayaran' });
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
        await expect(page).toHaveURL(/\/admin\/invoices\/\d+$/);
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
            .toMatch(/^\d+$/);

        await page.reload();
        await expect(page.getByRole('combobox', { name: 'Pelanggan' })).toHaveValue(
            e2eCustomerName,
        );
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
        { data?: Array<{ id: number; number: string; customer?: { name: string } }> } | undefined;
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
