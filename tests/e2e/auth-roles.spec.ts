import { readFileSync } from 'node:fs';
import type { Page } from '@playwright/test';

import { expect, test } from './support/fixtures';

type DemoRole = 'admin' | 'manager' | 'staff' | 'technician' | 'customer';

type DemoUser = {
    role: DemoRole;
    name: string;
    email: string;
};

const seederSource = readFileSync('database/seeders/DemoUserSeeder.php', 'utf8');
const demoPassword = seederSource.match(/(?:public|private) const PASSWORD = '([^']+)';/)?.[1];
const demoUsers = Array.from(
    seederSource.matchAll(
        /'(admin|manager|staff|technician|customer)' => \['name' => '([^']+)', 'email' => '([^']+)'/g,
    ),
).map(([, role, name, email]) => ({ role: role as DemoRole, name, email }));

if (!demoPassword || demoUsers.length !== 5) {
    throw new Error('Could not derive DemoUserSeeder credentials.');
}

// Technician and customer accounts are app-only on the web: they land on /app-only (app-only.spec.ts).
type WebRole = Exclude<DemoRole, 'technician' | 'customer'>;

const appOnlyRoles: DemoRole[] = ['technician', 'customer'];

const roleExpectations: Record<
    WebRole,
    {
        allowedPath: string;
        visible: string[];
        hidden: string[];
    }
> = {
    admin: {
        allowedPath: '/admin/users',
        visible: [
            'Dasbor',
            'Pengguna',
            'Peran',
            'Hak Akses',
            'Pelanggan',
            'Tagihan',
            'Organisasi',
            'Perusahaan',
            'Dokumen',
            'Penomoran',
            'Paket Layanan',
            'Permintaan Barang',
            'Persetujuan',
            'Penugasan',
            'Kinerja Teknisi',
        ],
        // APP_ENV=e2e closes the developer gallery, so Komponen is hidden even for admin.
        // The admin demo user has no employee profile, hence no Pekerjaan Saya.
        hidden: ['Komponen', 'Pekerjaan Saya'],
    },
    manager: {
        allowedPath: '/admin/customers',
        visible: [
            'Dasbor',
            'Pelanggan',
            'Tagihan',
            'Tiket',
            'Laporan',
            'Paket Layanan',
            'Permintaan Barang',
            'Persetujuan',
            'Penugasan',
            'Pekerjaan Saya',
        ],
        hidden: [
            'Pengguna',
            'Peran',
            'Hak Akses',
            'Karyawan',
            'Kendaraan',
            'Dokumen',
            'Penomoran',
            'Organisasi',
            'Perusahaan',
            'Komponen',
            // The demo manager sits in no organization, so the server withholds the technician report.
            'Kinerja Teknisi',
        ],
    },
    staff: {
        allowedPath: '/admin/stocks',
        visible: [
            'Dasbor',
            'Pelanggan',
            'Barang',
            'Tagihan',
            'Tiket',
            'Laporan',
            'Paket Layanan',
            'Permintaan Barang',
            'Pekerjaan Saya',
        ],
        hidden: [
            'Pengguna',
            'Peran',
            'Hak Akses',
            'Karyawan',
            'Kendaraan',
            'Dokumen',
            'Penomoran',
            'Organisasi',
            'Perusahaan',
            // Operator persona (role-persona-sod): staff cannot approve, so the inbox is hidden.
            'Persetujuan',
            'Penugasan',
            'Kinerja Teknisi',
            'Komponen',
        ],
    },
};

test('@kanban-t_525dca8a landing sends guests to the login page', async ({ page }) => {
    await page.goto('/');

    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByRole('button', { name: 'Masuk' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Register' })).toHaveCount(0);
});

test('@kanban-t_525dca8a login link navigates and invalid credentials show error', async ({
    page,
}) => {
    await page.goto('/login');

    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByRole('heading', { name: 'Masuk' })).toBeVisible();

    await page.getByLabel('Email').fill('nobody@example.test');
    await page.getByLabel(/^Kata sandi( \*)?$/).fill('wrong-password');
    await page.getByRole('button', { name: 'Masuk' }).click();

    await expect(page.getByText('Email atau kata sandi salah.')).toBeVisible();
});

test('password inputs reveal and hide the typed value without submitting', async ({ page }) => {
    await page.goto('/login');

    const field = page.getByLabel(/^Kata sandi( \*)?$/);
    await field.fill('rahasia-123');
    await expect(field).toHaveAttribute('type', 'password');

    const reveal = page.getByRole('button', { name: 'Tampilkan kata sandi' });
    await expect(reveal).toHaveAttribute('aria-pressed', 'false');
    await reveal.click();

    await expect(field).toHaveAttribute('type', 'text');
    await expect(field).toHaveValue('rahasia-123');
    await expect(field).toHaveAttribute('autocomplete', 'current-password');
    await expect(page).toHaveURL(/\/login$/);

    await page.getByRole('button', { name: 'Sembunyikan kata sandi' }).click();
    await expect(field).toHaveAttribute('type', 'password');
});

test('@kanban-t_525dca8a register endpoint stays unavailable', async ({ page }) => {
    expect((await page.request.get('/register')).status()).toBe(404);
    expect(
        (
            await page.request.post('/register', {
                form: {
                    name: 'Browser Register',
                    email: 'browser-register@example.test',
                    password: 'password',
                    password_confirmation: 'password',
                },
            })
        ).status(),
    ).toBe(404);
});

test('@kanban-t_525dca8a seeded demo roles can login and see permitted shell', async ({ page }) => {
    for (const demoUser of demoUsers) {
        await login(page, demoUser);

        if (isAppOnly(demoUser.role)) {
            await expect(
                page.getByRole('main').getByRole('heading', { name: 'Gunakan aplikasi Inbils' }),
            ).toBeVisible();
            await page.getByRole('button', { name: 'Keluar' }).click();
            await expect(page.getByRole('button', { name: 'Masuk' })).toBeVisible();
            continue;
        }

        await expectShellFor(page, demoUser);
        await expectRoleSidebar(page, demoUser.role);
        await expectPermittedAccess(page, demoUser.role);

        await logout(page);
    }

    await login(
        page,
        demoUsers.find((demoUser) => demoUser.role === 'admin')!,
    );
    await expectShellFor(
        page,
        demoUsers.find((demoUser) => demoUser.role === 'admin')!,
    );
});

test('tenant roles are read-only: list opens the role, no create, edit or delete', async ({
    page,
    expectHttpError,
}) => {
    await login(
        page,
        demoUsers.find((demoUser) => demoUser.role === 'admin')!,
    );
    await page.goto('/admin/roles');

    const main = page.getByRole('main');
    await expect(main.getByRole('heading', { name: 'Peran' })).toBeVisible();
    await expect(main.getByText('Definisi peran dikelola oleh operator platform.')).toBeVisible();
    await expect(main.getByRole('link', { name: 'Buat Peran' })).toHaveCount(0);
    await expect(main.getByRole('link', { name: 'Ubah' })).toHaveCount(0);
    await expect(main.getByRole('button', { name: 'Aksi lainnya' })).toHaveCount(0);

    await main.getByRole('link', { name: 'admin', exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/roles\/[0-9A-HJKMNP-TV-Z]{26}$/i);
    await expect(main.getByRole('heading', { name: 'Peran admin' })).toBeVisible();
    await expect(main.getByRole('heading', { name: 'Hak akses peran' })).toBeVisible();
    await expect(main.getByRole('link', { name: 'Ubah' })).toHaveCount(0);
    await main.getByRole('link', { name: 'Kembali' }).click();
    await expect(page).toHaveURL(/\/admin\/roles$/);

    expectHttpError({ url: '/admin/roles/create', method: 'GET', status: 404 });
    expect((await page.goto('/admin/roles/create'))?.status()).toBe(404);
});

test('@kanban-t_525dca8a dark toggle applies and survives reload', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'light' });
    await page.goto('/login');
    await page.evaluate(() => localStorage.setItem('darkMode', 'false'));
    await login(
        page,
        demoUsers.find((demoUser) => demoUser.role === 'admin')!,
    );

    await expect(page.locator('html')).not.toHaveClass(/dark/);
    await openUserMenu(page);
    await page.getByRole('menuitem', { name: 'Mode gelap', exact: true }).click();

    await expect(page.locator('html')).toHaveClass(/dark/);
    await expect.poll(() => page.evaluate(() => localStorage.getItem('darkMode'))).toBe('true');

    await page.reload();
    await expect(page.locator('html')).toHaveClass(/dark/);
    await expect(page.getByText('Demo Admin')).toBeVisible();
});

async function login(page: Page, demoUser: DemoUser) {
    await page.goto('/login');
    await page.getByLabel('Email').fill(demoUser.email);
    await page.getByLabel(/^Kata sandi( \*)?$/).fill(demoPassword!);
    await page.getByRole('button', { name: 'Masuk' }).click();
    await expect(page).toHaveURL(isAppOnly(demoUser.role) ? /\/app-only$/ : /\/admin\/dashboard$/);
}

function isAppOnly(role: DemoRole): role is Extract<DemoRole, 'technician' | 'customer'> {
    return appOnlyRoles.includes(role);
}

async function logout(page: Page) {
    // AdminLayout moved Logout into the user dropdown menu (Radix), so open
    // the menu first and activate the menuitem instead of a page-level button.
    await openUserMenu(page);
    await page.getByRole('menuitem', { name: 'Keluar', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Masuk' })).toBeVisible();
}

async function openUserMenu(page: Page) {
    await page.getByRole('button', { name: 'Menu pengguna' }).click();
}

async function expectShellFor(page: Page, demoUser: DemoUser) {
    await expect(page.getByRole('main').getByRole('heading', { name: 'Dasbor' })).toBeVisible();
    await expect(page.getByText(demoUser.name)).toBeVisible();
}

async function expectRoleSidebar(page: Page, role: WebRole) {
    const sidebar = page.locator('aside');
    const expectation = roleExpectations[role];

    await expandSidebarSections(sidebar);

    for (const label of expectation.visible) {
        await expect(sidebar.getByRole('link', { name: sidebarLabel(label) })).toBeVisible();
    }

    for (const label of expectation.hidden) {
        await expect(sidebar.getByRole('link', { name: sidebarLabel(label) })).toHaveCount(0);
    }
}

// Approval, Dispatch and Pekerjaan Saya append a queue count to the accessible name
// ("Approval 3"), so a link is matched by its label plus an optional trailing number.
function sidebarLabel(label: string) {
    return new RegExp(`^${label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\s*\\d*$`);
}

async function expandSidebarSections(sidebar: ReturnType<Page['locator']>) {
    // Sidebar sections are collapsible <details> groups (AdminLayout only opens the
    // section containing the active route), so links outside the active section exist
    // in the DOM but stay hidden until their <summary> is expanded.
    const summaries = sidebar.locator('details:not([open]) > summary');

    while ((await summaries.count()) > 0) {
        await summaries.first().click();
    }
}

async function expectPermittedAccess(page: Page, role: WebRole) {
    const response = await page.goto(roleExpectations[role].allowedPath);

    expect(response?.status()).toBe(200);
}
