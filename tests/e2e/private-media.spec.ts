/**
 * @private-media Uploaded files are served only through the authorized media route
 *
 * Coverage:
 * - staff uploads a ticket attachment; preview, open and download all use /admin/media/{id}
 * - the preview holds at 320 and 1280 px in light and dark
 * - the owning customer's app fetches the same attachment through the API; another customer's is 404
 * - a PDF attachment is a download only: no Open link, served as an attachment
 * - a guest is sent to login; the file lives on the private disk and /storage serves nothing
 *
 * Deterministic: unique records per run, no fixed IDs.
 */
import { existsSync } from 'node:fs';
import { readdir, readFile } from 'node:fs/promises';
import { join } from 'node:path';
import type { Page } from '@playwright/test';

import { apiLogin } from './support/api';
import { expect, test } from './support/fixtures';
import {
    PNG_1X1,
    datatableCustomerNames,
    e2eCustomerName,
    inertiaProps,
    loginAs,
    selectSearchOption,
    unique,
} from './support/demo';

const runId = unique('MEDIA');
// A second seeded customer: the demo customer owns the first ticket, not this one.
const otherCustomerName = datatableCustomerNames[0];
const ownTitle = `E2E private media own ${runId}`;
const otherTitle = `E2E private media other ${runId}`;
const fileName = `private-media-${runId}.png`;
const attachmentName = `private-media-${runId}`;
const pdfName = `contract-${runId}`;

type UploadFile = { name: string; mimeType: string; buffer: Buffer };

const png: UploadFile = { name: fileName, mimeType: 'image/png', buffer: PNG_1X1 };
const pdf: UploadFile = {
    name: `${pdfName}.pdf`,
    mimeType: 'application/pdf',
    buffer: Buffer.from(
        '%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n',
    ),
};

test.describe('@private-media ticket attachment', () => {
    test.describe.configure({ mode: 'serial' });

    let ownTicketId = '';
    let ownMediaUrl = '';
    let otherMediaUrl = '';

    test('staff uploads an attachment, then previews, opens and downloads it', async ({ page }) => {
        test.setTimeout(240_000);
        await loginAs(page, 'admin');
        ownTicketId = await createTicket(page, ownTitle, e2eCustomerName);
        const otherTicketId = await createTicket(page, otherTitle, otherCustomerName);
        ownMediaUrl = await uploadAttachment(page, ownTicketId, ownTitle, png);
        otherMediaUrl = await uploadAttachment(page, otherTicketId, otherTitle, png);

        expect(ownMediaUrl).toMatch(/^\/admin\/media\/[0-9A-HJKMNP-TV-Z]{26}$/i);
        expect(otherMediaUrl).toMatch(/^\/admin\/media\/[0-9A-HJKMNP-TV-Z]{26}$/i);

        await settleAppChunks(page);
        await page.goto(`/admin/tickets/${ownTicketId}`);
        await expectPreview(page, ownMediaUrl);

        // Open in a new tab: same route, rendered inline, never cached by shared caches.
        const open = page.getByRole('link', { name: `Buka ${attachmentName} di tab baru` });
        await expect(open).toHaveAttribute('href', ownMediaUrl);
        await expect(open).toHaveAttribute('target', '_blank');
        const opened = await page.request.get(ownMediaUrl);
        expect(opened.status()).toBe(200);
        expect(opened.headers()['content-type']).toBe('image/png');
        expect(opened.headers()['content-disposition']).toMatch(/^inline;/);
        expect(opened.headers()['cache-control']).toContain('private');
        expect(await opened.body()).toEqual(PNG_1X1);

        // Download: the file comes back byte for byte under its own name.
        const [download] = await Promise.all([
            page.waitForEvent('download'),
            page.getByRole('link', { name: `Unduh ${attachmentName}` }).click(),
        ]);
        expect(download.suggestedFilename()).toBe(fileName);
        expect(await readFile(await download.path())).toEqual(PNG_1X1);

        for (const width of [1280, 320]) {
            for (const dark of [false, true]) {
                await page.setViewportSize({ width, height: 800 });
                await page.evaluate(
                    (value) => localStorage.setItem('darkMode', String(value)),
                    dark,
                );
                await settleAppChunks(page);
                await page.goto(`/admin/tickets/${ownTicketId}`);
                await expect(page.locator('html')).toHaveClass(
                    dark ? /\bdark\b/ : /^(?!.*\bdark\b)/,
                );
                await expectPreview(page, ownMediaUrl);
                await expect(
                    page.getByRole('link', { name: `Unduh ${attachmentName}` }),
                ).toBeVisible();
                expect(
                    await page.evaluate(
                        () => document.documentElement.scrollWidth <= window.innerWidth,
                    ),
                    `${width}px ${dark ? 'dark' : 'light'}: no horizontal overflow`,
                ).toBe(true);
            }
        }
        await page.evaluate(() => localStorage.setItem('darkMode', 'false'));
    });

    test("the owning customer's app fetches it; another customer's attachment is not found", async ({
        request,
    }) => {
        // Customers use the app, so the media is fetched through the mobile API (a token of the customer app).
        const api = await apiLogin(request, 'customer');
        const mediaId = (url: string) => url.match(/\/([0-9A-HJKMNP-TV-Z]{26})$/i)?.[1] ?? '';

        const own = await api.get(`media/${mediaId(ownMediaUrl)}`);
        expect(own.status()).toBe(200);
        expect(own.headers()['content-type']).toMatch(/^image\/png/);
        expect((await api.get(`media/${mediaId(otherMediaUrl)}`)).status()).toBe(404);

        const ticket = await api.get(`customer/tickets/${ownTicketId}`);
        expect(ticket.status()).toBe(200);
    });

    test('a PDF attachment is a download only: no Open link, served as an attachment', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        await loginAs(page, 'admin');
        const pdfUrl = await uploadAttachment(page, ownTicketId, ownTitle, pdf);

        await settleAppChunks(page);
        await page.goto(`/admin/tickets/${ownTicketId}`);
        await expect(page.getByRole('link', { name: `Unduh ${pdfName}` })).toHaveAttribute(
            'href',
            pdfUrl,
        );
        await expect(page.getByRole('link', { name: `Buka ${pdfName} di tab baru` })).toHaveCount(
            0,
        );

        const response = await page.request.get(pdfUrl);
        expect(response.status()).toBe(200);
        expect(response.headers()['content-type']).toBe('application/pdf');
        expect(response.headers()['content-disposition']).toMatch(/^attachment;/);
        expect(response.headers()['x-content-type-options']).toBe('nosniff');
    });

    test('a guest is sent to login and the file lives on the private disk only', async ({
        request,
    }) => {
        const guest = await request.get(ownMediaUrl, { maxRedirects: 0 });
        expect(guest.status()).toBe(302);
        expect(guest.headers().location).toMatch(/\/login$/);

        const storage = join(process.cwd(), 'storage', 'app');
        const privateRoot = existsSync(join(storage, 'private', 'e2e'))
            ? join(storage, 'private', 'e2e')
            : join(storage, 'private');
        const publicRoot = existsSync(join(storage, 'public', 'e2e'))
            ? join(storage, 'public', 'e2e')
            : join(storage, 'public');
        const stored = (await readdir(privateRoot, { recursive: true })).filter(
            (path) => path.endsWith(fileName),
        );
        expect(stored).toHaveLength(2);
        expect(
            (await readdir(publicRoot, { recursive: true })).filter((path) =>
                path.endsWith(fileName),
            ),
        ).toEqual([]);

        const legacy = await request.get(`/storage/${stored[0]}`, { maxRedirects: 0 });
        expect([403, 404]).toContain(legacy.status());
    });
});

async function createTicket(page: Page, title: string, customerName: string) {
    await settleAppChunks(page);
    await page.goto('/admin/tickets/create');
    await expect(page.getByRole('main').getByRole('heading', { name: 'Buat Tiket' })).toBeVisible();
    await page.getByLabel('Judul').fill(title);
    await page.getByLabel('Sumber').selectOption('customer');
    await settleAppChunks(page);
    await selectSearchOption(page, 'Kategori', 'Masalah perangkat');
    await selectSearchOption(page, 'Pelanggan', customerName);
    await page.getByRole('button', { name: 'Simpan', exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/tickets/);

    const props = await inertiaProps(page, `/admin/tickets?search=${encodeURIComponent(title)}`);
    const id = props.props?.tickets?.data?.find(
        (ticket: { title: string }) => ticket.title === title,
    )?.id;
    if (!id) throw new Error(`Could not resolve ticket id for ${title}`);

    return String(id);
}

/** Uploads a file through the ticket page and returns the media route URL the page now exposes for it. */
async function uploadAttachment(page: Page, ticketId: string, title: string, file: UploadFile) {
    await settleAppChunks(page);
    await page.goto(`/admin/tickets/${ticketId}`);
    await expect(page.getByRole('main').getByRole('heading', { name: title })).toBeVisible();
    await page.locator('input[type="file"]').setInputFiles(file);
    await page.getByRole('button', { name: 'Unggah', exact: true }).click();
    await expect(page.getByText('Lampiran berhasil diunggah.').first()).toBeVisible({
        timeout: 15_000,
    });

    const props = await inertiaProps(page, `/admin/tickets/${ticketId}`);
    const attachment = props.props?.ticket?.data?.attachments?.find(
        (entry: { file_name: string }) => entry.file_name === file.name,
    );
    if (!attachment) throw new Error(`No attachment ${file.name} on ticket ${ticketId}`);

    return attachment.url as string;
}

async function expectPreview(page: Page, url: string) {
    const image = page.getByRole('img', { name: attachmentName });
    await expect(image).toBeVisible();
    await expect(image).toHaveAttribute('src', url);
    await expect
        .poll(() => image.evaluate((el: HTMLImageElement) => el.complete && el.naturalWidth > 0))
        .toBe(true);
}

/**
 * The page we navigate away from lazily loads chunks; a hard goto() while they are in flight aborts
 * them with net::ERR_ABORTED, which the fail-closed watcher treats as journey-breaking.
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
            let previous = scriptCount();
            let stableSince = performance.now();
            return new Promise<boolean>((resolve) => {
                const tick = () => {
                    const now = scriptCount();
                    if (now !== previous) {
                        previous = now;
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
