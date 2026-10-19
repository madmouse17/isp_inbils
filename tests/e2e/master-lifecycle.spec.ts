import type { APIResponse, Page } from '@playwright/test';

import { expect, test } from './support/fixtures';
import {
    PNG_1X1,
    expectDetail,
    inertiaProps,
    loginAs,
    refreshSession,
    unique,
} from './support/demo';

/**
 * Master lifecycle (lane master-lifecycle, design section 6):
 * - a master that other records use is deactivated and never deleted; one nothing uses is deleted for good;
 * - a deleted document is listed under Status "Dihapus" and comes back with Pulihkan;
 * - a package price edit appends a version, and a subscription moves to it only through "Ganti paket";
 * - an issued invoice keeps the customer it was issued to, while a draft follows the live name;
 * - a delete the server refuses is explained inside the dialog.
 *
 * Every record carries the run id, so the spec runs again on a database it has already used. Setup posts to
 * the routes the pages post to; the behaviour under test is driven and read through the pages. The e2e env
 * keeps PreventDuplicateWebSubmit on: an identical admin write inside 10 s answers 409, so no write below
 * repeats a body on a path.
 */

const run = unique('ML');
const encode = encodeURIComponent;

const PRICE_V1 = '150000';
const PRICE_V2 = '175000';
const RUPIAH_V1 = /Rp\s*150\.000(?![.\d])/;
const RUPIAH_V2 = /Rp\s*175\.000(?![.\d])/;
const OSM_TILE = /^https:\/\/[abc]\.tile\.openstreetmap\.org\/.*\.png(?:\?.*)?$/;

type Json = Record<string, unknown>;
type Named = { id: string; name: string };
type InvoiceRow = { id: string; number: string; status: string };
type Region = { code: string; name: string };
type Regions = { provinces: Region[]; cities: Region[]; districts: Region[]; villages: Region[] };
type PackageOptions = {
    bandwidthProfiles: { data: Named[] };
    speedProfiles: { data: Named[] };
    slaTiers: { data: Named[] };
    packageCategories: Array<Named & { is_active?: boolean }>;
};
type Subject = { package: Named; customer: Named; subscription: { id: string } };

/** A write the way the pages send it: session cookie, XSRF header, redirect not followed. A refusal is a 4xx here, so 302 proves it. */
async function write(
    page: Page,
    method: 'POST' | 'PUT' | 'DELETE',
    url: string,
    data?: Json,
): Promise<APIResponse> {
    const response = await page.request.fetch(url, {
        method,
        data,
        maxRedirects: 0,
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': await refreshSession(page) },
    });
    expect(response.status(), `${method} ${url}: ${await response.text()}`).toBe(302);

    return response;
}

/** The rows an index page sent for `prop`, whether it paginates (`{ data }`) or sends a plain list. */
async function listed<T>(page: Page, url: string, prop: string): Promise<T[]> {
    const rows = (await inertiaProps(page, url)).props?.[prop] as { data?: T[] } | T[] | undefined;

    return (Array.isArray(rows) ? rows : (rows?.data ?? [])) as T[];
}

async function named(page: Page, url: string, prop: string, name: string): Promise<Named> {
    const row = (await listed<Named>(page, url, prop)).find((candidate) => candidate.name === name);
    expect(row, `${prop} lists "${name}"`).toBeDefined();

    return row!;
}

async function createBandwidthProfile(page: Page, name: string): Promise<Named> {
    await write(page, 'POST', '/admin/bandwidth-profiles', {
        name,
        download_mbps: 40,
        upload_mbps: 20,
        type: 'shared',
        contention_ratio: 4,
        is_active: true,
    });

    return named(
        page,
        `/admin/bandwidth-profiles?search=${encode(name)}`,
        'bandwidthProfiles',
        name,
    );
}

/** A package at PRICE_V1 (version 1) on `bandwidthProfileId`, or on the first active profile. */
async function createPackage(page: Page, tag: string, bandwidthProfileId?: string): Promise<Named> {
    const options = (await inertiaProps(page, '/admin/service-packages/create'))
        .props as PackageOptions;
    const category = options.packageCategories.find((candidate) => candidate.is_active !== false);
    const name = `${run} paket ${tag}`;

    await write(page, 'POST', '/admin/service-packages', {
        code: `${run}-${tag}`,
        name,
        service_package_category_id: category?.id,
        bandwidth_profile_id: bandwidthProfileId ?? options.bandwidthProfiles.data[0].id,
        speed_profile_id: options.speedProfiles.data[0].id,
        sla_tier_id: options.slaTiers.data[0].id,
        price_mrc: PRICE_V1,
        price_otc: '250000',
        contract_min_months: 12,
        is_active: true,
    });

    return named(page, `/admin/service-packages?search=${encode(name)}`, 'servicePackages', name);
}

/** A province, city, district and village that belong together, found as the address fields find them: by the codes picked so far. */
async function addressRegion(page: Page) {
    const regionsFor = async (query: string) =>
        (await inertiaProps(page, `/admin/customers/create?${query}`)).props?.regions as Regions;
    const codes = (key: string, code: string) => `region_${key}%5B%5D=${code}`;
    const pick = (rows: Region[], pattern: RegExp) => {
        const row = rows.find((candidate) => pattern.test(candidate.name)) ?? rows[0];
        expect(row, `a region matching ${pattern}`).toBeDefined();

        return row.code;
    };

    const province_code = pick((await regionsFor('')).provinces, /jakarta/i);
    const provinceQuery = codes('provinces', province_code);
    const city_code = pick((await regionsFor(provinceQuery)).cities, /jakarta pusat/i);
    const cityQuery = `${provinceQuery}&${codes('cities', city_code)}`;
    const district_code = pick((await regionsFor(cityQuery)).districts, /menteng/i);
    const districtQuery = `${cityQuery}&${codes('districts', district_code)}`;
    const village_code = pick((await regionsFor(districtQuery)).villages, /menteng/i);

    return { province_code, city_code, district_code, village_code };
}

/** A customer with one address, one contact and a pending subscription on `packageId`, as the onboarding form creates it. */
async function createCustomer(page: Page, name: string, packageId: string): Promise<Named> {
    const create = (await inertiaProps(page, '/admin/customers/create')).props as {
        locations: Array<{ id: string; type: string }>;
    };
    const pop = create.locations.find((location) => location.type === 'pop');
    expect(pop, 'an active POP to serve the subscription').toBeDefined();
    const slug = run.toLowerCase();

    const response = await write(page, 'POST', '/admin/customers', {
        name,
        type: 'Individual',
        email: `${slug}@example.test`,
        phone: '081200000000',
        is_active: true,
        addresses: [
            {
                label: 'Rumah uji',
                address: 'Jl. Uji Siklus 17',
                ...(await addressRegion(page)),
                lat: -6.21,
                lng: 106.82,
                is_installation_point: true,
                is_primary: true,
            },
        ],
        contacts: [
            {
                name: `${name} Kontak`,
                phone: '081200000001',
                email: `kontak.${slug}@example.test`,
                is_primary: true,
            },
        ],
        subscription: { service_package_id: packageId, serving_pop_id: pop!.id, billing_day: 5 },
    });
    const id = new URL(response.headers()['location'], 'http://localhost').pathname
        .split('/')
        .pop();
    expect(id, 'the new customer is where the redirect points').toMatch(
        /^[0-9A-HJKMNP-TV-Z]{26}$/i,
    );

    return { id: id!, name };
}

let subject: Subject | undefined;

/**
 * The package, customer and subscription the version and invoice flows share, created by whichever of them
 * runs first. The customer starts as "Lama" and the invoice flow renames it.
 */
async function ensureSubject(page: Page): Promise<Subject> {
    if (!subject) {
        const servicePackage = await createPackage(page, 'versi');
        const customer = await createCustomer(page, `ML Lama ${run}`, servicePackage.id);
        const [subscription] = await listed<{ id: string }>(
            page,
            `/admin/customers/${customer.id}/subscriptions`,
            'subscriptions',
        );
        expect(subscription, 'the onboarding subscription').toBeDefined();
        subject = { package: servicePackage, customer, subscription };
    }

    return subject;
}

/** Every invoice of the customer, deleted ones included. */
function invoicesOf(page: Page, customerId: string, status = 'semua') {
    return listed<InvoiceRow>(
        page,
        `/admin/invoices?customer_id=${customerId}&status=${status}&per_page=50`,
        'invoices',
    );
}

async function createInvoice(
    page: Page,
    customerId: string,
    notes: string,
    subscriptionId?: string,
): Promise<InvoiceRow> {
    const known = new Set((await invoicesOf(page, customerId)).map((invoice) => invoice.id));

    await write(page, 'POST', '/admin/invoices', {
        customer_id: customerId,
        subscription_id: subscriptionId,
        notes,
    });

    const created = (await invoicesOf(page, customerId)).find((invoice) => !known.has(invoice.id));
    expect(created, 'the invoice just created is listed').toBeDefined();

    return created!;
}

async function createLocation(
    page: Page,
    code: string,
    type: 'region' | 'area',
    parentId?: string,
): Promise<Named & { code: string }> {
    const name = `Lokasi uji ${code}`;
    await write(page, 'POST', '/admin/locations', {
        code,
        name,
        type,
        parent_id: parentId,
        is_active: true,
    });

    return {
        ...(await named(page, `/admin/locations?search=${encode(code)}`, 'locations', name)),
        code,
    };
}

const tableRow = (page: Page, text: string) => page.getByRole('row').filter({ hasText: text });

async function expectToast(page: Page, text: string) {
    await expect(page.getByRole('status').filter({ hasText: text }).first()).toBeVisible();
}

/** Picks `label` in the Status filter and waits until the page has answered for `value`. */
async function filterStatus(page: Page, label: string, value: string) {
    await page.getByLabel('Status', { exact: true }).selectOption({ label });
    await expect.poll(() => new URL(page.url()).searchParams.get('status')).toBe(value);
}

test.describe('@lifecycle master lifecycle', () => {
    test.describe.configure({ mode: 'serial', timeout: 120_000 });

    test('a profile that a package uses is deactivated, never deleted, and Aktifkan brings it back', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        const profile = await createBandwidthProfile(page, `${run} profil dipakai`);
        const used = await createPackage(page, 'profil', profile.id);

        await page.goto(`/admin/bandwidth-profiles?search=${encode(profile.name)}`);
        const row = tableRow(page, profile.name);
        await expect(row).toContainText('Dipakai 1 paket');
        await row.getByRole('button', { name: 'Aksi lainnya' }).click();
        await expect(page.getByRole('menuitem', { name: 'Hapus permanen' })).toHaveCount(0);
        await page.getByRole('menuitem', { name: 'Nonaktifkan', exact: true }).click();

        const deactivate = page.getByRole('alertdialog', { name: `Nonaktifkan ${profile.name}?` });
        await expect(deactivate).toContainText(/dipakai 1 paket.*jadi tidak bisa dihapus permanen/);
        await deactivate.getByRole('button', { name: 'Nonaktifkan', exact: true }).click();
        await expectToast(page, 'Profil bandwidth dinonaktifkan.');
        await expect(deactivate).toBeHidden();

        await filterStatus(page, 'Nonaktif', 'nonaktif');
        // The cells of a row read as one string, so the badge is found by its own cell.
        await expect(row.getByRole('cell', { name: 'Nonaktif', exact: true })).toBeVisible();

        // The package keeps the profile it was sold with, on its page and in its edit form.
        await page.goto(`/admin/service-packages/${used.id}`);
        await expectDetail(page, 'Profil bandwidth', profile.name);
        await page.goto(`/admin/service-packages/${used.id}/edit`);
        await expect(page.getByRole('combobox', { name: 'Profil bandwidth' })).toHaveValue(
            profile.name,
        );

        // A new package is offered the active profiles only.
        await page.goto('/admin/service-packages/create');
        await page.getByRole('combobox', { name: 'Profil bandwidth' }).click();
        const offered = page.getByRole('listbox', { name: 'Profil bandwidth' }).getByRole('option');
        await expect(offered.first()).toBeVisible();
        await expect(offered.filter({ hasText: profile.name })).toHaveCount(0);

        await page.goto(`/admin/bandwidth-profiles?search=${encode(profile.name)}&status=nonaktif`);
        await row.getByRole('button', { name: 'Aksi lainnya' }).click();
        await page.getByRole('menuitem', { name: 'Aktifkan', exact: true }).click();
        const activate = page.getByRole('alertdialog', { name: `Aktifkan ${profile.name}?` });
        await activate.getByRole('button', { name: 'Aktifkan', exact: true }).click();
        await expectToast(page, 'Profil bandwidth diaktifkan.');
        await expect(activate).toBeHidden();

        await filterStatus(page, 'Aktif', 'aktif');
        await expect(row.getByRole('cell', { name: 'Aktif', exact: true })).toBeVisible();
        await row.getByRole('button', { name: 'Aksi lainnya' }).click();
        await expect(
            page.getByRole('menuitem', { name: 'Nonaktifkan', exact: true }),
        ).toBeVisible();
        await expect(page.getByRole('menuitem', { name: 'Hapus permanen' })).toHaveCount(0);
        await page.keyboard.press('Escape');

        await page.goto('/admin/service-packages/create');
        await page.getByRole('combobox', { name: 'Profil bandwidth' }).fill(profile.name);
        await expect(
            page
                .getByRole('listbox', { name: 'Profil bandwidth' })
                .getByRole('option', { name: profile.name }),
        ).toBeVisible();
    });

    test('a profile nothing uses is deleted for good after the confirmation', async ({ page }) => {
        await loginAs(page, 'admin');
        const profile = await createBandwidthProfile(page, `${run} profil bebas`);

        await page.goto(`/admin/bandwidth-profiles?search=${encode(profile.name)}`);
        const row = tableRow(page, profile.name);
        await expect(row).toBeVisible();
        await expect(row).not.toContainText('Dipakai');
        await row.getByRole('button', { name: 'Aksi lainnya' }).click();
        await page.getByRole('menuitem', { name: 'Hapus permanen' }).click();

        const destroy = page.getByRole('alertdialog', { name: `Hapus permanen ${profile.name}?` });
        await expect(destroy).toContainText('belum pernah dipakai data lain');
        await destroy.getByRole('button', { name: 'Hapus permanen', exact: true }).click();
        await expectToast(page, 'Profil bandwidth dihapus permanen.');
        await expect(destroy).toBeHidden();
        await expect(row).toHaveCount(0);

        // Gone for good: neither the trash nor the full list holds it.
        for (const [label, value] of [
            ['Semua, termasuk dihapus', 'semua'],
            ['Dihapus', 'dihapus'],
        ]) {
            await filterStatus(page, label, value);
            await expect(page.getByText('Tidak ada profil bandwidth yang cocok')).toBeVisible();
            await expect(row).toHaveCount(0);
        }
    });

    test('a package price edit appends version 2; the subscription keeps version 1 until Ganti paket', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        const { package: servicePackage, subscription } = await ensureSubject(page);

        await page.goto(`/admin/service-packages/${servicePackage.id}/edit`);
        await page.getByLabel('Harga bulanan').fill(PRICE_V2);
        await page.getByRole('button', { name: 'Simpan', exact: true }).click();
        await expect(page).toHaveURL(/\/admin\/service-packages\/?$/);

        const versions = page.getByRole('row');
        await page.goto(`/admin/service-packages/${servicePackage.id}`);
        await expect(versions).toHaveCount(3); // header, version 2, version 1
        await expect(versions.nth(1).getByRole('cell').first()).toHaveText(/^2/);
        await expect(versions.nth(1)).toContainText('Terbaru');
        await expect(versions.nth(1)).toContainText(RUPIAH_V2);
        await expect(versions.nth(1).getByRole('cell').last()).toHaveText('0');
        await expect(versions.nth(2).getByRole('cell').first()).toHaveText(/^1$/);
        await expect(versions.nth(2)).toContainText(RUPIAH_V1);
        await expect(versions.nth(2).getByRole('cell').last()).toHaveText('1');

        // The subscription still holds version 1 and the price it was sold at.
        await page.goto(`/admin/subscriptions/${subscription.id}`);
        await expectDetail(page, 'Nama paket', 'Versi 1 sejak');
        await expect(page.getByText('Versi terbaru tersedia')).toBeVisible();
        await expectDetail(page, 'Harga bulanan', RUPIAH_V1);
        await expectDetail(page, 'Biaya bulanan', RUPIAH_V1);

        await page.getByRole('button', { name: 'Ganti paket' }).click();
        const change = page.getByRole('dialog', { name: 'Ganti paket' });
        // The fee follows the package once its choices have loaded: version 2's price.
        await expect(change.getByLabel('Biaya bulanan')).toHaveValue(PRICE_V2);
        await change.getByRole('button', { name: 'Pindahkan' }).click();
        await expectToast(page, 'Langganan dipindahkan ke versi terbaru paket.');
        await expect(change).toBeHidden();

        await expectDetail(page, 'Nama paket', 'Versi 2 sejak');
        await expect(page.getByText('Versi terbaru tersedia')).toHaveCount(0);
        await expectDetail(page, 'Harga bulanan', RUPIAH_V2);
        await expectDetail(page, 'Biaya bulanan', RUPIAH_V2);

        await page.goto(`/admin/service-packages/${servicePackage.id}`);
        await expect(versions.nth(1).getByRole('cell').last()).toHaveText('1');
        await expect(versions.nth(2).getByRole('cell').last()).toHaveText('0');
    });

    test('an issued invoice keeps the name it was issued to; a draft follows the customer', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        const { customer, subscription, package: servicePackage } = await ensureSubject(page);

        const issued = await createInvoice(
            page,
            customer.id,
            `${run} diterbitkan`,
            subscription.id,
        );
        await write(page, 'POST', `/admin/invoices/${issued.id}/items`, {
            description: `${run} biaya uji`,
            quantity: 1,
            unit_price: 100000,
            discount_amount: 0,
            tax_rate: 0,
        });
        await write(page, 'POST', `/admin/invoices/${issued.id}/send`);
        const draft = await createInvoice(page, customer.id, `${run} masih draf`, subscription.id);

        const renamed = `ML Baru ${run}`;
        await page.goto(`/admin/customers/${customer.id}/edit`);
        await page.getByLabel('Nama').fill(renamed);
        await page.getByRole('button', { name: 'Simpan pelanggan' }).click();
        await expect(page).toHaveURL(/\/admin\/customers\/?$/);

        const billedTo = page.locator('p').filter({ hasText: /^\s*Pelanggan:/ });

        await page.goto(`/admin/invoices/${issued.id}`);
        await expectDetail(page, 'Status', 'Belum dibayar');
        await expect(billedTo).toContainText(customer.name);
        await expect(billedTo).toContainText('(saat terbit)');
        await expect(billedTo).not.toContainText(renamed);
        await expectDetail(page, 'Paket', servicePackage.name);

        const pdf = await page.request.get(`/admin/invoices/${issued.id}/pdf`);
        expect(pdf.status()).toBe(200);
        expect(pdf.headers()['content-type']).toContain('application/pdf');
        expect((await pdf.body()).subarray(0, 5).toString('latin1')).toBe('%PDF-');

        await page.goto(`/admin/invoices/${draft.id}`);
        await expectDetail(page, 'Status', 'Draf');
        await expect(billedTo).toContainText(renamed);
        await expect(billedTo).not.toContainText(customer.name);
        await expect(billedTo).not.toContainText('(saat terbit)');
    });

    test('a deleted draft invoice is listed under Dihapus and Pulihkan brings it back', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        const { customer } = await ensureSubject(page);

        const kept = await createInvoice(page, customer.id, `${run} draf tetap`);
        const deleted = await createInvoice(page, customer.id, `${run} draf dihapus`);
        // The invoice pages have no delete button: a draft is deleted through its route.
        await write(page, 'DELETE', `/admin/invoices/${deleted.id}`);

        await page.goto(`/admin/invoices?customer_id=${customer.id}`);
        await expect(tableRow(page, kept.number)).toBeVisible();
        await expect(tableRow(page, deleted.number)).toHaveCount(0);

        await filterStatus(page, 'Dihapus', 'dihapus');
        const trashed = tableRow(page, deleted.number);
        await expect(trashed).toContainText('Dihapus');
        await expect(tableRow(page, kept.number)).toHaveCount(0);
        await trashed.getByRole('button', { name: 'Aksi lainnya' }).click();
        await page.getByRole('menuitem', { name: 'Pulihkan', exact: true }).click();
        const restore = page.getByRole('alertdialog', { name: `Pulihkan ${deleted.number}?` });
        await restore.getByRole('button', { name: 'Pulihkan', exact: true }).click();
        await expectToast(page, 'Tagihan dipulihkan.');
        await expect(restore).toBeHidden();

        await filterStatus(page, 'Draf', 'draft');
        await expect(tableRow(page, deleted.number)).toContainText('Draf');
        await expect(tableRow(page, kept.number)).toBeVisible();
    });

    test('a location that gained a child is refused in the dialog and then offers only Nonaktifkan', async ({
        page,
    }) => {
        await page.route(OSM_TILE, (route) =>
            route.fulfill({ status: 200, contentType: 'image/png', body: PNG_1X1 }),
        );
        await loginAs(page, 'admin');
        const region = run.toUpperCase();
        const parent = await createLocation(page, `${region}-REG`, 'region');

        await page.goto(`/admin/locations?search=${encode(parent.code)}`);
        await page.getByRole('tab', { name: 'Tabel' }).click();
        const row = page
            .getByRole('row')
            .filter({ has: page.getByText(parent.code, { exact: true }) });
        await expect(row).toBeVisible();
        await expect(row).not.toContainText('Dipakai');

        // The row was drawn while the region had no child: one arrives before the delete is confirmed.
        await createLocation(page, `${region}-ARE`, 'area', parent.id);

        await row.getByRole('button', { name: 'Aksi lainnya' }).click();
        await page.getByRole('menuitem', { name: 'Hapus permanen' }).click();
        const dialog = page.getByRole('alertdialog', { name: `Hapus permanen ${parent.name}?` });
        await dialog.getByRole('button', { name: 'Hapus permanen', exact: true }).click();
        await expect(dialog.getByRole('alert')).toContainText(
            'Lokasi masih dipakai 1 lokasi turunan. Nonaktifkan saja.',
        );
        await expect(dialog).toBeVisible();
        await dialog.getByRole('button', { name: 'Batal' }).click();
        await expect(dialog).toBeHidden();

        // The refusal reloaded the page: the row now names what holds it.
        await expect(row).toContainText('Dipakai 1 lokasi turunan');
        await row.getByRole('button', { name: 'Aksi lainnya' }).click();
        await expect(
            page.getByRole('menuitem', { name: 'Nonaktifkan', exact: true }),
        ).toBeVisible();
        await expect(page.getByRole('menuitem', { name: 'Hapus permanen' })).toHaveCount(0);
    });
});
