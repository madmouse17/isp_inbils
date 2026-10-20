import { expect, test } from './support/fixtures';
import {
    expectSupportAccessRedirects,
    loginAsPlatformOperator,
    platformFixture,
} from './support/platform';

const READ_ONLY_MESSAGE =
    'Mode baca saja: perubahan tidak diizinkan selama akses dukungan. Aktifkan mode ubah dari banner untuk melanjutkan.';

test('@platform support access shows the banner, enforces read-only, switches to write mode and leaves an audit entry', async ({
    page,
    expectHttpError,
}) => {
    test.setTimeout(150_000);
    expectSupportAccessRedirects(expectHttpError);
    // The dialog posts the typed code as JSON; the wrong one comes back 422 and is shown under the field.
    expectHttpError({ url: '/platform/impersonation/write', method: 'POST', status: 422 });
    await loginAsPlatformOperator(page);
    await page.goto(`/platform?search=${encodeURIComponent(platformFixture.tenantCode!)}`);
    await page.getByRole('link', { name: 'E2E Suspend Tenant' }).click();
    await page.getByRole('button', { name: 'Masuk sebagai E2E Suspend Admin' }).click();
    const start = page.getByRole('alertdialog');
    await start.getByLabel('Alasan').fill('Pemeriksaan banner E2E');
    await start.getByLabel('Kata sandi operator').fill(platformFixture.password!);
    await start.getByRole('button', { name: 'Mulai akses dukungan' }).click();
    await expect(page).toHaveURL(/\/admin\/dashboard$/);

    const banner = page.getByRole('region', { name: 'Akses dukungan' });
    await expect(banner).toContainText('Anda masuk sebagai E2E Suspend Admin (E2E Suspend Tenant)');
    await expect(banner).toContainText('superadmin E2E Platform Operator');
    await expect(banner).toContainText('mode Baca');

    const timer = banner.getByRole('timer');
    await expect(timer).toHaveText(/^\d{2}:\d{2}$/);
    const before = await timer.innerText();
    await expect(timer).not.toHaveText(before, { timeout: 5_000 });

    await page.goto('/admin/locations');
    await page.getByRole('button', { name: 'Buat Lokasi' }).click();
    const create = page.getByRole('dialog');
    await create.getByLabel('Kode').fill('E2E-RO');
    await create.getByLabel('Nama').fill('Lokasi mode baca');
    await create.getByRole('button', { name: 'Simpan' }).click();
    await expect(page.getByText(READ_ONLY_MESSAGE)).toBeVisible();

    const codeLabel = `Ketik ${platformFixture.tenantCode} untuk melanjutkan`;
    await banner.getByRole('button', { name: 'Mode ubah' }).click();
    const write = page.getByRole('alertdialog');
    await write.getByLabel(codeLabel).fill('salah');
    await write.getByRole('button', { name: 'Aktifkan mode ubah' }).click();
    await expect(write.getByText('Kode perusahaan yang diketik tidak sesuai.')).toBeVisible();
    await write.getByLabel(codeLabel).fill(platformFixture.tenantCode!);
    await write.getByRole('button', { name: 'Aktifkan mode ubah' }).click();
    await expect(banner).toContainText('mode Ubah');

    await page.goto('/admin/reports/audit-log');
    const main = page.getByRole('main');
    await expect(
        main.getByText('Tim dukungan Inbils masuk sebagai E2E Suspend Admin').first(),
    ).toBeVisible();
    await expect(main.getByText('E2E Platform Operator')).toHaveCount(0);

    await banner.getByRole('button', { name: 'Keluar' }).click();
    await expect(page).toHaveURL(/\/platform\/companies\/[0-9A-HJKMNP-TV-Z]{26}$/i);
    await expect(page.getByRole('region', { name: 'Akses dukungan' })).toHaveCount(0);
});
