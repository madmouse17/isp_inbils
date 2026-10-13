import type { Locator, Page } from '@playwright/test';
import { expect, test } from './support/fixtures';
import { inertiaProps, loginAs, logout, unique } from './support/demo';

/**
 * Customer "Acquired by" attribution (customer-sales-attribution-v1).
 *
 * Fixtures (E2eFixtureSeeder::seedSalesAttributionFixtures, DEMO company):
 *   E2E Sales Assign Target        unassigned  (manager assign/clear flow)
 *   E2E Sales Correction Target    Demo Staff  (manager correction flow)
 *   E2E Sales Staff Assign Target  unassigned  (staff assign flow)
 *   E2E Sales Filter Manager       Demo Manager (read-only / filter / visual, never mutated)
 *   E2E Sales Filter Staff         Demo Staff   (filter, never mutated)
 *   E2E Sales Filter None          unassigned   (filter, visual, never mutated)
 * Mutating tests normalise their customer through the manager UI first, so the
 * spec is re-runnable without re-seeding.
 */

const assignTarget = 'E2E Sales Assign Target';
const correctionTarget = 'E2E Sales Correction Target';
const staffAssignTarget = 'E2E Sales Staff Assign Target';
const filterManager = 'E2E Sales Filter Manager';
const filterStaff = 'E2E Sales Filter Staff';
const filterNone = 'E2E Sales Filter None';
const filterSearch = 'E2E Sales Filter';

const manager = 'Demo Manager';
const staff = 'Demo Staff';

type CustomerRow = {
    id: number;
    name: string;
    acquired_by_user_id?: number | null;
    acquirer?: { id: number; name: string; is_active: boolean } | null;
};

const acquiredByField = (page: Page) =>
    page.getByRole('combobox', { name: 'Diperoleh oleh', exact: true });
const dropdownOf = (field: Locator) => field.locator('..');
const optionIn = (field: Locator, name: string) =>
    field.page().getByRole('option', { name: new RegExp(name) });
const correctionReason = (page: Page) => page.getByLabel('Alasan koreksi');
const saveButton = (page: Page) => page.getByRole('button', { name: 'Save Customer' });
const customersIndexUrl = /\/admin\/customers\/?$/;

async function customerIdFor(page: Page, name: string): Promise<number> {
    const { props } = await inertiaProps(
        page,
        `/admin/customers?search=${encodeURIComponent(name)}`,
    );
    const rows = (props?.customers?.data ?? []) as CustomerRow[];
    const row = rows.find((candidate) => candidate.name === name);
    if (!row) throw new Error(`Sales attribution fixture customer not found: ${name}`);
    return row.id;
}

async function currentAcquirer(page: Page, id: number): Promise<string | null> {
    const { props } = await inertiaProps(page, `/admin/customers/${id}`);
    const data = props?.customer?.data as CustomerRow | undefined;
    return data?.acquirer?.name ?? null;
}

async function openEdit(page: Page, id: number) {
    await page.goto(`/admin/customers/${id}/edit`);
    await expect(
        page.getByRole('main').getByRole('heading', { name: 'Edit Customer' }),
    ).toBeVisible();
}

async function chooseAcquirer(page: Page, name: string) {
    const field = acquiredByField(page);
    await field.click();
    await field.fill(name);
    await optionIn(field, name).first().click();
    await expect(field).toHaveValue(new RegExp(name));
}

async function clearAcquirer(page: Page, reason: string) {
    const field = acquiredByField(page);
    await dropdownOf(field)
        .getByRole('button', { name: /^Hapus pilihan/ })
        .click();
    await correctionReason(page).fill(reason);
}

async function save(page: Page) {
    await saveButton(page).click();
    await expect(page).toHaveURL(customersIndexUrl);
}

async function expectShowAcquirer(page: Page, id: number, name: string, expected: string | null) {
    await page.goto(`/admin/customers/${id}`);
    const main = page.getByRole('main');
    await expect(main.getByRole('heading', { name })).toBeVisible();
    const row = main.getByText(/^Acquired by:?\s*$/).locator('..');
    await expect(row).toBeVisible();
    if (expected === null) {
        await expect(row).toContainText('Belum ditentukan');
    } else {
        await expect(row).toContainText(expected);
        await expect(row).not.toContainText('Belum ditentukan');
    }
}

/** Bring a dedicated customer to a known baseline through the manager UI (logged-in manager required). */
async function ensureAcquirer(page: Page, name: string, wanted: string | null): Promise<number> {
    const id = await customerIdFor(page, name);
    const current = await currentAcquirer(page, id);
    if (current === wanted) return id;

    if (current !== null) {
        await openEdit(page, id);
        await clearAcquirer(page, unique('baseline reset'));
        await save(page);
    }
    if (wanted !== null) {
        await openEdit(page, id);
        await chooseAcquirer(page, wanted);
        await save(page);
    }
    expect(await currentAcquirer(page, id)).toBe(wanted);

    return id;
}

async function switchTo(page: Page, role: Parameters<typeof loginAs>[1]) {
    await logout(page);
    await loginAs(page, role);
}

async function expectNoHorizontalOverflow(page: Page, message: string) {
    await expect
        .poll(
            () =>
                page.evaluate(
                    () =>
                        document.documentElement.scrollWidth <=
                        document.documentElement.clientWidth,
                ),
            { message },
        )
        .toBe(true);
}

/** Tab (or Shift+Tab) from a known anchor until `target` owns focus. */
async function tabsTo(page: Page, anchor: Locator, target: Locator, maxPresses = 40) {
    for (const key of ['Tab', 'Shift+Tab']) {
        await anchor.focus();
        for (let press = 0; press < maxPresses; press += 1) {
            await page.keyboard.press(key);
            const focused = await target.evaluate((element) => element === document.activeElement);
            if (focused) return true;
        }
    }

    return false;
}

async function expectVisibleFocusIndicator(control: Locator) {
    expect(
        await control.evaluate((element) => {
            const style = getComputedStyle(element);
            return (
                (style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) > 0) ||
                (style.boxShadow !== 'none' && style.boxShadow !== '')
            );
        }),
        'Keyboard focus has a visible outline or ring',
    ).toBe(true);
}

async function setTheme(page: Page, width: number, dark: boolean) {
    await page.setViewportSize({ width, height: 800 });
    await page.evaluate((value) => localStorage.setItem('darkMode', String(value)), dark);
    await page.reload();
    await expect(page.locator('html')).toHaveClass(dark ? /\bdark\b/ : /^(?!.*\bdark\b)/);
}

async function expectInsideViewport(control: Locator, width: number) {
    await control.scrollIntoViewIfNeeded();
    const box = await control.boundingBox();
    expect(box).not.toBeNull();
    expect(box!.x).toBeGreaterThanOrEqual(0);
    expect(box!.x + box!.width).toBeLessThanOrEqual(width);
}

test.describe('@customer-sales-attribution', () => {
    test('manager assigns an acquirer from the eligible list and Show reflects it', async ({
        page,
    }) => {
        await loginAs(page, 'manager');
        const id = await ensureAcquirer(page, assignTarget, null);
        await expectShowAcquirer(page, id, assignTarget, null);

        await openEdit(page, id);
        const field = acquiredByField(page);
        await expect(field).toBeVisible();
        await expect(field).toHaveValue('');
        await expect(field).toHaveAttribute('placeholder', 'Belum ditentukan, cari karyawan');
        await expect(correctionReason(page)).toHaveCount(0);

        // Only eligible employees (active profile + customer.create) are offered.
        await field.click();
        await expect(optionIn(field, manager)).toBeVisible();
        await expect(optionIn(field, staff)).toBeVisible();
        await expect(optionIn(field, 'Demo Admin')).toHaveCount(0);
        await expect(optionIn(field, 'Demo Technician')).toHaveCount(0);
        await expect(optionIn(field, 'Demo Customer')).toHaveCount(0);
        await field.fill('zzz-no-such-employee');
        await expect(
            field.page().getByText('Tidak ada karyawan yang memenuhi syarat.'),
        ).toBeVisible();
        await field.fill('');

        await chooseAcquirer(page, staff);
        // Assigning from Unassigned is not a correction: no reason requested.
        await expect(correctionReason(page)).toHaveCount(0);
        await save(page);

        await expectShowAcquirer(page, id, assignTarget, staff);
        expect(await currentAcquirer(page, id)).toBe(staff);
    });

    test('manager corrects and clears an acquirer only with a reason', async ({ page }) => {
        await loginAs(page, 'manager');
        const id = await ensureAcquirer(page, correctionTarget, staff);

        await openEdit(page, id);
        const field = acquiredByField(page);
        await expect(field).toHaveValue(new RegExp(staff));
        await expect(correctionReason(page)).toHaveCount(0);

        // Change without a reason: error under the textarea, acquirer unchanged.
        await chooseAcquirer(page, manager);
        const reason = correctionReason(page);
        await expect(reason).toBeVisible();
        await expect(
            page.getByText('Dicatat di log audit bersama nilai lama dan barunya.'),
        ).toBeVisible();
        await saveButton(page).click();
        await expect(reason).toHaveAttribute('aria-invalid', 'true');
        await expect(reason).not.toHaveAccessibleDescription(/Dicatat di log audit/);
        await expect(reason).toHaveAccessibleDescription(/\S/);
        await expect(page).toHaveURL(new RegExp(`/admin/customers/${id}/edit$`));
        await expect(field).toHaveValue(new RegExp(manager));
        expect(await currentAcquirer(page, id)).toBe(staff);

        // Retry with a reason on the retained form.
        await reason.fill(unique('wrong acquirer recorded'));
        await save(page);
        await expectShowAcquirer(page, id, correctionTarget, manager);

        // Clearing is a correction too: needs a reason, ends Unassigned.
        await openEdit(page, id);
        await dropdownOf(acquiredByField(page))
            .getByRole('button', { name: /^Hapus pilihan/ })
            .click();
        await expect(acquiredByField(page)).toHaveValue('');
        await expect(correctionReason(page)).toBeVisible();
        await saveButton(page).click();
        await expect(correctionReason(page)).toHaveAttribute('aria-invalid', 'true');
        expect(await currentAcquirer(page, id)).toBe(manager);
        await correctionReason(page).fill(unique('customer was not acquired by sales'));
        await save(page);

        await expectShowAcquirer(page, id, correctionTarget, null);
        expect(await currentAcquirer(page, id)).toBeNull();
    });

    test('staff can assign an unassigned customer but cannot change an existing acquirer', async ({
        page,
    }) => {
        await loginAs(page, 'manager');
        const assignId = await ensureAcquirer(page, staffAssignTarget, null);
        const lockedId = await customerIdFor(page, filterManager);
        await switchTo(page, 'staff');

        // Unassigned: staff holds assign, so the control is editable.
        await openEdit(page, assignId);
        await expect(acquiredByField(page)).toBeVisible();
        await chooseAcquirer(page, manager);
        await expect(correctionReason(page)).toHaveCount(0);
        await save(page);
        await expectShowAcquirer(page, assignId, staffAssignTarget, manager);

        // Now acquired: read-only for an assign-only user.
        for (const id of [assignId, lockedId]) {
            await openEdit(page, id);
            const main = page.getByRole('main');
            await expect(main.getByText('Diperoleh oleh').first()).toBeVisible();
            await expect(main.getByText(manager).first()).toBeVisible();
            await expect(acquiredByField(page)).toHaveCount(0);
            await expect(page.getByRole('button', { name: /^Hapus pilihan/ })).toHaveCount(0);
            await expect(correctionReason(page)).toHaveCount(0);
        }
    });

    test('manager Index filters by acquirer and Unassigned, reflects acquired_by in the URL, and has no sort control', async ({
        page,
    }) => {
        await loginAs(page, 'manager');
        const searchUrl = `/admin/customers?search=${encodeURIComponent(filterSearch)}`;
        const { props } = await inertiaProps(page, searchUrl);
        const options = (props?.acquirerOptions ?? []) as Array<{ id: number; name: string }>;
        const managerId = options.find((option) => option.name === manager)?.id;
        const staffId = options.find((option) => option.name === staff)?.id;
        expect(managerId, 'manager is an acquirer option').toBeDefined();
        expect(staffId, 'staff is an acquirer option').toBeDefined();

        await page.goto(searchUrl);
        const main = page.getByRole('main');
        const rows = page.locator('tbody tr');
        const rowOf = (name: string) => rows.filter({ hasText: name });
        const acquiredBy = (url: URL) => url.searchParams.get('acquired_by');
        // The Index filter is a native select with its own label; the Edit field is the SearchSelect.
        const filter = page.getByRole('combobox', { name: 'Acquired by', exact: true });

        await expect(main.getByRole('heading', { name: 'Pelanggan' })).toBeVisible();
        await expect(rows).toHaveCount(3);
        await expect(rowOf(filterManager)).toContainText(manager);
        await expect(rowOf(filterStaff)).toContainText(staff);
        await expect(rowOf(filterNone)).toContainText('Belum ditentukan');

        // Column header exists and is not sortable (Name, by contrast, is).
        const header = main.getByRole('columnheader', { name: 'Acquired by' });
        await expect(header).toBeVisible();
        await expect(header.getByRole('button')).toHaveCount(0);
        await expect(
            main.getByRole('columnheader', { name: 'Name' }).getByRole('button'),
        ).toBeVisible();

        await expect(filter).toBeVisible();
        await expect(filter.getByRole('option', { name: 'All acquirers' })).toHaveCount(1);
        await expect(filter.getByRole('option', { name: 'Unassigned' })).toHaveCount(1);

        await filter.selectOption({ label: manager });
        await page.getByRole('button', { name: 'Filter' }).click();
        await expect.poll(() => acquiredBy(new URL(page.url()))).toBe(String(managerId));
        await expect(rows).toHaveCount(1);
        await expect(rowOf(filterManager)).toBeVisible();

        await page.reload();
        await expect(filter).toHaveValue(String(managerId));
        await expect(rows).toHaveCount(1);

        await filter.selectOption({ label: staff });
        await page.getByRole('button', { name: 'Filter' }).click();
        await expect.poll(() => acquiredBy(new URL(page.url()))).toBe(String(staffId));
        await expect(rows).toHaveCount(1);
        await expect(rowOf(filterStaff)).toBeVisible();

        await filter.selectOption({ label: 'Unassigned' });
        await page.getByRole('button', { name: 'Filter' }).click();
        await expect.poll(() => acquiredBy(new URL(page.url()))).toBe('unassigned');
        await expect(rows).toHaveCount(1);
        await expect(rowOf(filterNone)).toContainText('Belum ditentukan');

        await filter.selectOption({ label: 'All acquirers' });
        await page.getByRole('button', { name: 'Filter' }).click();
        await expect.poll(() => acquiredBy(new URL(page.url()))).toBeNull();
        await expect(rows).toHaveCount(3);
    });

    test('manager Edit and Index stay usable at 320px and 1280px in light and dark', async ({
        page,
    }) => {
        await loginAs(page, 'manager');
        const id = await customerIdFor(page, filterManager);

        for (const width of [320, 1280]) {
            for (const dark of [false, true]) {
                const mode = `${width}px ${dark ? 'dark' : 'light'}`;

                await openEdit(page, id);
                await setTheme(page, width, dark);
                const editField = acquiredByField(page);
                await expect(editField).toBeVisible();
                await expect(editField).toHaveValue(new RegExp(manager));
                await expectInsideViewport(editField, width);
                await expectNoHorizontalOverflow(page, `Edit overflow at ${mode}`);
                expect(
                    await tabsTo(page, page.getByLabel('Name'), editField),
                    `Tab reaches Acquired by on Edit at ${mode}`,
                ).toBe(true);
                await expectVisibleFocusIndicator(editField);

                // Pending correction reveals the reason textarea without breaking the layout.
                await chooseAcquirer(page, staff);
                const reason = correctionReason(page);
                await expect(reason).toBeVisible();
                await expectInsideViewport(reason, width);
                await expectNoHorizontalOverflow(page, `Edit correction overflow at ${mode}`);
                await reason.focus();
                await expectVisibleFocusIndicator(reason);

                await page.goto(`/admin/customers?search=${encodeURIComponent(filterSearch)}`);
                await expect(
                    page.getByRole('main').getByRole('heading', { name: 'Pelanggan' }),
                ).toBeVisible();
                await setTheme(page, width, dark);
                const filter = page.getByRole('combobox', { name: 'Acquired by', exact: true });
                await expect(filter).toBeVisible();
                await expectInsideViewport(filter, width);
                await expect(page.locator('tbody tr')).toHaveCount(3);
                await expectNoHorizontalOverflow(page, `Index overflow at ${mode}`);
                expect(
                    await tabsTo(page, page.getByLabel('Search', { exact: true }), filter),
                    `Tab reaches Acquired by filter on Index at ${mode}`,
                ).toBe(true);
                await expectVisibleFocusIndicator(filter);
            }
        }
    });
});
