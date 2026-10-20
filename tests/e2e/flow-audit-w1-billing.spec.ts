import type { Page } from '@playwright/test';
import { expect, test } from './support/fixtures';
import {
    demoPassword,
    inertiaProps,
    loginAs,
    logout,
    refreshSession,
    selectSearchOption,
} from './support/demo';

const CREATOR_EMAIL = 'billing-creator@qa.inbils.test';

async function openInvoice(page: Page, number: string) {
    await page.goto(`/admin/invoices?search=${encodeURIComponent(number)}`);
    await page.getByRole('link', { name: number, exact: true }).click();
    await expect(page.getByRole('heading', { name: `Tagihan ${number}`, level: 1 })).toBeVisible();
}

async function loginAsBillingCreator(page: Page) {
    await page.goto('/login');
    await page.getByLabel('Email').fill(CREATOR_EMAIL);
    await page.getByLabel(/^Kata sandi( \*)?$/).fill(demoPassword);
    await page.getByRole('button', { name: 'Masuk' }).click();
    await expect(page.getByRole('heading', { name: 'Dasbor', level: 1 })).toBeVisible();
}

test.describe('@flow-audit-w1 billing', () => {
    test.describe.configure({ mode: 'serial' });

    test('shows whole rupiah and the installation draft dashboard card', async ({ page }) => {
        await loginAs(page, 'admin');

        const finance = page.getByRole('region', { name: 'Keuangan' });
        const card = finance.getByRole('link', { name: /Tagihan draf menunggu dikirim/ });
        await expect(card).toContainText('3');
        await expect(card).toContainText('Nilai');
        await expect(card).toContainText('Rp 1.146.702');
        await expect(card).toHaveAttribute('href', /\/admin\/invoices\?status=draft$/);

        await openInvoice(page, 'E2E-W1-WHOLE');
        await expect(page.getByText(/^Total:\s*Rp\s*147\.702$/)).toBeVisible();
        await expect(
            page.getByRole('row').filter({ hasText: 'E2E whole rupiah line' }),
        ).toContainText(/Rp\s*147\.702/);
        await expect(page.getByText(/Rp\s*147\.702,00/)).toHaveCount(0);
    });

    test('refuses a future billing period', async ({ page, expectHttpError }) => {
        expectHttpError({
            url: '/admin/invoices/generate-preview',
            method: 'POST',
            status: 422,
        });
        await loginAs(page, 'admin');
        await page.goto('/admin/invoices');
        await page.getByRole('button', { name: 'Buat tagihan bulanan', exact: true }).click();

        const dialog = page.getByRole('dialog', { name: 'Buat tagihan bulanan' });
        const period = dialog.getByLabel('Periode', { exact: true });
        await period.fill('9999-12');
        await period.press('Tab');
        await dialog.getByRole('button', { name: 'Lihat pratinjau' }).click();

        await expect(
            dialog
                .getByRole('alert')
                .filter({ hasText: 'Periode tagihan paling lambat bulan berjalan.' }),
        ).toBeVisible();
        await expect(dialog.getByText(/tagihan akan dibuat/)).toHaveCount(0);
    });

    test('keeps a numeric date and blocks an invalid date from saving', async ({ page }) => {
        await loginAs(page, 'admin');
        await page.goto('/admin/invoices/create');
        await selectSearchOption(page, 'Pelanggan', 'E2E Linked Customer');

        const issueDate = page.getByLabel('Tanggal terbit', { exact: true });
        await issueDate.fill('20/10/2026');
        await issueDate.press('Tab');
        await expect(issueDate).toHaveValue('20/10/2026');
        await expect(issueDate).not.toHaveAttribute('aria-invalid', 'true');

        await issueDate.fill('31/02/2026');
        await issueDate.press('Tab');
        await expect(issueDate).toHaveAttribute('aria-invalid', 'true');
        await expect(
            page.getByRole('alert').filter({ hasText: 'Format tanggal tidak valid.' }),
        ).toBeVisible();

        await page.getByRole('button', { name: 'Simpan', exact: true }).click();
        await expect(page).toHaveURL(/\/admin\/invoices\/create(?:\?.*)?$/);
        await expect(issueDate).toBeFocused();
        await expect(issueDate).toHaveValue('31/02/2026');
    });

    test('limits draft controls to the billing creator and reports an empty send', async ({
        page,
    }) => {
        await loginAsBillingCreator(page);
        await openInvoice(page, 'E2E-W1-WHOLE');

        await expect(page.getByRole('button', { name: 'Tambah item' })).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'Hapus item E2E whole rupiah line' }),
        ).toBeVisible();
        await expect(page.getByRole('button', { name: 'Kirim tagihan' })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Batalkan tagihan' })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Catat pembayaran' })).toHaveCount(0);

        await logout(page);
        await loginAs(page, 'admin');
        await openInvoice(page, 'E2E-W1-EMPTY');
        const path = new URL(page.url()).pathname;
        const send = page.getByRole('button', { name: 'Kirim tagihan' });
        await expect(send).toBeDisabled();
        await expect(send).toHaveAccessibleDescription(
            'Tambahkan minimal satu item sebelum mengirim.',
        );

        const props = await inertiaProps(page, path);
        const companyId = String(props.props?.company?.id ?? '');
        expect(companyId).toMatch(/^[0-9A-HJKMNP-TV-Z]{26}$/i);
        const response = await page.request.post(`${path}/send`, {
            headers: {
                'X-XSRF-TOKEN': await refreshSession(page, path),
                'X-Inertia': 'true',
                'X-Inbils-Company': companyId,
            },
            maxRedirects: 0,
        });
        expect(response.status()).toBe(302);

        await page.reload();
        const denied = page
            .getByLabel('Notifikasi')
            .getByRole('alert')
            .filter({ hasText: 'Tagihan tanpa item atau bernilai Rp 0 tidak bisa dikirim.' });
        await expect(denied).toHaveCount(1);
        await expect(denied).toBeVisible();
        await expect(page).toHaveURL(path);
    });

    test('opens the refund dialog from both finance views and records the refund', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        await page.goto('/admin/billing/receivables');

        const exceptions = page
            .getByRole('alert')
            .filter({ hasText: 'pembayaran online perlu dicek' });
        await expect(exceptions.getByRole('link', { name: 'E2E-W1-REFUND' })).toBeVisible();
        await exceptions
            .getByRole('button', {
                name: 'Tandai dikembalikan untuk tagihan E2E-W1-REFUND',
            })
            .click();

        let dialog = page.getByRole('dialog', { name: 'Tandai pembayaran dikembalikan' });
        await expect(dialog).toContainText(
            'Isi setelah uang dikembalikan lewat dasbor Midtrans atau transfer bank. Tindakan ini dicatat di log audit.',
        );
        await expect(dialog.getByLabel('Nomor referensi pengembalian')).toBeVisible();
        await expect(dialog.getByLabel('Catatan')).toBeVisible();
        await dialog.getByRole('button', { name: 'Batal' }).click();

        await openInvoice(page, 'E2E-W1-REFUND');
        const attempt = page.getByRole('row').filter({ hasText: 'E2E-W1-REFUND-TRX' });
        await expect(attempt).toContainText('Perlu dicek');
        await attempt.getByRole('button', { name: 'Tandai dikembalikan' }).click();

        dialog = page.getByRole('dialog', { name: 'Tandai pembayaran dikembalikan' });
        await dialog.getByLabel('Nomor referensi pengembalian').fill('E2E-W1-REF-001');
        await dialog.getByLabel('Catatan').fill('Dana dikembalikan melalui dasbor Midtrans.');
        await dialog.getByRole('button', { name: 'Simpan', exact: true }).click();

        await expect(dialog).toBeHidden();
        await expect(
            page.getByRole('status').filter({ hasText: 'Pembayaran ditandai dikembalikan.' }),
        ).toBeVisible();
        await expect(attempt).toContainText('Dikembalikan');
        await expect(attempt).toContainText('oleh Demo Admin');
        await expect(attempt).toContainText('Ref E2E-W1-REF-001');
        await expect(attempt.getByRole('button', { name: 'Tandai dikembalikan' })).toHaveCount(0);
    });
});
