import type { Page } from '@playwright/test';

import { loginAs, selectSearchOption, unique } from './support/demo';
import { expect, test } from './support/fixtures';
import { loginAsPlatformOperator, platformFixture } from './support/platform';

const DEMO_COMPANY = 'Inbils Demo Company';
const DEMO_CODE = 'DEMO';
const templateNames = [
    'Admin',
    'Manajer',
    'NOC & Layanan Pelanggan',
    'Teknisi',
    'Gudang',
    'Keuangan',
    'Staf',
    'Pelanggan',
] as const;

async function createRole(page: Page, name: string, keyboardOnly = false) {
    await page.goto('/admin/roles/create');
    const template = page.getByLabel('Mulai dari templat');
    const roleName = page.getByLabel('Nama');
    const save = page.getByRole('button', { name: 'Buat peran' });

    if (keyboardOnly) {
        await template.focus();
        await page.keyboard.press('n');
        await expect(template).toHaveValue('noc');
        await roleName.focus();
        // Choosing a template fills the name; a person tabbing in has it selected, so typing replaces it.
        await page.keyboard.press('ControlOrMeta+A');
        await page.keyboard.type(name);
        await save.focus();
        await page.keyboard.press('Enter');
    } else {
        await template.selectOption('noc');
        await roleName.fill(name);
        await save.click();
    }

    await expect(page).toHaveURL(/\/admin\/roles$/);
    await expect(page.getByRole('link', { name, exact: true })).toBeVisible();
}

async function deleteRole(page: Page, name: string, moveTo?: string, keyboardOnly = false) {
    const remove = page.getByRole('button', { name: `Hapus peran ${name}` });
    if (keyboardOnly) {
        await remove.focus();
        await page.keyboard.press('Enter');
    } else {
        await remove.click();
    }

    const dialog = page.getByRole('dialog', { name: `Hapus peran ${name}?` });
    await expect(dialog).toBeVisible();
    if (moveTo) await selectSearchOption(page, 'Pindahkan 1 pengguna ke', moveTo);

    const confirm = dialog.getByRole('button', { name: 'Hapus peran' });
    if (keyboardOnly) {
        await confirm.focus();
        await page.keyboard.press('Enter');
    } else {
        await confirm.click();
    }

    await expect(dialog).toBeHidden();
    await expect(page.getByRole('link', { name, exact: true })).toHaveCount(0);
}

async function openDemoCompany(page: Page) {
    await page.goto(`/platform?search=${encodeURIComponent(DEMO_CODE)}`);
    await page.getByRole('link', { name: DEMO_COMPANY, exact: true }).click();
    await expect(page).toHaveURL(/\/platform\/companies\/[0-9A-HJKMNP-TV-Z]{26}$/i);
}

async function confirmPlatformAction(page: Page, label: string) {
    const dialog = page.getByRole('alertdialog');
    await expect(dialog).toBeVisible();
    // Inside the step-up window of an earlier confirmation the dialog asks for no password (F11-U03).
    const field = dialog.getByLabel('Kata sandi operator');
    if (await field.isVisible()) await field.fill(platformFixture.password!);
    await dialog.getByRole('button', { name: label }).click();
}

test.describe.configure({ mode: 'serial' });

test.describe('@tenant-roles', () => {
    test('admin creates a role from the NOC template, renames it and assigns it in Pengguna', async ({
        page,
    }) => {
        const initial = unique('E2E NOC');
        const renamed = unique('E2E Layanan');

        await loginAs(page, 'admin');
        await createRole(page, initial);
        await page.getByRole('link', { name: initial, exact: true }).click();
        await expect(page).toHaveURL(/\/admin\/roles\/[0-9A-HJKMNP-TV-Z]{26}$/i);
        await page.getByRole('main').getByRole('link', { name: 'Ubah' }).click();
        await page.getByLabel('Nama').fill(renamed);
        await page.getByRole('button', { name: 'Simpan peran' }).click();
        await expect(page).toHaveURL(/\/admin\/roles$/);
        await expect(page.getByRole('link', { name: renamed, exact: true })).toBeVisible();

        await page.goto('/admin/users?search=Demo%20Staff');
        await page.getByRole('button', { name: 'Atur peran Demo Staff' }).click();
        const dialog = page.getByRole('dialog', { name: 'Atur peran Demo Staff' });
        await dialog.getByRole('checkbox', { name: renamed, exact: true }).check();
        await dialog.getByRole('button', { name: 'Simpan peran' }).click();
        await expect(dialog).toBeHidden();
        await expect(page.getByRole('row').filter({ hasText: 'Demo Staff' })).toContainText(
            renamed,
        );

        await page.goto('/admin/users?search=Demo%20Staff');
        await page.getByRole('button', { name: 'Atur peran Demo Staff' }).click();
        const cleanup = page.getByRole('dialog', { name: 'Atur peran Demo Staff' });
        await cleanup.getByRole('checkbox', { name: renamed, exact: true }).uncheck();
        await cleanup.getByRole('button', { name: 'Simpan peran' }).click();
        await expect(cleanup).toBeHidden();
        await page.goto('/admin/roles');
        await deleteRole(page, renamed);
    });

    test('a role with users is deleted after moving them', async ({ page }) => {
        const name = unique('E2E Move');
        await loginAs(page, 'admin');
        await createRole(page, name);
        await page.goto('/admin/users?search=Demo%20Manager');
        await page.getByRole('button', { name: 'Atur peran Demo Manager' }).click();
        const dialog = page.getByRole('dialog', { name: 'Atur peran Demo Manager' });
        await dialog.getByRole('checkbox', { name, exact: true }).check();
        await dialog.getByRole('button', { name: 'Simpan peran' }).click();
        await expect(dialog).toBeHidden();

        await page.goto('/admin/roles');
        await deleteRole(page, name, 'Manajer');
        await page.goto('/admin/users?search=Demo%20Manager');
        await expect(page.getByRole('row').filter({ hasText: 'Demo Manager' })).toContainText(
            'Manajer',
        );
    });

    test('last administrator cannot remove the Admin role in Pengguna', async ({ page }) => {
        await page.goto('/login');
        await page.getByLabel('Email').fill(platformFixture.tenantAdminEmail!);
        await page.getByLabel(/^Kata sandi( \*)?$/).fill(platformFixture.tenantAdminPassword!);
        await page.getByRole('button', { name: 'Masuk' }).click();
        await expect(page).toHaveURL(/\/admin\/dashboard$/);
        await page.goto('/admin/users?search=E2E%20Suspend%20Admin');
        await page.getByRole('button', { name: 'Atur peran E2E Suspend Admin' }).click();
        const dialog = page.getByRole('dialog', { name: 'Atur peran E2E Suspend Admin' });
        await dialog.getByRole('checkbox', { name: 'Admin', exact: true }).uncheck();
        await dialog.getByRole('button', { name: 'Simpan peran' }).click();
        await expect(dialog.getByRole('alert')).toContainText(
            'Perusahaan harus tetap punya minimal satu admin aktif yang memegang roles.manage.',
        );
        await expect(dialog).toBeVisible();
    });

    test('keyboard-only creates and deletes a role', async ({ page }) => {
        const name = unique('E2E Keyboard');
        await loginAs(page, 'admin');
        await createRole(page, name, true);
        await deleteRole(page, name, undefined, true);
    });

    for (const dark of [false, true]) {
        test(`role pages fit 320px in ${dark ? 'dark' : 'light'} mode`, async ({ page }) => {
            await page.setViewportSize({ width: 320, height: 800 });
            await loginAs(page, 'admin');
            await page.evaluate(
                (enabled) => localStorage.setItem('darkMode', String(enabled)),
                dark,
            );

            for (const path of ['/admin/roles', '/admin/roles/create', '/admin/users']) {
                await page.goto(path);
                await expect(page.locator('html')).toHaveClass(
                    dark ? /\bdark\b/ : /^(?!.*\bdark\b)/,
                );
                await expect(
                    page.getByRole('main').getByRole('heading', { level: 1 }),
                ).toBeVisible();
                expect(
                    await page.evaluate(
                        () =>
                            document.documentElement.scrollWidth -
                            document.documentElement.clientWidth,
                    ),
                    `${path} overflows at 320px in ${dark ? 'dark' : 'light'} mode`,
                ).toBeLessThanOrEqual(0);
            }
        });
    }

    test('operator edits a template and repairs one company role', async ({ page }) => {
        const repaired = unique('E2E Perbaikan');
        const repairedAgain = `${repaired} Ubah`;

        await loginAsPlatformOperator(page);
        await page.goto('/platform/role-templates');
        await page.getByRole('link', { name: 'Gudang', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Ubah templat Gudang' })).toBeVisible();
        await page.getByLabel('Nama').fill('Gudang Operasional');
        await page.getByRole('button', { name: 'Simpan templat' }).click();
        await confirmPlatformAction(page, 'Simpan templat');
        await expect(page).toHaveURL(/\/platform\/role-templates$/);
        await expect(
            page.getByRole('link', { name: 'Gudang Operasional', exact: true }),
        ).toBeVisible();

        await openDemoCompany(page);
        // The tenant holds a subsidiary too (E2eFixtureSeeder), and each company has its own table of roles.
        const roleSection = page.getByRole('region', { name: DEMO_COMPANY, exact: true });
        const companyRoleRows = roleSection.getByRole('row');
        for (const name of templateNames) {
            await expect(companyRoleRows.filter({ hasText: new RegExp(`^${name}`) })).toBeVisible();
        }
        await expect(companyRoleRows.filter({ hasText: /^Gudang Operasional/ })).toHaveCount(0);

        await roleSection.getByLabel(`Templat untuk ${DEMO_CODE}`).selectOption('noc');
        await roleSection.getByRole('link', { name: 'Tambah dari templat' }).click();
        await page.getByLabel('Nama').fill(repaired);
        await page.getByRole('button', { name: 'Tambah peran' }).click();
        await confirmPlatformAction(page, 'Tambah peran');
        await expect(page).toHaveURL(/\/platform\/companies\/[0-9A-HJKMNP-TV-Z]{26}$/i);

        await page.getByRole('link', { name: `Perbaiki peran ${repaired}` }).click();
        await page.getByLabel('Nama').fill(repairedAgain);
        await page.getByRole('button', { name: 'Simpan peran' }).click();
        await confirmPlatformAction(page, 'Simpan peran');
        await expect(page).toHaveURL(/\/platform\/companies\/[0-9A-HJKMNP-TV-Z]{26}$/i);
        await page.getByRole('link', { name: `Perbaiki peran ${repairedAgain}` }).click();
        await page.getByRole('button', { name: 'Hapus peran' }).click();
        await confirmPlatformAction(page, 'Hapus peran');
        await expect(page).toHaveURL(/\/platform\/companies\/[0-9A-HJKMNP-TV-Z]{26}$/i);
        await expect(
            page.getByRole('link', { name: `Perbaiki peran ${repairedAgain}` }),
        ).toHaveCount(0);

        await page.goto('/platform/role-templates');
        await page.getByRole('link', { name: 'Gudang Operasional', exact: true }).click();
        await page.getByLabel('Nama').fill('Gudang');
        await page.getByRole('button', { name: 'Simpan templat' }).click();
        await confirmPlatformAction(page, 'Simpan templat');
        await expect(page.getByRole('link', { name: 'Gudang', exact: true })).toBeVisible();
    });
});
