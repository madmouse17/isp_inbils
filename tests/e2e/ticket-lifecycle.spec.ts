/**
 * @lifecycle Ticket journeys (P1-C)
 *
 * Coverage:
 * - create + assign (resolved handler, no fixed ID) + start/first-response
 * - resolve + SLA assertion (resolved before deadline, non-empty timestamps)
 * - closure
 * - SPK spawn + backlink verification
 *
 * Deterministic: no fixed IDs, unique records per run, owned data.
 *
 * Handler/checker split: start/resolve are limited to the assigned handler (technician, through the app API);
 * close is open to any ticket.close holder (manager) once the ticket is resolved.
 */
import type { Page } from '@playwright/test';

import { apiLogin } from './support/api';
import { expect, test } from './support/fixtures';
import {
    e2eCustomerName,
    expectDetail,
    loginAs,
    logout,
    selectSearchOption,
    unique,
} from './support/demo';

const runId = unique('TKT');

test.describe('@lifecycle ticket', () => {
    test.describe.configure({ mode: 'serial' });

    test('full journey: create, assign, first response, resolve with SLA, close, SPK backlink', async ({
        page,
        request,
    }) => {
        const title = `E2E ticket lifecycle ${runId}`;
        const resolution = `resolved-${runId}`;

        await loginAs(page, 'admin');

        // --- Create via UI ---
        const ticketId = await createTicketViaUi(page, title);
        await settleAppChunks(page);
        await page.goto(`/admin/tickets/${ticketId}`);
        await expect(page.getByRole('main').getByRole('heading', { name: title })).toBeVisible();

        // --- Assign via UI (resolve handler id dynamically, no fixed ID) ---
        await assignToHandlerViaUi(page, 'Demo Technician');

        // --- Technician handles the ticket through the app API ---
        await logout(page);
        const api = await apiLogin(request, 'technician');

        // Start (records first_response_at)
        const started = await api.post(`technician/tickets/${ticketId}/start`, {});
        expect(started.status()).toBe(200);
        expect(((await started.json()) as { data: { status: string } }).data.status).toBe(
            'on_progress',
        );

        // Public comment
        const comment = `first-response-${runId}`;
        const commented = await api.post(`technician/tickets/${ticketId}/comments`, {
            body: comment,
        });
        expect(commented.status()).toBe(201);

        // Resolve
        const resolved = await api.post(`technician/tickets/${ticketId}/resolve`, {
            resolution_note: resolution,
        });
        expect(resolved.status()).toBe(200);
        expect(((await resolved.json()) as { data: { status: string } }).data.status).toBe(
            'resolved',
        );

        // --- Staff see first response, comment, resolution and the SLA outcome ---
        await loginAs(page, 'admin');
        await settleAppChunks(page);
        await page.goto(`/admin/tickets/${ticketId}`);
        await expect(page.getByRole('main').getByRole('heading', { name: title })).toBeVisible();
        await expect(page.getByText('Diselesaikan').first()).toBeVisible({ timeout: 15_000 });
        await expect(page.getByText(comment)).toBeVisible();
        await expect(page.getByText(resolution)).toBeVisible();

        const firstResponse = await detailValue(page, 'Respons pertama');
        expect(firstResponse).not.toBe('');
        expect(firstResponse).not.toBe('-');

        const deadlineText = (await detailValue(page, 'Target penyelesaian')).replace(
            /\s*\(Target [^)]*\)$/,
            '',
        );
        const deadline = parseDate(deadlineText);
        const resolvedText = await detailValue(page, 'Diselesaikan');
        const resolvedAt = parseDate(resolvedText);
        expect(resolvedAt.getTime()).toBeLessThanOrEqual(deadline.getTime());
        await expect(page.getByText('Penyelesaian terlambat')).toHaveCount(0);

        // --- Close: the manager (checker, not the handler) closes the technician's work ---
        await settleAppChunks(page);
        await logout(page);
        await loginAs(page, 'manager');
        await settleAppChunks(page);
        await page.goto(`/admin/tickets/${ticketId}`);
        await expect(page.getByRole('main').getByRole('heading', { name: title })).toBeVisible();
        await expectDetail(page, 'Petugas', 'Demo Technician');
        await page.getByRole('button', { name: 'Tutup', exact: true }).click();
        const closeDialog = page.getByRole('dialog', { name: 'Tutup tiket' });
        await closeDialog.getByRole('button', { name: 'Tutup tiket' }).click();
        await expectDetail(page, 'Status', 'Ditutup');
        const closedText = await detailValue(page, 'Ditutup');
        expect(closedText.trim()).not.toBe('-');

        // --- SPK spawn + backlink verification (product data, deterministic) ---
        await logout(page);
        await loginAs(page, 'admin');
        const title2 = `E2E ticket spk ${runId}`;
        const spkTicketId = await createTicketViaUi(page, title2);
        await settleAppChunks(page);
        await page.goto(`/admin/tickets/${spkTicketId}`);
        await expect(page.getByRole('main').getByRole('heading', { name: title2 })).toBeVisible();
        // SPK can be spawned from an assigned ticket, so the admin needs no handler role.
        await assignToHandlerViaUi(page, 'Demo Technician');

        await page.getByRole('button', { name: 'Buat SPK' }).click();
        const spawnDialog = page.getByRole('dialog', { name: 'Buat SPK dari tiket' });
        await spawnDialog.getByRole('button', { name: 'Buat SPK' }).click();
        await expect(page).toHaveURL(/\/admin\/spk/, { timeout: 15_000 });

        // The product stores the backlink in DB (tickets.spawned_spk_id / work_orders.ticket_id)
        // but renders no link on either Show page; verify the linkage via the Inertia show props.
        const ticketProps = await inertiaProps(page, `/admin/tickets/${spkTicketId}`);
        const spawnedSpkId = ticketProps?.props?.ticket?.data?.spawned_spk_id;
        expect(spawnedSpkId).toBeTruthy();

        const spkProps = await inertiaProps(page, `/admin/spk/${spawnedSpkId}`);
        const wo = spkProps?.props?.workOrder?.data;
        expect(wo?.source).toBe('ticket');
        expect(wo?.title).toContain('SPK dari tiket');
    });
});

/** Read the value paired with a definition-list label on the Show page. */
async function detailValue(page: Page, label: string) {
    const term = page.getByRole('main').locator('dt', { hasText: label }).first();
    await expect(term).toBeVisible();
    return (await term.locator('xpath=following-sibling::dd[1]').innerText()).trim();
}

/** Assign the open ticket on the Show page to the named handler. */
async function assignToHandlerViaUi(page: Page, handlerName: string) {
    await page.getByRole('button', { name: 'Tugaskan' }).click();
    const assignDialog = page.getByRole('dialog', { name: 'Tugaskan tiket' });
    await selectSearchOption(page, 'Petugas', handlerName);
    await assignDialog.getByRole('button', { name: 'Tugaskan' }).click();
    await expect(assignDialog).toBeHidden({ timeout: 15_000 });
    await expectDetail(page, 'Petugas', handlerName);
}

/**
 * Wait until every code-split app script that has already started has finished
 * loading (responseEnd > 0). Lazy chunks (StatusBadge, useTableQuery, Index,
 * Skeleton, ExportMenu, ...) start shortly after the create page document and
 * submitting while they are in flight aborts them with net::ERR_ABORTED, which
 * the fail-closed watcher treats as journey-breaking. Polling performance
 * resource entries is deterministic (no timing guess): once all initiated
 * scripts have a responseEnd, no further chunk will start on the current page.
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
    // Late-starting lazy chunks (auth/data resolve then mount, e.g. StatusBadge,
    // Index, ExportMenu, useTableQuery, Skeleton) begin ~0.5s after the app
    // document. The poll above only covers scripts that had ALREADY started, so
    // a hard goto() right after it can still abort a chunk that starts later.
    // Require a stability window: no new /build/assets/ script resource for
    // 300ms. Deterministic (count-based, not a fixed sleep) and cheap.
    await page.waitForFunction(
        (ms) => {
            const scriptCount = () =>
                performance
                    .getEntriesByType('resource')
                    .filter(
                        (entry) =>
                            (entry as PerformanceResourceTiming).initiatorType === 'script' &&
                            entry.name.includes('/build/assets/'),
                    ).length;
            let prev = scriptCount();
            let stableSince = performance.now();
            return new Promise<boolean>((resolve) => {
                const tick = () => {
                    const now = scriptCount();
                    if (now !== prev) {
                        prev = now;
                        stableSince = performance.now();
                    } else if (performance.now() - stableSince >= ms) {
                        resolve(true);
                        return;
                    }
                    setTimeout(tick, 50);
                };
                tick();
            });
        },
        300,
        { timeout: 20_000 },
    );
    await page.waitForLoadState('networkidle');
}

function parseDate(text: string): Date {
    // The company shows `d/m/Y H:i`, which Date cannot read on its own.
    const shown = text.match(/^(\d{2})\/(\d{2})\/(\d{4}) (\d{2}):(\d{2})/);
    const d = shown
        ? new Date(+shown[3], +shown[2] - 1, +shown[1], +shown[4], +shown[5])
        : new Date(text);
    if (Number.isNaN(d.getTime())) throw new Error(`Unparseable date: ${text}`);
    return d;
}

/** Create a ticket via the admin UI and return its id (no fixed IDs). */
async function createTicketViaUi(page: Page, title: string) {
    await settleAppChunks(page);
    await page.goto('/admin/tickets/create');
    await expect(page.getByRole('main').getByRole('heading', { name: 'Buat Tiket' })).toBeVisible();
    await page.getByLabel('Judul').fill(title);
    await page.getByLabel('Sumber').selectOption('customer');
    await settleAppChunks(page);
    await selectSearchOption(page, 'Kategori', 'Device Issue');
    await selectSearchOption(page, 'Pelanggan', e2eCustomerName);
    await page.getByRole('button', { name: 'Simpan', exact: true }).click();

    await expect(page).toHaveURL(/\/admin\/tickets/);
    return await findTicketId(page, title);
}

/**
 * Resolve a ticket id by its unique title via the Tickets index Inertia props.
 * Note: Tickets/Index.tsx renders row.number/row.subject, but TicketResource
 * supplies code/title, so the title column is blank in the UI; the server-side
 * search still matches title, so we read the id from the Inertia page object.
 */
async function findTicketId(page: Page, title: string) {
    const props = await inertiaProps(page, `/admin/tickets?search=${encodeURIComponent(title)}`);
    const id = props?.props?.tickets?.data?.find((t: { title: string }) => t.title === title)?.id;
    if (!id) throw new Error(`Could not resolve ticket id for ${title}`);
    return String(id);
}

/** Fetch a page and return its decoded Inertia page object (props). */
async function inertiaProps(page: Page, url: string) {
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
    return JSON.parse(json);
}
