import type { Locator, Page } from '@playwright/test';
import { expect, test } from './support/fixtures';
import { e2eCustomerName, expectDetail, inertiaProps, loginAs } from './support/demo';
import {
    expectSupportAccessRedirects,
    loginAsPlatformOperator,
    platformFixture,
} from './support/platform';

async function expectReset(
    page: Page,
    path: string,
    openName: string,
    dialogName: string,
    dirty: Array<[string, string]>,
    close: (dialog: Locator) => Promise<void>,
) {
    await page.goto(path);
    const open = page.getByRole('button', { name: openName }).first();
    await open.click();
    let dialog = page.getByRole('dialog', { name: dialogName });
    await expect(dialog).toBeVisible();
    for (const [label, value] of dirty)
        await dialog.getByRole('textbox', { name: label, exact: true }).fill(value);
    await close(dialog);
    await expect(dialog).toBeHidden();

    await open.click();
    dialog = page.getByRole('dialog', { name: dialogName });
    for (const [label] of dirty)
        await expect(dialog.getByRole('textbox', { name: label, exact: true })).toHaveValue('');
    await dialog.getByRole('button', { name: 'Batal' }).click();
    await expect(dialog).toBeHidden();
}

async function customerId(page: Page): Promise<string> {
    const response = await inertiaProps(
        page,
        `/admin/customers?search=${encodeURIComponent(e2eCustomerName)}`,
    );
    const customer = (
        response.props?.customers?.data as Array<{ id: string; name: string }> | undefined
    )?.find(({ name }) => name === e2eCustomerName);
    if (!customer) throw new Error(`${e2eCustomerName} fixture not found`);

    return customer.id;
}

async function switchToSubsidiary(page: Page) {
    await page.getByRole('button', { name: /^Ganti perusahaan, saat ini:/ }).click();
    await page.getByRole('menuitemradio', { name: /^E2E Subsidiary Company/ }).click();
    await expect(
        page.getByRole('status').filter({ hasText: 'Sekarang bekerja di E2E Subsidiary Company.' }),
    ).toBeVisible();
}

test.describe('@flow-audit-w1 operations', () => {
    test.describe.configure({ mode: 'serial' });

    test('resets the employee dialog after cancel', async ({ page }) => {
        await loginAs(page, 'admin');
        await expectReset(
            page,
            '/admin/employees',
            'Buat Karyawan',
            'Buat Karyawan',
            [
                ['Telepon', '081299900001'],
                ['Catatan', 'Tidak boleh tersisa setelah dialog ditutup'],
            ],
            async (dialog) => dialog.getByRole('button', { name: 'Batal' }).click(),
        );
    });

    test('resets the vehicle dialog after close', async ({ page }) => {
        await loginAs(page, 'admin');
        await expectReset(
            page,
            '/admin/vehicles',
            'Buat Kendaraan',
            'Buat Kendaraan',
            [
                ['Nomor polisi', 'QA-RESET'],
                ['Catatan', 'Tidak boleh tersisa setelah dialog ditutup'],
            ],
            async (dialog) => dialog.getByRole('button', { name: 'Tutup' }).click(),
        );
    });

    test('resets the customer-address dialog after Escape', async ({ page }) => {
        await loginAs(page, 'admin');
        await expectReset(
            page,
            `/admin/customers/${await customerId(page)}/addresses`,
            'Tambah alamat',
            'Tambah Alamat',
            [
                ['Label', 'Alamat sementara'],
                ['Alamat', 'Jl. Tidak Disimpan 1'],
            ],
            async () => page.keyboard.press('Escape'),
        );
    });

    test('resets the document dialog after cancel', async ({ page }) => {
        await loginAs(page, 'admin');
        await expectReset(
            page,
            '/admin/documents',
            'Buat Jenis Dokumen',
            'Buat Jenis Dokumen',
            [
                ['Nama', 'Dokumen sementara'],
                ['Kode', 'QA-RESET-DOC'],
            ],
            async (dialog) => dialog.getByRole('button', { name: 'Batal' }).click(),
        );
    });

    test('names the product and location when SPK approval lacks stock', async ({ page }) => {
        await loginAs(page, 'manager');
        await page.goto('/admin/spk?search=E2E-W1-SHORT-STOCK');
        await page.getByRole('link', { name: 'Buka SPK E2E-W1-SHORT-STOCK' }).click();
        await expectDetail(page, 'Status', 'Menunggu review');

        await page.getByRole('button', { name: 'Setujui', exact: true }).click();
        const dialog = page.getByRole('dialog', {
            name: 'Setujui SPK E2E-W1-SHORT-STOCK?',
        });
        await expect(dialog).toContainText('Pekerjaan dan penggunaan material disahkan permanen.');
        await dialog.getByRole('button', { name: 'Setujui', exact: true }).click();

        const message =
            'Stok Kabel Fiber Optic Single di AUDIT POP Bandung Timur tersisa 0 m, dibutuhkan 18 m.';
        const stockToast = page
            .getByLabel('Notifikasi')
            .getByRole('alert')
            .filter({ hasText: message });
        await expect(stockToast).toHaveCount(1);
        await expect(stockToast).toBeVisible();
        await expectDetail(page, 'Status', 'Menunggu review');
    });

    test('redirects a subsidiary to reports with the head-company message', async ({ page }) => {
        await loginAs(page, 'admin');
        await switchToSubsidiary(page);
        await page.goto('/admin/reports/consolidated');

        await expect(page).toHaveURL(/\/admin\/reports$/);
        await expect(
            page.getByRole('status').filter({
                hasText:
                    'Metrik Konsolidasi hanya tersedia di perusahaan induk Inbils Demo Company. Ganti perusahaan lewat pemilih di menu samping.',
            }),
        ).toHaveCount(1);
    });

    test('renders the profile refusal page during support access', async ({
        page,
        expectHttpError,
    }) => {
        expectSupportAccessRedirects(expectHttpError);
        expectHttpError({ url: '/profile', method: 'GET', status: 403 });
        await loginAsPlatformOperator(page);
        await page.goto(`/platform?search=${encodeURIComponent(platformFixture.tenantCode!)}`);
        await page.getByRole('link', { name: 'E2E Suspend Tenant' }).click();
        await page.getByRole('button', { name: 'Masuk sebagai E2E Suspend Admin' }).click();

        const start = page.getByRole('alertdialog');
        await start.getByLabel('Alasan').fill('Pemeriksaan profil selama akses dukungan');
        await start.getByLabel('Kata sandi operator').fill(platformFixture.password!);
        await start.getByRole('button', { name: 'Mulai akses dukungan' }).click();
        await expect(page).toHaveURL(/\/admin\/dashboard$/);

        await page.goto('/profile');
        await expect(page.getByRole('heading', { name: 'Akses ditolak', level: 1 })).toBeVisible();
        await expect(
            page.getByText('Pengaturan akun pribadi tidak tersedia selama akses dukungan.', {
                exact: true,
            }),
        ).toBeVisible();
        await expect(page.getByText('Kode galat 403', { exact: true })).toBeVisible();
        await page.getByRole('button', { name: 'Kembali' }).click();
        await expect(page).toHaveURL(/\/admin\/dashboard$/);
        await page
            .getByRole('region', { name: 'Akses dukungan' })
            .getByRole('button', { name: 'Keluar' })
            .click();
        await expect(page).toHaveURL(/\/platform\/companies\/[0-9A-HJKMNP-TV-Z]{26}$/i);
    });

    test('persists and restores the inventory digest switch', async ({ page }) => {
        await loginAs(page, 'admin');
        await page.goto('/profile');

        const digest = page.getByRole('switch', {
            name: 'Ringkasan harian permintaan barang dan retur',
        });
        await expect(page.getByRole('heading', { name: 'Notifikasi email' })).toBeVisible();
        await expect(digest).not.toBeChecked();

        await digest.click();
        await expect(digest).toBeChecked();
        await expect(
            page.getByRole('status').filter({ hasText: 'Preferensi notifikasi disimpan.' }),
        ).toBeVisible();
        await page.reload();
        await expect(digest).toBeChecked();

        await digest.click();
        await expect(digest).not.toBeChecked();
        await expect(
            page.getByRole('status').filter({ hasText: 'Preferensi notifikasi disimpan.' }),
        ).toBeVisible();
        await page.reload();
        await expect(digest).not.toBeChecked();
    });
});
