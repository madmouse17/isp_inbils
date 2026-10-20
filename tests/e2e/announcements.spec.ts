/**
 * @announcements Pengumuman & Promo (.scratch/announcements-promo/spec.md, ticket 03)
 *
 * Coverage:
 * - the sidebar link and the screen: the manager opens them, staff has no link and gets 403
 * - an announcement is saved as a draft, published after a confirmation, shows Tayang, reaches the home feed of the
 *   demo customer, and leaves it again once deactivated; the phone preview follows the form and has its own theme
 * - a banner: an image that is not 2:1 is refused in the browser, a 2:1 one is saved, published and carried by the feed
 *
 * Deterministic: a unique title per test run, so a repeated run never meets a title it already created. A published
 * row cannot be deleted, so each one is deactivated at the end and stays behind as history; e2e-setup resets it.
 */
import type { Page } from '@playwright/test';

import { apiLogin, type ApiClient } from './support/api';
import { expect, test } from './support/fixtures';
import { PNG_1X1, loginAs, unique } from './support/demo';

const newAnnouncementTitle = () => `E2E Gangguan ${unique('ANN')}`;
const newBannerTitle = () => `E2E Promo ${unique('ANN')}`;

/** 2 x 1 pixels: the smallest picture of the 2:1 ratio a banner needs. */
const PNG_2X1 = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAYAAAD0In+KAAAADklEQVR4nGP4z8AAQv8BD/kD/YURmXYAAAAASUVORK5CYII=',
    'base64',
);

type Feed = {
    data: {
        announcements: Array<{ title: string; kind: string }>;
        banners: Array<{
            title: string;
            alt_text: string;
            link_kind: string;
            link_target: string | null;
            image_url: string | null;
        }>;
    };
};

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

/** The home feed the demo customer gets from the mobile API. */
async function customerFeed(customer: ApiClient): Promise<Feed> {
    const response = await customer.get('customer/home-feed');
    expect(response.status(), 'GET /api/v1/customer/home-feed').toBe(200);

    return (await response.json()) as Feed;
}

test.describe('@announcements', () => {
    test.describe.configure({ mode: 'serial' });

    test('the manager opens Pengumuman & Promo from the sidebar and staff is refused', async ({
        page,
    }) => {
        // Sidebar groups are collapsed <details> unless they hold the current page.
        const link = page.locator('aside a[href$="/admin/announcements"]');

        await loginAs(page, 'manager');
        await page
            .locator('aside details')
            .filter({ has: page.locator('summary', { hasText: 'Pelanggan & Layanan' }) })
            .locator('summary')
            .click();
        await page
            .getByRole('navigation')
            .getByRole('link', { name: 'Pengumuman & Promo' })
            .click();
        await expect(page).toHaveURL(/\/admin\/announcements$/);
        await expect(
            page.getByRole('main').getByRole('heading', { name: 'Pengumuman & Promo' }),
        ).toBeVisible();

        await page.context().clearCookies();
        await loginAs(page, 'staff');
        await expect(link).toHaveCount(0);
        expect((await page.request.get('/admin/announcements')).status()).toBe(403);
    });

    test('an announcement is drafted, published, carried by the customer feed and deactivated', async ({
        page,
    }) => {
        const announcementTitle = newAnnouncementTitle();
        await loginAs(page, 'admin');
        // One customer sign-in per test: the login endpoint allows 20 a minute per address, and a repeated run needs more.
        const customer = await apiLogin(page.request, 'customer');
        await settleAppChunks(page);
        await page.goto('/admin/announcements');

        const main = page.getByRole('main');
        await main.getByRole('button', { name: 'Buat Pengumuman' }).first().click();
        const dialog = page.getByRole('dialog', { name: 'Buat Pengumuman' });
        await dialog.getByLabel('Jenis').selectOption('outage');
        await dialog.getByLabel('Judul').fill(announcementTitle);
        await dialog.getByLabel('Isi').fill('Perbaikan kabel di Bandung.\nSelesai pukul 16.00.');

        // The phone shows what is typed, and keeps its own Terang or Gelap choice.
        const phone = dialog.getByRole('group', { name: 'Pratinjau beranda aplikasi pelanggan' });
        await expect(phone.getByText(announcementTitle)).toBeVisible();
        await expect(phone.getByText('Perbaikan kabel di Bandung.')).toBeVisible();
        await dialog.getByRole('button', { name: 'Gelap' }).click();
        await expect(dialog.getByRole('button', { name: 'Gelap' })).toHaveAttribute(
            'aria-pressed',
            'true',
        );

        await dialog.getByRole('button', { name: 'Simpan', exact: true }).click();
        await expect(dialog).toBeHidden();

        const row = main.getByRole('row').filter({ hasText: announcementTitle });
        await expect(row).toContainText('Draf');
        await expect(row).toContainText('Gangguan');
        await expect(row).toContainText('Semua pelanggan');
        expect(
            (await customerFeed(customer)).data.announcements.map((item) => item.title),
        ).not.toContain(announcementTitle);

        await row
            .getByRole('button', { name: `Terbitkan pengumuman ${announcementTitle}` })
            .click();
        const publish = page.getByRole('alertdialog');
        await expect(publish).toContainText('setiap pelanggan sasaran menerima satu notifikasi');
        await publish.getByRole('button', { name: 'Terbitkan', exact: true }).click();
        await expect(row.getByText('Tayang', { exact: true })).toBeVisible();

        const feed = await customerFeed(customer);
        expect(feed.data.announcements.find((item) => item.title === announcementTitle)?.kind).toBe(
            'outage',
        );

        await row
            .getByRole('button', { name: `Nonaktifkan pengumuman ${announcementTitle}` })
            .click();
        await page
            .getByRole('alertdialog')
            .getByRole('button', { name: 'Nonaktifkan', exact: true })
            .click();
        await expect(row.getByText('Nonaktif', { exact: true })).toBeVisible();
        expect(
            (await customerFeed(customer)).data.announcements.map((item) => item.title),
        ).not.toContain(announcementTitle);
    });

    test('a banner needs a 2:1 image: another ratio is refused, 2:1 is saved, published and carried by the feed', async ({
        page,
    }) => {
        const bannerTitle = newBannerTitle();
        await loginAs(page, 'admin');
        const customer = await apiLogin(page.request, 'customer');
        await settleAppChunks(page);
        await page.goto('/admin/announcements?tab=banners');

        const main = page.getByRole('main');
        await main.getByRole('button', { name: 'Buat Banner Promo' }).first().click();
        const dialog = page.getByRole('dialog', { name: 'Buat Banner Promo' });
        const file = dialog.locator('input[type="file"]');

        // A square picture is refused before anything is sent, with the size it measured.
        await file.setInputFiles({ name: 'persegi.png', mimeType: 'image/png', buffer: PNG_1X1 });
        await expect(
            dialog.getByText('Gambar ini 1 x 1 piksel. Rasio harus 2:1', { exact: false }),
        ).toBeVisible();

        await file.setInputFiles({ name: 'banner.png', mimeType: 'image/png', buffer: PNG_2X1 });
        await expect(dialog.getByText('Rasio harus 2:1', { exact: false })).toBeHidden();

        await dialog.getByLabel('Judul').fill(bannerTitle);
        await dialog.getByLabel('Teks alternatif').fill('Poster promo E2E');
        await dialog.getByLabel('Saat banner diketuk').selectOption('app');
        await dialog.getByLabel('Halaman di aplikasi').selectOption('invoices');
        await expect(
            dialog
                .getByRole('group', { name: 'Pratinjau beranda aplikasi pelanggan' })
                .getByRole('img', { name: 'Poster promo E2E' }),
        ).toBeVisible();

        await dialog.getByRole('button', { name: 'Simpan', exact: true }).click();
        await expect(dialog).toBeHidden();

        const row = main.getByRole('row').filter({ hasText: bannerTitle });
        await expect(row).toContainText('Draf');
        await expect(row).toContainText('Membuka halaman Tagihan');

        await row.getByRole('button', { name: `Terbitkan banner ${bannerTitle}` }).click();
        await page
            .getByRole('alertdialog')
            .getByRole('button', { name: 'Terbitkan', exact: true })
            .click();
        await expect(row.getByText('Tayang', { exact: true })).toBeVisible();

        const banner = (await customerFeed(customer)).data.banners.find(
            (item) => item.title === bannerTitle,
        );
        expect(banner).toMatchObject({
            alt_text: 'Poster promo E2E',
            link_kind: 'app',
            link_target: 'invoices',
        });
        expect(banner?.image_url).toBeTruthy();

        await row.getByRole('button', { name: `Nonaktifkan banner ${bannerTitle}` }).click();
        await page
            .getByRole('alertdialog')
            .getByRole('button', { name: 'Nonaktifkan', exact: true })
            .click();
        await expect(row.getByText('Nonaktif', { exact: true })).toBeVisible();
        expect((await customerFeed(customer)).data.banners.map((item) => item.title)).not.toContain(
            bannerTitle,
        );
    });
});
