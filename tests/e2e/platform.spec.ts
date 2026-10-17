import { expect, test } from './support/fixtures';
import { totp } from './support/totp';
import {
    expectSupportAccessRedirects,
    loginAsPlatformOperator,
    platformFixture,
} from './support/platform';

test.describe.configure({ mode: 'serial' });

test('@platform first login enrols mandatory two-factor authentication', async ({ page }) => {
    test.setTimeout(90_000);
    await page.goto('/platform/login');
    await page.getByLabel('Email').fill(platformFixture.enrolEmail!);
    await page.getByLabel('Kata sandi').fill(platformFixture.password!);
    await page.getByRole('button', { name: 'Masuk' }).click();
    await expect(page).toHaveURL(/\/platform\/two-factor\/setup$/);

    await page.getByRole('button', { name: 'Mulai pengaturan' }).click();
    await expect(page.getByRole('img', { name: 'Kode QR untuk kunci pengaturan' })).toBeVisible();
    const secret = (await page.locator('code').innerText()).replace(/\s/g, '');
    expect(secret).toMatch(/^[A-Z2-7]{32}$/);

    await page.getByLabel('Kode autentikasi').fill('000000');
    await page.getByRole('button', { name: 'Konfirmasi dan aktifkan' }).click();
    await expect(page.getByText('Kode autentikasi dua langkah tidak valid.')).toBeVisible();
    await page.getByLabel('Kode autentikasi').fill(totp(secret));
    await page.getByRole('button', { name: 'Konfirmasi dan aktifkan' }).click();
    await expect(page.getByRole('heading', { name: 'Kode pemulihan Anda' })).toBeVisible();
    await expect(
        page.getByRole('list', { name: 'Kode pemulihan' }).getByRole('listitem'),
    ).toHaveCount(8);
    await page.getByRole('button', { name: 'Saya sudah menyimpan kode ini' }).click();
    await expect(page).toHaveURL(/\/platform\/?$/);
});

test('@platform operator creates a tenant', async ({ page }) => {
    test.setTimeout(90_000);
    await loginAsPlatformOperator(page);
    await page.getByRole('main').getByRole('link', { name: 'Perusahaan baru' }).click();
    const code = `E2E-PLATFORM-${Date.now()}`;
    const email = `admin-${Date.now()}@platform-create.example.test`;

    await page.getByLabel('Nama perusahaan').fill('E2E Platform Created');
    await page.getByLabel('Kode perusahaan').fill(code);
    await page.getByLabel('Nama admin').fill('E2E Created Admin');
    await page.getByLabel('Email admin').fill(email);
    await page.getByRole('button', { name: 'Buat perusahaan' }).click();

    await expect(page).toHaveURL(/\/platform\/companies\/\d+$/);
    await expect(
        page.getByRole('main').getByRole('heading', { name: 'E2E Platform Created' }),
    ).toBeVisible();
    // The new admin is listed twice: under "Admin perusahaan" and among the users.
    await expect(page.getByText(email)).toHaveCount(2);
});

test('@platform suspend blocks tenant login and reactivate restores it', async ({
    page,
    browser,
    baseURL,
}) => {
    test.setTimeout(120_000);
    await loginAsPlatformOperator(page);
    await page.goto(`/platform?search=${encodeURIComponent(platformFixture.tenantCode!)}`);
    await page.getByRole('link', { name: 'E2E Suspend Tenant' }).click();
    await page.getByRole('button', { name: 'Tangguhkan', exact: true }).click();
    const suspend = page.getByRole('alertdialog');
    await suspend.getByLabel('Alasan').fill('Pemeriksaan akses E2E');
    await suspend
        .getByLabel(`Ketik ${platformFixture.tenantCode} untuk melanjutkan`)
        .fill(platformFixture.tenantCode!);
    await suspend.getByLabel('Kata sandi operator').fill(platformFixture.password!);
    await suspend.getByRole('button', { name: 'Tangguhkan perusahaan' }).click();
    await expect(page.getByText('Ditangguhkan', { exact: true }).first()).toBeVisible();

    const tenantContext = await browser.newContext({ baseURL });
    const tenantPage = await tenantContext.newPage();
    await tenantPage.goto('/login');
    await tenantPage.getByLabel('Email').fill(platformFixture.tenantAdminEmail!);
    await tenantPage.getByLabel('Kata sandi').fill(platformFixture.tenantAdminPassword!);
    await tenantPage.getByRole('button', { name: 'Masuk' }).click();
    await expect(
        tenantPage.getByText('Akun perusahaan Anda sedang ditangguhkan. Hubungi dukungan.'),
    ).toBeVisible();

    await page.getByRole('button', { name: 'Aktifkan kembali', exact: true }).click();
    const reactivate = page.getByRole('alertdialog');
    await reactivate.getByLabel('Alasan').fill('Pemeriksaan E2E selesai');
    await reactivate.getByRole('button', { name: 'Aktifkan kembali' }).click();
    await expect(page.getByText('Aktif', { exact: true }).first()).toBeVisible();

    await tenantPage.reload();
    await tenantPage.getByLabel('Email').fill(platformFixture.tenantAdminEmail!);
    await tenantPage.getByLabel('Kata sandi').fill(platformFixture.tenantAdminPassword!);
    await tenantPage.getByRole('button', { name: 'Masuk' }).click();
    await expect(tenantPage).toHaveURL(/\/admin\/dashboard$/);
    await tenantContext.close();
});

test('@platform operator requests a tenant export once and sees it queued', async ({ page }) => {
    test.setTimeout(120_000);
    await loginAsPlatformOperator(page);
    await page.goto(`/platform?search=${encodeURIComponent(platformFixture.tenantCode!)}`);
    await page.getByRole('link', { name: 'E2E Suspend Tenant' }).click();

    for (const attempt of ['first', 'second']) {
        await page.getByRole('button', { name: 'Minta ekspor', exact: true }).click();
        const request = page.getByRole('alertdialog');
        await request
            .getByLabel(`Ketik ${platformFixture.tenantCode} untuk melanjutkan`)
            .fill(platformFixture.tenantCode!);
        await request.getByLabel('Kata sandi operator').fill(platformFixture.password!);
        await request.getByRole('button', { name: 'Minta ekspor' }).click();

        if (attempt === 'first') {
            await expect(page.getByText('Ekspor data dijadwalkan.')).toBeVisible();
            await expect(
                page.getByRole('main').getByRole('cell', { name: 'Antrean', exact: true }),
            ).toBeVisible();
        } else {
            await expect(request.getByText('Ekspor perusahaan ini masih diproses.')).toBeVisible();
            await request.getByRole('button', { name: 'Batal' }).click();
        }
    }

    await page.getByRole('link', { name: 'Semua ekspor' }).click();
    await expect(page).toHaveURL(/\/platform\/exports\?company_id=\d+$/);
    await expect(
        page.getByRole('main').getByRole('cell', { name: 'Antrean', exact: true }),
    ).toBeVisible();
});

test('@platform audit page lists operator activity with its properties', async ({ page }) => {
    test.setTimeout(90_000);
    await loginAsPlatformOperator(page);
    await page.getByRole('link', { name: 'Audit platform' }).click();
    await expect(page).toHaveURL(/\/platform\/audit$/);
    const main = page.getByRole('main');
    await expect(main.getByRole('heading', { name: 'Audit platform' })).toBeVisible();

    await main.getByLabel('Cari').fill('tenant.provisioned');
    await expect(main.getByText('Perusahaan dibuat').first()).toBeVisible();
    await main
        .getByRole('button', { name: /^Lihat rincian Perusahaan dibuat$/ })
        .first()
        .click();
    await expect(page.getByRole('dialog').getByText('Properti tercatat')).toBeVisible();
});

test('@platform operator starts and stops support access from the company page', async ({
    page,
    expectHttpError,
}) => {
    test.setTimeout(120_000);
    expectSupportAccessRedirects(expectHttpError);
    await loginAsPlatformOperator(page);
    await page.goto(`/platform?search=${encodeURIComponent(platformFixture.tenantCode!)}`);
    await page.getByRole('link', { name: 'E2E Suspend Tenant' }).click();
    await page.getByRole('button', { name: 'Masuk sebagai E2E Suspend Admin' }).click();
    const start = page.getByRole('alertdialog');
    await start.getByLabel('Alasan').fill('Pemeriksaan akses dukungan dari halaman perusahaan');
    await start.getByLabel('Kata sandi operator').fill(platformFixture.password!);
    await start.getByRole('button', { name: 'Mulai akses dukungan' }).click();
    await expect(page).toHaveURL(/\/admin\/dashboard$/);

    const banner = page.getByRole('region', { name: 'Akses dukungan' });
    await expect(banner).toContainText('E2E Suspend Admin');
    await banner.getByRole('button', { name: 'Keluar' }).click();
    await expect(page).toHaveURL(/\/platform\/companies\/\d+$/);
});
