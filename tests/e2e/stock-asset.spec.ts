import { readFileSync } from 'node:fs';
import type { Locator, Page } from '@playwright/test';

import { expect, test } from './support/fixtures';

const seederSource = readFileSync('database/seeders/DemoUserSeeder.php', 'utf8');
const demoPassword = seederSource.match(/private const PASSWORD = '([^']+)';/)?.[1];
const adminEmail = seederSource.match(
    /'admin' => \['name' => 'Demo Admin', 'email' => '([^']+)'/,
)?.[1];

if (!demoPassword || !adminEmail) {
    throw new Error('Could not derive DemoUserSeeder admin credentials.');
}

test.describe('@kanban-t_f519540a stock and asset UI wiring', () => {
    test('@kanban-t_f519540a receives stock through UI and shows movement evidence', async ({
        page,
    }) => {
        const note = `E2E stock receive ${Date.now()}`;

        await loginAsAdmin(page);
        await page.goto('/admin/stocks');
        await expect(page.getByRole('main').getByRole('heading', { name: 'Stocks' })).toBeVisible();
        await page.getByRole('button', { name: 'Receive' }).click();

        const dialog = page.getByRole('dialog', { name: 'Receive Stock' });
        const productOption = await selectFirstRealOption(dialog.getByRole('combobox').nth(0));
        const locationOption = await selectFirstRealOption(dialog.getByRole('combobox').nth(1));
        await dialog.getByLabel('Quantity').fill('3');
        await dialog.getByLabel('Note').fill(note);
        await dialog.getByRole('button', { name: 'Submit' }).click();

        await page.getByRole('link', { name: 'Stock Movements' }).click();
        await expect(
            page.getByRole('main').getByRole('heading', { name: 'Stock Movements' }),
        ).toBeVisible();
        await page.getByLabel('Type').selectOption('receive');
        await page.getByRole('button', { name: 'Filter' }).click();

        const row = page.getByRole('row').filter({ hasText: note });
        await expect(row).toContainText('receive');
        await expect(row).toContainText(productOption.fullLabel);
        await expect(row).toContainText(locationOption.displayLabel);
        await expect(row).toContainText('3.00');
    });

    test('@kanban-t_f519540a opens network asset detail and navigates related records', async ({
        page,
    }) => {
        await loginAsAdmin(page);
        await openLinkedAsset(page);

        await expect(
            page.getByRole('main').getByRole('heading', { name: 'E2E Linked ONT' }),
        ).toBeVisible();
        await page.getByRole('link', { name: 'E2E Linked Customer' }).click();
        await expect(page).toHaveURL(/\/admin\/customers\/\d+$/);
        await expect(
            page.getByRole('main').getByRole('heading', { name: 'E2E Linked Customer' }),
        ).toBeVisible();

        await openLinkedAsset(page);
        await page.getByRole('link', { name: 'E2E-LINKED-SUB' }).click();
        await expect(page).toHaveURL(/\/admin\/subscriptions\/\d+$/);
        await expect(
            page.getByRole('main').getByRole('heading', { name: 'Subscription E2E-LINKED-SUB' }),
        ).toBeVisible();
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

async function openLinkedAsset(page: Page) {
    await page.goto('/admin/network-assets?search=E2E-LINKED-ASSET-001');
    await expect(
        page.getByRole('main').getByRole('heading', { name: 'Network Assets' }),
    ).toBeVisible();
    await page
        .getByRole('row')
        .filter({ hasText: 'E2E-LINKED-ASSET-001' })
        .getByRole('link', { name: 'Show' })
        .click();
}

async function selectFirstRealOption(select: Locator) {
    const option = select.locator('option').nth(1);
    const value = await option.getAttribute('value');
    const fullLabel = (await option.textContent())?.trim();
    const displayLabel = fullLabel?.split(' — ').pop()?.trim();

    expect(value).toBeTruthy();
    expect(fullLabel).toBeTruthy();
    expect(displayLabel).toBeTruthy();
    await select.selectOption(value!);

    return { fullLabel: fullLabel!, displayLabel: displayLabel! };
}
