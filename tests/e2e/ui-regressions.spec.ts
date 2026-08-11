import { expect, test } from './support/fixtures';
import { loginAs } from './support/demo';

test('@regression admin billing and spk indexes render without page errors', async ({ page }) => {
    const pageErrors: Error[] = [];
    page.on('pageerror', (error) => pageErrors.push(error));

    await loginAs(page, 'admin');

    await page.goto('/admin/spk');
    await expect(page.getByRole('heading', { name: 'SPK / Work Orders' })).toBeVisible();

    await page.goto('/admin/invoices');
    await expect(page.getByRole('heading', { name: 'Invoices' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Generate Tagihan' })).toBeVisible();

    expect(pageErrors).toEqual([]);
});
