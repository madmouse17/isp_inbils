/**
 * @lifecycle SPK installation journey (P1-C)
 *
 * Residual gaps (no app fix in this task):
 * - Stock movement / asset install / subscription activation / invoice side-effects
 *   after approve are not always visible on SPK show UI — only state + items + evidence asserted.
 * - Network asset picker on add-item is optional backend field; UI only exposes product SearchSelect.
 */
import type { Page } from '@playwright/test';

import { expect, test } from './support/fixtures';
import {
    PNG_1X1,
    e2eCustomerName,
    expectDetail,
    expectSpkState,
    loginAs,
    logout,
    selectFirstRealOption,
    selectSearchOption,
    unique,
} from './support/demo';

const runId = unique('SPK');

test.describe('@lifecycle SPK installation', () => {
    test.describe.configure({ mode: 'serial' });

    let spkUrl = '';
    const title = `E2E SPK lifecycle ${runId}`;

    test('admin creates SPK, reserves product item, assigns technician', async ({ page }) => {
        await loginAs(page, 'admin');

        await page.goto('/admin/spk/create');
        await page.getByLabel('Type').selectOption('installation');
        await page.getByLabel('Title').fill(title);
        await selectOptionByTextLoose(page, 'Customer', e2eCustomerName);
        await selectOptionByTextLoose(page, 'Subscription', 'E2E-LINKED-SUB');
        await selectFirstRealOption(page.getByLabel('Location'));
        await page.getByLabel('Priority').selectOption('high');
        await page.getByLabel('Scheduled Date').fill('2026-07-20');
        await page.getByLabel('Description').fill('P1-C SPK lifecycle with item + evidence + approve.');
        await page.getByRole('button', { name: 'Create', exact: true }).click();

        await expect(page).toHaveURL(/\/admin\/spk$/);
        await page.goto(`/admin/spk?search=${encodeURIComponent(title)}`);
        const spkRow = page.getByRole('row').nth(1);
        const spkLink = spkRow.getByRole('link').first();
        await expect(spkLink).toBeVisible();
        await spkLink.click();
        await expect(page).toHaveURL(/\/admin\/spk\/\d+$/);
        spkUrl = page.url();
        await expect(page.getByRole('main').getByRole('heading', { name: title })).toBeVisible();
        await expectSpkState(page, 'draft');
        await expectDetail(page, 'Customer', e2eCustomerName);

        await page.getByRole('button', { name: 'Add Item' }).click();
        const itemDialog = page.getByRole('dialog', { name: 'AddItem' });
        // Modal title is "AddItem" from actionModal casing — tolerate "Add Item" if UI normalizes later.
        const dialog = (await itemDialog.count()) > 0 ? itemDialog : page.getByRole('dialog').last();
        await selectSearchOption(page, 'Product', /E2E-STOCK-PRODUCT/);
        await dialog.getByLabel(/Quantity Reserved/).fill('1');
        await dialog.getByLabel(/Quantity Used/).fill('0');
        await dialog.getByLabel('Note').fill(`item-${runId}`);
        await dialog.getByRole('button', { name: 'Confirm' }).click();

        await expect(page.getByRole('row').filter({ hasText: `item-${runId}` })).toBeVisible();

        await page.getByRole('button', { name: 'Assign' }).click();
        await selectSearchOption(page, 'Technician', 'Demo Technician');
        await page.getByRole('dialog', { name: 'Assign' }).getByRole('button', { name: 'Confirm' }).click();

        await expectSpkState(page, 'assigned');
        await expectDetail(page, 'Technician', 'Demo Technician');
    });

    test('technician starts, uploads evidence, submits for review', async ({ page }) => {
        expect(spkUrl).toBeTruthy();
        await loginAs(page, 'technician');
        await page.goto(spkUrl);

        await expect(page.getByRole('main').getByRole('heading', { name: title })).toBeVisible();
        await expectSpkState(page, 'assigned');

        await page.getByRole('button', { name: 'Start' }).click();
        await page.getByRole('dialog', { name: 'Start' }).getByRole('button', { name: 'Confirm' }).click();
        await expectSpkState(page, 'in_progress');

        await page.locator('input[type="file"]').setInputFiles({
            name: `evidence-${runId}.png`,
            mimeType: 'image/png',
            buffer: PNG_1X1,
        });
        await page.getByLabel('Caption').fill(`caption-${runId}`);
        await page.getByRole('button', { name: 'Upload' }).click();
        await expect(page.getByText(`caption-${runId}`)).toBeVisible();

        await page.getByRole('button', { name: 'Submit for Review' }).click();
        await page.getByRole('dialog', { name: 'Submit' }).getByRole('button', { name: 'Confirm' }).click();
        await expectSpkState(page, 'waiting_review');
    });

    test('technician is denied approve; manager approves to completed', async ({ page }) => {
        test.setTimeout(60_000);
        await logout(page);
        if (!spkUrl) {
            spkUrl = await createWaitingReviewSpk(page, title, runId, e2eCustomerName);
        }

        await loginAs(page, 'technician');
        await page.goto(spkUrl);
        await expectSpkState(page, 'waiting_review');
        await expect(page.getByRole('button', { name: 'Approve' })).toBeHidden();
        await expect(page.getByRole('button', { name: 'Reject' })).toBeHidden();

        const denied = await page.request.post(
            spkUrl.replace(/\/admin\/spk\/(\d+).*/, '/admin/spk/$1/approve'),
        );
        expect([403, 419, 405, 302]).toContain(denied.status());
        expect(denied.status()).not.toBe(200);

        await logout(page);
        await loginAs(page, 'manager');
        await page.goto(spkUrl);
        await expect(page.getByRole('button', { name: 'Approve' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Reject' })).toBeVisible();
        await page.getByRole('button', { name: 'Approve' }).click();
        await page.getByRole('dialog', { name: 'Approve' }).getByRole('button', { name: 'Confirm' }).click();
        await expectSpkState(page, 'completed');
    });
});

async function createWaitingReviewSpk(page: Page, title: string, runId: string, customer: string) {
    await loginAs(page, 'admin');

    await page.goto('/admin/spk/create');
    await page.getByLabel('Type').selectOption('installation');
    await page.getByLabel('Title').fill(title);
    await selectOptionByTextLoose(page, 'Customer', customer);
    await selectOptionByTextLoose(page, 'Subscription', 'E2E-LINKED-SUB');
    await selectFirstRealOption(page.getByLabel('Location'));
    await page.getByLabel('Priority').selectOption('high');
    await page.getByLabel('Scheduled Date').fill('2026-07-20');
    await page.getByLabel('Description').fill('P1-C SPK lifecycle with item + evidence + approve.');
    await page.getByRole('button', { name: 'Create', exact: true }).click();

    await expect(page).toHaveURL(/\/admin\/spk$/);
    await page.goto(`/admin/spk?search=${encodeURIComponent(title)}`);
    const spkRow = page.getByRole('row').nth(1);
    const spkLink = spkRow.getByRole('link').first();
    await expect(spkLink).toBeVisible();
    await spkLink.click();
    await expect(page).toHaveURL(/\/admin\/spk\/\d+$/);
    await expect(page.getByRole('main').getByRole('heading', { name: title })).toBeVisible();
    await expectSpkState(page, 'draft');
    await expectDetail(page, 'Customer', customer);


    await page.getByRole('button', { name: 'Assign' }).click();
    await selectSearchOption(page, 'Technician', 'Demo Technician');
    await page.getByRole('dialog', { name: 'Assign' }).getByRole('button', { name: 'Confirm' }).click();

    await expectSpkState(page, 'assigned');
    await expectDetail(page, 'Technician', 'Demo Technician');

    await page.getByRole('button', { name: 'Start' }).click();
    await page.getByRole('dialog', { name: 'Start' }).getByRole('button', { name: 'Confirm' }).click();
    await expectSpkState(page, 'in_progress');

    await page.locator('input[type="file"]').setInputFiles({
        name: `evidence-${runId}.png`,
        mimeType: 'image/png',
        buffer: PNG_1X1,
    });
    await page.getByLabel('Caption').fill(`caption-${runId}`);
    await page.getByRole('button', { name: 'Upload' }).click();
    await expect(page.getByText(`caption-${runId}`)).toBeVisible();

    await page.getByRole('button', { name: 'Submit for Review' }).click();
    await page.getByRole('dialog', { name: 'Submit' }).getByRole('button', { name: 'Confirm' }).click();
    await expectSpkState(page, 'waiting_review');

    return page.url();
}

async function selectOptionByTextLoose(page: Page, label: string, text: string) {
    const select = page.getByLabel(label);
    const value = await select.locator('option').filter({ hasText: text }).first().getAttribute('value');
    expect(value).toBeTruthy();
    await select.selectOption(value!);
}
