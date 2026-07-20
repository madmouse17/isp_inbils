import { readFileSync } from 'node:fs';
import type { Locator, Page } from '@playwright/test';

import { expect, test } from './support/fixtures';

const seederSource = readFileSync('database/seeders/DemoUserSeeder.php', 'utf8');
const demoPassword = seederSource.match(/private const PASSWORD = '([^']+)';/)?.[1];
const adminEmail = seederSource.match(
    /'admin' => \['name' => 'Demo Admin', 'email' => '([^']+)'/,
)?.[1];
const runId = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;

if (!demoPassword || !adminEmail) {
    throw new Error('Could not derive DemoUserSeeder admin credentials.');
}

test.describe('@kanban-t_49387795 UI journeys', () => {
    test.describe.configure({ mode: 'serial' });

    test('@kanban-t_49387795 creates, assigns, and starts SPK with visible lifecycle states', async ({
        page,
    }) => {
        const customerName = await createCustomer(page, `SPK-${runId}`);
        const technicianId = await visibleTechnicianId(page);
        const title = 'E2E T49387795 SPK lifecycle';

        await page.goto('/admin/spk/create');
        await page.getByLabel('Type').selectOption('maintenance');
        await page.getByLabel('Title').fill(title);
        await selectOptionByText(page.getByLabel('Customer'), customerName);
        await selectFirstRealOption(page.getByLabel('Location'));
        await page.getByLabel('Priority').selectOption('high');
        await page.getByLabel('Scheduled Date').fill('2026-07-14');
        await page
            .getByLabel('Description')
            .fill('Create, assign, start only. PHP tests cover stock, asset, invoice correctness.');
        await page.getByRole('button', { name: 'Create', exact: true }).click();

        await expect(page).toHaveURL(/\/admin\/spk\/\d+$/);
        await expect(page.getByRole('main').getByRole('heading', { name: title })).toBeVisible();
        await expectSpkState(page, 'draft');
        await expect(page.getByRole('button', { name: 'Assign' })).toBeVisible();

        await page.getByRole('button', { name: 'Assign' }).click();
        const assignDialog = page.getByRole('dialog', { name: 'Assign' });
        await assignDialog.getByLabel('Technician ID').fill(technicianId);
        await assignDialog.getByRole('button', { name: 'Confirm' }).click();

        await expectSpkState(page, 'assigned');
        await expectDetail(page, 'Technician', 'Demo Technician');
        await expect(page.getByRole('button', { name: 'Start' })).toBeVisible();

        await page.getByRole('button', { name: 'Start' }).click();
        const startDialog = page.getByRole('dialog', { name: 'Start' });
        await startDialog.getByRole('button', { name: 'Confirm' }).click();

        await expectSpkState(page, 'in_progress');
        await expect(page.getByRole('button', { name: 'Submit for Review' })).toBeVisible();
    });

    test('@kanban-t_49387795 records invoice payment and shows paid balance', async ({ page }) => {
        const customerName = await createCustomer(page, `PAY-${runId}`);
        const invoiceLine = 'E2E T49387795 payment line';
        const reference = `E2E-T49387795-PAY-${runId}`;

        await page.goto('/admin/invoices/create');
        await selectOptionByText(page.getByLabel('Customer'), customerName);
        await page.getByLabel('Issue Date').fill('2026-07-14');
        await page.getByLabel('Due Date').fill('2026-07-28');
        await page.getByLabel('Notes').fill('Payment recording journey.');
        await page.getByRole('button', { name: 'Create', exact: true }).click();

        await expect(page).toHaveURL(/\/admin\/invoices\/\d+$/);
        await expectDetail(page, 'Status', 'draft');

        await page.getByRole('button', { name: 'Add Item' }).click();
        const itemDialog = page.getByRole('dialog', { name: 'Add Item' });
        await itemDialog.getByLabel('Description').fill(invoiceLine);
        await itemDialog.getByLabel('Quantity').fill('1');
        await itemDialog.getByLabel('Unit Price').fill('120000');
        await itemDialog.getByLabel('Discount').fill('0');
        await itemDialog.getByLabel('Tax Rate (%)').fill('0');
        await itemDialog.getByRole('button', { name: 'Add' }).click();

        await expect(page.getByRole('row').filter({ hasText: invoiceLine })).toContainText(
            '120000.00',
        );
        await expectDetail(page, 'Total', '120000.00');

        await page.getByRole('button', { name: 'Send' }).click();
        await expectDetail(page, 'Status', 'sent');
        await expect(page.getByRole('button', { name: 'Record Payment' })).toBeVisible();

        await page.getByRole('button', { name: 'Record Payment' }).click();
        const paymentDialog = page.getByRole('dialog', { name: 'Record Payment' });
        await expect(paymentDialog.getByText(/Remaining:\s*120000/)).toBeVisible();
        await paymentDialog.getByLabel('Amount').fill('120000');
        await paymentDialog.getByLabel('Method').selectOption('transfer');
        await paymentDialog.getByLabel('Reference').fill(reference);
        await paymentDialog.getByLabel('Notes').fill('Paid by E2E.');
        await paymentDialog.getByRole('button', { name: 'Record' }).click();

        await expectDetail(page, 'Status', 'paid');
        await expectDetail(page, 'Paid', '120000.00');
        await expectDetail(page, 'Remaining', '0');
        const paymentRow = page.getByRole('row').filter({ hasText: reference });
        await expect(paymentRow).toContainText('120000.00');
        await expect(paymentRow).toContainText('transfer');
        await expect(paymentRow).toContainText('Active');
    });

    test('@kanban-t_49387795 creates ticket, assigns, starts, comments, and spawns SPK', async ({
        page,
    }) => {
        const customerName = await createCustomer(page, `TICKET-${runId}`);
        const handlerId = await visibleTechnicianId(page);
        const title = 'E2E T49387795 ticket journey';
        const comment = 'E2E T49387795 internal dispatch note';

        await page.goto('/admin/tickets/create');
        await page.getByLabel('Title').fill(title);
        await selectOptionByText(page.getByLabel('Category'), 'No Internet');
        await page.getByLabel('Priority').selectOption('high');
        await selectOptionByText(page.getByLabel('Customer'), customerName);
        await page
            .getByLabel('Description')
            .fill('Ticket assignment, start, comment, and SPK spawn journey.');
        await page.getByRole('button', { name: 'Create', exact: true }).click();

        await expect(page).toHaveURL(/\/admin\/tickets\/\d+$/);
        await expect(page.getByRole('main').getByRole('heading', { name: title })).toBeVisible();
        await expectDetail(page, 'Status', 'open');

        await page.getByRole('button', { name: 'Assign' }).click();
        const assignDialog = page.getByRole('dialog', { name: 'Assign' });
        await assignDialog.getByLabel('Handler ID').fill(handlerId);
        await assignDialog.getByRole('button', { name: 'Confirm' }).click();

        await expectDetail(page, 'Status', 'assigned');
        await expectDetail(page, 'Handler', 'Demo Technician');

        await page.getByRole('button', { name: 'Start' }).click();
        await expectDetail(page, 'Status', 'on_progress');
        await expect(page.getByRole('button', { name: 'Spawn SPK' })).toBeVisible();

        await page.getByRole('button', { name: 'Add Comment' }).click();
        const commentDialog = page.getByRole('dialog', { name: 'Comment' });
        await commentDialog.getByLabel('Comment').fill(comment);
        await commentDialog.getByLabel('Visibility').selectOption('internal');
        await commentDialog.getByRole('button', { name: 'Confirm' }).click();

        await expect(page.getByText(comment)).toBeVisible();

        await page.getByRole('button', { name: 'Spawn SPK' }).click();
        await page
            .getByRole('dialog', { name: 'Spawn Spk' })
            .getByRole('button', { name: 'Confirm' })
            .click();

        await expect(page).toHaveURL(/\/admin\/spk\/\d+$/);
        await expect(
            page
                .getByRole('main')
                .getByRole('heading', { name: /SPK from Ticket TKT-\d{4}-\d{5}/ }),
        ).toBeVisible();
        await expectSpkState(page, 'generated');
        await expectDetail(page, 'Customer', customerName);
    });
});

async function loginAsAdmin(page: Page) {
    await page.goto('/admin/dashboard');

    if (page.url().endsWith('/login')) {
        await page.getByLabel('Email').fill(adminEmail!);
        await page.getByLabel('Password').fill(demoPassword!);
        await page.getByRole('button', { name: 'Log in' }).click();
    }

    await expect(page).toHaveURL(/\/admin\/dashboard$/);
}

async function createCustomer(page: Page, key: string) {
    const code = `E2E-${key}-49387795`;
    const name = `E2E ${key} 49387795`;

    await loginAsAdmin(page);
    await page.goto('/admin/customers/create');
    await page.getByLabel('Code').fill(code);
    await page.getByLabel('Name').fill(name);
    await page.getByLabel('Email').fill(`${code.toLowerCase()}@example.test`);
    await page.getByLabel('Phone').fill('0800004938');
    await page.getByRole('button', { name: 'Create', exact: true }).click();

    await expect(page).toHaveURL(/\/admin\/customers\/\d+$/);
    await expect(page.getByRole('main').getByRole('heading', { name })).toBeVisible();

    return name;
}

async function visibleTechnicianId(page: Page) {
    await loginAsAdmin(page);
    await page.goto('/admin/spk');

    return optionValueByText(page.getByLabel('Technician'), 'Demo Technician');
}

async function selectOptionByText(select: Locator, text: string) {
    await select.selectOption(await optionValueByText(select, text));
}

async function selectFirstRealOption(select: Locator) {
    const value = await select.locator('option').nth(1).getAttribute('value');

    expect(value).toBeTruthy();
    await select.selectOption(value!);

    return value!;
}

async function optionValueByText(select: Locator, text: string) {
    const value = await select
        .locator('option')
        .filter({ hasText: text })
        .first()
        .getAttribute('value');

    expect(value).toBeTruthy();

    return value!;
}

async function expectDetail(page: Page, label: string, value: string) {
    await expect(
        page
            .locator('p')
            .filter({ hasText: `${label}:` })
            .first(),
    ).toContainText(value);
}

async function expectSpkState(page: Page, status: string) {
    await expect(page.getByText(new RegExp(`SPK-\\d{4}-\\d{5} · ${status}`))).toBeVisible();
}
