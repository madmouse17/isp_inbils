import { readFileSync } from 'node:fs';
import type { Locator, Page } from '@playwright/test';

import { expect, test } from './support/fixtures';
import { loginAs } from './support/demo';

test.describe('@kanban-t_f519540a stock and asset UI wiring', () => {
    // Skipped: UI refactors broke test selectors and DB setup
    test('@kanban-t_f519540a receives stock through UI and shows movement evidence', async ({
        page,
    }) => {
        const note = `E2E stock receive ${Date.now()}`;

        await loginAs(page, 'admin');
        await settleAppChunks(page);
        await page.goto('/admin/stocks');
        await expect(page.getByRole('main').getByRole('heading', { name: 'Stok' })).toBeVisible();
        await page.getByRole('button', { name: 'Terima Barang' }).click();

        const dialog = page.getByRole('dialog', { name: 'Terima Barang' });
        const productOption = await selectFirstRealOption(dialog.getByRole('combobox').nth(0));
        const locationOption = await selectFirstRealOption(dialog.getByRole('combobox').nth(1));
        await dialog.getByLabel('Jumlah').fill('3');
        await dialog.getByLabel('Catatan').fill(note);
        await dialog.getByRole('button', { name: 'Simpan' }).click();

        await page.getByRole('link', { name: 'Mutasi Stok' }).click();
        await expect(
            page.getByRole('main').getByRole('heading', { name: 'Mutasi Stok' }),
        ).toBeVisible();
        await page.getByLabel('Type').selectOption('receive');
        await page.getByRole('button', { name: 'Filter' }).click();

        const row = page.getByRole('row').filter({ hasText: note });
        await expect(row).toContainText('receive');
        await expect(row).toContainText(productOption.productName);
        await expect(row).toContainText('3.00');
        await expect(row).toContainText(locationOption.locationName);
    });

    test('@receive-modal keyboard, mobile, and dark-mode runtime proof', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.emulateMedia({ colorScheme: 'dark' });
        await loginAsAdmin(page);
        await page.goto('/admin/stocks');
        await page.evaluate(() => localStorage.setItem('darkMode', 'true'));
        await page.reload();

        const trigger = page.getByRole('button', { name: 'Terima Barang' });
        await expect(page.locator('html')).toHaveClass(/dark/);
        await expect(trigger).toBeVisible();
        await trigger.click();

        const dialog = page.getByRole('dialog', { name: 'Terima Barang' });
        await expect(dialog).toBeVisible();
        await expect(dialog).toBeInViewport();
        await expect(dialog.getByLabel('Produk')).toBeFocused();

        await page.keyboard.press('Shift+Tab');
        await expect(dialog.getByRole('button', { name: 'Tutup' })).toBeFocused();
        await page.keyboard.press('Tab');
        await expect(dialog.getByLabel('Produk')).toBeFocused();

        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
        await expect(trigger).toBeFocused();
    });

    test('@kanban-t_f519540a opens network asset detail and navigates related records', async ({
        page,
    }) => {
        await loginAsAdmin(page);
        await settleAppChunks(page);
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
    await loginAs(page, 'admin');
}

/**
 * Wait until every code-split app script that has already started has finished
 * loading (responseEnd > 0), then require a short stability window with no new
 * /build/assets/ script resources. The fail-closed request watcher treats an
 * aborted in-flight lazy chunk as journey-breaking, so we settle the current
 * page before a hard goto() to another admin route. Count-based, deterministic
 * (mirrors ticket-lifecycle.spec.ts).
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

async function openLinkedAsset(page: Page) {
    await page.goto('/admin/network-assets?search=E2E-LINKED-ASSET-001');
    await expect(
        page.getByRole('main').getByRole('heading', { name: 'Aset Jaringan' }),
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

    const productName = fullLabel?.split(' (')[0]?.trim();
    const locationName = displayLabel?.split(' (')[0]?.trim();
    return {
        fullLabel: fullLabel!,
        displayLabel: displayLabel!,
        productName: productName!,
        locationName: locationName!,
    };
}
