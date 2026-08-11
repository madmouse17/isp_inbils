import { expect, test } from './support/fixtures';
import { datatableCustomerNames, loginAs } from './support/demo';

/**
 * DataTable smoke: first production consumer (Customers index).
 * Asserts composite DataTable mount + row/empty testids after seed login.
 */
test('@kanban-t_41ed9a9c customers index renders DataTable with rows, sort, filter, and pagination', async ({
    page,
}) => {
    await loginAs(page, 'admin');

    await page.goto('/admin/customers?per_page=10');
    await expect(page).toHaveURL(/\/admin\/customers\/?\?per_page=10$/);
    await expect(page.getByRole('main').getByRole('heading', { name: 'Customers' })).toBeVisible({
        timeout: 30_000,
    });

    await page.getByLabel('Search').fill('Fiber');
    await page.getByRole('button', { name: 'Filter' }).click();
    await expect(page).toHaveURL(/search=Fiber/);

    await page.getByRole('button', { name: 'Name' }).click();
    await expect(page).toHaveURL(/sort=name/);
    await expect(page.locator('tbody tr').filter({ hasText: datatableCustomerNames[0] }).first()).toBeVisible();

    await page.getByRole('button', { name: 'Next page' }).click();
    await expect(page).toHaveURL(/page=2/);
    await expect(page.locator('tbody tr').filter({ hasText: datatableCustomerNames[10] }).first()).toBeVisible({
        timeout: 15_000,
    });
});
