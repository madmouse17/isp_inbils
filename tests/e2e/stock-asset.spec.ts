import { readFileSync } from 'node:fs';
import type { Page } from '@playwright/test';

import { expect, test } from './support/fixtures';
import { loginAs, selectSearchOption } from './support/demo';

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
        await selectSearchOption(page, 'Barang', 'E2E-STOCK-PRODUCT');
        await selectSearchOption(page, 'Lokasi', 'E2E Rack 01');
        await dialog.getByLabel('Jumlah').fill('3');
        await dialog.getByLabel('Catatan').fill(note);
        await dialog.getByRole('button', { name: 'Simpan' }).click();

        await page.getByRole('link', { name: 'Mutasi Stok' }).click();
        await expect(
            page.getByRole('main').getByRole('heading', { name: 'Mutasi Stok' }),
        ).toBeVisible();
        // The type filter applies at once: there is no Filter button.
        await page.getByLabel('Jenis', { exact: true }).selectOption('receive');

        const row = page.getByRole('row').filter({ hasText: note });
        await expect(row).toContainText('Masuk');
        await expect(row).toContainText('E2E Stock Product');
        await expect(row.getByRole('cell').filter({ hasText: /^3([.,]00)?$/ })).toHaveCount(1);
        await expect(row).toContainText('E2E Rack 01');
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
        await expect(dialog.getByRole('combobox', { name: 'Barang' })).toBeFocused();

        await page.keyboard.press('Shift+Tab');
        await expect(dialog.getByRole('button', { name: 'Tutup' })).toBeFocused();
        await page.keyboard.press('Tab');
        await expect(dialog.getByRole('combobox', { name: 'Barang' })).toBeFocused();

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
        await expect(page).toHaveURL(/\/admin\/customers\/[0-9A-HJKMNP-TV-Z]{26}$/i);
        await expect(
            page.getByRole('main').getByRole('heading', { name: 'E2E Linked Customer' }),
        ).toBeVisible();

        await openLinkedAsset(page);
        await page.getByRole('link', { name: 'E2E-LINKED-SUB' }).click();
        await expect(page).toHaveURL(/\/admin\/subscriptions\/[0-9A-HJKMNP-TV-Z]{26}$/i);
        await expect(
            page.getByRole('main').getByRole('heading', { name: 'Langganan E2E-LINKED-SUB' }),
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
        .getByRole('link', { name: 'Lihat', exact: true })
        .click();
}
