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
import { companyDateFormat, formatIsoAs } from './support/dateRange';
import {
    PNG_1X1,
    e2eCustomerName,
    expectDetail,
    expectSpkState,
    loginAs,
    logout,
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
        await page.getByLabel('Jenis').selectOption('installation');
        await page.getByLabel('Judul').fill(title);
        await selectSearchOption(page, 'Pelanggan', e2eCustomerName);
        await selectSearchOption(page, 'Langganan', 'E2E-LINKED-SUB');
        await selectSearchOption(page, 'Lokasi', 'E2E ODP Site 01');
        await page.getByLabel('Prioritas').selectOption('high');
        await typeDate(page, 'Tanggal jadwal', '2026-07-20');
        await page
            .getByLabel('Deskripsi')
            .fill('P1-C SPK lifecycle with item + evidence + approve.');
        await page.getByRole('button', { name: 'Simpan', exact: true }).click();

        await expect(page).toHaveURL(/\/admin\/spk$/);
        await page.goto(`/admin/spk?search=${encodeURIComponent(title)}`);
        const spkRow = page.getByRole('row').nth(1);
        const spkLink = spkRow.getByRole('link').first();
        await expect(spkLink).toBeVisible();
        await spkLink.click();
        await expect(page).toHaveURL(/\/admin\/spk\/[0-9A-HJKMNP-TV-Z]{26}$/i);
        spkUrl = page.url();
        await expect(page.getByRole('main').getByRole('heading', { name: title })).toBeVisible();
        await expectSpkState(page, 'draft');
        await expectDetail(page, 'Pelanggan', e2eCustomerName);

        await page.getByRole('button', { name: 'Tambah item' }).click();
        const itemDialog = page.getByRole('dialog', { name: 'Tambah item' });
        // Fall back to the last dialog if the accessible title is not exposed yet.
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
        await selectSearchOption(page, 'Barang', 'E2E-STOCK-PRODUCT');
        await dialog.getByLabel(/Jumlah dicadangkan/).fill('1');
        await dialog.getByLabel(/Jumlah terpakai/).fill('0');
        await dialog.getByLabel('Catatan').fill(`item-${runId}`);
        await dialog.getByRole('button', { name: 'Tambah' }).click();

        await expect(page.getByRole('row').filter({ hasText: `item-${runId}` })).toBeVisible();

        await page.getByRole('button', { name: 'Tugaskan' }).click();
        await selectSearchOption(page, 'Teknisi', 'Demo Technician');
        await page
            .getByRole('dialog', { name: 'Tugaskan SPK' })
            .getByRole('button', { name: 'Tugaskan' })
            .click();

        await expectSpkState(page, 'assigned');
        await expectDetail(page, 'Teknisi', 'Demo Technician');

        const timeline = page.getByRole('list', { name: 'Tahap kunjungan' });
        await expect(timeline.getByRole('listitem')).toHaveCount(4);
        await expect(timeline.locator('[aria-current="step"]')).toContainText('Ditugaskan');
    });

    test('technician starts, uploads evidence, submits for review through the app API', async ({
        page,
        request,
    }) => {
        expect(spkUrl).toBeTruthy();
        const id = spkUrl.match(/\/admin\/spk\/([0-9A-HJKMNP-TV-Z]{26})/i)?.[1];
        expect(id, 'SPK id in the URL').toBeTruthy();

        const api = await apiLogin(request, 'technician');
        const woPath = `technician/work-orders/${id}`;
        const status = async () =>
            ((await (await api.get(woPath)).json()) as { data: { status: string } }).data.status;
        expect(await status()).toBe('assigned');

        expect((await api.post(`${woPath}/en-route`, {})).status()).toBe(200);
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
        const timeline = page.getByRole('list', { name: 'Tahap kunjungan' });
        await expect(timeline.getByRole('listitem')).toHaveCount(4);
        await expect(timeline.locator('[aria-current="step"]')).toHaveCount(0);
        await expect(timeline).not.toContainText('Belum dicapai');
        await expect(page.getByText(`caption-${runId}`)).toBeVisible();
        const preview = page.getByRole('img', { name: `evidence-${runId}` });
        await expect(preview).toHaveAttribute('src', /^\/admin\/media\/[0-9A-HJKMNP-TV-Z]{26}$/i);
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
        await expect(page.getByRole('button', { name: 'Setujui' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Tolak' })).toBeVisible();
        await page.getByRole('button', { name: 'Setujui' }).click();
        await page
            .getByRole('dialog', { name: 'Setujui SPK' })
            .getByRole('button', { name: 'Setujui' })
            .click();
        await expectSpkState(page, 'completed');
    });
});

async function createWaitingReviewSpk(page: Page, title: string, runId: string, customer: string) {
    await loginAs(page, 'admin');

    await page.goto('/admin/spk/create');
    await page.getByLabel('Jenis').selectOption('installation');
    await page.getByLabel('Judul').fill(title);
    await selectSearchOption(page, 'Pelanggan', customer);
    await selectSearchOption(page, 'Langganan', 'E2E-LINKED-SUB');
    await selectSearchOption(page, 'Lokasi', 'E2E ODP Site 01');
    await page.getByLabel('Prioritas').selectOption('high');
    await typeDate(page, 'Tanggal jadwal', '2026-07-20');
    await page.getByLabel('Deskripsi').fill('P1-C SPK lifecycle with item + evidence + approve.');
    await page.getByRole('button', { name: 'Simpan', exact: true }).click();

    await expect(page).toHaveURL(/\/admin\/spk$/);
    await page.goto(`/admin/spk?search=${encodeURIComponent(title)}`);
    const spkRow = page.getByRole('row').nth(1);
    const spkLink = spkRow.getByRole('link').first();
    await expect(spkLink).toBeVisible();
    await spkLink.click();
    await expect(page).toHaveURL(/\/admin\/spk\/[0-9A-HJKMNP-TV-Z]{26}$/i);
    await expect(page.getByRole('main').getByRole('heading', { name: title })).toBeVisible();
    await expectSpkState(page, 'draft');
    await expectDetail(page, 'Pelanggan', customer);

    await page.getByRole('button', { name: 'Tugaskan' }).click();
    await selectSearchOption(page, 'Teknisi', 'Demo Technician');
    await page
        .getByRole('dialog', { name: 'Tugaskan SPK' })
        .getByRole('button', { name: 'Tugaskan' })
        .click();

    await expectSpkState(page, 'assigned');
    await expectDetail(page, 'Teknisi', 'Demo Technician');

    await page.getByRole('button', { name: 'Mulai' }).click();
    await page
        .getByRole('dialog', { name: /Mulai pengerjaan SPK/ })
        .getByRole('button', { name: 'Mulai' })
        .click();
    await expectSpkState(page, 'in_progress');

    await page.locator('input[type="file"]').setInputFiles({
        name: `evidence-${runId}.png`,
        mimeType: 'image/png',
        buffer: PNG_1X1,
    });
    await page.getByLabel('Keterangan').fill(`caption-${runId}`);
    await page.getByRole('button', { name: 'Unggah' }).click();
    await expect(page.getByText(`caption-${runId}`)).toBeVisible();

    await page.getByRole('button', { name: 'Ajukan review' }).click();
    await page
        .getByRole('dialog', { name: /Ajukan SPK .* untuk review/ })
        .getByRole('button', { name: 'Ajukan' })
        .click();
    await expectSpkState(page, 'waiting_review');

    return page.url();
}

/** Type a day into a DatePicker in the company format and commit it with Tab. */
async function typeDate(page: Page, label: string, iso: string) {
    const format = await companyDateFormat(page);
    const field = page.getByLabel(label, { exact: true });
    await field.fill(formatIsoAs(iso, format));
    await field.press('Tab');
}
