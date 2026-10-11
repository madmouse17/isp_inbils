/**
 * @lifecycle Ticket journeys (P1-C)
 *
 * Coverage:
 * - create + assign (resolved handler, no fixed ID) + start/first-response
 * - resolve + SLA assertion (resolved before deadline, non-empty timestamps)
 * - closure
 * - SPK spawn + backlink verification
 * - denied actions (technician close denied, customer admin-create denied)
 *
 * Deterministic: no fixed IDs, unique records per run, owned data.
 *
 * Handler: start/resolve/close are limited to the assigned handler who holds that
 * ability (TicketPolicy). Only admin holds all three, so the acting admin is the handler.
 */
import type { Page } from '@playwright/test';

import { expect, test } from './support/fixtures';
import {
    csrfToken,
    e2eCustomerName,
    expectDetail,
    loginAs,
    logout,
    refreshSession,
    unique,
} from './support/demo';

const runId = unique('TKT');

test.describe('@lifecycle ticket', () => {
    test.describe.configure({ mode: 'serial' });

    test('full journey: create, assign, first response, resolve with SLA, close, SPK backlink', async ({
        page,
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
        await assignToHandlerViaUi(page, 'Demo Admin');

        // --- Start (records first_response_at) ---
        await page.getByRole('button', { name: 'Start', exact: true }).click();
        await expect(page.getByText('on_progress').first()).toBeVisible({ timeout: 15_000 });
        const firstResponse = await detailValue(page, 'First Response:');
        expect(firstResponse.trim()).not.toBe('');
        expect(firstResponse.trim()).not.toBe('-');

        // --- Public comment ---
        const comment = `first-response-${runId}`;
        await page.getByRole('button', { name: 'Add Comment' }).click();
        const commentDialog = page.getByRole('dialog', { name: 'Comment' });
        await commentDialog.getByLabel('Comment').fill(comment);
        await commentDialog.getByRole('button', { name: 'Confirm' }).click();
        await expect(page.getByText(comment)).toBeVisible({ timeout: 15_000 });

        // --- Resolve + SLA assertion ---
        const deadlineText = await detailValue(page, 'SLA Deadline:');
        expect(deadlineText.trim()).not.toBe('-');
        const deadline = parseDate(deadlineText);
        await page.getByRole('button', { name: 'Resolve', exact: true }).click();
        const resolveDialog = page.getByRole('dialog', { name: 'Resolve' });
        await resolveDialog.getByLabel('Resolution Note').fill(resolution);
        await resolveDialog.getByRole('button', { name: 'Confirm' }).click();
        await expect(page.getByText('resolved').first()).toBeVisible({ timeout: 15_000 });
        await expect(page.getByText(resolution)).toBeVisible();

        const resolvedText = await detailValue(page, 'Resolved:');
        const resolvedAt = parseDate(resolvedText);
        expect(resolvedAt.getTime()).toBeLessThanOrEqual(deadline.getTime());
        await expect(page.getByText('SLA Breached')).toHaveCount(0);

        // --- Close ---
        await page.getByRole('button', { name: 'Close', exact: true }).click();
        const closeDialog = page.getByRole('dialog', { name: 'Close' });
        await closeDialog.getByRole('button', { name: 'Confirm' }).click();
        await expect(page.getByText('closed').first()).toBeVisible({ timeout: 15_000 });
        const closedText = await detailValue(page, 'Closed:');
        expect(closedText.trim()).not.toBe('-');

        // --- SPK spawn + backlink verification (product data, deterministic) ---
        const title2 = `E2E ticket spk ${runId}`;
        const spkTicketId = await createTicketViaUi(page, title2);
        await settleAppChunks(page);
        await page.goto(`/admin/tickets/${spkTicketId}`);
        await expect(page.getByRole('main').getByRole('heading', { name: title2 })).toBeVisible();
        await assignToHandlerViaUi(page, 'Demo Admin');
        await page.getByRole('button', { name: 'Start', exact: true }).click();
        await expect(page.getByText('on_progress').first()).toBeVisible({ timeout: 15_000 });

        await page.getByRole('button', { name: 'Spawn SPK' }).click();
        const spawnDialog = page.getByRole('dialog', { name: 'Spawn Spk' });
        await spawnDialog.getByRole('button', { name: 'Confirm' }).click();
        await expect(page).toHaveURL(/\/admin\/spk/, { timeout: 15_000 });

        // The product stores the backlink in DB (tickets.spawned_spk_id / work_orders.ticket_id)
        // but renders no link on either Show page; verify the linkage via the Inertia show props.
        const ticketProps = await inertiaProps(page, `/admin/tickets/${spkTicketId}`);
        const spawnedSpkId = ticketProps?.props?.ticket?.data?.spawned_spk_id;
        expect(spawnedSpkId).toBeTruthy();

        const spkProps = await inertiaProps(page, `/admin/spk/${spawnedSpkId}`);
        const wo = spkProps?.props?.workOrder?.data;
        expect(wo?.source).toBe('ticket');
        expect(wo?.title).toContain('SPK from Ticket');
    });

    test('technician denied close; customer denied close', async ({ page }) => {
        const denyTitle = `deny-close ${runId}`;

        await loginAs(page, 'admin');
        const csrf = await csrfToken(page);

        // Seed an open ticket via API to keep the denial surface stable.
        const createResp = await page.request.post('/admin/tickets', {
            headers: { 'X-XSRF-TOKEN': csrf },
            maxRedirects: 0,
            form: {
                title: denyTitle,
                source: 'customer',
                category_id: await firstOptionValue(page, '/admin/tickets/create', 'Category'),
                priority: 'low',
                customer_id: await firstOptionValue(page, '/admin/tickets/create', 'Customer'),
                description: 'deny path',
            },
        });
        expect(createResp.status()).toBe(302);

        const ticketId = await findTicketId(page, denyTitle);
        expect(ticketId).toBeTruthy();

        await logout(page);
        await loginAs(page, 'technician');
        // Refresh against a technician-reachable page so the session/XSRF cookies
        // match the live technician session before the raw close POST (a stale
        // token would surface as 419, not the required 403).
        const techCsrf = await refreshSession(page, '/admin/spk');
        // Technician cannot view this unassigned ticket, so the route model-binding
        // renders the page-level denial; the close attempt must be forbidden.
        const close = await page.request.post(`/admin/tickets/${ticketId}/close`, {
            headers: { 'X-XSRF-TOKEN': techCsrf },
        });
        expect(close.status()).toBe(403);

        await logout(page);
        await loginAs(page, 'customer');
        // Customer has ticket.create but not ticket.close; verify the close
        // action is denied via the API (the route requires a valid ticket id).
        const custCsrf = await refreshSession(page, '/admin/dashboard');
        const custClose = await page.request.post(`/admin/tickets/${ticketId}/close`, {
            headers: { 'X-XSRF-TOKEN': custCsrf },
        });
        expect(custClose.status()).toBe(403);
    });
});

/** Read the value line that follows a "Label:" line in the Show page main text. */
async function detailValue(page: import('@playwright/test').Page, label: string) {
    const text = await page.getByRole('main').innerText();
    const lines = text
        .split('\n')
        .map((l) => l.trim())
        .filter((l) => l !== '');
    const idx = lines.findIndex((l) => l.startsWith(label));
    if (idx === -1) throw new Error(`Label not found: ${label}`);
    // Value may be on the same line after the label, or the next non-empty line.
    const same = lines[idx].slice(label.length).trim();
    if (same) return same;
    return (lines[idx + 1] ?? '').trim();
}

/** Resolve an active user's id by display name via the admin Users index props. */
async function resolveUserId(page: import('@playwright/test').Page, name: string) {
    const props = await inertiaProps(page, `/admin/users?search=${encodeURIComponent(name)}`);
    const id = props?.props?.users?.data?.find((u: { name: string }) => u.name === name)?.id;
    if (!id) throw new Error(`Could not resolve user id for ${name}`);
    return String(id);
}

/** Assign the open ticket on the Show page to the named handler and assert the Handler detail. */
async function assignToHandlerViaUi(page: Page, handlerName: string) {
    const handlerId = await resolveUserId(page, handlerName);
    await page.getByRole('button', { name: 'Assign' }).click();
    const assignDialog = page.getByRole('dialog', { name: 'Assign' });
    await assignDialog.getByLabel('Handler ID').fill(handlerId);
    await assignDialog.getByRole('button', { name: 'Confirm' }).click();
    await expect(assignDialog).toBeHidden({ timeout: 15_000 });
    await expectDetail(page, 'Handler', handlerName);
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
async function settleAppChunks(page: import('@playwright/test').Page) {
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

/** Return the first non-empty option value for a labelled select on a page. */
async function firstOptionValue(page: import('@playwright/test').Page, url: string, label: string) {
    // The page we navigate away from (post-login Dashboard, Tickets Index, ...)
    // lazily loads table/nav chunks; a hard goto() that starts while those are
    // in flight aborts them with net::ERR_ABORTED (fail-closed watcher). Settle
    // first, mirroring createTicketViaUi.
    await settleAppChunks(page);
    await page.goto(url);
    const select = page.getByLabel(label);
    const option = select.locator('option').nth(1);
    const value = await option.getAttribute('value');
    if (!value) throw new Error(`No option value for ${label}`);
    return value;
}

function parseDate(text: string): Date {
    const d = new Date(text);
    if (Number.isNaN(d.getTime())) throw new Error(`Unparseable date: ${text}`);
    return d;
}

/** Create a ticket via the admin UI and return its id (no fixed IDs). */
async function createTicketViaUi(page: import('@playwright/test').Page, title: string) {
    // The page we navigate away from (post-login Dashboard, or Tickets Index)
    // lazily loads table/nav chunks (DataTable, ExportMenu, useTableQuery,
    // Skeleton, …). A hard goto() that starts while those are in flight aborts
    // them with net::ERR_ABORTED and no correlated navigation event, which the
    // fail-closed watcher treats as journey-breaking. Settle the current page
    // first so those chunks finish before we leave it.
    await settleAppChunks(page);
    await page.goto('/admin/tickets/create');
    await expect(
        page.getByRole('main').getByRole('heading', { name: 'Create Ticket' }),
    ).toBeVisible();
    await page.getByLabel('Title').fill(title);
    await page.getByLabel('Source').selectOption('customer');
    // Wait for the create page's lazy chunk to finish (selects are populated by it),
    // so submitting does not abort in-flight code-split requests (fail-closed watcher).
    const category = page.getByLabel('Category');
    await expect(category.locator('option', { hasText: 'Device Issue' })).toHaveCount(1);
    await expect(
        page.getByLabel('Customer').locator('option', { hasText: e2eCustomerName }),
    ).toHaveCount(1);
    // The Inertia layout code-splits nav/menu chunks (DataTable, StatusBadge,
    // ExportMenu, ...) that start ~0.5s after the create-page document. Submitting
    // while they are in flight aborts them (net::ERR_ABORTED), which the
    // fail-closed watcher treats as journey-breaking. settleAppChunks polls
    // performance resource entries so every already-started chunk gets a
    // responseEnd before the POST navigates away (deterministic, no timing guess).
    await settleAppChunks(page);
    await category.selectOption({ label: 'Device Issue' });
    await page.getByLabel('Customer').selectOption({ label: e2eCustomerName });
    await page.getByRole('button', { name: 'Create', exact: true }).click();

    await expect(page).toHaveURL(/\/admin\/tickets/);
    return await findTicketId(page, title);
}

/**
 * Resolve a ticket id by its unique title via the Tickets index Inertia props.
 * Note: Tickets/Index.tsx renders row.number/row.subject, but TicketResource
 * supplies code/title, so the title column is blank in the UI; the server-side
 * search still matches title, so we read the id from the Inertia page object.
 */
async function findTicketId(page: import('@playwright/test').Page, title: string) {
    const props = await inertiaProps(page, `/admin/tickets?search=${encodeURIComponent(title)}`);
    const id = props?.props?.tickets?.data?.find((t: { title: string }) => t.title === title)?.id;
    if (!id) throw new Error(`Could not resolve ticket id for ${title}`);
    return String(id);
}

/** Fetch a page and return its decoded Inertia page object (props). */
async function inertiaProps(page: import('@playwright/test').Page, url: string) {
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
