import { loginAs, unique } from './support/demo';
import { expect, test } from './support/fixtures';

/**
 * The "+ Baru" dialogs of the package form create a bandwidth profile, a speed profile and an SLA tier without leaving the
 * page. The dialog asks for the new row with the X-Inline-Create header (QuickCreateDialog.tsx); the store routes answer a
 * plain form post, or a client that only accepts JSON, with the redirect (InlineCreateJsonTest).
 */
test.describe('@quick-create package form "+ Baru"', () => {
    test('each dialog creates its row, selects it, and the speed dialog starts from the chosen bandwidth profile', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        await page.goto('/admin/service-packages/create');

        // The three pickers each carry one "+ Baru": bandwidth, speed, SLA, in that order.
        const baru = page.getByRole('button', { name: '+ Baru' });
        const stored = (path: string) =>
            page.waitForResponse(
                (response) =>
                    new URL(response.url()).pathname === path &&
                    response.request().method() === 'POST',
            );

        const bandwidthName = unique('QC bandwidth');
        await baru.nth(0).click();
        const bandwidth = page.getByRole('dialog', { name: 'Profil bandwidth baru' });
        await bandwidth.getByLabel('Nama').fill(bandwidthName);
        await bandwidth.getByLabel('Unduh (Mbps)').fill('40');
        await bandwidth.getByLabel('Unggah (Mbps)').fill('20');
        const bandwidthStored = stored('/admin/bandwidth-profiles');
        await bandwidth.getByRole('button', { name: 'Simpan' }).click();
        expect((await bandwidthStored).status(), 'the dialog gets the row, not the redirect').toBe(
            201,
        );
        await expect(bandwidth).toBeHidden();
        await expect(page.getByRole('combobox', { name: 'Profil bandwidth' })).toHaveValue(
            bandwidthName,
        );

        const speedName = unique('QC kecepatan');
        await baru.nth(1).click();
        const speed = page.getByRole('dialog', { name: 'Profil kecepatan baru' });
        await expect(speed.getByLabel('Unduh maksimum (Mbps)')).toHaveValue('40');
        await expect(speed.getByLabel('Unggah maksimum (Mbps)')).toHaveValue('20');
        await speed.getByLabel('Nama').fill(speedName);
        const speedStored = stored('/admin/speed-profiles');
        await speed.getByRole('button', { name: 'Simpan' }).click();
        expect((await speedStored).status()).toBe(201);
        await expect(speed).toBeHidden();
        await expect(page.getByRole('combobox', { name: 'Profil kecepatan' })).toHaveValue(
            speedName,
        );

        const slaName = unique('QC SLA');
        await baru.nth(2).click();
        const sla = page.getByRole('dialog', { name: 'Tingkat SLA baru' });
        await sla.getByLabel('Nama').fill(slaName);
        await sla.getByLabel('Target respons (jam)').fill('2');
        await sla.getByLabel('Target penyelesaian (jam)').fill('8');
        const slaStored = stored('/admin/sla-tiers');
        await sla.getByRole('button', { name: 'Simpan' }).click();
        expect((await slaStored).status()).toBe(201);
        await expect(sla).toBeHidden();
        await expect(page.getByRole('combobox', { name: 'Tingkat SLA' })).toHaveValue(slaName);
    });

    test('a refusal stays in the dialog under its field', async ({ page, expectHttpError }) => {
        expectHttpError({ url: '/admin/sla-tiers', method: 'POST', status: 422 });
        await loginAs(page, 'admin');
        await page.goto('/admin/service-packages/create');

        await page.getByRole('button', { name: '+ Baru' }).nth(2).click();
        const dialog = page.getByRole('dialog', { name: 'Tingkat SLA baru' });
        await dialog.getByLabel('Nama').fill(unique('QC SLA salah'));
        await dialog.getByLabel('Target respons (jam)').fill('9');
        await dialog.getByLabel('Target penyelesaian (jam)').fill('8');
        await dialog.getByRole('button', { name: 'Simpan' }).click();

        await expect(
            dialog.getByText('Target respons tidak boleh lebih lama dari target penyelesaian.'),
        ).toBeVisible();
    });
});
