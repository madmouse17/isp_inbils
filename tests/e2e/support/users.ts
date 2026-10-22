import type { Page } from '@playwright/test';

import { inertiaProps } from './demo';
import { expect } from './fixtures';

/**
 * A login the way an admin makes one: name, email and roles, then "Kirim undangan". The invitation goes to the `array`
 * mailer of the E2E app, where no spec can open it, so a spec that signs in as the new user gives it a first password on
 * the edit form, which still takes one.
 */
export async function createUser(
    page: Page,
    user: { name: string; email: string; roles?: string[]; password?: string },
) {
    await page.goto('/admin/users/create');
    await expect(
        page.getByRole('main').getByRole('heading', { name: 'Buat Pengguna', level: 1 }),
    ).toBeVisible({ timeout: 15_000 });
    await page.getByLabel('Nama').fill(user.name);
    await page.getByLabel('Email').fill(user.email);
    for (const role of user.roles ?? []) {
        await page.getByRole('checkbox', { name: role, exact: true }).check();
    }
    await page.getByRole('button', { name: 'Kirim undangan' }).click();
    await expect(page).toHaveURL(/\/admin\/users\/?$/);

    if (!user.password) return;

    const found = await inertiaProps(page, `/admin/users?search=${encodeURIComponent(user.email)}`);
    const id = found.props?.users?.data?.[0]?.id;
    expect(id, `the user list holds ${user.email}`).toBeTruthy();

    await page.goto(`/admin/users/${id}/edit`);
    await page.getByLabel(/^Kata sandi( \*)?$/).fill(user.password);
    await page.getByRole('button', { name: 'Simpan' }).click();
    await expect(page).toHaveURL(/\/admin\/users\/?$/);
}
