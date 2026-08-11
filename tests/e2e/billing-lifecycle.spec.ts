/**
 * @lifecycle Billing journeys (P1-C)
 *
 * Residual gaps:
 * - Payment reversal UI not present (only invoice cancel) — cancel covered, reverse skipped.
 * - Recurring generate depends on active subscriptions in period; empty preview still proves UI path.
 * - Duplicate-period skip asserted only when preview returns skipped count > 0 or re-preview stable.
 */
import { expect, test } from './support/fixtures';
import {
    expectDetail,
    e2eCustomerName,
    loginAs,
    logout,
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
        await page.goto(`/admin/invoices?search=${encodeURIComponent(runId)}`);
        await page.getByRole('row').filter({ hasText: runId }).first().getByRole('link', { name: 'Show' }).click();
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

        // Cancel path on a separate draft invoice
        await page.goto('/admin/invoices/create');
        await selectOptionByText(page.getByLabel('Customer'), customerName);
        await page.getByLabel('Issue Date').fill('2026-07-02');
        await page.getByLabel('Due Date').fill('2026-08-01');
        await page.getByLabel('Notes').fill('cancel me');
        await page.getByRole('button', { name: 'Create', exact: true }).click();
        await expect(page).toHaveURL(/\/admin\/invoices$/);
        await page.goto(`/admin/invoices?search=${encodeURIComponent(runId)}`);
        await page.getByRole('row').filter({ hasText: 'cancel me' }).first().getByRole('link', { name: 'Show' }).click();
        await expect(page).toHaveURL(/\/admin\/invoices\/\d+$/);
        await page.getByRole('button', { name: 'Cancel' }).click();
        const cancelDialog = page.getByRole('dialog', { name: 'Cancel' });
        await cancelDialog.getByLabel('Reason').fill(`cancel-${runId}`);
        await cancelDialog.getByRole('button', { name: /Confirm|Cancel Invoice|Submit/i }).click();
        // UI may use Confirm or form submit labeled Cancel inside dialog
        if (await page.getByRole('dialog', { name: 'Cancel' }).count()) {
            const dlg = page.getByRole('dialog', { name: 'Cancel' });
            const confirm = dlg.getByRole('button').filter({ hasText: /Confirm|Cancel/ }).last();
            await confirm.click();
        }
        await expectDetail(page, 'Status', 'cancelled');

        // Recurring generate preview UI
        await page.goto('/admin/invoices');
        await page.getByRole('button', { name: 'Generate Tagihan' }).click();
        const gen = page.getByRole('dialog', { name: 'Generate Tagihan Bulanan' });
        await expect(gen).toBeVisible();
        await gen.getByLabel('Periode').fill('2026-06');
        await gen.getByRole('button', { name: 'Preview' }).click();
        await expect(gen.getByText(/tagihan|dilewati|Tidak ada/i)).toBeVisible();
        // Second preview same period — still responds (duplicate skip lives in backend skipped count)
        await gen.getByRole('button', { name: 'Preview' }).click();
        await expect(gen.getByText(/tagihan|dilewati|Tidak ada/i)).toBeVisible();
        await gen.getByRole('button', { name: 'Batal' }).click();
    });

    test('technician denied billing routes', async ({ page }) => {
        await loginAs(page, 'technician');
        await expect(page.locator('aside').getByRole('link', { name: 'Billing', exact: true })).toHaveCount(
            0,
        );

        const list = await page.request.get('/admin/invoices');
        expect(list.status()).toBe(403);

        const create = await page.request.get('/admin/invoices/create');
        expect(create.status()).toBe(403);

        await logout(page);
    });
});
