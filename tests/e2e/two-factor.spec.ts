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
const invalidCode = 'The provided two-factor code was invalid.';

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

    await submitCode(page, 'Authentication code', '000000');
    await expect(page.getByText(invalidCode)).toBeVisible();
    await expect(page).toHaveURL(/\/two-factor-challenge$/);

    await submitCode(page, 'Authentication code', spentAtLogin);
    await expectDashboard(page);

    await startLogin(page, email);
    await submitCode(page, 'Authentication code', spentAtLogin);
    await expect(page.getByText(invalidCode), 'the same code is refused twice').toBeVisible();

    await page.getByRole('button', { name: 'Use a recovery code' }).click();
    await submitCode(page, 'Recovery code', codes[0]);
    await expectDashboard(page);

    await startLogin(page, email);
    await page.getByRole('button', { name: 'Use a recovery code' }).click();
    await submitCode(page, 'Recovery code', codes[0]);
    await expect(page.getByText(invalidCode), 'a recovery code works once').toBeVisible();
    await submitCode(page, 'Recovery code', codes[1]);
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

    await page.goto(`/admin/users?search=${encodeURIComponent(email)}`);
    await expect(
        page.getByRole('row').filter({ hasText: email }).getByText('On', { exact: true }),
        'the users list shows who has two-factor on',
    ).toBeVisible();

    await page.goto(`/admin/users/${adminId}`);
    await expect(page.getByText('Two-factor authentication', { exact: true })).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'Reset two-factor' }),
        'no reset on your own page',
    ).toHaveCount(0);

    await page.goto(`/admin/users/${colleagueId}`);
    await expect(page.getByText('On', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Reset two-factor' }).click();
    await expect(page.getByRole('alertdialog')).toContainText(
        'Reset two-factor authentication for',
    );
    await page.getByRole('alertdialog').getByRole('button', { name: 'Reset' }).click();
    await expect(page.getByText('Off', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Reset two-factor' })).toHaveCount(0);

    await page.goto(`/admin/users?search=${encodeURIComponent(email)}`);
    await expect(
        page.getByRole('row').filter({ hasText: email }).getByText('Off', { exact: true }),
        'the users list shows the reset',
    ).toBeVisible();

    // The colleague logs in with the password alone.
    await logout(page);
    await submitPassword(page, email);
    await expectDashboard(page);
});

async function createStaffUser(page: Page, email: string) {
    await page.goto('/admin/users/create');
    await expect(
        page.getByRole('main').getByRole('heading', { name: 'Create User', level: 1 }),
    ).toBeVisible({ timeout: 15_000 });
    await page.getByLabel('Name').fill('QA Two-Factor');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(userPassword);
    await page.getByRole('checkbox', { name: 'staff', exact: true }).check();
    await page.getByRole('button', { name: 'Create' }).click();
    await expect(page).toHaveURL(/\/admin\/users\/?$/);
}

async function submitPassword(page: Page, email: string) {
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(userPassword);
    await page.getByRole('button', { name: 'Log in' }).click();
}

/** A fresh browser session logs in with the password and lands on the challenge. */
async function startLogin(page: Page, email: string) {
    await logout(page);
    await submitPassword(page, email);
    await expect(page).toHaveURL(/\/two-factor-challenge$/);
    await expect(page.getByRole('heading', { name: 'Two-factor authentication' })).toBeVisible();
}

async function submitCode(page: Page, label: string, code: string) {
    await page.getByLabel(label).fill(code);
    await page.getByRole('button', { name: 'Log in' }).click();
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
    await page.getByLabel('Password').fill(userPassword);
    await page.getByRole('button', { name: 'Confirm' }).click();
    await expect(page).toHaveURL(/\/profile$/);
}

async function enrol(page: Page): Promise<{ secret: string; codes: string[] }> {
    await page.goto('/profile');
    await expect(page.getByRole('heading', { name: 'Two-Factor Authentication' })).toBeVisible();

    await page.getByRole('button', { name: 'Enable two-factor authentication' }).click();
    await confirmPassword(page);
    await page.getByRole('button', { name: 'Enable two-factor authentication' }).click();

    await expect(page.getByRole('img', { name: 'QR code for the setup key below' })).toBeVisible();
    const secret = (await page.locator('code').first().innerText()).replace(/\s/g, '');
    expect(secret).toMatch(/^[A-Z2-7]{32}$/);

    await page.getByLabel('Authentication code').fill('000000');
    await page.getByRole('button', { name: 'Confirm and turn on' }).click();
    await expect(page.getByText(invalidCode)).toBeVisible();
    await expect(
        page.getByRole('img', { name: 'QR code for the setup key below' }),
        'a wrong code leaves the QR code on screen',
    ).toBeVisible();

    await page.getByLabel('Authentication code').fill(totp(secret));
    await page.getByRole('button', { name: 'Confirm and turn on' }).click();
    await expect(page.getByRole('heading', { name: 'Your recovery codes' })).toBeVisible();

    const codes = await page
        .getByRole('list', { name: 'Recovery codes' })
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

    await page.getByRole('button', { name: 'I have saved these codes' }).click();
    await expect(page.getByRole('heading', { name: 'Your recovery codes' })).toBeHidden();
    await expect(page.getByText('8 recovery codes left.')).toBeVisible();

    // A reload shows neither the setup key nor the codes, anywhere in the page.
    await page.reload();
    await expect(page.getByText('8 recovery codes left.')).toBeVisible();
    const html = await page.content();
    for (const value of [secret, ...codes]) {
        expect(html).not.toContain(value);
    }

    return { secret, codes };
}

async function turnOff(page: Page) {
    await page.goto('/profile');
    await page.getByRole('button', { name: 'Turn off two-factor authentication' }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Turn off' }).click();
    // The new session has not confirmed the password yet.
    await confirmPassword(page);

    await page.getByRole('button', { name: 'Turn off two-factor authentication' }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Turn off' }).click();
    await expect(
        page.getByRole('button', { name: 'Enable two-factor authentication' }),
    ).toBeVisible();
}
