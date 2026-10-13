import type { Page } from '@playwright/test';
import { expect, test } from './support/fixtures';
import { csrfToken, inertiaProps, loginAs, logout, refreshSession, unique } from './support/demo';
import { chartCard, expectChartSettled } from './support/charts';
import { typeDateRange } from './support/dateRange';

type Employee = {
    id: number;
    user_id: number;
    employee_number: string;
    organization_id: number | null;
    status: string;
};

type Organization = { id: number; code: string };

// The Technician report draws three charts under its cards; the first is named after the bucket size.
const chartTitles = [
    /^SPK selesai per (hari|minggu|bulan)$/,
    'SPK selesai menurut jenis',
    'Tiket selesai menurut kategori',
];

async function employeeByNumber(page: Page, number: string): Promise<Employee> {
    const response = await inertiaProps(
        page,
        `/admin/employees?search=${encodeURIComponent(number)}`,
    );
    const matches = (response.props?.employees?.data ?? []).filter(
        (row: Employee) => row.employee_number === number,
    );
    if (matches.length !== 1)
        throw new Error(`Expected exactly one employee ${number}, found ${matches.length}`);
    return matches[0];
}

async function organizationByCode(page: Page, code: string): Promise<Organization> {
    const response = await inertiaProps(
        page,
        `/admin/organizations?search=${encodeURIComponent(code)}`,
    );
    const matches = (response.props?.organizations?.data ?? []).filter(
        (row: Organization) => row.code === code,
    );
    if (matches.length !== 1)
        throw new Error(`Expected exactly one organization ${code}, found ${matches.length}`);
    return matches[0];
}

async function updateEmployee(
    page: Page,
    employee: Employee,
    organizationId: number | null,
    status = 'active',
) {
    const response = await page.request.put(`/admin/employees/${employee.id}`, {
        headers: { 'X-XSRF-TOKEN': await csrfToken(page) },
        maxRedirects: 0,
        form: {
            employee_number: employee.employee_number,
            organization_id: organizationId === null ? '' : String(organizationId),
            status,
        },
    });
    expect(response.status(), `Employee update ${employee.employee_number}`).toBe(302);
    const updated = await employeeByNumber(page, employee.employee_number);
    expect(updated.organization_id).toBe(organizationId);
    expect(updated.status).toBe(status);
}

async function assertLayout(page: Page, width: number, dark: boolean) {
    // The reload below would abort a chart chunk that is still loading.
    for (const title of chartTitles) await expectChartSettled(chartCard(page, title));
    await page.setViewportSize({ width, height: 800 });
    await page.evaluate((value) => localStorage.setItem('darkMode', String(value)), dark);
    await page.reload();
    await expect(page.locator('html')).toHaveClass(dark ? /dark/ : /^(?!.*dark)/);
    const period = page.getByLabel('Periode', { exact: true });
    const run = page.getByRole('button', { name: 'Run' });
    await expect(period).toBeVisible();
    await expect(run).toBeVisible();
    await expect(page.getByLabel('Technician')).toHaveCount(1);
    // The charts load lazily: wait until they are drawn so the overflow check sees them.
    for (const title of chartTitles) await expectChartSettled(chartCard(page, title));
    expect(
        await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
    ).toBe(true);

    await page.getByLabel('Technician').focus();
    await expect(page.getByLabel('Technician')).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(period).toBeFocused();
    // The calendar button, and the clear button once a range is set, sit between the field and Run.
    for (let i = 0; i < 5 && !(await run.evaluate((el) => el === document.activeElement)); i++) {
        await page.keyboard.press('Tab');
    }
    await expect(run).toBeFocused();
    await period.focus();
    expect(
        await period.evaluate(
            (el) =>
                getComputedStyle(el).outlineStyle !== 'none' ||
                getComputedStyle(el).boxShadow !== 'none',
        ),
    ).toBe(true);
}

test('manager hierarchy uses canonical report filters and capability navigation', async ({
    page,
    expectHttpError,
}) => {
    // Three lazy charts plus eight layout checks across two reports: well over the default 30 s.
    test.slow();
    const managerCode = unique('TP').slice(0, 36);
    const siblingCode = unique('TP').slice(0, 36);
    const created: Organization[] = [];
    let manager: Employee | undefined;
    let technician: Employee | undefined;

    await loginAs(page, 'admin');
    try {
        manager = await employeeByNumber(page, 'DEMO-MANAGER');
        technician = await employeeByNumber(page, 'DEMO-TECHNICIAN');

        for (const code of [managerCode, siblingCode]) {
            const response = await page.request.post('/admin/organizations', {
                headers: { 'X-XSRF-TOKEN': await refreshSession(page, '/admin/organizations') },
                maxRedirects: 0,
                form: {
                    code,
                    name: `Technician Performance ${code}`,
                    type: 'team',
                    parent_id: '',
                    is_active: '1',
                },
            });
            expect(response.status(), `Create organization ${code}`).toBe(302);
            created.push(await organizationByCode(page, code));
        }

        await updateEmployee(page, manager, created[0].id);
        await updateEmployee(page, technician, created[0].id);
        await logout(page);

        await loginAs(page, 'manager');
        await page.goto('/admin/reports/technician');
        await expect(page.getByText('Pilih teknisi untuk melihat laporan.')).toBeVisible();
        // Laporan and Kinerja Teknisi both exist here: only the longer href is current.
        const sidebar = page.locator('aside');
        await expect(sidebar.getByRole('link', { name: 'Laporan', exact: true })).toHaveCount(1);
        await expect(
            sidebar.getByRole('link', { name: 'Kinerja Teknisi', exact: true }),
        ).toHaveAttribute('aria-current', 'page');
        await expect(sidebar.locator('a[aria-current="page"]')).toHaveCount(1);
        await expect(
            sidebar
                .locator('details')
                .filter({ has: page.locator('summary', { hasText: 'Keuangan & Laporan' }) }),
        ).toHaveJSProperty('open', true);
        await expect(page.getByRole('button', { name: 'Run' })).toBeDisabled();
        await expect(
            page.getByLabel('Technician').locator(`option[value="${technician.user_id}"]`),
        ).toHaveCount(1);
        await page.getByLabel('Technician').selectOption(String(technician.user_id));
        await typeDateRange(
            page,
            page.getByLabel('Periode', { exact: true }),
            '2026-02-01',
            '2026-02-28',
        );
        const teamRequest = page.waitForRequest(
            (request) =>
                request.method() === 'GET' &&
                request.url().includes('/admin/reports/technician?technician_id='),
        );
        await page.getByRole('button', { name: 'Run' }).click();
        const teamUrl = new URL((await teamRequest).url());
        expect(teamUrl.searchParams.get('technician_id')).toBe(String(technician.user_id));
        expect(teamUrl.searchParams.get('date_from')).toBe('2026-02-01');
        expect(teamUrl.searchParams.get('date_to')).toBe('2026-02-28');
        await expect(page.getByText('SPK Completed')).toBeVisible();
        for (const dark of [false, true])
            for (const width of [1280, 320]) await assertLayout(page, width, dark);
        await logout(page);

        await loginAs(page, 'admin');
        await updateEmployee(page, technician, created[1].id);
        await logout(page);
        await loginAs(page, 'manager');
        await page.goto('/admin/reports/technician');
        await expect(
            page.getByLabel('Technician').locator(`option[value="${technician.user_id}"]`),
        ).toHaveCount(0);
        await expect(page.getByText('Tidak ada teknisi yang dapat dipilih.')).toBeVisible();
        const deniedPath = `/admin/reports/technician?technician_id=${technician.user_id}`;
        expectHttpError({ url: deniedPath, method: 'GET', status: 403 });
        const sibling = await page.goto(deniedPath);
        expect(sibling?.status()).toBe(403);
        await logout(page);

        await loginAs(page, 'admin');
        await updateEmployee(page, manager, null);
        await logout(page);
        await loginAs(page, 'manager');
        // Without an organization the server withholds the report, so the sidebar offers no link either.
        await expect(page.getByRole('link', { name: 'Kinerja Teknisi' })).toHaveCount(0);
        const noOrgPath = '/admin/reports/technician?check=no-organization';
        expectHttpError({ url: noOrgPath, method: 'GET', status: 403 });
        const noOrg = await page.goto(noOrgPath);
        expect(noOrg?.status()).toBe(403);
        await logout(page);

        await loginAs(page, 'staff');
        await expect(page.getByRole('link', { name: 'Kinerja Teknisi' })).toHaveCount(0);
        const staffPath = '/admin/reports/technician?check=staff-denied';
        expectHttpError({ url: staffPath, method: 'GET', status: 403 });
        const staff = await page.goto(staffPath);
        expect(staff?.status()).toBe(403);
    } finally {
        if (page.url().includes('/admin/') || !page.url().includes('/login')) await logout(page);
        await loginAs(page, 'admin');
        if (manager) await updateEmployee(page, manager, manager.organization_id, manager.status);
        if (technician)
            await updateEmployee(page, technician, technician.organization_id, technician.status);
        for (const organization of created.reverse()) {
            const response = await page.request.delete(`/admin/organizations/${organization.id}`, {
                headers: { 'X-XSRF-TOKEN': await refreshSession(page, '/admin/organizations') },
                maxRedirects: 0,
            });
            expect(response.status(), `Delete owned organization ${organization.code}`).toBe(302);
            const remaining = await inertiaProps(
                page,
                `/admin/organizations?search=${encodeURIComponent(organization.code)}`,
            );
            expect(
                (remaining.props?.organizations?.data ?? []).some(
                    (row: Organization) => row.id === organization.id,
                ),
            ).toBe(false);
        }
    }
});
