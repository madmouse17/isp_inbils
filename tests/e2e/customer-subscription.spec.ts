import { readFileSync } from 'node:fs';
import type { Page } from '@playwright/test';

import { expect, test } from './support/fixtures';

const seederSource = readFileSync('database/seeders/DemoUserSeeder.php', 'utf8');
const demoPassword = seederSource.match(/(?:public|private) const PASSWORD = '([^']+)';/)?.[1];
const adminEmail = seederSource.match(
    /'admin' => \['name' => 'Demo Admin', 'email' => '([^']+)'/,
)?.[1];

if (!demoPassword || !adminEmail) {
    throw new Error('Could not derive DemoUserSeeder admin credentials.');
}

test('@kanban-t_744bf022 admin creates customer, installation address, and pending subscription', async ({
    page,
}) => {
    const suffix = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
    const customerName = `E2E Customer ${suffix}`;
    const customerEmail = `e2e-${suffix}@example.test`;
    const addressLabel = `E2E Installation ${suffix}`;
    const billingDay = '17';
    const mrcAmount = '150000';

    await loginAsAdmin(page);
    await page.goto('/admin/customers/create');
    await expect(
        page.getByRole('main').getByRole('heading', { name: 'Create Customer' }),
    ).toBeVisible();

    await page.getByLabel('Name').fill(customerName);
    await page.getByLabel('Email').fill(customerEmail);
    await page.getByLabel('Phone').fill('0800000000');

    // The create form requires a complete address (backend validates label,
    // street, region cascade, and coordinates). Fill the default address on
    // the Addresses tab, then submit from the customer tab.
    await page.getByRole('tab', { name: /Addresses \(\d+\)/ }).click();
    await page.getByLabel('Label').fill(addressLabel);
    await page.getByLabel('Address *', { exact: true }).fill('17 E2E Fiber Street');
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
    await page.getByLabel('Name').fill(`${customerName} Contact`);
    await page.getByLabel('Phone').fill('081200000000');
    await page.getByLabel('Email').fill(`contact.${suffix}@example.test`);

    // Subscription & SPK section: package + serving POP are required; the E2E
    // seeder provisions "Home 10Mbps" and "Default POP".
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
    await expect(page.getByRole('main').getByRole('heading', { name: customerName })).toBeVisible();

    // The customer show page renders related tables inline (read-only) — the
    // create-address flow now happens on the create form itself (default
    // address seeded there) and subscription creation lives on the dedicated
    // per-customer subscriptions page.
    const customerId = page.url().match(/\/admin\/customers\/(\d+)$/)?.[1];
    if (!customerId) throw new Error('Could not resolve created customer id');

    // Onboarding leaves only a pending subscription, so the derived status is Prospective.
    await expect(page.getByRole('main').getByText('Prospective')).toBeVisible();
    const customerSearch = encodeURIComponent(customerName);
    await page.goto(`/admin/customers?search=${customerSearch}&status=active`);
    await expect(page.getByRole('main').getByText(customerName)).toHaveCount(0);
    await page.goto(`/admin/customers?search=${customerSearch}&status=prospective`);
    await expect(page.getByRole('row').filter({ hasText: customerName })).toContainText(
        'Prospective',
    );
    await page.goto(`/admin/customers/${customerId}`);

    await expect(page.getByRole('tab', { name: /Addresses \(\d+\)/ })).toBeVisible();
    await expect(page.getByRole('tab', { name: /Subscriptions \(\d+\)/ })).toBeVisible();

    await page.goto(`/admin/customers/${customerId}/subscriptions`);
    await expect(
        page.getByRole('main').getByRole('heading', { name: 'Subscriptions' }),
    ).toBeVisible();
    await page.getByRole('button', { name: 'Create Subscription' }).click();

    const subscriptionDialog = page.getByRole('dialog', { name: 'Create Subscription' });
    const packageSelect = subscriptionDialog.getByLabel('Service Package');
    const packageName = (await packageSelect.locator('option').nth(1).textContent())?.split(
        ' (MRC:',
    )[0];

    expect(packageName).toBeTruthy();
    await packageSelect.selectOption({ index: 1 });
    const addressSelect = subscriptionDialog.getByLabel('Installation Address');
    const addressOption = addressSelect.locator('option').filter({ hasText: addressLabel });
    const addressValue = await addressOption.getAttribute('value');

    expect(addressValue).toBeTruthy();
    // The dialog lists the customer's addresses; the newly created customer
    // has exactly one address (the create form's default), so fall back to the
    // first real option when the label doesn't match (label text rendering
    // varies by region).
    if (addressValue) {
        await addressSelect.selectOption(addressValue);
    } else {
        await packageSelect.selectOption({ index: 1 });
        for (let i = 1; i < (await addressSelect.locator('option').count()); i += 1) {
            await addressSelect.selectOption({ index: i });
        }
    }
    await subscriptionDialog.getByLabel('Billing Day (1-28)').fill(billingDay);
    await subscriptionDialog.getByLabel('MRC Amount').fill(mrcAmount);
    await subscriptionDialog.getByLabel('Contract Months').fill('12');
    await subscriptionDialog.getByRole('button', { name: 'Create', exact: true }).click();

    // storeForCustomer() redirects back to the per-customer subscriptions index.
    // Onboarding already created one pending subscription with the same
    // package, so both rows share 'Business 100Mbps' + 'pending'. Disambiguate
    // by the MRC that only the dialog-created row carries (onboarding defaults
    // to the package MRC, i.e. the seeded package — this created row is set to
    // mrcAmount explicitly).
    await expect(page).toHaveURL(new RegExp(`/admin/customers/${customerId}/subscriptions$`));
    const subscriptionRow = page
        .getByRole('row')
        .filter({ hasText: packageName! })
        .filter({ hasText: `${mrcAmount}.00` });
    await expect(subscriptionRow).toBeVisible();
    await expect(subscriptionRow).toContainText('pending');
    await expect(subscriptionRow).toContainText(`${mrcAmount}.00`);
    await expect(subscriptionRow).toContainText(billingDay);
});

async function loginAsAdmin(page: Page) {
    await page.goto('/login');
    await page.getByLabel('Email').fill(adminEmail!);
    await page.getByLabel('Password').fill(demoPassword!);
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(/\/admin\/dashboard$/);
}
