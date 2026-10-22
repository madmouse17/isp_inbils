import type { Page } from '@playwright/test';

import { loginAs, type DemoRole } from './support/demo';
import { expect, test } from './support/fixtures';

/**
 * Sidebar information architecture (resources/js/lib/navigation.ts): one standalone Dashboard
 * link plus up to six titled `<details>` groups, each shown only when the actor can open
 * something inside it. Expectations per role derive from RolePermissionSeeder and the demo data.
 */

const groupTitles = [
    'Operasional',
    'Pelanggan & Layanan',
    'Jaringan',
    'Inventaris',
    'Keuangan & Laporan',
    'Administrasi',
] as const;

type GroupTitle = (typeof groupTitles)[number];

type RoleSidebar = {
    /** Titled groups rendered for the role, in sidebar order. */
    groups: readonly GroupTitle[];
    /** Links that must sit inside the given group. */
    placed: Partial<Record<GroupTitle, readonly string[]>>;
    /** Links the role must not see anywhere. */
    absent: readonly string[];
};

const serviceLinks = [
    'Paket Layanan',
    'Kategori Paket',
    'Profil Bandwidth',
    'Profil Kecepatan',
    'Tingkat SLA',
];
const inventoryLinks = [
    'Permintaan Barang',
    'Barang',
    'Kategori',
    'Satuan',
    'Stok',
    'Cari Barang',
    'Mutasi Stok',
];
const financeLinks = ['Tagihan', 'Tunggakan', 'Laporan', 'Kinerja Teknisi'];
// IPAM and ODN are readable by every staff-side role (Q14 of .scratch/ipam-odn/spec.md); NAS routers
// need their own read permission, which the technician lacks.
const networkRegisterLinks = ['Alamat IP', 'ODN'];
const adminOnlyLinks = [
    'Pengguna',
    'Peran',
    'Hak Akses',
    'Karyawan',
    'Kendaraan',
    'Jenis Dokumen',
    'Penomoran',
    'Organisasi',
    'Perusahaan',
];

// APP_ENV=e2e serves the developer gallery route but `developerGalleryEnabled` is false, so
// Komponen is absent from the sidebar for every role, admin included.
// Technician and customer accounts are app-only on the web (app-only.spec.ts): no sidebar to check.
type WebRole = Exclude<DemoRole, 'technician' | 'customer'>;

const roleSidebars: Record<WebRole, RoleSidebar> = {
    admin: {
        groups: groupTitles,
        placed: {
            Operasional: ['SPK', 'Persetujuan', 'Penugasan', 'Tiket', 'Evaluasi'],
            'Pelanggan & Layanan': ['Pelanggan', ...serviceLinks],
            Jaringan: ['Lokasi', 'Aset Jaringan', 'Router NAS', ...networkRegisterLinks],
            Inventaris: inventoryLinks,
            'Keuangan & Laporan': financeLinks,
            Administrasi: [
                'Perusahaan',
                'Organisasi',
                'Karyawan',
                'Kendaraan',
                'Pengguna',
                'Peran',
                'Hak Akses',
                'Jenis Dokumen',
                'Penomoran',
            ],
        },
        // The admin demo user has no employee profile, hence no Pekerjaan Saya.
        absent: ['Komponen', 'Pekerjaan Saya'],
    },
    manager: {
        groups: [
            'Operasional',
            'Pelanggan & Layanan',
            'Jaringan',
            'Inventaris',
            'Keuangan & Laporan',
        ],
        placed: {
            Operasional: ['Pekerjaan Saya', 'Penugasan', 'Persetujuan', 'SPK', 'Tiket', 'Evaluasi'],
            'Pelanggan & Layanan': ['Pelanggan', ...serviceLinks],
            Jaringan: ['Lokasi', 'Aset Jaringan', 'Router NAS', ...networkRegisterLinks],
            Inventaris: inventoryLinks,
            'Keuangan & Laporan': ['Tagihan', 'Tunggakan', 'Laporan'],
        },
        // The demo manager sits in no organization, so the server withholds the technician report.
        absent: [...adminOnlyLinks, 'Kinerja Teknisi', 'Komponen'],
    },
    staff: {
        groups: [
            'Operasional',
            'Pelanggan & Layanan',
            'Jaringan',
            'Inventaris',
            'Keuangan & Laporan',
        ],
        placed: {
            Operasional: ['Pekerjaan Saya', 'SPK', 'Tiket', 'Evaluasi'],
            'Pelanggan & Layanan': ['Pelanggan', ...serviceLinks],
            Jaringan: ['Lokasi', 'Aset Jaringan', 'Router NAS', ...networkRegisterLinks],
            Inventaris: inventoryLinks,
            'Keuangan & Laporan': ['Tagihan', 'Tunggakan', 'Laporan'],
        },
        // Staff is the operator persona (role-persona-sod): no approve/reject, so no Approval inbox.
        absent: [...adminOnlyLinks, 'Persetujuan', 'Penugasan', 'Kinerja Teknisi', 'Komponen'],
    },
};

const roles = Object.keys(roleSidebars) as WebRole[];

type SidebarLink = { label: string; href: string; current: boolean };
type SidebarGroup = { title: string; open: boolean; links: SidebarLink[] };
type SidebarSnapshot = { standalone: SidebarLink[]; groups: SidebarGroup[] };

for (const role of roles) {
    test(`@sidebar-ia ${role}: titled groups, placement and visibility`, async ({ page }) => {
        const expected = roleSidebars[role];

        await loginAs(page, role);
        await expandAllGroups(page);
        const snapshot = await sidebarSnapshot(page);
        const labels = [...snapshot.standalone, ...snapshot.groups.flatMap((g) => g.links)].map(
            (link) => link.label,
        );

        // Group titles are exactly the role's groups, never System/Developer or Dashboard.
        const titles = snapshot.groups.map((group) => group.title);
        expect(titles).toEqual(expected.groups);
        expect(titles).not.toContain('System/Developer');
        expect(titles).not.toContain('Dasbor');

        // Dashboard is the only entry outside a group: titled groups + 1 stay within 7.
        expect(snapshot.standalone.map((link) => link.label)).toEqual(['Dasbor']);
        const entries = snapshot.groups.length + snapshot.standalone.length;
        expect(entries).toBe(expected.groups.length + 1);
        expect(entries).toBeLessThanOrEqual(7);

        // No empty group header.
        for (const group of snapshot.groups) {
            expect(group.links.length, `${role}: group ${group.title} is empty`).toBeGreaterThan(0);
        }

        for (const [title, placed] of Object.entries(expected.placed)) {
            const group = snapshot.groups.find((entry) => entry.title === title);
            expect(
                group?.links.map((link) => link.label),
                `${role}: ${title}`,
            ).toEqual(expect.arrayContaining([...placed]));
        }

        for (const label of expected.absent) {
            expect(labels, `${role} must not see ${label}`).not.toContain(label);
        }

        // Every link is actually reachable once the groups are expanded.
        await expect(page.locator('aside a[href]:visible')).toHaveCount(labels.length);
    });

    test(`@sidebar-ia ${role}: every visible link opens and the gallery link stays hidden`, async ({
        page,
    }) => {
        test.setTimeout(120_000);

        await loginAs(page, role);
        await expandAllGroups(page);
        const hrefs = await page
            .locator('aside a[href]')
            .evaluateAll((links) => links.map((link) => link.getAttribute('href') ?? ''));
        expect(hrefs.length).toBeGreaterThan(0);
        expect(hrefs).not.toContain('/admin/components');

        const failures: string[] = [];
        for (const href of hrefs) {
            const status = (await page.request.get(href)).status();
            if (status !== 200) failures.push(`${href} answered ${status}`);
        }
        expect(failures).toEqual([]);

        // The sidebar never links the gallery, but the route answers in this environment: 200 for admin, 403 for everyone else.
        const gallery = await page.request.get('/admin/components');
        expect(gallery.status()).toBe(role === 'admin' ? 200 : 403);
    });
}

test('@sidebar-ia active link and default-open group follow the route', async ({ page }) => {
    const cases: { path: string; open: GroupTitle[]; current: string }[] = [
        { path: '/admin/dashboard', open: [], current: 'Dasbor' },
        {
            path: '/admin/reports/technician',
            open: ['Keuangan & Laporan'],
            current: 'Kinerja Teknisi',
        },
        { path: '/admin/reports', open: ['Keuangan & Laporan'], current: 'Laporan' },
        { path: '/admin/reports/business', open: ['Keuangan & Laporan'], current: 'Laporan' },
        { path: '/admin/users', open: ['Administrasi'], current: 'Pengguna' },
    ];

    await loginAs(page, 'admin');

    for (const { path, open, current } of cases) {
        await settleAppChunks(page);
        const response = await page.goto(path);
        expect(response?.status(), path).toBe(200);
        // Retries until the layout hydrated and marked the route's link.
        await expect(page.locator('aside a[aria-current="page"]'), path).toHaveCount(1);

        const snapshot = await sidebarSnapshot(page);
        const links = [...snapshot.standalone, ...snapshot.groups.flatMap((g) => g.links)];

        expect(
            links.filter((link) => link.current).map((link) => link.label),
            `${path}: current link`,
        ).toEqual([current]);
        expect(
            snapshot.groups.filter((group) => group.open).map((group) => group.title),
            `${path}: open groups`,
        ).toEqual(open);
    }
});

test('@sidebar-ia keyboard opens a group, reaches its link and navigates', async ({ page }) => {
    await loginAs(page, 'admin');

    const group = page
        .locator('aside details')
        .filter({ has: page.locator('summary', { hasText: 'Pelanggan & Layanan' }) });
    const summary = group.locator('summary');

    await expect(group).toHaveJSProperty('open', false);
    await summary.focus();
    await expect(summary).toBeFocused();

    await page.keyboard.press('Enter');
    await expect(group).toHaveJSProperty('open', true);
    await page.keyboard.press('Space');
    await expect(group).toHaveJSProperty('open', false);
    await page.keyboard.press('Enter');
    await expect(group).toHaveJSProperty('open', true);

    await page.keyboard.press('Tab');
    const customers = group.getByRole('link', { name: sidebarLabel('Pelanggan') });
    await expect(customers).toBeFocused();
    await expect(customers).not.toHaveCSS('box-shadow', 'none');

    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/\/admin\/customers$/);
    await expect(customers).toHaveAttribute('aria-current', 'page');
    await expect(group).toHaveJSProperty('open', true);
});

for (const dark of [false, true]) {
    test(`@sidebar-ia ${dark ? 'dark' : 'light'} theme: desktop sidebar and mobile drawer`, async ({
        page,
    }) => {
        const aside = page.locator('aside');
        const toggle = page.getByRole('button', { name: 'Menu navigasi' });

        await loginAs(page, 'admin');

        // 1280px: the sidebar is always on screen and has no toggle.
        await applyLayout(page, 1280, dark);
        await expect(aside).toBeInViewport();
        await expect(toggle).toBeHidden();
        await expandAllGroups(page);
        await expectNoHorizontalOverflow(page);
        const desktopLabels = await linkLabels(page);

        // 320px: off canvas until the toggle is pressed.
        await applyLayout(page, 320, dark);
        await expect(aside).not.toBeInViewport();
        await expectNoHorizontalOverflow(page);
        await toggle.click();
        await expect(aside).toBeInViewport();
        await expandAllGroups(page);
        await expectNoHorizontalOverflow(page);
        expect(await linkLabels(page)).toEqual(desktopLabels);

        // The overlay strip right of the 288px drawer closes it.
        await page.mouse.click(310, 400);
        await expect(aside).not.toBeInViewport();

        // Following a link closes the drawer on the next page.
        await toggle.click();
        await expect(aside).toBeInViewport();
        await openGroup(page, 'Pelanggan & Layanan');
        await aside.getByRole('link', { name: sidebarLabel('Pelanggan') }).click();
        await expect(page).toHaveURL(/\/admin\/customers$/);
        await expect(aside).not.toBeInViewport();
    });
}

test('@sidebar-ia mobile drawer is out of the tab order until opened, Escape returns focus', async ({
    page,
}) => {
    await loginAs(page, 'admin');
    await page.setViewportSize({ width: 375, height: 812 });
    await expect(page.locator('aside')).not.toBeInViewport();

    const toggle = page.getByRole('button', { name: 'Menu navigasi' });
    const focusedInDrawer = page.locator('aside :focus');

    // Closed: Tab never lands on a sidebar link, however far it travels.
    await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
    for (let step = 0; step < 12; step++) {
        await page.keyboard.press('Tab');
        await expect(focusedInDrawer, `Tab stop ${step + 1}`).toHaveCount(0);
    }

    await toggle.click();
    await expect(page.locator('aside')).toBeInViewport();
    await expect(page.getByRole('navigation', { name: 'Navigasi utama' })).toHaveCount(1);
    await expect(focusedInDrawer).toHaveCount(1);

    await page.keyboard.press('Escape');
    await expect(page.locator('aside')).not.toBeInViewport();
    await expect(toggle).toBeFocused();
});

// Approval, Dispatch and Pekerjaan Saya append a queue count to the accessible name
// ("Approval 3"), so a link is matched by its label plus an optional trailing number.
function sidebarLabel(label: string) {
    return new RegExp(`^${label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\s*\\d*$`);
}

async function expandAllGroups(page: Page) {
    // Only the group holding the active route starts open; the rest keep their links in the
    // DOM but hidden until the <summary> is activated.
    await expect(
        page.locator('aside').getByRole('link', { name: sidebarLabel('Dasbor') }),
    ).toBeVisible();
    const closed = page.locator('aside details:not([open]) > summary');

    while ((await closed.count()) > 0) {
        await closed.first().click();
    }

    await expect(page.locator('aside details:not([open])')).toHaveCount(0);
}

async function openGroup(page: Page, title: GroupTitle) {
    const group = page
        .locator('aside details')
        .filter({ has: page.locator('summary', { hasText: title }) });

    if (!(await group.evaluate((details: HTMLDetailsElement) => details.open))) {
        await group.locator('summary').click();
    }

    await expect(group).toHaveJSProperty('open', true);
}

async function sidebarSnapshot(page: Page): Promise<SidebarSnapshot> {
    return page.locator('aside').evaluate((aside) => {
        const text = (node: Element | null) =>
            (node?.textContent ?? '').replace(/\s+/g, ' ').trim();
        const toLink = (anchor: HTMLAnchorElement): SidebarLink => ({
            // The queue badge is a trailing number inside the link; labels never end in one.
            label: text(anchor).replace(/\s*\d+$/, ''),
            href: anchor.getAttribute('href') ?? '',
            current: anchor.getAttribute('aria-current') === 'page',
        });

        return {
            standalone: Array.from(aside.querySelectorAll<HTMLAnchorElement>('a[href]'))
                .filter((anchor) => !anchor.closest('details'))
                .map(toLink),
            groups: Array.from(aside.querySelectorAll('details')).map((details) => ({
                title: text(details.querySelector(':scope > summary')),
                open: details.open,
                links: Array.from(details.querySelectorAll<HTMLAnchorElement>('a[href]')).map(
                    toLink,
                ),
            })),
        };
    });
}

async function linkLabels(page: Page) {
    const snapshot = await sidebarSnapshot(page);

    return [...snapshot.standalone, ...snapshot.groups.flatMap((group) => group.links)]
        .map((link) => link.label)
        .sort();
}

async function applyLayout(page: Page, width: number, dark: boolean) {
    await settleAppChunks(page);
    await page.setViewportSize({ width, height: 800 });
    await page.evaluate((value) => localStorage.setItem('darkMode', String(value)), dark);
    await page.reload();
    await expect(page.locator('html')).toHaveClass(dark ? /dark/ : /^(?!.*dark)/);
    await expect(
        page.locator('aside').getByRole('link', { name: sidebarLabel('Dasbor') }),
    ).toBeAttached();
}

async function expectNoHorizontalOverflow(page: Page) {
    const overflow = await page.evaluate(() => {
        const root = document.documentElement;
        const scroller = document.querySelector('aside div.overflow-y-auto');

        return {
            document: root.scrollWidth - root.clientWidth,
            scroller: scroller ? scroller.scrollWidth - scroller.clientWidth : null,
        };
    });

    expect(overflow.scroller, 'sidebar scroll container exists').not.toBeNull();
    expect(overflow.document, 'document overflows horizontally').toBeLessThanOrEqual(0);
    expect(overflow.scroller, 'sidebar overflows horizontally').toBeLessThanOrEqual(0);
}

/**
 * Wait until every code-split app script that has already started has finished loading, then
 * require a short stability window with no new /build/assets/ script resources. The fail-closed
 * request watcher treats an aborted in-flight lazy chunk as journey-breaking, so the current page
 * is settled before a hard goto() or reload(). Same helper as stock-asset.spec.ts.
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
