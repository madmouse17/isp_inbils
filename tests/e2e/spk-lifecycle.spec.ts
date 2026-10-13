/**
 * @lifecycle SPK installation journey (P1-C)
 *
 * Residual gaps (no app fix in this task):
 * - Stock movement / asset install / subscription activation / invoice side-effects
 *   after approve are not always visible on SPK show UI — only state + items + evidence asserted.
 * - Network asset picker on add-item is optional backend field; UI only exposes product SearchSelect.
 */
import type { Page } from '@playwright/test';

import { apiLogin } from './support/api';
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
    // Test 2-3 tetap serial dan bergantung pada hasil test pertama.
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
        await page
            .getByLabel('Description')
            .fill('P1-C SPK lifecycle with item + evidence + approve.');
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
        const dialog =
            (await itemDialog.count()) > 0 ? itemDialog : page.getByRole('dialog').last();
        // Item memakai product NON-asset (E2E-STOCK-PRODUCT) agar deterministik
        // terhadap state inbils_test kumulatif: asset serial E2E-SERIALIZED-ASSET
        // hanya available saat fresh seed (WorkOrderController::networkAssets()
        // memfilter status=available), sehingga run kedua+ tanpa reset DB selalu
        // kekurangan opsi asset dan test timeout. Network Asset picker memang
        // hanya dirender untuk product type=asset dan WAJIB memilih asset
        // available terikat product — kondisi yang mustahil dipenuhi setelah asset
        // serial terpakai. Lifecycle SPK (item → assign → start → evidence →
        // reject/approve) tidak bergantung pada asset; jalur asset penuh tetap
        // diuji oleh stock-asset.spec.ts.
        await selectSearchOption(page, 'Product', /E2E-STOCK-PRODUCT/);
        await dialog.getByLabel(/Quantity Reserved/).fill('1');
        await dialog.getByLabel(/Quantity Used/).fill('0');
        await dialog.getByLabel('Note').fill(`item-${runId}`);
        await dialog.getByRole('button', { name: 'Confirm' }).click();

        await expect(page.getByRole('row').filter({ hasText: `item-${runId}` })).toBeVisible();

        await page.getByRole('button', { name: 'Assign' }).click();
        await selectSearchOption(page, 'Technician', 'Demo Technician');
        await page
            .getByRole('dialog', { name: 'Assign' })
            .getByRole('button', { name: 'Confirm' })
            .click();

        await expectSpkState(page, 'assigned');
        await expectDetail(page, 'Technician', 'Demo Technician');
    });

    test('technician starts, uploads evidence, submits for review through the app API', async ({
        page,
        request,
    }) => {
        expect(spkUrl).toBeTruthy();
        const id = spkUrl.match(/\/admin\/spk\/(\d+)/)?.[1];
        expect(id, 'SPK id in the URL').toBeTruthy();

        const api = await apiLogin(request, 'technician');
        const woPath = `technician/work-orders/${id}`;
        const status = async () =>
            ((await (await api.get(woPath)).json()) as { data: { status: string } }).data.status;
        expect(await status()).toBe('assigned');

        expect((await api.post(`${woPath}/start`, {})).status()).toBe(200);
        expect(await status()).toBe('in_progress');

        const evidence = await api.postFile(
            `${woPath}/evidence`,
            { name: `evidence-${runId}.png`, mimeType: 'image/png', buffer: PNG_1X1 },
            { caption: `caption-${runId}` },
        );
        expect(evidence.status()).toBe(201);

        expect((await api.post(`${woPath}/submit`, {})).status()).toBe(200);
        expect(await status()).toBe('waiting_review');

        // Staff see the evidence and the review state on the web.
        await loginAs(page, 'admin');
        await page.goto(spkUrl);
        await expect(page.getByRole('main').getByRole('heading', { name: title })).toBeVisible();
        await expectSpkState(page, 'waiting_review');
        await expect(page.getByText(`caption-${runId}`)).toBeVisible();
        const preview = page.getByRole('img', { name: `evidence-${runId}` });
        await expect(preview).toHaveAttribute('src', /^\/admin\/media\/\d+$/);
        await expect
            .poll(() =>
                preview.evaluate((el: HTMLImageElement) => el.complete && el.naturalWidth > 0),
            )
            .toBe(true);
    });

    test('manager approves to completed', async ({ page }) => {
        test.setTimeout(60_000);
        await logout(page);
        if (!spkUrl) {
            spkUrl = await createWaitingReviewSpk(page, title, runId, e2eCustomerName);
        }

        await logout(page);
        await loginAs(page, 'manager');
        await page.goto(spkUrl);
        await expect(page.getByRole('button', { name: 'Approve' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Reject' })).toBeVisible();
        await page.getByRole('button', { name: 'Approve' }).click();
        await page
            .getByRole('dialog', { name: 'Approve' })
            .getByRole('button', { name: 'Confirm' })
            .click();
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
    await page
        .getByRole('dialog', { name: 'Assign' })
        .getByRole('button', { name: 'Confirm' })
        .click();

    await expectSpkState(page, 'assigned');
    await expectDetail(page, 'Technician', 'Demo Technician');

    await page.getByRole('button', { name: 'Start' }).click();
    await page
        .getByRole('dialog', { name: 'Start' })
        .getByRole('button', { name: 'Confirm' })
        .click();
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
    await page
        .getByRole('dialog', { name: 'Submit' })
        .getByRole('button', { name: 'Confirm' })
        .click();
    await expectSpkState(page, 'waiting_review');

    return page.url();
}

async function selectOptionByTextLoose(page: Page, label: string, text: string) {
    const select = page.getByLabel(label);
    const value = await select
        .locator('option')
        .filter({ hasText: text })
        .first()
        .getAttribute('value');
    expect(value).toBeTruthy();
    await select.selectOption(value!);
}
