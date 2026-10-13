import { expect, test } from './support/fixtures';
import { demoUsers, loginAs, type DemoRole } from './support/demo';

// Technician and customer accounts are app-only on the web (app-only.spec.ts); they have no dashboard.
type WebRole = Exclude<DemoRole, 'technician' | 'customer'>;

const roleVisibility: Record<
    WebRole,
    {
        groups: string[];
        cards: string[];
        hiddenGroups: string[];
        adminStats?: boolean;
    }
> = {
    admin: {
        groups: ['Operasional', 'Persetujuan', 'Gudang', 'Keuangan', 'Notifikasi', 'Administrasi'],
        cards: [
            'Belum ditugaskan',
            'SLA terlampaui',
            'Menunggu persetujuan saya',
            'Antrean permintaan gudang',
            'Antrean retur material',
            'Stok di bawah minimum',
            'Invoice jatuh tempo',
        ],
        hiddenGroups: ['Aktivitas saya'],
        adminStats: true,
    },
    manager: {
        groups: ['Operasional', 'Persetujuan', 'Gudang', 'Keuangan', 'Notifikasi'],
        cards: [
            'Pekerjaan saya',
            'Belum ditugaskan',
            'SLA terlampaui',
            'Menunggu persetujuan saya',
            'Antrean permintaan gudang',
            'Antrean retur material',
            'Stok di bawah minimum',
            'Invoice jatuh tempo',
        ],
        hiddenGroups: ['Aktivitas saya', 'Administrasi'],
        adminStats: false,
    },
    staff: {
        groups: ['Operasional', 'Gudang', 'Keuangan', 'Notifikasi'],
        cards: [
            'Pekerjaan saya',
            'SLA terlampaui',
            'Antrean permintaan gudang',
            'Antrean retur material',
            'Stok di bawah minimum',
            'Invoice jatuh tempo',
        ],
        // Operator persona (role-persona-sod): staff cannot approve, so no Persetujuan group.
        hiddenGroups: ['Persetujuan', 'Aktivitas saya', 'Administrasi'],
        adminStats: false,
    },
};

for (const demoUser of demoUsers) {
    const { role } = demoUser;
    if (role === 'technician' || role === 'customer') continue;
    const expectations = roleVisibility[role];

    test(`@dashboard-persona ${role} sees assigned capability groups and cards`, async ({
        page,
    }) => {
        await loginAs(page, role);

        const main = page.getByRole('main');
        await expect(main.getByRole('heading', { name: 'Dasbor', level: 1 })).toBeVisible();

        for (const title of expectations.groups) {
            await expect(main.getByRole('region', { name: title })).toBeVisible();
        }

        for (const title of expectations.hiddenGroups) {
            await expect(main.getByRole('region', { name: title })).toBeHidden();
        }

        for (const title of expectations.cards) {
            await expect(main.getByRole('heading', { name: title, level: 3 })).toBeVisible();
        }

        const notifRegion = main.getByRole('region', { name: 'Notifikasi' });
        await expect(
            notifRegion.getByRole('link', { name: 'Lihat semua notifikasi' }),
        ).toBeVisible();
    });

    test(`@dashboard-persona ${role} card links return 200 responses`, async ({ page }) => {
        await loginAs(page, role);

        const main = page.getByRole('main');

        // Wait for page to finish any lazy loaded chunks before grabbing hrefs
        await expect(main.getByRole('heading', { name: 'Dasbor', level: 1 })).toBeVisible();

        // Collect all distinct hrefs from section cards and SLA items, excluding notifications
        const links = main.locator(
            'section:not([aria-labelledby="dashboard-notifications"]):not([aria-labelledby="dashboard-admin"]) a[href]',
        );

        const count = await links.count();
        const hrefs = new Set<string>();

        for (let i = 0; i < count; i++) {
            const href = await links.nth(i).getAttribute('href');
            if (href) hrefs.add(href);
        }

        expect(hrefs.size).toBeGreaterThan(0);

        for (const href of hrefs) {
            const response = await page.request.get(href);
            expect(response.status(), `GET ${href} must be 200 OK`).toBe(200);
        }
    });
}

test('@dashboard-persona admin block contents visible only for admin', async ({ page }) => {
    await loginAs(page, 'admin');

    const adminRegion = page.getByRole('region', { name: 'Administrasi' });
    await expect(adminRegion).toBeVisible();

    await expect(adminRegion.getByText('Pengguna', { exact: true })).toBeVisible();
    await expect(adminRegion.getByRole('heading', { name: 'Perusahaan', level: 3 })).toBeVisible();
    await expect(
        adminRegion.getByRole('heading', { name: 'Aktivitas terbaru', level: 3 }),
    ).toBeVisible();
    await expect(adminRegion.getByRole('heading', { name: 'Modul', level: 3 })).toBeVisible();

    // Check one module link exists
    await expect(adminRegion.getByRole('link').first()).toBeVisible();

    await page.context().clearCookies();
    await loginAs(page, 'manager');

    await expect(page.getByRole('region', { name: 'Administrasi' })).toBeHidden();
});

for (const dark of [false, true]) {
    test(`@dashboard-persona ${dark ? 'dark' : 'light'} layout prevents overflow and maintains focus visibility at 320px`, async ({
        page,
    }) => {
        await page.setViewportSize({ width: 320, height: 800 });
        await loginAs(page, 'admin');
        await page.evaluate((value) => localStorage.setItem('darkMode', String(value)), dark);
        await page.reload();

        const html = page.locator('html');
        await expect(html).toHaveClass(dark ? /dark/ : /^(?!.*dark)/);

        const main = page.getByRole('main');
        await expect(main.getByRole('heading', { name: 'Dasbor', level: 1 })).toBeVisible();

        const overflow = await page.evaluate(() => {
            const root = document.documentElement;
            const scrollableMain = document.querySelector('main');

            return {
                document: root.scrollWidth - root.clientWidth,
                main: scrollableMain
                    ? scrollableMain.scrollWidth - scrollableMain.clientWidth
                    : null,
            };
        });

        expect(overflow.document, 'document overflows horizontally').toBeLessThanOrEqual(0);
        expect(overflow.main, 'main overflows horizontally').toBeLessThanOrEqual(0);

        // Verify focus styling exists on a section card
        const cardLink = main.locator('section a[href]').first();
        await cardLink.focus();
        await expect(cardLink).toBeFocused();

        const hasFocusIndicator = await cardLink.evaluate((el) => {
            const style = getComputedStyle(el);
            return style.outlineStyle !== 'none' || style.boxShadow !== 'none';
        });

        expect(hasFocusIndicator, 'Keyboard focus has a visible outline or ring').toBe(true);
    });
}
