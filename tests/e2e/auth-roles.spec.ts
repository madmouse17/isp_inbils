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
const demoPassword = seederSource.match(/private const PASSWORD = '([^']+)';/)?.[1];
const demoUsers = Array.from(
    seederSource.matchAll(
        /'(admin|manager|staff|technician|customer)' => \['name' => '([^']+)', 'email' => '([^']+)'/g,
    ),
).map(([, role, name, email]) => ({ role: role as DemoRole, name, email }));

if (!demoPassword || demoUsers.length !== 5) {
    throw new Error('Could not derive DemoUserSeeder credentials.');
}

const roleExpectations: Record<
    DemoRole,
    {
        allowedPath: string;
        visible: string[];
        hidden: string[];
        forbiddenPath?: string;
    }
> = {
    admin: {
        allowedPath: '/admin/users',
        visible: ['Dashboard', 'Users', 'Roles', 'Permissions', 'Customers', 'Billing'],
        hidden: [],
    },
    manager: {
        allowedPath: '/admin/customers',
        visible: ['Dashboard', 'Customers', 'Billing', 'Ticketing', 'Reports'],
        hidden: ['Users', 'Roles', 'Permissions', 'Employees', 'Vehicles', 'Documents'],
    },
    staff: {
        allowedPath: '/admin/stocks',
        visible: ['Dashboard', 'Customers', 'Inventory', 'Billing', 'Ticketing', 'Reports'],
        hidden: ['Users', 'Roles', 'Permissions', 'Employees', 'Vehicles'],
    },
    technician: {
        allowedPath: '/admin/spk',
        visible: ['Dashboard', 'Customers', 'Inventory', 'Network Assets', 'SPK', 'Ticketing'],
        hidden: ['Users', 'Roles', 'Service', 'Billing', 'Reports'],
    },
    customer: {
        allowedPath: '/admin/tickets',
        visible: ['Dashboard', 'Ticketing'],
        hidden: [
            'Users',
            'Roles',
            'Customers',
            'Inventory',
            'Network Assets',
            'SPK',
            'Billing',
            'Reports',
        ],
        forbiddenPath: '/admin/customers',
    },
};

test('@kanban-t_525dca8a landing hydrates and exposes login only', async ({ page }) => {
    const response = await page.goto('/');

    expect(response?.status()).toBe(200);
    await expect(page.locator('body')).not.toBeEmpty();
    await expect(page.getByRole('link', { name: 'Log in' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Register' })).toHaveCount(0);
});

test('@kanban-t_525dca8a login link navigates and invalid credentials show error', async ({
    page,
}) => {
    await page.goto('/');
    await page.getByRole('link', { name: 'Log in' }).click();

    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByRole('heading', { name: 'Log in to admin' })).toBeVisible();

    await page.getByLabel('Email').fill('nobody@example.test');
    await page.getByLabel('Password').fill('wrong-password');
    await page.getByRole('button', { name: 'Log in' }).click();

    await expect(page.getByText('These credentials do not match our records.')).toBeVisible();
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
        await expectShellFor(page, demoUser);
        await expectRoleSidebar(page, demoUser.role);
        await expectPermittedAccess(page, demoUser.role);

        if (demoUser.role === 'customer') {
            const response = await page.request.get(roleExpectations.customer.forbiddenPath!);

            expect(response.status()).toBe(403);
        }

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

test('@kanban-t_525dca8a dark toggle applies and survives reload', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'light' });
    await page.goto('/login');
    await page.evaluate(() => localStorage.setItem('darkMode', 'false'));
    await login(
        page,
        demoUsers.find((demoUser) => demoUser.role === 'admin')!,
    );

    await expect(page.locator('html')).not.toHaveClass(/dark/);
    await page.getByRole('button', { name: 'Toggle dark mode' }).click();

    await expect(page.locator('html')).toHaveClass(/dark/);
    await expect.poll(() => page.evaluate(() => localStorage.getItem('darkMode'))).toBe('true');

    await page.reload();
    await expect(page.locator('html')).toHaveClass(/dark/);
    await expect(page.getByText('Demo Admin')).toBeVisible();
});

async function login(page: Page, demoUser: DemoUser) {
    await page.goto('/login');
    await page.getByLabel('Email').fill(demoUser.email);
    await page.getByLabel('Password').fill(demoPassword!);
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(/\/admin\/dashboard$/);
}

async function logout(page: Page) {
    await page.getByRole('button', { name: 'Logout' }).click();
    await expect(page.getByRole('link', { name: 'Log in' })).toBeVisible();
}

async function expectShellFor(page: Page, demoUser: DemoUser) {
    await expect(page.getByRole('main').getByRole('heading', { name: 'Dashboard' })).toBeVisible();
    await expect(page.getByText(demoUser.name)).toBeVisible();
}

async function expectRoleSidebar(page: Page, role: DemoRole) {
    const sidebar = page.locator('aside');
    const expectation = roleExpectations[role];

    for (const label of expectation.visible) {
        await expect(sidebar.getByRole('link', { name: label, exact: true })).toBeVisible();
    }

    for (const label of expectation.hidden) {
        await expect(sidebar.getByRole('link', { name: label, exact: true })).toHaveCount(0);
    }
}

async function expectPermittedAccess(page: Page, role: DemoRole) {
    const response = await page.goto(roleExpectations[role].allowedPath);

    expect(response?.status()).toBe(200);
}
