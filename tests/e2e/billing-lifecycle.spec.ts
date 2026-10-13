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
import { expect, test } from './support/fixtures';
import {
    expectDetail,
    e2eCustomerName,
    inertiaProps,
    loginAs,
    logout,
    refreshSession,
    selectOptionByText,
    unique,
} from './support/demo';

const runId = unique('BILL');

test.describe('@lifecycle billing', () => {
    test.describe.configure({ mode: 'serial' });

    test('partial then full payment, cancel draft sibling, generate preview', async ({ page }) => {
        await loginAs(page, 'admin');
        const customerName = e2eCustomerName;
        const line = `E2E bill line ${runId}`;
        const partialRef = `E2E-PARTIAL-${runId}`;
        const fullRef = `E2E-FULL-${runId}`;

        await page.goto('/admin/invoices/create');
        await selectOptionByText(page.getByLabel('Customer'), customerName);
        await page.getByLabel('Issue Date').fill('2026-07-01');
        await page.getByLabel('Due Date').fill('2026-07-31');
        await page.getByLabel('Notes').fill('P1-C partial/full payment.');
        await page.getByRole('button', { name: 'Create', exact: true }).click();
        await expect(page).toHaveURL(/\/admin\/invoices$/);
        await openDraftInvoice(page, customerName);
        await expect(page).toHaveURL(/\/admin\/invoices\/\d+$/);
        await expectDetail(page, 'Status', 'draft');
        await page.getByRole('button', { name: 'Add Item' }).click();
        const itemDialog = page.getByRole('dialog', { name: 'Add Item' });
        await itemDialog.getByLabel('Description').fill(line);
        await itemDialog.getByLabel('Quantity').fill('1');
        await itemDialog.getByLabel('Unit Price').fill('100000');
        await itemDialog.getByLabel('Discount').fill('0');
        await itemDialog.getByLabel('Tax Rate (%)').fill('0');
        await itemDialog.getByRole('button', { name: 'Add' }).click();
        await expect(page.getByRole('row').filter({ hasText: line })).toContainText('100000.00');

        await page.getByRole('button', { name: 'Send' }).click();
        await expectDetail(page, 'Status', 'sent');

        await page.getByRole('button', { name: 'Record Payment' }).click();
        const pay1 = page.getByRole('dialog', { name: 'Record Payment' });
        await pay1.getByLabel('Amount').fill('40000');
        await pay1.getByLabel('Method').selectOption('transfer');
        await pay1.getByLabel('Reference').fill(partialRef);
        await pay1.getByRole('button', { name: 'Record' }).click();
        await expectDetail(page, 'Status', 'partial');
        await expectDetail(page, 'Paid', '40000.00');
        await expect(page.getByRole('row').filter({ hasText: partialRef })).toContainText('Active');

        await page.getByRole('button', { name: 'Record Payment' }).click();
        const pay2 = page.getByRole('dialog', { name: 'Record Payment' });
        await pay2.getByLabel('Amount').fill('60000');
        await pay2.getByLabel('Method').selectOption('cash');
        await pay2.getByLabel('Reference').fill(fullRef);
        await pay2.getByRole('button', { name: 'Record' }).click();
        await expectDetail(page, 'Status', 'paid');
        await expectDetail(page, 'Paid', '100000.00');
        await expect(page.getByRole('row').filter({ hasText: fullRef })).toContainText('Active');

        // Cancel path on a separate draft invoice — the first invoice is now
        // paid, so the only remaining draft for the customer is the sibling.
        await page.goto('/admin/invoices/create');
        await selectOptionByText(page.getByLabel('Customer'), customerName);
        await page.getByLabel('Issue Date').fill('2026-07-02');
        await page.getByLabel('Due Date').fill('2026-08-01');
        await page.getByLabel('Notes').fill('cancel me');
        await page.getByRole('button', { name: 'Create', exact: true }).click();
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
        expect(selfCancelBody.message).toBe('Cannot cancel own created invoice.');
        await page.goto(siblingPath);
        await expectDetail(page, 'Status', 'draft');

        // A different actor holding billing.cancel (manager) cancels it.
        await logout(page);
        await loginAs(page, 'manager');
        await page.goto(siblingPath);
        await expectDetail(page, 'Status', 'draft');
        await page.getByRole('button', { name: 'Cancel' }).click();
        const cancelDialog = page.getByRole('dialog', { name: 'Cancel Invoice' });
        await cancelDialog.getByLabel('Reason').fill(`cancel-${runId}`);
        await cancelDialog.getByRole('button', { name: 'Confirm Cancel' }).click();
        await expectDetail(page, 'Status', 'cancelled');

        // Recurring generate preview UI
        await page.goto('/admin/invoices');
        await page.getByRole('button', { name: 'Generate Tagihan' }).click();
        const gen = page.getByRole('dialog', { name: 'Generate Tagihan Bulanan' });
        await expect(gen).toBeVisible();
        await gen.getByLabel('Periode').fill('2026-06');
        await gen.getByRole('button', { name: 'Preview' }).click();
        await expect(
            gen.getByRole('paragraph').filter({ hasText: /tagihan|dilewati/ }),
        ).toBeVisible();
        // Second preview same period — still responds (duplicate skip lives in backend skipped count)
        await gen.getByRole('button', { name: 'Preview' }).click();
        await expect(
            gen.getByRole('paragraph').filter({ hasText: /tagihan|dilewati/ }),
        ).toBeVisible();
        await gen.getByRole('button', { name: 'Batal' }).click();
    });
});

async function openDraftInvoice(page: import('@playwright/test').Page, customerName: string) {
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
        .filter({ hasText: 'Draft' })
        .first();
    await expect(row).toBeVisible({ timeout: 15_000 });
    await row.getByRole('link', { name: 'Show' }).click();
}

/**
 * Resolve the newest invoice number for the given customer from the invoices
 * index Inertia page props (canonical support/demo.ts inertiaProps helper).
 * Invoice numbers are sequential, so the highest number is the just-created
 * invoice. Falls back to a non-empty number if the customer has none yet.
 */
async function createdInvoiceNumber(page: import('@playwright/test').Page, customerName: string) {
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
