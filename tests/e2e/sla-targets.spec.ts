/**
 * @sla-targets SLA targets drive ticket deadlines; measurement stays automatic (.scratch/sla-targets/spec.md)
 *
 * Coverage:
 * - tier form holds contract targets only (no uptime/credit), tier list shows the computed 30-day columns
 * - a ticket of a subscriber takes the tier's target, with its source on the detail page
 * - a ticket without a subscription takes its category's target; editing the category changes the next ticket
 * - the category screen is closed to roles without ticket.category.manage
 *
 * Deterministic: unique records per run; the category edit is restored at the end.
 */
import type { Page } from '@playwright/test';

import { expect, test } from './support/fixtures';
import { e2eCustomerName, inertiaProps, loginAs, unique } from './support/demo';

const runId = unique('SLA');
const HOUR_MS = 3_600_000;

/**
 * Wait until every code-split app script that has already started has finished loading, so a hard goto()
 * does not abort an in-flight chunk (net::ERR_ABORTED), which the fail-closed watcher treats as breaking.
 */
async function settleAppChunks(page: Page) {
    await page.waitForFunction(
        () => {
            const scripts = performance
                .getEntriesByType('resource')
                .filter(
                    (entry) =>
                        (entry as PerformanceResourceTiming).initiatorType === 'script' &&
                        entry.name.includes('/build/assets/'),
                );
            return (
                scripts.length > 0 &&
                scripts.every((entry) => (entry as PerformanceResourceTiming).responseEnd > 0)
            );
        },
        undefined,
        { timeout: 20_000 },
    );
}

async function createTicketViaUi(page: Page, title: string, subscriptionCode?: string) {
    await settleAppChunks(page);
    await page.goto('/admin/tickets/create');
    await expect(
        page.getByRole('main').getByRole('heading', { name: 'Create Ticket' }),
    ).toBeVisible();
    await page.getByLabel('Title').fill(title);
    await page.getByLabel('Source').selectOption('customer');
    const category = page.getByLabel('Category');
    await expect(category.locator('option', { hasText: 'Device Issue' })).toHaveCount(1);
    await expect(
        page.getByLabel('Customer').locator('option', { hasText: e2eCustomerName }),
    ).toHaveCount(1);
    await settleAppChunks(page);
    await category.selectOption({ label: 'Device Issue' });
    await page.getByLabel('Customer').selectOption({ label: e2eCustomerName });
    if (subscriptionCode) {
        await page.getByLabel('Subscription').selectOption({ label: subscriptionCode });
    }
    await page.getByRole('button', { name: 'Create', exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/tickets/);

    const props = await inertiaProps(page, `/admin/tickets?search=${encodeURIComponent(title)}`);
    const id = props.props?.tickets?.data?.find((t: { title: string }) => t.title === title)?.id;
    if (!id) throw new Error(`Could not resolve ticket id for ${title}`);

    return String(id);
}

type TicketData = {
    created_at: string;
    sla_deadline: string;
    response_deadline: string | null;
    deadline_source: { type: 'tier' | 'category'; name: string };
};

async function ticketData(page: Page, id: string) {
    const props = await inertiaProps(page, `/admin/tickets/${id}`);

    return props.props?.ticket?.data as TicketData;
}

const hoursBetween = (from: string, to: string) =>
    (new Date(to).getTime() - new Date(from).getTime()) / HOUR_MS;

async function editCategory(page: Page, name: string, response: string, resolution: string) {
    const dialog = page.getByRole('dialog');
    await page.getByRole('button', { name: `Ubah kategori ${name}` }).click();
    await dialog.getByLabel('Target respons (jam)').fill(response);
    await dialog.getByLabel('Target penyelesaian (jam)').fill(resolution);
    await dialog.getByRole('button', { name: 'Simpan' }).click();
    await expect(dialog).toBeHidden();
}

test.describe('@sla-targets', () => {
    test.describe.configure({ mode: 'serial' });

    test('tier form holds targets only and the list shows the computed 30-day columns', async ({
        page,
    }) => {
        const tierName = `E2E SLA ${runId}`;
        await loginAs(page, 'admin');
        await settleAppChunks(page);
        await page.goto('/admin/sla-tiers/create');

        const main = page.getByRole('main');
        await expect(main.getByRole('heading', { name: 'Tambah Tingkat SLA' })).toBeVisible();
        await expect(
            main.getByText('Target layanan per paket. Hasil diukur otomatis dari tiket.'),
        ).toBeVisible();
        await expect(page.getByLabel('Uptime')).toHaveCount(0);
        await expect(page.getByLabel('Credit')).toHaveCount(0);

        await page.getByLabel('Nama').fill(tierName);
        await page.getByLabel('Target respons (jam)').fill('3');
        await page.getByLabel('Target penyelesaian (jam)').fill('6');
        await settleAppChunks(page);
        await page.getByRole('button', { name: 'Simpan' }).click();

        await expect(page).toHaveURL(/\/admin\/sla-tiers/);
        await expect(
            main.getByRole('columnheader', { name: 'Respons tepat waktu (30 hari)' }),
        ).toBeVisible();
        await expect(
            main.getByRole('columnheader', { name: 'Penyelesaian tepat waktu (30 hari)' }),
        ).toBeVisible();
        await expect(main.getByRole('columnheader', { name: /Uptime|Credit/ })).toHaveCount(0);

        await page.goto(`/admin/sla-tiers?search=${encodeURIComponent(tierName)}`);
        const row = main.getByRole('row').filter({ hasText: tierName });
        await expect(row).toBeVisible();
        // No ticket yet on a new tier: both rates are empty, not 0%.
        await expect(row.getByRole('cell').nth(3)).toHaveText('-');
        await expect(row.getByRole('cell').nth(4)).toHaveText('-');
        await expect(row.getByRole('cell').nth(5)).toHaveText('0');
    });

    test('a subscriber ticket takes the tier target and names its source', async ({ page }) => {
        const title = `E2E SLA tier ticket ${runId}`;
        await loginAs(page, 'admin');
        const id = await createTicketViaUi(page, title, 'E2E-LINKED-SUB');

        const ticket = await ticketData(page, id);
        expect(ticket.deadline_source.type).toBe('tier');
        expect(ticket.response_deadline).not.toBeNull();
        expect(hoursBetween(ticket.created_at, ticket.response_deadline as string)).toBeLessThan(
            hoursBetween(ticket.created_at, ticket.sla_deadline),
        );

        await settleAppChunks(page);
        await page.goto(`/admin/tickets/${id}`);
        const main = page.getByRole('main');
        await expect(main.getByRole('heading', { name: title })).toBeVisible();
        await expect(main.getByText('Target respons:')).toBeVisible();
        await expect(main.getByText('Target penyelesaian:')).toBeVisible();
        await expect(
            main.getByText(`(Target ${ticket.deadline_source.name})`).first(),
        ).toBeVisible();
        await expect(main.getByText('Respons terlambat')).toHaveCount(0);
    });

    test('editing a category changes the target of the next ticket without a subscription', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        await settleAppChunks(page);
        await page.goto('/admin/ticket-categories');
        const main = page.getByRole('main');
        await expect(main.getByRole('heading', { name: 'Kategori Tiket' })).toBeVisible();

        const row = main.getByRole('row').filter({ hasText: 'Device Issue' });
        const original = {
            response: (await row.getByRole('cell').nth(2).innerText()).trim(),
            resolution: (await row.getByRole('cell').nth(3).innerText()).trim(),
        };

        try {
            await editCategory(page, 'Device Issue', '2', '5');
            await expect(row.getByRole('cell').nth(2)).toHaveText('2');
            await expect(row.getByRole('cell').nth(3)).toHaveText('5');

            const title = `E2E SLA category ticket ${runId}`;
            const id = await createTicketViaUi(page, title);
            const ticket = await ticketData(page, id);

            expect(ticket.deadline_source).toEqual({ type: 'category', name: 'Device Issue' });
            expect(hoursBetween(ticket.created_at, ticket.sla_deadline)).toBeCloseTo(5, 1);
            expect(hoursBetween(ticket.created_at, ticket.response_deadline as string)).toBeCloseTo(
                2,
                1,
            );

            await settleAppChunks(page);
            await page.goto(`/admin/tickets/${id}`);
            await expect(
                page.getByRole('main').getByText('(Target kategori Device Issue)').first(),
            ).toBeVisible();
        } finally {
            await settleAppChunks(page);
            await page.goto('/admin/ticket-categories');
            await editCategory(page, 'Device Issue', original.response, original.resolution);
        }
    });

    test('only holders of ticket.category.manage open the category screen', async ({ page }) => {
        // Sidebar groups are collapsed <details> unless they hold the current page.
        const categoryLink = page.locator('aside a[href$="/admin/ticket-categories"]');

        await loginAs(page, 'manager');
        await page
            .locator('aside details')
            .filter({ has: page.locator('summary', { hasText: 'Pelanggan & Layanan' }) })
            .locator('summary')
            .click();
        await expect(
            page.getByRole('navigation').getByRole('link', { name: 'Kategori Tiket' }),
        ).toBeVisible();
        expect((await page.request.get('/admin/ticket-categories')).status()).toBe(200);

        await page.context().clearCookies();
        await loginAs(page, 'staff');
        await expect(categoryLink).toHaveCount(0);
        expect((await page.request.get('/admin/ticket-categories')).status()).toBe(403);
    });
});
