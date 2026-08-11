/**
 * P1-C journey manifest (review required; test-side scope only):
 * - Billing lifecycle and denial: billing-lifecycle.spec.ts
 * - Inventory movements, denial, and no-negative guard: inventory-lifecycle.spec.ts
 * - SPK installation, evidence, denial, and approval: spk-lifecycle.spec.ts
 * - Ticket lifecycle, SLA evidence, and denial: ticket-lifecycle.spec.ts
 * - Operations readiness and API non-exposure: ops-api-smoke.spec.ts
 *   - /up liveness contract
 *   - /ready public-safe JSON contract
 *   - /ready exposes only the scheduler smoke state
 *   - former scaffold API paths return 404 for guest and auth
 * - Scheduler heartbeat state setup: ReadinessEndpointTest::test_scheduler_heartbeat_command_makes_ready_probe_report_ok
 *
 * Run only this manifest with `npm run test:e2e:ops-smoke`.
 * The guarded E2E reset owns data cleanup; unique() isolates records within the one-worker run.
 * Authentication helpers live here; browser/network failure handling stays in fixtures.ts.
 * Playwright retains screenshots only on failure and traces/videos on failure.
 */
import type { Locator, Page } from '@playwright/test';

import { expect } from './fixtures';

/**
 * E2E credentials from DemoUserSeeder (isolated E2E database, seeded via
 * tests/e2e/setup.php -> DatabaseSeeder -> DemoUserSeeder).
 */
export type DemoRole = 'admin' | 'manager' | 'staff' | 'technician' | 'customer';

export type DemoUser = {
    role: DemoRole;
    name: string;
    email: string;
};

export const demoPassword = 'password';
export const e2eCustomerName = 'E2E Linked Customer';

export const datatableCustomerNames = [
    'Atlas Fiber',
    'Aria Fiber',
    'Astra Fiber',
    'Aurora Fiber',
    'Apex Fiber',
    'Ariel Fiber',
    'Aster Fiber',
    'Alto Fiber',
    'Amber Fiber',
    'Axiom Fiber',
    'Bima Fiber',
] as const;

export function unique(prefix: string) {
    return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
}

export async function expectDetail(page: Page, label: string, value: string) {
    await expect(
        page
            .locator('p')
            .filter({ hasText: `${label}:` })
            .first(),
    ).toContainText(value);
}

export async function expectSpkState(page: Page, status: string) {
    await expect(page.getByText(new RegExp(`SPK-\\d{4}-\\d{5} · ${status}`))).toBeVisible();
}

const e2eUsers: DemoUser[] = [
    { role: 'admin', name: 'Demo Admin', email: 'admin@demo.inbils.test' },
    { role: 'manager', name: 'Demo Manager', email: 'manager@demo.inbils.test' },
    { role: 'staff', name: 'Demo Staff', email: 'staff@demo.inbils.test' },
    { role: 'technician', name: 'Demo Technician', email: 'technician@demo.inbils.test' },
    { role: 'customer', name: 'Demo Customer', email: 'customer@demo.inbils.test' },
];

export const demoUsers: DemoUser[] = e2eUsers;

export function userFor(role: DemoRole): DemoUser {
    const user = e2eUsers.find((entry) => entry.role === role);
    if (!user) {
        throw new Error(`Unknown E2E role: ${role}`);
    }

    return user;
}

export async function loginAs(page: Page, role: DemoRole = 'admin') {
    const user = userFor(role);
    const intendedUrl = role === 'technician' ? '/admin/spk' : null;

    await page.goto(intendedUrl ?? '/login');
    await page.getByLabel('Email').fill(user.email);
    await page.getByLabel('Password').fill(demoPassword);
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(
        page
            .getByRole('main')
            .getByRole('heading', { name: intendedUrl ? 'SPK / Work Orders' : 'Dashboard' }),
    ).toBeVisible({ timeout: 15_000 });
}

export async function logout(page: Page) {
    await page.goto('/logout');
}

export const PNG_1X1 = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
    'base64',
);

export async function createCustomer(page: Page, name: string) {
    await page.goto('/admin/customers/create');
    const codeField = page.getByLabel(/^code/i);
    if (await codeField.count()) {
        await codeField.fill(`CUS-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`);
    }
    await page.getByLabel(/name/i).fill(name);
    const email = `${unique(name)
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '.')}@example.test`;
    const emailField = page.getByLabel(/email/i);
    if (await emailField.count()) {
        await emailField.fill(email);
    }
    const phoneField = page.getByLabel(/phone/i);
    if (await phoneField.count()) {
        await phoneField.fill(`0800${String(Date.now()).slice(-8)}`);
    }
    await page.getByRole('button', { name: /save|create|simpan/i }).click();
    await expect(page).toHaveURL(/\/admin\/customers\/?$/);
    await expect(page.getByRole('main').getByRole('heading', { name: 'Customers' })).toBeVisible();

    return name;
}

export async function selectOptionByText(select: Locator, text: string) {
    await select.selectOption({ label: text });
}

export async function selectFirstRealOption(select: Locator) {
    const options = select.locator('option');
    for (let attempt = 0; attempt < 20; attempt += 1) {
        const count = await options.count();
        for (let i = 0; i < count; i += 1) {
            const value = await options.nth(i).getAttribute('value');
            if (value && value !== '' && value !== '0') {
                await select.selectOption(value);
                return value;
            }
        }
        await select.page().waitForTimeout(150);
    }
    throw new Error('No real option available');
}

export async function selectSearchOption(page: Page, label: string, text: string | RegExp) {
    const field = page.getByLabel(label);
    await field.click();
    if (typeof text === 'string') {
        await field.fill(text);
        await page.getByRole('button', { name: text }).first().click();
        return;
    }
    await page.getByRole('button', { name: text }).first().click();
}

export async function technicianUserId(page: Page) {
    await page.goto('/admin/users?search=Demo%20Technician');
    const row = page.getByRole('row').filter({ hasText: 'Demo Technician' }).first();
    await expect(row).toBeVisible({ timeout: 15_000 });
    const href = await row.getByRole('link', { name: 'Show' }).getAttribute('href');
    const id = href?.match(/\/(\d+)$/)?.[1];
    if (!id) {
        throw new Error('Could not resolve Demo Technician user id');
    }
    return id;
}
