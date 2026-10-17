import type { Locator, Page, Request, Route } from '@playwright/test';
import { expect, test } from './support/fixtures';
import { csrfToken, inertiaProps, loginAs, logout, unique } from './support/demo';

type Ticket = {
    id: string;
    code: string;
    title: string;
    status: string;
    assigned_to: string | null;
    updated_at: string;
};

type NotificationRow = {
    id: string;
    type: string;
    title: string;
    message: string;
    read_at: string | null;
    detail_url: string | null;
};

type Inbox = {
    data: NotificationRow[];
    unreadCount: number;
    meta: { current_page: number; last_page: number };
};

async function switchTo(page: Page, role: 'admin' | 'manager') {
    await logout(page);
    await loginAs(page, role);
}

async function inbox(page: Page, pageNumber = 1): Promise<Inbox> {
    const response = await inertiaProps(page, `/admin/notifications?page=${pageNumber}`);
    return response.props?.notifications;
}

async function notificationById(page: Page, id: string) {
    let pageNumber = 1;
    let result: Inbox;
    do {
        result = await inbox(page, pageNumber);
        const row = result.data.find((notification) => notification.id === id);
        if (row) return row;
        pageNumber++;
    } while (pageNumber <= result.meta.last_page);
    throw new Error(`Owned notification ${id} disappeared from the inbox`);
}

async function dispatchTicket(page: Page, title: string): Promise<Ticket> {
    const response = await inertiaProps(
        page,
        `/admin/dispatch?domain=ticket&status=all&search=${encodeURIComponent(title)}`,
    );
    const matches = response.props?.items?.data.filter((row: Ticket) => row.title === title);
    expect(matches, `Owned dispatch ticket ${title}`).toHaveLength(1);
    return matches[0];
}

async function assign(page: Page, ticket: Ticket, handlerId: string) {
    const current = await dispatchTicket(page, ticket.title);
    const response = await page.request.post('/admin/dispatch/assign', {
        headers: { 'X-XSRF-TOKEN': await csrfToken(page) },
        maxRedirects: 0,
        data: {
            domain: 'ticket',
            id: current.id,
            assignee_id: handlerId,
            expected_assigned_to: current.assigned_to,
            expected_updated_at: current.updated_at,
        },
    });
    expect(response.status(), `Assign owned ticket ${ticket.code}`).toBe(302);
    expect((await dispatchTicket(page, ticket.title)).assigned_to).toBe(handlerId);
}

async function postAction(page: Page, ticket: Ticket, action: string, data = {}) {
    const response = await page.request.post(`/admin/tickets/${ticket.id}/${action}`, {
        headers: { 'X-XSRF-TOKEN': await csrfToken(page) },
        maxRedirects: 0,
        data,
    });
    expect(response.status(), `${action} owned ticket ${ticket.code}`).toBe(302);
}

async function tabTo(page: Page, control: Locator) {
    for (let i = 0; i < 80; i++) {
        await page.keyboard.press('Tab');
        if (await control.evaluate((element) => element === document.activeElement)) break;
    }
    await expect(control).toBeFocused();
    expect(
        await control.evaluate((element) => {
            const style = getComputedStyle(element);
            return (
                (style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) > 0) ||
                style.boxShadow !== 'none'
            );
        }),
        'Keyboard focus has a visible outline or ring',
    ).toBe(true);
}

function article(page: Page, ticket: Ticket) {
    return page.getByRole('article').filter({
        has: page.getByText(`Tiket ${ticket.code} sudah ditugaskan kepada Anda.`, { exact: true }),
    });
}

async function clearOwnedUnread(page: Page, owned: Ticket[], deleted = false) {
    let pageNumber = 1;
    let result: Inbox;
    do {
        result = await inbox(page, pageNumber);
        for (const row of result.data.filter((row) =>
            owned.some(
                (ticket) => row.message === `Tiket ${ticket.code} sudah ditugaskan kepada Anda.`,
            ),
        )) {
            if (deleted) expect(row.detail_url).toBeNull();
            if (row.read_at === null) {
                const response = await page.request.patch(`/admin/notifications/${row.id}/read`, {
                    headers: { 'X-XSRF-TOKEN': await csrfToken(page) },
                    maxRedirects: 0,
                });
                expect(response.status()).toBe(302);
            }
        }
        pageNumber++;
    } while (pageNumber <= result.meta.last_page);
}

test('@notification-center owned assignments persist reads and reassignment notifies again', async ({
    page,
}, testInfo) => {
    const owned: Ticket[] = [];
    let adminId = '';
    let technicianId = '';
    const notificationIds: string[] = [];

    await loginAs(page, 'admin');
    try {
        for (const [name, target] of [
            ['Demo Admin', 'admin'],
            ['Demo Technician', 'technician'],
        ]) {
            const response = await inertiaProps(
                page,
                `/admin/users?search=${encodeURIComponent(name)}`,
            );
            const users = response.props?.users?.data.filter(
                (user: { name: string }) => user.name === name,
            );
            expect(users, `Unique ${name} fixture`).toHaveLength(1);
            if (target === 'admin') adminId = users[0].id;
            else technicianId = users[0].id;
        }
        const createProps = await inertiaProps(page, '/admin/tickets/create');
        const categories = createProps.props?.categories?.data;
        expect(
            categories?.length,
            'At least one active ticket category is required',
        ).toBeGreaterThan(0);

        for (const label of ['details', 'read']) {
            const title = unique(`Notification ${label}`);
            const response = await page.request.post('/admin/tickets', {
                headers: { 'X-XSRF-TOKEN': await csrfToken(page) },
                maxRedirects: 0,
                data: {
                    title,
                    source: 'internal',
                    category_id: categories[0].id,
                    priority: 'low',
                    description: 'Owned notification browser acceptance ticket',
                },
            });
            expect(response.status(), `Create ${title}`).toBe(302);
            const ticket = await dispatchTicket(page, title);
            owned.push(ticket);
        }

        // The admin is the handler who receives the notifications: another actor hands the tickets over,
        // preserving the no-self-assignment rule. (The technician's inbox is the app's, not the web's.)
        await switchTo(page, 'manager');
        for (const ticket of owned) await assign(page, ticket, adminId);
        await switchTo(page, 'admin');
        const before = await inbox(page);
        const rows = owned.map((ticket) => {
            const matches = before.data.filter((row) =>
                row.detail_url?.endsWith(`/admin/tickets/${ticket.id}`),
            );
            expect(matches, `Notification for ${ticket.code}`).toHaveLength(1);
            expect(matches[0]).toMatchObject({
                type: 'ticket_assigned',
                title: 'Tiket ditugaskan',
                read_at: null,
            });
            notificationIds.push(matches[0].id);
            return matches[0];
        });
        expect(before.unreadCount).toBeGreaterThanOrEqual(2);
        const bell = page.getByRole('button', {
            name: `Notifikasi, ${before.unreadCount} belum dibaca`,
            exact: true,
        });
        await tabTo(page, bell);
        await page.keyboard.press('Enter');
        await expect(page).toHaveURL(/\/admin\/notifications$/);
        await expect(bell).toBeVisible();

        // Hold the real mutation so fast local responses cannot conceal premature navigation.
        const details = article(page, owned[0]).getByRole('button', { name: 'Lihat detail' });
        await tabTo(page, details);
        const readUrl = new URL(`/admin/notifications/${rows[0].id}/read`, page.url()).href;
        const detailUrl = new URL(`/admin/tickets/${owned[0].id}`, page.url()).href;
        let releaseRead!: () => void;
        const readGate = new Promise<void>((resolve) => {
            releaseRead = resolve;
        });
        let finishRead!: () => void;
        const readFinished = new Promise<void>((resolve) => {
            finishRead = resolve;
        });
        let readHeld = false;
        let observeRequest!: (kind: 'read' | 'details') => void;
        const firstRequest = new Promise<'read' | 'details'>((resolve) => {
            observeRequest = resolve;
        });
        let detailRequests = 0;
        const watchDetails = (request: Request) => {
            if (request.method() === 'GET' && request.url() === detailUrl) {
                detailRequests++;
                observeRequest('details');
            }
        };
        const holdRead = async (route: Route) => {
            try {
                if (route.request().method() === 'PATCH') {
                    readHeld = true;
                    observeRequest('read');
                    await readGate;
                }
                await route.continue();
            } finally {
                finishRead();
            }
        };
        page.on('request', watchDetails);
        await page.route(readUrl, holdRead);
        try {
            await page.keyboard.press('Enter');
            expect(await firstRequest, 'Read mutation precedes detail navigation').toBe('read');
            await page.evaluate(
                () =>
                    new Promise<void>((resolve) => {
                        requestAnimationFrame(() => requestAnimationFrame(() => resolve()));
                    }),
            );
            expect(detailRequests, 'Do not navigate while the read mutation is pending').toBe(0);
            expect(new URL(page.url()).pathname).toBe('/admin/notifications');
        } finally {
            releaseRead();
            if (readHeld) await readFinished;
            await page.unroute(readUrl, holdRead);
            page.off('request', watchDetails);
        }
        await expect(page).toHaveURL(new RegExp(`/admin/tickets/${owned[0].id}$`));
        await expect(
            page.getByRole('main').getByRole('heading', { name: owned[0].title }),
        ).toBeVisible();
        await page.goto('/admin/notifications');
        const opened = await notificationById(page, rows[0].id);
        expect(opened.read_at).not.toBeNull();
        await expect(article(page, owned[0]).getByText(/^Belum dibaca/)).toHaveCount(0);
        await expect(
            page.getByRole('button', {
                name: `Notifikasi, ${before.unreadCount - 1} belum dibaca`,
                exact: true,
            }),
        ).toBeVisible();

        await article(page, owned[1])
            .getByRole('button', { name: 'Tandai dibaca', exact: true })
            .click();
        await expect(
            article(page, owned[1]).getByRole('button', { name: 'Tandai dibaca', exact: true }),
        ).toHaveCount(0);
        const marked = await notificationById(page, rows[1].id);
        expect(marked.read_at).not.toBeNull();
        await page.reload();
        expect((await notificationById(page, rows[0].id)).read_at).toBe(opened.read_at);
        expect((await notificationById(page, rows[1].id)).read_at).toBe(marked.read_at);
        await article(page, owned[0]).getByRole('button', { name: 'Lihat detail' }).click();
        await expect(page).toHaveURL(new RegExp(`/admin/tickets/${owned[0].id}$`));
        await page.goto('/admin/notifications');
        expect((await notificationById(page, rows[0].id)).read_at).toBe(opened.read_at);

        // UI-only response projection: real pagination is covered by the PHP boundary test.
        const inboxUrl = new URL('/admin/notifications', page.url()).href;
        let finishProjection!: () => void;
        const projectionReady = new Promise<void>((resolve) => {
            finishProjection = resolve;
        });
        const projectReadPage = async (route: Route) => {
            const response = await route.fetch();
            expect(response.status()).toBe(200);
            const payload = await response.json();
            const notifications = payload.props.notifications as Inbox;
            expect(notifications.data.some((row) => row.id === rows[0].id)).toBe(true);
            notifications.data = notifications.data.map((row) => ({
                ...row,
                read_at: opened.read_at,
            }));
            notifications.unreadCount = 1;
            notifications.meta.current_page = 1;
            notifications.meta.last_page = Math.max(2, notifications.meta.last_page);
            await route.fulfill({ response, json: payload });
            finishProjection();
        };
        await page.route(inboxUrl, projectReadPage);
        try {
            await page.getByRole('button', { name: /^Notifikasi(?:, \d+ belum dibaca)?$/ }).click();
            await projectionReady;
            await expect(
                page.getByRole('button', { name: 'Notifikasi, 1 belum dibaca', exact: true }),
            ).toBeVisible();
            await expect(page.getByRole('article').getByText(/^Belum dibaca/)).toHaveCount(0);
            await expect(
                page.getByRole('button', { name: 'Tandai semua dibaca', exact: true }),
            ).toBeEnabled();
        } finally {
            await page.unroute(inboxUrl, projectReadPage);
        }
        await page.reload();

        // Handing a ticket away and back is a real new assignment: it produces a new unread notification.
        await switchTo(page, 'manager');
        await assign(page, owned[1], technicianId);
        await assign(page, owned[1], adminId);
        await switchTo(page, 'admin');
        await page.goto('/admin/notifications');
        const reassigned = await inbox(page);
        const newRow = reassigned.data.find(
            (row) =>
                row.detail_url?.endsWith(`/admin/tickets/${owned[1].id}`) &&
                !notificationIds.includes(row.id),
        );
        expect(newRow, 'Reassignment produces a new real unread notification').toBeDefined();
        expect(newRow?.read_at).toBeNull();
        notificationIds.push(newRow!.id);
        await page.goto('/admin/notifications');

        for (const dark of [false, true]) {
            for (const width of [1280, 320]) {
                await page.setViewportSize({ width, height: 800 });
                await page.evaluate(
                    (value) => localStorage.setItem('darkMode', String(value)),
                    dark,
                );
                await page.reload();
                await expect(page.locator('html')).toHaveClass(dark ? /dark/ : /^(?!.*dark)/);
                await expect(
                    page.getByRole('main').getByRole('heading', { name: 'Notifikasi' }),
                ).toBeVisible();
                expect(
                    await page.evaluate(
                        () => document.documentElement.scrollWidth <= window.innerWidth,
                    ),
                ).toBe(true);
                const markAll = page.getByRole('button', {
                    name: 'Tandai semua dibaca',
                    exact: true,
                });
                await expect(markAll).toBeEnabled();
                await tabTo(page, markAll);
                await testInfo.attach(`notifications-${width}-${dark ? 'dark' : 'light'}`, {
                    body: await page.screenshot({ fullPage: true }),
                    contentType: 'image/png',
                });
            }
        }
        await page.keyboard.press('Enter');
        await expect(
            page.getByRole('button', { name: 'Tandai semua dibaca', exact: true }),
        ).toBeDisabled();
        await page.reload();
        expect((await inbox(page)).unreadCount).toBe(0);
        expect((await notificationById(page, newRow!.id)).read_at).not.toBeNull();
        expect((await notificationById(page, rows[0].id)).read_at).toBe(opened.read_at);
        expect((await notificationById(page, rows[1].id)).read_at).toBe(marked.read_at);
        await expect(page.getByRole('button', { name: 'Notifikasi', exact: true })).toBeVisible();
    } finally {
        if (owned.length > 0) {
            await switchTo(page, 'manager');
            for (const ticket of owned) {
                const current = await dispatchTicket(page, ticket.title);
                if (current.assigned_to !== adminId) await assign(page, ticket, adminId);
            }
            await switchTo(page, 'admin');
            await clearOwnedUnread(page, owned);
            for (const ticket of owned) {
                await postAction(page, ticket, 'start');
                await postAction(page, ticket, 'resolve', {
                    resolution_note: 'Notification acceptance cleanup',
                });
            }
            // Maker/checker: the admin resolved as handler, so the manager closes.
            await switchTo(page, 'manager');
            for (const ticket of owned) {
                await postAction(page, ticket, 'close');
            }
            await switchTo(page, 'admin');
            for (const ticket of owned) {
                const closed = await inertiaProps(page, `/admin/tickets/${ticket.id}`);
                expect(closed.props?.ticket?.data.status).toBe('closed');
                const response = await page.request.delete(`/admin/tickets/${ticket.id}`, {
                    headers: { 'X-XSRF-TOKEN': await csrfToken(page) },
                    maxRedirects: 0,
                });
                expect(response.status(), `Delete owned ticket ${ticket.code}`).toBe(302);
                const gone = await page.request.get(`/admin/tickets/${ticket.id}`);
                expect(gone.status()).toBe(404);
            }
            // Assignment notifications are durable; clear only owned rows for both recipients.
            await clearOwnedUnread(page, owned, true);
        }
    }
});
