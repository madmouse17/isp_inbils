import type { Locator, Page } from '@playwright/test';

import { expect, test } from './support/fixtures';
import {
    expectDetail,
    expectSpkState,
    inertiaProps,
    loginAs,
    logout,
    selectFirstRealOption,
    selectOptionByText,
    technicianUserId,
} from './support/demo';

const runId = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;

test.describe('@kanban-t_49387795 UI journeys', () => {
    test.describe.configure({ mode: 'serial' });

    test('@kanban-t_49387795 creates, assigns, and starts SPK with visible lifecycle states', async ({
        page,
    }) => {
        const customerName = await createCustomer(page, `SPK-${runId}`);
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

        // store() redirects to the index. The index rows carry the SPK code
        // link (aria-label "Open SPK SPK-…") and the customer name — the SPK
        // title is a detail-page field and is NOT rendered in the index, so
        // target the row via its unique customer name instead.
        await expect(page).toHaveURL(/\/admin\/spk$/);
        const spkRow = page.getByRole('row').filter({ hasText: customerName }).first();
        await expect(spkRow).toBeVisible({ timeout: 15_000 });
        await spkRow.getByRole('link', { name: /Open SPK/ }).click();
        await expect(page).toHaveURL(/\/admin\/spk\/\d+$/);
        const spkUrl = page.url();
        await expect(page.getByRole('main').getByRole('heading', { name: title })).toBeVisible();
        await expectSpkState(page, 'draft');
        await expect(page.getByRole('button', { name: 'Assign' })).toBeVisible();

        await page.getByRole('button', { name: 'Assign' }).click();
        const assignDialog = page.getByRole('dialog', { name: 'Assign' });
        // The SPK assign dialog exposes a combobox (SearchSelect) labeled
        // "Technician", not a numeric "Technician ID" input: type to filter the
        // technician employees and pick the option by its employee name.
        await assignDialog.getByLabel('Technician').fill('Demo Technician');
        await assignDialog
            .getByRole('button', { name: /Demo Technician/ })
            .first()
            .click();
        await assignDialog.getByRole('button', { name: 'Confirm' }).click();

        await expectSpkState(page, 'assigned');
        await expectDetail(page, 'Technician', 'Demo Technician');

        // Starting is limited to the assigned technician (WorkOrderPolicy::start).
        await logout(page);
        await loginAs(page, 'technician');
        await page.goto(spkUrl);
        await expectSpkState(page, 'assigned');
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

        // store() redirects to the index; open the just-created invoice via the
        // customer-scoped row (one invoice per fresh customer in this journey).
        await expect(page).toHaveURL(/\/admin\/invoices$/);
        const invoiceRow = page.getByRole('row').filter({ hasText: customerName }).first();
        await invoiceRow.getByRole('link', { name: 'Show' }).click();
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
        const handlerId = await technicianUserId(page);
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

        // store() redirects to the tickets index (by design). The index rows
        // render the ticket code and priority/status badges but the title column
        // is blank (the resource supplies code/title, the column renders number/
        // subject), so resolve the created ticket's id from the Inertia props of
        // the title-scoped index, then open its detail page.
        await expect(page).toHaveURL(/\/admin\/tickets$/);
        const ticketId = await ticketIdFromIndex(page, title);
        if (!ticketId) throw new Error('Could not resolve created ticket id');
        await page.goto(`/admin/tickets/${ticketId}`);
        await expect(page.getByRole('main').getByRole('heading', { name: title })).toBeVisible();
        await expectDetail(page, 'Status', 'open');

        await page.getByRole('button', { name: 'Assign' }).click();
        const assignDialog = page.getByRole('dialog', { name: 'Assign' });
        await assignDialog.getByLabel('Handler ID').fill(handlerId);
        await assignDialog.getByRole('button', { name: 'Confirm' }).click();

        await expectDetail(page, 'Status', 'assigned');
        await expectDetail(page, 'Handler', 'Demo Technician');

        // Starting is limited to the assigned handler (TicketPolicy::start).
        const ticketUrl = page.url();
        await logout(page);
        await loginAs(page, 'technician');
        await page.goto(ticketUrl);
        await page.getByRole('button', { name: 'Start' }).click();
        await expectDetail(page, 'Status', 'on_progress');

        // Internal comments and SPK spawning are abilities the technician lacks.
        await logout(page);
        await loginAs(page, 'admin');
        await page.goto(ticketUrl);
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

async function createCustomer(page: Page, key: string) {
    const slug = `E2E-${key}-49387795`;
    const name = `E2E ${key} 49387795`;

    await loginAs(page);
    await page.goto('/admin/customers/create');
    await page.getByLabel('Name').fill(name);
    await page.getByLabel('Email').fill(`${slug.toLowerCase()}@example.test`);
    await page.getByLabel('Phone').fill('0800004938');

    // The create form requires a complete address on the Addresses tab.
    await page.getByRole('tab', { name: /Addresses \(\d+\)/ }).click();
    await page.getByLabel('Label').fill(`E2E Address ${key}`);
    await page.getByLabel('Address *', { exact: true }).fill('8 E2E Fiber Street');
    await page.getByLabel('Province *', { exact: true }).click();
    await page
        .getByRole('button', { name: /jakarta/i })
        .first()
        .click();
    await page.getByLabel('City / Regency *', { exact: true }).click();
    await page
        .getByRole('button', { name: /jakarta pusat/i })
        .first()
        .click();
    await page.getByLabel('District *', { exact: true }).click();
    await page
        .getByRole('button', { name: /menteng/i })
        .first()
        .click();
    await page.getByLabel('Village / Subdistrict *', { exact: true }).click();
    await page
        .getByRole('button', { name: /menteng/i })
        .first()
        .click();
    await page.getByRole('button', { name: 'Lat / Long' }).click();
    await page.getByLabel('Latitude', { exact: true }).fill('-6.2100000');
    await page.getByLabel('Longitude', { exact: true }).fill('106.8200000');
    await page.getByRole('button', { name: 'Find' }).click();

    // The create form also requires a primary contact.
    await page.getByRole('tab', { name: /Contacts \(\d+\)/ }).click();
    await page.getByLabel('Name').fill(`${name} Contact`);
    await page.getByLabel('Phone').fill('081200004938');
    await page.getByLabel('Email').fill(`contact.${slug.toLowerCase()}@example.test`);

    // Subscription & SPK section: package + serving POP are required and the
    // E2E seeder provisions "Home 10Mbps" and "Default POP". The POP option
    // label is rendered as "code — name (type)" (see Create.tsx), so match the
    // seeded "Default POP" by substring — the same loose pattern as
    // customer-subscription.spec.ts — instead of an exact label match.
    await page.getByRole('tab', { name: 'Subscription & SPK' }).click();
    await page.getByLabel('Service Package').selectOption({ index: 1 });
    const popSelect = page.getByLabel('Serving POP / Location');
    const popValue = await popSelect
        .locator('option')
        .filter({ hasText: 'Default POP' })
        .first()
        .getAttribute('value');
    expect(popValue).toBeTruthy();
    await popSelect.selectOption(popValue!);

    await page.getByRole('tab', { name: 'Customer Data' }).click();
    await page.getByRole('button', { name: 'Create Customer & SPK' }).click();

    await expect(page).toHaveURL(/\/admin\/customers\/\d+$/);
    await expect(page.getByRole('main').getByRole('heading', { name })).toBeVisible();

    return name;
}

/**
 * Resolve a ticket id by its unique title via the Tickets index Inertia props
 * (canonical parsing helper: support/demo.ts inertiaProps). Tickets/Index.tsx
 * renders row.number/row.subject but TicketResource supplies code/title, so the
 * title column is blank in the UI; the server-side search still matches title,
 * so we read the id from the Inertia page object.
 */
async function ticketIdFromIndex(page: Page, title: string) {
    const { props } = await inertiaProps(
        page,
        `/admin/tickets?search=${encodeURIComponent(title)}`,
    );
    const tickets = props?.['tickets'] as
        { data?: Array<{ id: number; title: string }> } | undefined;
    const ticket = tickets?.data?.find((entry) => entry.title === title);

    return ticket ? String(ticket.id) : null;
}
