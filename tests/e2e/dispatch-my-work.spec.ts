import type { APIRequestContext, Locator, Page, Request, Route } from '@playwright/test';
import { apiLogin } from './support/api';
import { expect, test } from './support/fixtures';
import { loginAs, logout, selectSearchOption } from './support/demo';

function staleAssignmentBody(route: Route): string {
    const request = route.request();
    const postData = request.postData();
    if (postData === null) throw new Error('Expected assignment POST data');

    const contentType = request.headers()['content-type']?.split(';', 1)[0].trim().toLowerCase();
    if (contentType === 'application/x-www-form-urlencoded') {
        const formData = new URLSearchParams(postData);
        formData.set('expected_updated_at', '2000-01-01T00:00:00.000Z');
        return formData.toString();
    }

    if (contentType === 'application/json') {
        const payload: unknown = JSON.parse(postData);
        if (payload === null || typeof payload !== 'object' || Array.isArray(payload)) {
            throw new Error('Expected a JSON object for assignment');
        }
        return JSON.stringify({
            ...(payload as Record<string, unknown>),
            expected_updated_at: '2000-01-01T00:00:00.000Z',
        });
    }

    throw new Error(`Unsupported assignment request encoding: ${contentType ?? 'unknown'}`);
}

async function openAssignedDispatchDialog(page: Page) {
    await page.goto('/admin/dispatch?status=assigned');
    const row = page.getByRole('row').filter({ hasText: 'E2E Dispatch Ticket' }).first();
    await expect(row).toBeVisible();
    await row.getByRole('button', { name: 'Ganti petugas' }).click();

    const dialog = page.getByRole('dialog');
    const assigneeSelect = dialog.getByRole('combobox', { name: 'Pilih petugas tiket' });
    await expect(dialog).toBeVisible();
    await expect(assigneeSelect).toHaveValue(/\S/);
    return dialog;
}

/** Titles in the technician app's My Work list: the dispatcher's assignments land there. */
async function myWorkTitles(request: APIRequestContext) {
    const api = await apiLogin(request, 'technician');
    const response = await api.get('technician/my-work?per_page=100');
    expect(response.status()).toBe(200);

    return ((await response.json()) as { data: { title: string }[] }).data.map(
        (item) => item.title,
    );
}

function pageUrlForExpectedError(page: Page) {
    const url = new URL(page.url());
    return `${url.pathname}${url.search}`;
}

async function cancelInertiaRequests(page: Page) {
    await page.evaluate(async () => {
        const entry = document.querySelector<HTMLScriptElement>(
            'script[type="module"][src*="/build/assets/app-"]',
        );
        if (!entry) throw new Error('Expected the built application entry module');
        // The hashed entry URL is selected at runtime; use its existing router instance.
        const exports = (await import(entry.src)) as Record<string, unknown>;
        const router = Object.values(exports).find(
            (value) =>
                value !== null &&
                typeof value === 'object' &&
                'cancelAll' in value &&
                typeof value.cancelAll === 'function' &&
                'reload' in value &&
                typeof value.reload === 'function',
        ) as { cancelAll: () => void } | undefined;
        if (!router) throw new Error('The built application does not export its Inertia router');
        router.cancelAll();
    });
}

async function exerciseReload(
    page: Page,
    outcome: 'success' | 'failure' | 'cancel',
    expectHttpError: (error: { url: string; method: string; status: number }) => void,
) {
    const dialog = page.getByRole('dialog');
    const reload = dialog.getByRole('button', { name: 'Muat ulang antrean' });
    const reloadUrl = page.url();

    if (outcome === 'success') {
        const response = page.waitForResponse(
            (candidate) => candidate.url() === reloadUrl && candidate.request().method() === 'GET',
        );
        await reload.click();
        expect((await response).status()).toBe(200);
        return;
    }

    let releaseReload!: () => void;
    const released = new Promise<void>((resolve) => {
        releaseReload = resolve;
    });
    let reloadSeen!: () => void;
    const seen = new Promise<void>((resolve) => {
        reloadSeen = resolve;
    });
    let intercepted = 0;
    const reloadHandler = async (route: Route) => {
        if (route.request().method() !== 'GET' || intercepted > 0) {
            await route.continue();
            return;
        }
        intercepted += 1;
        reloadSeen();
        if (outcome === 'cancel') {
            await released;
            await route.continue();
            return;
        }
        await route.fulfill({
            status: 500,
            contentType: 'text/html',
            body: '<html><body>Queue reload unavailable</body></html>',
        });
    };

    try {
        await page.route(reloadUrl, reloadHandler);
        if (outcome === 'failure') {
            expectHttpError({ url: pageUrlForExpectedError(page), method: 'GET', status: 500 });
            const response = page.waitForResponse(
                (candidate) =>
                    candidate.url() === reloadUrl && candidate.request().method() === 'GET',
            );
            await reload.click();
            expect((await response).status()).toBe(500);
        } else {
            await reload.click();
            await seen;
            await expect(dialog.getByRole('button', { name: 'Memuat ulang...' })).toBeDisabled();
            await expect(dialog.getByRole('button', { name: 'Batal', exact: true })).toBeDisabled();
            await cancelInertiaRequests(page);
        }

        const message =
            outcome === 'failure'
                ? 'Gagal memuat ulang antrean. Terjadi gangguan koneksi atau respons tidak valid.'
                : 'Pemuatan ulang antrean dibatalkan. Status antrean belum dapat diperbarui.';
        await expect(dialog.getByText(message, { exact: true }).last()).toBeVisible();
        expect(intercepted).toBe(1);
    } finally {
        releaseReload();
        await page.unroute(reloadUrl, reloadHandler);
    }
}

async function expectMobileBounds(page: Page, locator: Locator) {
    await expect(locator).toBeVisible();
    const bounds = await locator.boundingBox();
    expect(bounds).not.toBeNull();
    expect(bounds!.x).toBeGreaterThanOrEqual(0);
    expect(bounds!.x + bounds!.width).toBeLessThanOrEqual(320);
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(
        320,
    );
}

test.describe('@lifecycle dispatch-my-work', () => {
    test.describe.configure({ mode: 'serial' });

    test('dispatches a seeded ticket and SPK into the technician work queue', async ({
        page,
        request,
    }) => {
        await loginAs(page, 'admin');
        await page.goto('/admin/dispatch');
        await expect(
            page.getByRole('main').getByRole('heading', { name: 'Penugasan' }),
        ).toBeVisible();
        await expect(page.getByRole('table').getByText('E2E Dispatch Ticket')).toBeVisible();
        await expect(page.getByRole('table').getByText('E2E Dispatch SPK')).toBeVisible();

        const assignFromQueue = async (title: string, assigneeLabel: string) => {
            const row = page.getByRole('row').filter({ hasText: title }).first();
            await expect(row).toBeVisible();

            const assignButton = row.getByRole('button', {
                name: /^(Tugaskan|Ganti petugas)$/,
            });
            await expect(assignButton).toBeVisible();
            await assignButton.click();

            const dialog = page.getByRole('dialog');
            await expect(dialog).toBeVisible();
            await expect(dialog.getByText(title, { exact: true })).toBeVisible();

            await selectSearchOption(page, assigneeLabel, 'Demo Technician');
            await dialog.getByRole('button', { name: 'Simpan penugasan' }).click();
            await expect(dialog).toBeHidden();
        };

        await assignFromQueue('E2E Dispatch Ticket', 'Pilih petugas tiket');
        await assignFromQueue('E2E Dispatch SPK', 'Pilih teknisi lapangan');

        // The technician works in the app, its own API client: the admin page stays signed in, and the
        // assigned items show up in the app's My Work API.
        const mine = await myWorkTitles(request);
        expect(mine).toContain('E2E Dispatch Ticket');
        expect(mine).toContain('E2E Dispatch SPK');

        // The technician sets off in the app: the queue says so next to the assignee.
        const api = await apiLogin(request, 'technician');
        const list = (await (await api.get('technician/my-work?per_page=100')).json()) as {
            data: { id: number; domain: string; title: string }[];
        };
        const spk = list.data.find(
            (item) => item.domain === 'spk' && item.title === 'E2E Dispatch SPK',
        );
        expect(spk, 'the dispatched SPK in My Work').toBeTruthy();
        expect((await api.post(`technician/work-orders/${spk?.id}/en-route`, {})).status()).toBe(
            200,
        );

        await page.goto('/admin/dispatch?status=assigned');
        const spkRow = page.getByRole('row').filter({ hasText: 'E2E Dispatch SPK' }).first();
        await expect(spkRow.getByText('Dalam perjalanan')).toBeVisible();
    });

    test('keeps ordinary assignment validation retryable', async ({ page, request }) => {
        await loginAs(page, 'admin');
        await page.goto('/admin/dispatch?status=assigned');

        const row = page.getByRole('row').filter({ hasText: 'E2E Dispatch Ticket' }).first();
        await expect(row).toBeVisible();
        await row.getByRole('button', { name: 'Ganti petugas' }).click();

        const dialog = page.getByRole('dialog');
        await expect(dialog).toBeVisible();
        const assigneeSelect = dialog.getByRole('combobox', { name: 'Pilih petugas tiket' });
        await selectSearchOption(page, 'Pilih petugas tiket', 'Demo Technician');

        let interceptedCount = 0;
        const assignRoute = '**/admin/dispatch/assign';
        const assignRouteHandler = async (route: Route) => {
            const request = route.request();
            if (request.method() !== 'POST' || interceptedCount > 0) {
                await route.continue();
                return;
            }

            interceptedCount += 1;
            const headers = request.headers();
            const contentType = headers['content-type']?.split(';', 1)[0].trim().toLowerCase();
            const postData = request.postData();
            let transformedPostData = postData;

            if (contentType === 'application/x-www-form-urlencoded' && postData !== null) {
                const formData = new URLSearchParams(postData);
                formData.delete('expected_updated_at');
                transformedPostData = formData.toString();
            } else if (contentType === 'application/json' && postData !== null) {
                const payload: unknown = JSON.parse(postData);
                if (payload === null || typeof payload !== 'object' || Array.isArray(payload)) {
                    throw new Error('Expected a JSON object for assignment');
                }
                delete (payload as Record<string, unknown>).expected_updated_at;
                transformedPostData = JSON.stringify(payload);
            } else {
                throw new Error(
                    `Unsupported assignment request encoding: ${contentType ?? 'unknown'}`,
                );
            }

            await route.continue({ postData: transformedPostData });
        };

        await page.route(assignRoute, assignRouteHandler);
        const submitButton = dialog.getByRole('button', { name: 'Simpan penugasan' });
        const selectedValue = await assigneeSelect.inputValue();
        try {
            const firstResponse = page.waitForResponse(
                (response) =>
                    response.url().endsWith('/admin/dispatch/assign') &&
                    response.request().method() === 'POST',
            );
            await submitButton.click();
            await firstResponse;
            expect(interceptedCount).toBe(1);

            await expect(page.getByText('Terjadi kesalahan', { exact: true })).toBeVisible();
            await expect(page.getByText('Data berubah', { exact: true })).toHaveCount(0);
            await expect(dialog).toBeVisible();
            await expect(assigneeSelect).toHaveValue(selectedValue);
            await expect(submitButton).toBeEnabled();
        } finally {
            await page.unroute(assignRoute, assignRouteHandler);
        }

        await submitButton.click();
        await expect(dialog).toBeHidden();

        await page.goto('/admin/dispatch?status=assigned');
        await expect(page.getByRole('table').getByText('E2E Dispatch Ticket')).toBeVisible();
        await logout(page);
        expect(await myWorkTitles(request)).toContain('E2E Dispatch Ticket');
    });

    test('deduplicates rapid native assignment submits', async ({ page, request }) => {
        await logout(page);
        await loginAs(page, 'admin');
        await page.goto('/admin/dispatch?status=assigned');

        const title = 'E2E Dispatch Ticket';
        const row = page.getByRole('row').filter({ hasText: title }).first();
        await expect(row).toBeVisible();
        await row.getByRole('button', { name: 'Ganti petugas' }).click();

        const dialog = page.getByRole('dialog');
        await expect(dialog).toBeVisible();
        await selectSearchOption(page, 'Pilih petugas tiket', 'Demo Technician');

        const assignRoute = '**/admin/dispatch/assign';
        let requestCount = 0;
        let releaseFirstRequest!: () => void;
        const firstRequestReleased = new Promise<void>((resolve) => {
            releaseFirstRequest = resolve;
        });
        let firstRequestSeen!: () => void;
        const firstRequest = new Promise<void>((resolve) => {
            firstRequestSeen = resolve;
        });
        const requestListener = (request: Request) => {
            if (request.method() === 'POST' && request.url().endsWith('/admin/dispatch/assign')) {
                requestCount += 1;
                if (requestCount === 1) firstRequestSeen();
            }
        };
        const assignRouteHandler = async (route: Route) => {
            const request = route.request();
            if (request.method() === 'POST' && requestCount === 1) {
                await firstRequestReleased;
            }
            await route.continue();
        };

        page.on('request', requestListener);
        await page.route(assignRoute, assignRouteHandler);
        const submitButton = dialog.getByRole('button', { name: 'Simpan penugasan' });
        const assignmentResponse = page.waitForResponse(
            (response) =>
                response.url().endsWith('/admin/dispatch/assign') &&
                response.request().method() === 'POST',
        );

        try {
            await dialog.locator('form').evaluate((element) => {
                if (!(element instanceof HTMLFormElement)) {
                    throw new Error('Assignment form was not found');
                }
                const form = element;
                const submitter = form.querySelector('button[type="submit"]');
                if (!(submitter instanceof HTMLButtonElement)) {
                    throw new Error('Assignment submit button was not found');
                }
                form.requestSubmit(submitter);
                form.requestSubmit(submitter);
            });
            await firstRequest;
            await expect(submitButton).toBeDisabled();
            expect(requestCount).toBe(1);
            releaseFirstRequest();
            await assignmentResponse;
            await expect(dialog).toBeHidden();
            expect(requestCount).toBe(1);
        } finally {
            releaseFirstRequest();
            await page.unroute(assignRoute, assignRouteHandler);
            page.removeListener('request', requestListener);
        }

        await page.goto('/admin/dispatch?status=assigned');
        await expect(page.getByRole('row').filter({ hasText: title })).toHaveCount(1);

        await logout(page);
        expect((await myWorkTitles(request)).filter((mine) => mine === title)).toHaveLength(1);
    });

    for (const outcome of ['success', 'failure', 'cancel'] as const) {
        test(`keeps stale Dispatch assignment fail-closed through reload ${outcome}`, async ({
            page,
            expectHttpError,
        }) => {
            await loginAs(page, 'admin');
            const dialog = await openAssignedDispatchDialog(page);
            const assigneeSelect = dialog.getByRole('combobox', { name: 'Pilih petugas tiket' });
            const originalAssignee = await assigneeSelect.inputValue();
            const assignUrl = new URL('/admin/dispatch/assign', page.url()).href;
            let assignments = 0;
            const staleHandler = async (route: Route) => {
                if (route.request().method() !== 'POST') {
                    await route.continue();
                    return;
                }
                assignments += 1;
                await route.continue({ postData: staleAssignmentBody(route) });
            };

            try {
                await page.route(assignUrl, staleHandler);
                await dialog.getByRole('button', { name: 'Simpan penugasan' }).click();
                await expect(dialog.getByText('Data berubah', { exact: true })).toBeVisible();
                await expect(assigneeSelect).toBeDisabled();
                await expect(dialog.getByRole('button', { name: 'Simpan penugasan' })).toHaveCount(
                    0,
                );
                await expect(dialog.getByRole('status')).toBeFocused();
                expect(assignments).toBe(1);

                await exerciseReload(page, outcome, expectHttpError);

                if (outcome === 'success') {
                    await expect(dialog.getByText('Data berubah', { exact: true })).toHaveCount(0);
                    await expect(assigneeSelect).toBeEnabled();
                    await expect(assigneeSelect).toHaveValue(originalAssignee);
                    await expect(
                        dialog.getByRole('button', { name: 'Simpan penugasan' }),
                    ).toBeEnabled();
                    await dialog.getByRole('button', { name: 'Batal', exact: true }).click();
                    await expect(dialog).toBeHidden();
                } else {
                    await expect(dialog).toBeVisible();
                    await expect(assigneeSelect).toBeDisabled();
                    await expect(
                        dialog.getByRole('button', { name: 'Simpan penugasan' }),
                    ).toHaveCount(0);
                    await expect(
                        dialog.getByRole('button', { name: 'Muat ulang antrean' }),
                    ).toBeEnabled();
                }
                expect(assignments).toBe(1);
            } finally {
                await page.unroute(assignUrl, staleHandler);
            }
        });
    }

    test('opens and cancels dispatch by keyboard without mutation', async ({ page }) => {
        await loginAs(page, 'admin');
        await page.goto('/admin/dispatch?status=assigned');
        const row = page.getByRole('row').filter({ hasText: 'E2E Dispatch Ticket' }).first();
        const opener = row.getByRole('button', { name: 'Ganti petugas', exact: true });
        const dialog = page.getByRole('dialog');
        let mutations = 0;
        const onRequest = (request: Request) => {
            if (
                request.method() === 'POST' &&
                /\/admin\/(dispatch\/assign|tickets\/\d+\/start)$/.test(
                    new URL(request.url()).pathname,
                )
            ) {
                mutations += 1;
            }
        };

        page.on('request', onRequest);
        try {
            await opener.focus();
            await expect(opener).toBeFocused();
            await page.keyboard.press('Enter');
            await expect(dialog).toBeVisible();
            const cancel = dialog.getByRole('button', { name: 'Batal', exact: true });
            await cancel.focus();
            await expect(cancel).toBeFocused();
            await page.keyboard.press('Enter');
            await expect(dialog).toBeHidden();

            await opener.focus();
            await page.keyboard.press('Enter');
            await expect(dialog).toBeVisible();
            await page.keyboard.press('Escape');
            await expect(dialog).toBeHidden();
            expect(mutations).toBe(0);
            await page.reload();
            await expect(opener).toBeEnabled();
        } finally {
            page.removeListener('request', onRequest);
        }
    });

    test('keeps dispatch cards and dialog actions within 320px', async ({ page }) => {
        await loginAs(page, 'admin');
        await page.setViewportSize({ width: 320, height: 800 });
        await page.goto('/admin/dispatch?status=assigned');
        await expect(page.getByRole('table')).toBeHidden();

        for (const title of ['E2E Dispatch Ticket', 'E2E Dispatch SPK']) {
            const card = page
                .locator('div.md\\:hidden .rounded-lg.border')
                .filter({ has: page.getByText(title, { exact: true }) })
                .last();
            await expectMobileBounds(page, card);
            const opener = card.getByRole('button', { name: 'Ganti petugas', exact: true });
            await expectMobileBounds(page, opener);
            expect((await opener.boundingBox())!.height).toBeGreaterThanOrEqual(44);
            await opener.click();

            const dialog = page.getByRole('dialog');
            await expectMobileBounds(page, dialog);
            const submit = dialog.getByRole('button', {
                name: 'Simpan penugasan',
                exact: true,
            });
            const cancel = dialog.getByRole('button', { name: 'Batal', exact: true });
            for (const action of [submit, cancel]) {
                await action.scrollIntoViewIfNeeded();
                await expectMobileBounds(page, action);
                expect((await action.boundingBox())!.height).toBeGreaterThanOrEqual(44);
            }
            await cancel.click();
            await expect(dialog).toBeHidden();
            await expect(opener).toBeEnabled();
        }
    });
});
