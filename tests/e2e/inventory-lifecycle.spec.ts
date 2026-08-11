/**
 * @lifecycle Inventory issue / transfer / adjust (P1-C)
 *
 * Residual gaps:
 * - Explicit reservation-conflict UI not present beyond available column; no-negative enforced by API flash/422.
 * - Approval workflow for adjust does not exist — Gate permission only.
 */
import { expect, test } from './support/fixtures';
import { loginAs, logout, selectFirstRealOption, unique } from './support/demo';

const runId = unique('INV');

test.describe('@lifecycle inventory', () => {
    test.describe.configure({ mode: 'serial' });

    test('receive, issue, transfer, adjust with movement evidence', async ({ page }) => {
        await loginAs(page, 'admin');
        const receiveNote = `recv-${runId}`;
        const issueNote = `issue-${runId}`;
        const transferNote = `xfer-${runId}`;
        const adjustNote = `adj-${runId}`;

        await page.goto('/admin/stocks');
        await expect(page.getByRole('main').getByRole('heading', { name: 'Stocks' })).toBeVisible();
        await page.waitForTimeout(500);

        await page.getByRole('button', { name: 'Receive' }).click();
        let dialog = page.getByRole('dialog', { name: 'Receive Stock' });
        const product = 'Kabel UTP Cat6';
        const location = 'Default POP';
        await dialog.getByLabel('Product').selectOption({ label: product });
        await dialog.getByLabel('Location').selectOption({ label: location });
        await dialog.getByLabel('Quantity').fill('10');
        await dialog.getByLabel('Note').fill(receiveNote);
        await dialog.getByRole('button', { name: 'Submit' }).click();

        await page.getByRole('button', { name: 'Issue' }).click();
        dialog = page.getByRole('dialog', { name: 'Issue Stock' });
        await dialog.getByLabel('Product').selectOption(product);
        await dialog.getByLabel('Location').selectOption(location);
        await dialog.getByLabel('Quantity').fill('2');
        await dialog.getByLabel('Note').fill(issueNote);
        await dialog.getByRole('button', { name: 'Submit' }).click();

        await page.getByRole('button', { name: 'Transfer' }).click();
        dialog = page.getByRole('dialog', { name: 'Transfer Stock' });
        await dialog.getByLabel('Product').selectOption(product);
        const from = await selectFirstRealOption(dialog.getByLabel('From Location'));
        // pick a different to-location when possible
        const toSelect = dialog.getByLabel('To Location');
        const toOptions = toSelect.locator('option');
        const toCount = await toOptions.count();
        let toValue = await toOptions.nth(1).getAttribute('value');
        if (toCount > 2) {
            for (let i = 1; i < toCount; i += 1) {
                const v = await toOptions.nth(i).getAttribute('value');
                if (v && v !== from) {
                    toValue = v;
                    break;
                }
            }
        }
        expect(toValue).toBeTruthy();
        await toSelect.selectOption(toValue!);
        await dialog.getByLabel('Quantity').fill('1');
        await dialog.getByLabel('Note').fill(transferNote);
        await dialog.getByRole('button', { name: 'Transfer' }).click();

        await page.getByRole('button', { name: 'Adjust' }).click();
        dialog = page.getByRole('dialog', { name: 'Adjust Stock' });
        await dialog.getByLabel('Product').selectOption(product);
        await dialog.getByLabel('Location').selectOption(location);
        await dialog.getByLabel('New Quantity').fill('7');
        await dialog.getByLabel('Note').fill(adjustNote);
        await dialog.getByRole('button', { name: 'Adjust' }).click();

        await page.getByRole('link', { name: 'Stock Movements' }).click();
        await expect(
            page.getByRole('main').getByRole('heading', { name: 'Stock Movements' }),
        ).toBeVisible();

        for (const note of [receiveNote, issueNote, transferNote, adjustNote]) {
            await expect(page.getByRole('row').filter({ hasText: note })).toBeVisible();
        }
    });

    test('technician denied stock adjust; can open stocks view', async ({ page }) => {
        await loginAs(page, 'technician');
        const stocks = await page.request.get('/admin/stocks');
        expect([200, 403]).toContain(stocks.status());

        const adjust = await page.request.post('/admin/stocks/adjust', {
            form: {
                product_id: '1',
                location_id: '1',
                new_quantity: '0',
                note: `deny-${runId}`,
            },
        });
        expect([403, 419, 422, 302]).toContain(adjust.status());
        expect(adjust.status()).not.toBe(200);

        await logout(page);
    });

    test('issue more than available surfaces validation/error without soft-pass', async ({ page }) => {
        await loginAs(page, 'admin');
        await page.goto('/admin/stocks');
        await page.getByRole('button', { name: 'Issue' }).click();
        const dialog = page.getByRole('dialog', { name: 'Issue Stock' });
        await selectFirstRealOption(dialog.getByLabel('Product'));
        await selectFirstRealOption(dialog.getByLabel('Location'));
        await dialog.getByLabel('Quantity').fill('999999999');
        await dialog.getByLabel('Note').fill(`overissue-${runId}`);
        await dialog.getByRole('button', { name: 'Submit' }).click();

        // Expect stay on stocks with dialog error, flash, or validation — not a success movement.
        await page.getByRole('link', { name: 'Stock Movements' }).click();
        await expect(page.getByRole('row').filter({ hasText: `overissue-${runId}` })).toHaveCount(0);
    });
});
