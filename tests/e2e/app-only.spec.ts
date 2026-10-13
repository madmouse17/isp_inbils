import { loginAs, type DemoRole } from './support/demo';
import { expect, test } from './support/fixtures';

/**
 * Technician and customer accounts work in the Android apps. On the web they land on /app-only,
 * every admin or portal URL sends them back there, and only the account page (password, 2FA)
 * and the Midtrans return page stay reachable. Spec: .scratch/app-only-personas/spec.md R1, Q1.
 */

const appOnlyRoles: { role: Extract<DemoRole, 'technician' | 'customer'>; copy: string }[] = [
    {
        role: 'technician',
        copy: 'Akun teknisi dipakai lewat aplikasi Android Inbils Teknisi. Masuk di aplikasi dengan email dan kata sandi yang sama.',
    },
    {
        role: 'customer',
        copy: 'Akun pelanggan dipakai lewat aplikasi Android Inbils Pelanggan. Masuk di aplikasi dengan email dan kata sandi yang sama.',
    },
];

for (const { role, copy } of appOnlyRoles) {
    test.describe(`@app-only ${role}`, () => {
        test('lands on the app-only page with the app name and no sidebar', async ({ page }) => {
            await loginAs(page, role);

            const main = page.getByRole('main');
            await expect(
                main.getByRole('heading', { name: 'Gunakan aplikasi Inbils', level: 1 }),
            ).toBeVisible();
            await expect(main.getByText(copy)).toBeVisible();
            await expect(page.getByRole('navigation')).toHaveCount(0);
        });

        test('hides the Play Store button while no store URL is configured', async ({ page }) => {
            await loginAs(page, role);

            await expect(page.getByRole('link', { name: 'Buka Play Store' })).toHaveCount(0);
            await expect(page.getByRole('link', { name: 'Kelola akun & 2FA' })).toBeVisible();
            await expect(page.getByRole('button', { name: 'Keluar' })).toBeVisible();
        });

        test('sends admin and portal URLs back to the app-only page', async ({ page }) => {
            await loginAs(page, role);

            for (const path of ['/admin/dashboard', '/admin/tickets', '/admin/my-work']) {
                await page.goto(path);
                await expect(page, `${path} must redirect`).toHaveURL(/\/app-only$/);
            }
        });

        test('keeps the account page reachable from the app-only page', async ({ page }) => {
            await loginAs(page, role);

            const link = page.getByRole('link', { name: 'Kelola akun & 2FA' });
            await link.focus();
            await expect(link).toBeFocused();
            await link.click();

            await expect(page).toHaveURL(/\/profile$/);
            await expect(
                page.getByRole('main').getByRole('heading', { name: 'Profile', level: 1 }),
            ).toBeVisible();
        });

        test('shows the account page without the admin sidebar and links back', async ({
            page,
        }) => {
            await loginAs(page, role);
            await page.goto('/profile');

            const main = page.getByRole('main');
            await expect(main.getByRole('heading', { name: 'Profile', level: 1 })).toBeVisible();
            await expect(page.getByRole('navigation')).toHaveCount(0);
            await expect(page.locator('aside')).toHaveCount(0);

            await main.getByRole('link', { name: 'Kembali ke halaman aplikasi' }).click();
            await expect(page).toHaveURL(/\/app-only$/);
        });

        test('logs out from the app-only page', async ({ page }) => {
            await loginAs(page, role);

            await page.getByRole('button', { name: 'Keluar' }).click();

            // Breeze's logout lands on the welcome page; the session must be gone.
            await expect(page).toHaveURL(/\/(login)?$/);
            await page.goto('/app-only');
            await expect(page).toHaveURL(/\/login$/);
        });
    });
}

test('@app-only the customer still reaches the Midtrans return page', async ({ page }) => {
    await loginAs(page, 'customer');

    const response = await page.request.get('/portal/payments/return');

    expect(response.status()).toBe(200);
});

test('@app-only staff roles are not sent to the app-only page', async ({ page }) => {
    await loginAs(page, 'admin');

    await expect(page).toHaveURL(/\/admin\/dashboard$/);
    await page.goto('/app-only');
    await expect(page).toHaveURL(/\/admin\/dashboard$/);
});
