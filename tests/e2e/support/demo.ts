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
 * - Scheduler overlap protection: SchedulerLockTest::test_billing_schedules_use_without_overlapping
 * - Former API containment: ApiExposureTest (guest/auth, all HTTP methods, member, query, trailing-slash variants)
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

export const receivableCustomerNames = datatableCustomerNames;

export const receivableGrandTotal = receivableCustomerNames.reduce(
    (total, _name, index) => total + (index + 1) * 10000,
    120000,
);

export function unique(prefix: string) {
    return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
}

/** SPK status codes as the badge reads them (lib/statuses/workOrder.ts). */
const spkStatusLabels: Record<string, string> = {
    draft: 'Draf',
    generated: 'Menunggu penugasan',
    assigned: 'Ditugaskan',
    in_progress: 'Dikerjakan',
    waiting_review: 'Menunggu review',
    completed: 'Selesai',
    rejected: 'Dikembalikan',
    cancelled: 'Dibatalkan',
};

const escapeRegExp = (text: string) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/**
 * The value shown next to `label` on a detail page: the `<dd>` of the `<dt>` named `label` (SPK,
 * Tiket), or the `<p>` that starts "Label:" (Tagihan). A status reads as its badge label.
 */
export async function expectDetail(page: Page, label: string, value: string | RegExp) {
    const name = escapeRegExp(label);
    const term = page.locator('dt').filter({ hasText: new RegExp(`^\\s*${name}\\s*$`) });
    const definition = term.locator('xpath=following-sibling::dd[1]');
    const paragraph = page.locator('p').filter({ hasText: new RegExp(`^\\s*${name}:`) });

    await expect(definition.or(paragraph).first()).toContainText(value);
}

/** `status` is the stored code; the SPK page shows its code and the status badge. */
export async function expectSpkState(page: Page, status: string) {
    await expect(page.getByText(/^SPK-\d{4}-\d{5}$/).first()).toBeVisible();
    await expectDetail(page, 'Status', spkStatusLabels[status] ?? status);
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
    const appOnly = role === 'technician' || role === 'customer';

    await page.goto('/login');
    await page.getByLabel('Email').fill(user.email);
    await page.getByLabel(/^Kata sandi( \*)?$/).fill(demoPassword);
    await page.getByRole('button', { name: 'Masuk' }).click();

    // Technician and customer accounts are app-only on the web: they land on /app-only.
    if (appOnly) {
        await expect(page).toHaveURL(/\/app-only$/, { timeout: 15_000 });
        await expect(
            page.getByRole('main').getByRole('heading', { name: 'Gunakan aplikasi Inbils' }),
        ).toBeVisible();
        return;
    }

    await expect(page.getByRole('main').getByRole('heading', { name: 'Dasbor' })).toBeVisible({
        timeout: 15_000,
    });
}

export async function logout(page: Page) {
    await page.context().clearCookies();
    await page.goto('/login');
}

/**
 * Resolve the current session CSRF token for raw `page.request` posts.
 * The app renders no `meta[name="csrf-token"]` tag (Laravel issues the
 * `XSRF-TOKEN` cookie instead), so read that cookie from the browser context
 * and URL-decode it.
 *
 * IMPORTANT: send the decoded value via the `X-XSRF-TOKEN` header, NOT
 * `X-CSRF-TOKEN`. Laravel's `VerifyCsrfToken::getTokenFromRequest()` only
 * *decrypts* the `X-XSRF-TOKEN` header before comparing it to the session
 * token; `X-CSRF-TOKEN` is compared raw and the cookie value is encrypted, so
 * `X-CSRF-TOKEN` always mismatches and surfaces as 419. Verified empirically:
 * `X-XSRF-TOKEN` + decoded cookie yields the expected 403 on a denied route.
 */
export async function csrfToken(page: Page) {
    const cookies = await page.context().cookies();
    const raw = cookies.find((cookie) => cookie.name === 'XSRF-TOKEN')?.value;
    if (!raw) {
        throw new Error('No XSRF-TOKEN cookie in the current browser context');
    }
    return decodeURIComponent(raw);
}

/**
 * `page.request` (APIRequestContext) shares the browser context's cookie jar:
 * the response's session/XSRF Set-Cookie headers are applied synchronously, so
 * after a real GET the freshly-read `csrfToken()` matches the live session.
 * Call this between a login switch and a raw POST that asserts on 403 — a 419
 * there means the token is stale, not that authorization failed.
 */
export async function refreshSession(page: Page, url = '/admin/stocks') {
    const response = await page.request.get(url);
    expect([200, 403]).toContain(response.status());
    return csrfToken(page);
}

export const PNG_1X1 = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
    'base64',
);

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
    // Found by role: the clear button of a chosen field is also labelled "Hapus pilihan: {label}".
    const field = page.getByRole('combobox', { name: label });
    await field.click();
    if (typeof text === 'string') {
        await field.fill(text);
    }
    // The options are role=option items in a popover, outside any dialog the field sits in.
    await page.getByRole('option', { name: text }).first().click();
}

/** Opens the SearchSelect `label`, takes its first option and returns that option's label (not its description). */
export async function selectFirstSearchOption(page: Page, label: string): Promise<string> {
    await page.getByRole('combobox', { name: label }).click();
    // The field's own list: a native select on the same page also holds role=option items, and they come first in the DOM.
    const option = page.getByRole('listbox', { name: label }).getByRole('option').first();
    await expect(option).toBeVisible();
    const name = (await option.locator('span').first().textContent()) ?? '';
    await option.click();

    return name;
}

export async function technicianUserId(page: Page) {
    await page.goto('/admin/users?search=Demo%20Technician');
    const row = page.getByRole('row').filter({ hasText: 'Demo Technician' }).first();
    await expect(row).toBeVisible({ timeout: 15_000 });
    await row.getByRole('button', { name: 'Aksi lainnya' }).click();
    const href = await page.getByRole('menuitem', { name: 'Lihat detail' }).getAttribute('href');
    await page.keyboard.press('Escape');
    const id = href?.match(/\/([0-9A-HJKMNP-TV-Z]{26})$/i)?.[1];
    if (!id) {
        throw new Error('Could not resolve Demo Technician user id');
    }
    return id;
}

/**
 * Fetch a page via the request context (inherits the browser session) and return
 * its decoded Inertia page object. Lets specs resolve real record ids from
 * server-rendered props instead of hard-coding fixed IDs.
 */
export async function inertiaProps(page: Page, url: string) {
    const resp = await page.request.get(url, { headers: { Accept: 'text/html' } });
    const html = await resp.text();
    const match = html.match(/data-page="([^"]*)"/);
    if (!match) throw new Error(`No Inertia data-page on ${url}`);
    const json = match[1]
        .replace(/&quot;/g, '"')
        .replace(/&#039;/g, "'")
        .replace(/&amp;/g, '&')
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>');
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    return JSON.parse(json) as { props?: Record<string, any> };
}

/**
 * The unit price of a one-line invoice that comes to exactly `total` rupiah once the company's PPN is added, read from the
 * invoice page now open. A line takes the company's rate (the item dialog has no tax field), and the line total and the tax
 * are each whole rupiah, so the price is found by trying the one next to the plain division.
 */
export async function unitPriceForTotal(page: Page, total: number): Promise<number> {
    const rate = Number((await inertiaProps(page, new URL(page.url()).pathname)).props?.tax_rate);
    const guess = Math.round(total / (1 + rate / 100));
    const price = [guess, guess - 1, guess + 1].find(
        (candidate) => candidate + Math.round((candidate * rate) / 100) === total,
    );

    if (price === undefined)
        throw new Error(`No whole-rupiah line makes ${total} with a PPN of ${rate}%.`);

    return price;
}
