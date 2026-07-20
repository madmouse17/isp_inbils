import { readFileSync } from 'node:fs';
import type { Page } from '@playwright/test';

import { expect, test } from './support/fixtures';

const seederSource = readFileSync('database/seeders/DemoUserSeeder.php', 'utf8');
const demoPassword = seederSource.match(/private const PASSWORD = '([^']+)';/)?.[1];
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
    const customerCode = `E2E-CUST-${suffix}`;
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

    await page.getByLabel('Code').fill(customerCode);
    await page.getByLabel('Name').fill(customerName);
    await page.getByLabel('Email').fill(customerEmail);
    await page.getByLabel('Phone').fill('0800000000');
    await page.getByRole('button', { name: 'Create', exact: true }).click();

    await expect(page).toHaveURL(/\/admin\/customers\/\d+$/);
    await expect(page.getByRole('main').getByRole('heading', { name: customerName })).toBeVisible();
    await expect(page.getByText(customerCode, { exact: false })).toBeVisible();

    await page.getByRole('link', { name: 'Manage Addresses →' }).click();
    await expect(page.getByRole('main').getByRole('heading', { name: 'Addresses' })).toBeVisible();
    await page.getByRole('button', { name: 'Add Address' }).click();

    const addressDialog = page.getByRole('dialog', { name: 'Add Address' });
    await addressDialog.getByLabel('Label').fill(addressLabel);
    await addressDialog.getByLabel('Address').fill('17 E2E Fiber Street');
    await addressDialog.getByLabel('City').fill('Jakarta');
    await addressDialog.getByRole('switch', { name: 'Installation Point' }).click();
    await addressDialog.getByRole('switch', { name: 'Primary' }).click();
    await addressDialog.getByRole('button', { name: 'Add', exact: true }).click();

    await expect(addressDialog).toHaveCount(0);
    const addressRow = page.getByRole('row').filter({ hasText: addressLabel });
    await expect(addressRow).toContainText('17 E2E Fiber Street');
    await expect(addressRow).toContainText('Yes');

    await page.getByRole('button', { name: 'Back' }).click();
    await expect(page.getByRole('main').getByRole('heading', { name: customerName })).toBeVisible();
    await page.getByRole('tab', { name: /Subscriptions/ }).click();
    await page.getByRole('link', { name: 'Manage Subscriptions →' }).click();
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
    await addressSelect.selectOption(addressValue!);
    await subscriptionDialog.getByLabel('Billing Day (1-28)').fill(billingDay);
    await subscriptionDialog.getByLabel('MRC Amount').fill(mrcAmount);
    await subscriptionDialog.getByLabel('Contract Months').fill('12');
    await subscriptionDialog.getByRole('button', { name: 'Create', exact: true }).click();

    await expect(page).toHaveURL(/\/admin\/subscriptions\/\d+$/);
    await expect(page.getByText(`Package: ${packageName!}`, { exact: true })).toBeVisible();
    await expect(page.getByText(`MRC: ${mrcAmount}.00`, { exact: true })).toBeVisible();
    await expect(page.getByText(`Billing Day: ${billingDay}`, { exact: true })).toBeVisible();
    await expect(page.getByText('Status: pending', { exact: true })).toBeVisible();

    await page.getByRole('button', { name: 'Back' }).click();
    const subscriptionRow = page.getByRole('row').filter({ hasText: packageName! });
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
