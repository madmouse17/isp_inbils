import type { Page } from '@playwright/test';
import { expect, test } from './support/fixtures';
import { inertiaProps, loginAs } from './support/demo';

async function subscriptionId(page: Page): Promise<string> {
    const customers = await inertiaProps(page, '/admin/customers?search=E2E%20WhatsApp%20Customer');
    const customer = (
        customers.props?.customers?.data as Array<{ id: string; name: string }> | undefined
    )?.find(({ name }) => name === 'E2E WhatsApp Customer');
    if (!customer) throw new Error('E2E WhatsApp Customer fixture not found');

    const subscriptions = await inertiaProps(
        page,
        `/admin/customers/${customer.id}/subscriptions?search=E2E-WA-SUB`,
    );
    const subscription = (
        subscriptions.props?.subscriptions?.data as Array<{ id: string; code: string }> | undefined
    )?.find(({ code }) => code === 'E2E-WA-SUB');
    if (!subscription) throw new Error('E2E-WA-SUB fixture not found');

    return subscription.id;
}

test.describe('@flow-audit-w1 network', () => {
    test.describe.configure({ mode: 'serial' });

    test('shows both isolir explanations and all configured actions', async ({ page }) => {
        await page.goto('/isolir/DEMO');

        await expect(page).toHaveTitle(/Layanan dibatasi/);
        await expect(
            page.getByRole('heading', {
                name: 'Layanan internet Anda sedang dibatasi',
                level: 1,
            }),
        ).toBeVisible();

        const bills = page.getByRole('region', { name: 'Tagihan belum dibayar' });
        await expect(bills).toContainText(
            'Ada tagihan yang belum dibayar? Bayar lewat aplikasi Inbils Demo Company. Layanan aktif kembali otomatis setelah pembayaran diterima.',
        );
        await expect(
            bills.getByRole('link', { name: 'Buka aplikasi Inbils Demo Company' }),
        ).toHaveAttribute(
            'href',
            'https://play.google.com/store/apps/details?id=id.inbils.customer',
        );

        const otherReason = page.getByRole('region', { name: 'Dibatasi karena alasan lain' });
        await expect(otherReason).toContainText(
            'Dibatasi oleh Inbils Demo Company karena alasan lain? Hubungi kami.',
        );
        await expect(otherReason.getByRole('link', { name: /^Telepon/ })).toHaveAttribute(
            'href',
            'tel:081234567890',
        );
        await expect(otherReason.getByRole('link', { name: 'WhatsApp' })).toHaveAttribute(
            'href',
            'https://wa.me/6281234567890',
        );
        await expect(page.getByRole('link', { name: /Masuk/ })).toHaveCount(0);
    });

    test('explains a RADIUS-off resync and reports its deferred delivery', async ({ page }) => {
        await loginAs(page, 'admin');
        const id = await subscriptionId(page);
        await page.goto(`/admin/subscriptions/${id}/network-account`);

        await expect(page.getByRole('heading', { name: 'Akses jaringan', level: 1 })).toBeVisible();
        await expect(page.getByText('Langganan E2E-WA-SUB')).toBeVisible();
        await page.getByRole('button', { name: 'Sinkron ulang' }).click();

        const dialog = page.getByRole('alertdialog', {
            name: 'Sinkron ulang akun e2e-wa@demo?',
        });
        await expect(dialog).toContainText(
            'RADIUS belum dikonfigurasi. Perubahan disimpan dan dikirim ke router setelah RADIUS aktif.',
        );
        await dialog.getByRole('button', { name: 'Sinkron ulang' }).click();

        await expect(
            page.getByRole('status').filter({
                hasText:
                    'Perubahan disimpan, tetapi belum dikirim ke router karena RADIUS belum dikonfigurasi untuk perusahaan ini.',
            }),
        ).toBeVisible();
    });

    test('shows a neutral NAS state while RADIUS is off', async ({ page }) => {
        await loginAs(page, 'admin');
        await page.goto('/admin/nas-devices');

        const row = page.getByRole('row').filter({ hasText: 'E2E-ROUTER-002' });
        await expect(row).toContainText('10.99.9.2');
        await expect(row).toContainText('RADIUS belum dikonfigurasi');
        await expect(row).not.toContainText('Gagal ditulis');
        await expect(row).not.toContainText('Menunggu ditulis');
    });

    test('does not promise a disconnect in the RADIUS-off block dialog', async ({ page }) => {
        await loginAs(page, 'admin');
        const id = await subscriptionId(page);
        await page.goto(`/admin/subscriptions/${id}`);

        const trigger = page.getByRole('button', { name: 'Blokir internet', exact: true });
        await trigger.click();
        const dialog = page.getByRole('dialog', { name: 'Blokir internet E2E-WA-SUB?' });
        await expect(dialog).toContainText(
            'RADIUS belum dikonfigurasi. Blokir disimpan dan dikirim ke router setelah RADIUS aktif. Tagihan tetap berjalan dan pembayaran tidak mencabut blokir. Di aplikasi pelanggan tertulis Dibatasi.',
        );
        await expect(dialog.getByLabel('Alasan')).toBeVisible();
        await expect(
            dialog.getByRole('button', { name: 'Blokir internet', exact: true }),
        ).toBeDisabled();
        await dialog.getByRole('button', { name: 'Batal' }).click();
        await expect(dialog).toBeHidden();
        await expect(trigger).toBeFocused();
    });
});
