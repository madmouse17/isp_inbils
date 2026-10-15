import type { Page } from '@playwright/test';

import { inertiaProps, loginAs, logout, unique } from './support/demo';
import { expect, test } from './support/fixtures';
import { totp } from './support/totp';

/**
 * Two-factor authentication from the user's side (docs/SECURITY.md section 3): enrol from the
 * Profile, see the recovery codes once, then log in with an authenticator code and with a recovery
 * code; an admin resets a colleague. A disposable staff user keeps the demo accounts untouched for
 * every other spec. The reset test needs `users.two_factor.reset` on the admin role, which the
 * integration step adds to RolePermissionSeeder; until then it reports itself as skipped.
 */

const userPassword = 'Password123!';
const invalidCode = 'Kode autentikasi dua langkah tidak valid.';

test('@two-factor the authenticator helper matches the RFC 6238 test vector', () => {
    // RFC 6238 appendix B, SHA-1, T = 59 s: 94287082, whose last six digits are the code.
    expect(totp('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 59_000)).toBe('287082');
});

test('@two-factor a user enrols from the profile, then logs in with a code and with a recovery code', async ({
    page,
}) => {
    test.setTimeout(150_000);
    const email = `${unique('qa-2fa')}@example.test`;

    await loginAs(page, 'admin');
    await createStaffUser(page, email);
    await logout(page);

    await submitPassword(page, email);
    await expect(page).toHaveURL(/\/admin\/dashboard$/);

    const { secret, codes } = await enrol(page);

    // The confirming code is spent, so the next step's code is the fresh one.
    const spentAtLogin = totp(secret, Date.now() + 30_000);

    await startLogin(page, email);
    // The password alone opened no session: the dashboard still sends the user to log in.
    await page.goto('/admin/dashboard');
    await expect(page).toHaveURL(/\/login$/);
    await page.goto('/two-factor-challenge');

    await submitCode(page, 'Kode autentikasi', '000000');
    await expect(page.getByText(invalidCode)).toBeVisible();
    await expect(page).toHaveURL(/\/two-factor-challenge$/);

    await submitCode(page, 'Kode autentikasi', spentAtLogin);
    await expectDashboard(page);

    await startLogin(page, email);
    await submitCode(page, 'Kode autentikasi', spentAtLogin);
    await expect(page.getByText(invalidCode), 'the same code is refused twice').toBeVisible();

    await page.getByRole('button', { name: 'Pakai kode pemulihan' }).click();
    await submitCode(page, 'Kode pemulihan', codes[0]);
    await expectDashboard(page);

    await startLogin(page, email);
    await page.getByRole('button', { name: 'Pakai kode pemulihan' }).click();
    await submitCode(page, 'Kode pemulihan', codes[0]);
    await expect(page.getByText(invalidCode), 'a recovery code works once').toBeVisible();
    await submitCode(page, 'Kode pemulihan', codes[1]);
    await expectDashboard(page);

    await turnOff(page);
});

test('@two-factor an admin resets a colleague who lost the authenticator', async ({ page }) => {
    test.setTimeout(150_000);
    const email = `${unique('qa-2fa-reset')}@example.test`;

    await loginAs(page, 'admin');
    await createStaffUser(page, email);
    await logout(page);
    await submitPassword(page, email);
    await expect(page).toHaveURL(/\/admin\/dashboard$/);
    await enrol(page);
    await logout(page);

    await loginAs(page, 'admin');
    const dashboard = await inertiaProps(page, '/admin/dashboard');
    test.skip(
        !dashboard.props?.auth?.user?.permissions?.includes('users.two_factor.reset'),
        'RolePermissionSeeder does not grant users.two_factor.reset to admin yet (needs-change in the lane report)',
    );

    const found = await inertiaProps(page, `/admin/users?search=${encodeURIComponent(email)}`);
    const colleagueId = found.props?.users?.data?.[0]?.id;
    const adminId = (await inertiaProps(page, '/admin/users?search=admin%40demo.inbils.test')).props
        ?.users?.data?.[0]?.id;
    expect(colleagueId).toBeTruthy();
    expect(adminId).toBeTruthy();

    // Columns: Nama, Email, Peran, Status, 2FA, Aksi. The Status cell also says Aktif, so the 2FA cell is picked by position.
    const twoFactorCell = () =>
        page.getByRole('row').filter({ hasText: email }).getByRole('cell').nth(4);

    await page.goto(`/admin/users?search=${encodeURIComponent(email)}`);
    await expect(twoFactorCell(), 'the users list shows who has two-factor on').toHaveText('Aktif');

    await page.goto(`/admin/users/${adminId}`);
    await expect(page.getByText('Autentikasi dua langkah', { exact: true })).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'Atur ulang 2FA' }),
        'no reset on your own page',
    ).toHaveCount(0);

    await page.goto(`/admin/users/${colleagueId}`);
    const twoFactor = page.getByRole('region', { name: 'Autentikasi dua langkah' });
    await expect(twoFactor.getByText('Aktif', { exact: true })).toBeVisible();
    await twoFactor.getByRole('button', { name: 'Atur ulang 2FA' }).click();
    await expect(page.getByRole('alertdialog')).toContainText(
        'Atur ulang autentikasi dua langkah untuk',
    );
    await page
        .getByRole('alertdialog')
        .getByRole('button', { name: 'Atur ulang', exact: true })
        .click();
    await expect(twoFactor.getByText('Nonaktif', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Atur ulang 2FA' })).toHaveCount(0);

    await page.goto(`/admin/users?search=${encodeURIComponent(email)}`);
    await expect(twoFactorCell(), 'the users list shows the reset').toHaveText('Nonaktif');

    // The colleague logs in with the password alone.
    await logout(page);
    await submitPassword(page, email);
    await expectDashboard(page);
});

async function createStaffUser(page: Page, email: string) {
    await page.goto('/admin/users/create');
    await expect(
        page.getByRole('main').getByRole('heading', { name: 'Buat Pengguna', level: 1 }),
    ).toBeVisible({ timeout: 15_000 });
    await page.getByLabel('Nama').fill('QA Two-Factor');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Kata sandi').fill(userPassword);
    await page.getByRole('checkbox', { name: 'staff', exact: true }).check();
    await page.getByRole('button', { name: 'Buat', exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/users\/?$/);
}

async function submitPassword(page: Page, email: string) {
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Kata sandi').fill(userPassword);
    await page.getByRole('button', { name: 'Masuk' }).click();
}

/** A fresh browser session logs in with the password and lands on the challenge. */
async function startLogin(page: Page, email: string) {
    await logout(page);
    await submitPassword(page, email);
    await expect(page).toHaveURL(/\/two-factor-challenge$/);
    await expect(
        page.getByRole('heading', { name: 'Autentikasi dua langkah', exact: true }),
    ).toBeVisible();
}

async function submitCode(page: Page, label: string, code: string) {
    await page.getByLabel(label).fill(code);
    await page.getByRole('button', { name: 'Masuk' }).click();
}

async function expectDashboard(page: Page) {
    await expect(page).toHaveURL(/\/admin\/dashboard$/);
    await expect(page.getByRole('main').getByRole('heading', { name: 'Dasbor' })).toBeVisible({
        timeout: 15_000,
    });
}

/** Password confirmation comes first, as for every change to the second factor. */
async function confirmPassword(page: Page) {
    await expect(page).toHaveURL(/\/confirm-password$/);
    await page.getByLabel('Kata sandi').fill(userPassword);
    await page.getByRole('button', { name: 'Konfirmasi' }).click();
    await expect(page).toHaveURL(/\/profile$/);
}

async function enrol(page: Page): Promise<{ secret: string; codes: string[] }> {
    await page.goto('/profile');
    await expect(
        page.getByRole('heading', { name: 'Autentikasi dua langkah', exact: true }),
    ).toBeVisible();

    await page
        .getByRole('button', { name: 'Aktifkan autentikasi dua langkah', exact: true })
        .click();
    await confirmPassword(page);
    await page
        .getByRole('button', { name: 'Aktifkan autentikasi dua langkah', exact: true })
        .click();

    await expect(
        page.getByRole('img', { name: 'Kode QR untuk kunci pengaturan di bawah' }),
    ).toBeVisible();
    const secret = (await page.locator('code').first().innerText()).replace(/\s/g, '');
    expect(secret).toMatch(/^[A-Z2-7]{32}$/);

    await page.getByLabel('Kode autentikasi').fill('000000');
    await page.getByRole('button', { name: 'Konfirmasi dan aktifkan' }).click();
    await expect(page.getByText(invalidCode)).toBeVisible();
    await expect(
        page.getByRole('img', { name: 'Kode QR untuk kunci pengaturan di bawah' }),
        'a wrong code leaves the QR code on screen',
    ).toBeVisible();

    await page.getByLabel('Kode autentikasi').fill(totp(secret));
    await page.getByRole('button', { name: 'Konfirmasi dan aktifkan' }).click();
    await expect(page.getByRole('heading', { name: 'Kode pemulihan Anda' })).toBeVisible();

    const codes = await page
        .getByRole('list', { name: 'Kode pemulihan' })
        .getByRole('listitem')
        .allInnerTexts();
    expect(codes).toHaveLength(8);

    // Neither secret nor codes in storage, history state or the address bar.
    const stored = await page.evaluate(() =>
        JSON.stringify({
            history: window.history.state,
            local: { ...window.localStorage },
            session: { ...window.sessionStorage },
            href: window.location.href,
        }),
    );
    for (const value of [secret, ...codes]) {
        expect(stored).not.toContain(value);
    }

    await page.getByRole('button', { name: 'Saya sudah menyimpan kode ini' }).click();
    await expect(page.getByRole('heading', { name: 'Kode pemulihan Anda' })).toBeHidden();
    await expect(page.getByText('Sisa kode pemulihan: 8.')).toBeVisible();

    // A reload shows neither the setup key nor the codes, anywhere in the page.
    await page.reload();
    await expect(page.getByText('Sisa kode pemulihan: 8.')).toBeVisible();
    const html = await page.content();
    for (const value of [secret, ...codes]) {
        expect(html).not.toContain(value);
    }

    return { secret, codes };
}

async function turnOff(page: Page) {
    await page.goto('/profile');
    await page
        .getByRole('button', { name: 'Nonaktifkan autentikasi dua langkah', exact: true })
        .click();
    await page
        .getByRole('alertdialog')
        .getByRole('button', { name: 'Nonaktifkan', exact: true })
        .click();
    // The new session has not confirmed the password yet.
    await confirmPassword(page);

    await page
        .getByRole('button', { name: 'Nonaktifkan autentikasi dua langkah', exact: true })
        .click();
    await page
        .getByRole('alertdialog')
        .getByRole('button', { name: 'Nonaktifkan', exact: true })
        .click();
    await expect(
        page.getByRole('button', { name: 'Aktifkan autentikasi dua langkah', exact: true }),
    ).toBeVisible();
}
