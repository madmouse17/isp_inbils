import type { Page } from '@playwright/test';
import { expect, test } from './support/fixtures';
import { receivableCustomerNames, receivableGrandTotal, loginAs } from './support/demo';

const path = '/admin/billing/receivables';
const currency = new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
});
async function openReceivables(page: Page) {
    await loginAs(page, 'admin');
    await page.goto(`${path}?per_page=10`);
    await expect(page.getByRole('heading', { name: 'Tunggakan' }).first()).toBeVisible({
        timeout: 30_000,
    });
}

test.describe('@datatable-e Receivables', () => {
    test('sorts with keyboard, paginates 10 to 25, preserves full totals and query on reload', async ({
        page,
    }) => {
        await openReceivables(page);
        await expect(page.getByText('Showing 1–10 of 12')).toBeVisible();
        await expect(page.getByRole('link', { name: receivableCustomerNames[0] })).toBeVisible();
        const customerSort = page.getByRole('button', { name: 'Pelanggan' });
        await customerSort.focus();
        await expect(customerSort).toBeFocused();
        await page.keyboard.press('Enter');
        await expect(page).toHaveURL(/sort=customer/);
        await expect(page).toHaveURL(/direction=desc/);
        await expect(customerSort.locator('..')).toHaveAttribute('aria-sort', 'descending');
        await expect(page.locator('tbody tr').first()).toContainText('E2E Linked Customer');

        await customerSort.focus();
        await page.keyboard.press('Space');
        await expect(page).toHaveURL(/direction=asc/);
        await expect(customerSort.locator('..')).toHaveAttribute('aria-sort', 'ascending');
        await expect(page.locator('tbody tr').first()).toContainText('Alto Fiber');
        const totalLabel = page.getByText('Total piutang', { exact: true });
        const grandTotalCard = totalLabel
            .locator('..')
            .getByText(currency.format(receivableGrandTotal));
        await expect(grandTotalCard).toBeVisible();

        await page.getByRole('button', { name: 'Next page' }).click();
        await expect(page).toHaveURL(/page=2/);
        await expect(page).toHaveURL(/per_page=10/);
        await expect(page).toHaveURL(/sort=customer/);
        await expect(page).toHaveURL(/direction=asc/);
        await expect(page.getByText('Showing 11–12 of 12')).toBeVisible();
        await expect(page.getByText(currency.format(receivableGrandTotal))).toBeVisible();
        await page.reload();
        await expect(page).toHaveURL(/page=2/);
        await expect(page.getByRole('button', { name: 'Previous page' })).toBeEnabled();
        await page.getByRole('button', { name: 'Previous page' }).click();
        await expect(page).toHaveURL(/page=1/);
        await page.getByLabel('Rows per page').selectOption('25');
        await expect(page).toHaveURL(/per_page=25/);
        await expect(page).toHaveURL(/page=1/);
        await expect(page.getByText('Showing 1–12 of 12')).toBeVisible();
        await expect(page.getByText(currency.format(receivableGrandTotal))).toBeVisible();
        await page.getByRole('button', { name: 'Total' }).focus();
        await page.keyboard.press('Enter');
        await expect(page).toHaveURL(/sort=total/);
        await expect(page).toHaveURL(/direction=asc/);
    });

    test('renders without horizontal overflow at phone and desktop sizes in both themes', async ({
        page,
    }) => {
        for (const width of [320, 1280]) {
            await page.setViewportSize({ width, height: 900 });
            for (const dark of [false, true]) {
                await page.context().clearCookies();
                await page.goto('/login');
                await page.evaluate(
                    (enabled) => localStorage.setItem('darkMode', String(enabled)),
                    dark,
                );
                await loginAs(page, 'admin');
                await page.goto(`${path}?per_page=10`);
                await expect(
                    page.getByRole('heading', { name: 'Tunggakan' }).first(),
                ).toBeVisible();
                await expect(page.locator('html')).toHaveClass(
                    dark ? /\bdark\b/ : /^(?!.*\bdark\b)/,
                );
                const sizes = await page.evaluate(() => ({
                    viewport: window.innerWidth,
                    content: document.documentElement.scrollWidth,
                }));
                expect(sizes.content).toBeLessThanOrEqual(sizes.viewport);
                const perPage = await page.getByLabel('Rows per page').boundingBox();
                expect(perPage).not.toBeNull();
                expect(perPage!.x).toBeGreaterThanOrEqual(0);
                expect(perPage!.x + perPage!.width).toBeLessThanOrEqual(width);
            }
        }
    });

    test('preserves the existing suspension action and modal without submitting it', async ({
        page,
    }) => {
        await openReceivables(page);
        await page.getByLabel('Rows per page').selectOption('25');
        const linkedCustomerRow = page.getByRole('row').filter({ hasText: 'E2E Linked Customer' });
        const suspendAction = linkedCustomerRow.getByRole('button', {
            name: 'Isolir E2E-LINKED-SUB',
        });
        await expect(suspendAction).toBeVisible();
        await suspendAction.click();
        const dialog = page.getByRole('dialog');
        await expect(dialog).toBeVisible();
        await expect(dialog.getByRole('heading', { name: /Isolir subscription/ })).toBeVisible();
        await expect(dialog.getByRole('button', { name: 'Isolir', exact: true })).toBeDisabled();
        await dialog.getByLabel('Alasan isolir (wajib)').fill('E2E modal verification');
        await expect(dialog.getByRole('button', { name: 'Isolir', exact: true })).toBeEnabled();
        await dialog.getByRole('button', { name: 'Batal' }).click();
        await expect(dialog).toHaveCount(0);
        await suspendAction.click();
        await expect(dialog).toBeVisible();
        await expect(dialog.getByLabel('Alasan isolir (wajib)')).toHaveValue('');
        await expect(dialog.getByRole('button', { name: 'Isolir', exact: true })).toBeDisabled();
        await dialog.getByLabel('Alasan isolir (wajib)').fill('E2E escape dismissal');
        await page.keyboard.press('Escape');
        await expect(dialog).toHaveCount(0);
        await suspendAction.click();
        await expect(dialog).toBeVisible();
        await expect(dialog.getByLabel('Alasan isolir (wajib)')).toHaveValue('');
        await expect(dialog.getByRole('button', { name: 'Isolir', exact: true })).toBeDisabled();
        await dialog.getByRole('button', { name: 'Batal' }).click();
        await expect(dialog).toHaveCount(0);
    });
});
