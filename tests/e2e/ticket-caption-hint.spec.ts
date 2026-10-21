import { expect, test } from './support/fixtures';
import { loginAs } from './support/demo';

test.describe('@ticket-caption-hint', () => {
    test('the caption of an attachment says the customer sees it', async ({ page }) => {
        await loginAs(page, 'admin');
        await page.goto(`/admin/tickets?search=${encodeURIComponent('E2E-W1-TICKET-STARTED')}`);
        await page.getByRole('link', { name: 'E2E-W1-TICKET-STARTED', exact: true }).click();

        // The customer gets the caption with the file: staff must know before they type it.
        await expect(page.getByLabel('Keterangan (opsional)')).toHaveAccessibleDescription(
            'Terlihat oleh pelanggan',
        );
    });
});
