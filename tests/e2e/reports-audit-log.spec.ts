import { expect, test } from './support/fixtures';
import { loginAs } from './support/demo';

test.describe('@smoke @audit-log UI report audit log', () => {
    test('renders audit log table, controls, filtering, and property disclosure modal', async ({
        page,
    }) => {
        // Login as admin (has report.audit.view capability)
        await loginAs(page, 'admin');

        // Navigate to Audit Log report
        await page.goto('/admin/reports/audit-log');
        await expect(page).toHaveURL(/\/admin\/reports\/audit-log/);

        // Verify Page Header
        await expect(
            page.getByRole('main').getByRole('heading', { name: 'Audit Log' }),
        ).toBeVisible();

        // Verify Filter Card & Controls
        await expect(page.getByText('Filter & Pencarian Log')).toBeVisible();
        await expect(
            page.getByPlaceholder('Cari deskripsi, modul, event, pelaku...'),
        ).toBeVisible();
        await expect(page.getByRole('combobox', { name: 'Modul' })).toBeVisible();
        await expect(page.getByLabel('ID Pengguna (Causer)')).toBeVisible();
        await expect(page.getByLabel('Dari Tanggal', { exact: true })).toBeVisible();
        await expect(page.getByLabel('Sampai Tanggal', { exact: true })).toBeVisible();

        // Verify Quick Presets
        await expect(page.getByRole('button', { name: 'Hari Ini' })).toBeVisible();
        await expect(page.getByRole('button', { name: '7 Hari' })).toBeVisible();
        await expect(page.getByRole('button', { name: '30 Hari' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Bulan Ini' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Reset Tanggal' })).toBeVisible();

        // Verify Action Buttons
        await expect(page.getByRole('button', { name: 'Atur Ulang', exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Terapkan Filter' })).toBeVisible();

        // Verify Table Column Headers (Desktop)
        await expect(page.getByRole('columnheader', { name: 'Waktu' })).toBeVisible();
        await expect(page.getByRole('columnheader', { name: 'Modul' })).toBeVisible();
        await expect(page.getByRole('columnheader', { name: 'Aksi' })).toBeVisible();
        await expect(page.getByRole('columnheader', { name: 'Deskripsi' })).toBeVisible();
        await expect(page.getByRole('columnheader', { name: 'Pelaku' })).toBeVisible();
        await expect(page.getByRole('columnheader', { name: 'Subjek' })).toBeVisible();
        await expect(page.getByRole('columnheader', { name: 'Detail' })).toBeVisible();

        const detailButtons = page.getByRole('button', { name: /Lihat detail log aktivitas/ });
        await expect(detailButtons.first()).toBeVisible();
        const firstLogName = await detailButtons.first().getAttribute('aria-label');
        const previousPage = page.getByRole('button', { name: 'Previous', exact: true });
        await expect(previousPage).toBeDisabled();
        await page.getByRole('button', { name: 'Next', exact: true }).click();
        await expect(page).toHaveURL(/[?&]page=2(?:&|$)/);
        await expect(previousPage).toBeEnabled();
        await expect(detailButtons.first()).toBeVisible();
        await expect(detailButtons.first()).not.toHaveAttribute('aria-label', firstLogName!);
        await previousPage.click();
        await expect(page).toHaveURL(/[?&]page=1(?:&|$)/);
        await expect(previousPage).toBeDisabled();
        await expect(detailButtons.first()).toHaveAttribute('aria-label', firstLogName!);

        // Test Date Validation Alert: set Date To earlier than Date From
        await page.getByLabel('Dari Tanggal', { exact: true }).fill('2026-09-10');
        await page.getByLabel('Sampai Tanggal', { exact: true }).fill('2026-09-01');
        await expect(page.getByText('Rentang Tanggal Tidak Valid')).toBeVisible();

        // Clear invalid dates using Reset Tanggal preset
        await page.getByRole('button', { name: 'Reset Tanggal' }).click();
        await expect(page.getByText('Rentang Tanggal Tidak Valid')).not.toBeVisible();
        await expect(page.getByLabel('Dari Tanggal', { exact: true })).toHaveValue('');
        await expect(page.getByLabel('Sampai Tanggal', { exact: true })).toHaveValue('');

        // Test search input interaction: submit filter and assert URL & dataset changes
        const searchInput = page.getByPlaceholder('Cari deskripsi, modul, event, pelaku...');
        await searchInput.fill('customer');
        await page.getByRole('button', { name: 'Terapkan Filter' }).click();
        await expect(page).toHaveURL(/search=customer/);
        // Search may match module, event, description, or actor.
        const customerRows = page.locator('tbody tr');
        await expect(customerRows.first()).toBeVisible();
        for (const row of await customerRows.all()) {
            const searchedCells = await row.locator('td:nth-child(n+2):nth-child(-n+5)').allInnerTexts();
            expect(searchedCells.join(' ')).toMatch(/customer/i);
        }

        // Test search by causer (pelaku): submit filter and assert dataset is narrowed to causer rows
        await searchInput.fill('Demo Admin');
        await page.getByRole('button', { name: 'Terapkan Filter' }).click();
        await expect(page).toHaveURL(/search=Demo(\+|%20)Admin/);
        const causerCells = page.locator('tbody tr td:nth-child(5)');
        await expect(causerCells.first()).toBeVisible();
        const causerTexts = await causerCells.allInnerTexts();
        for (const text of causerTexts) {
            expect(text).toContain('Demo Admin');
        }

        // Clear search filter and re-apply to restore full dataset
        await searchInput.fill('');
        await page.getByRole('button', { name: 'Terapkan Filter' }).click();
        await expect(page).toHaveURL(/\/admin\/reports\/audit-log/);
        await expect(page).not.toHaveURL(/search=/);
        await expect(detailButtons.first()).toHaveAttribute('aria-label', firstLogName!);

        // Test sorting: clicking sortable header 'Modul' sorts ascending
        const moduleHeader = page.getByRole('columnheader', { name: 'Modul' });
        await moduleHeader.getByRole('button').click();
        await expect(page).toHaveURL(/sort=log_name/);

        const ascModuleCells = page.locator('tbody tr td:nth-child(2)');
        await expect(ascModuleCells.first()).toBeVisible();
        const ascTexts = (await ascModuleCells.allInnerTexts()).map((t) => t.trim().toUpperCase());
        for (let i = 0; i < ascTexts.length - 1; i++) {
            expect(ascTexts[i].localeCompare(ascTexts[i + 1])).toBeLessThanOrEqual(0);
        }

        // Clicking sortable header 'Modul' again toggles sort direction to descending
        await moduleHeader.getByRole('button').click();
        await expect(page).toHaveURL(/direction=desc/);

        const descModuleCells = page.locator('tbody tr td:nth-child(2)');
        await expect(descModuleCells.first()).toBeVisible();
        const descTexts = (await descModuleCells.allInnerTexts()).map((t) =>
            t.trim().toUpperCase(),
        );
        for (let i = 0; i < descTexts.length - 1; i++) {
            expect(descTexts[i].localeCompare(descTexts[i + 1])).toBeGreaterThanOrEqual(0);
        }
        expect(descTexts[0]).not.toEqual(ascTexts[0]);

        // Test sorting by description without depending on the first seeded value.
        const descriptionHeader = page.getByRole('columnheader', { name: 'Deskripsi' });
        await descriptionHeader.getByRole('button').click();
        await expect(page).toHaveURL(/sort=description/);
        const descriptionCells = page.locator('tbody tr td:nth-child(4)');
        await expect(descriptionCells.first()).toBeVisible();
        const descriptions = await descriptionCells.allInnerTexts();
        for (let i = 0; i < descriptions.length - 1; i++) {
            expect(descriptions[i].localeCompare(descriptions[i + 1])).toBeLessThanOrEqual(0);
        }

        // Test non-sortable headers: clicking 'Pelaku' or 'Subjek' does NOT set sort=causer or sort=subject
        const causerHeader = page.getByRole('columnheader', { name: 'Pelaku' });
        await causerHeader.click();
        await expect(page).not.toHaveURL(/sort=causer/);

        const subjectHeader = page.getByRole('columnheader', { name: 'Subjek' });
        await subjectHeader.click();
        await expect(page).not.toHaveURL(/sort=subject/);

        // Test Detail Modal: fixture guarantees audit logs exist deterministically
        await expect(detailButtons.first()).toBeVisible();
        const selectedLogName = await detailButtons.first().getAttribute('aria-label');
        await detailButtons.first().click();

        // Modal should open
        const modal = page.getByRole('dialog', {
            name: selectedLogName!.replace('Lihat detail log aktivitas', 'Detail Log Aktivitas'),
            exact: true,
        });
        await expect(modal).toBeVisible();
        await expect(modal.getByText('Informasi Ringkasan')).toBeVisible();
        await expect(modal.getByText('Properti & Perubahan Nilai')).toBeVisible();

        // Close modal
        await modal.getByRole('button', { name: 'Tutup' }).first().click();
        await expect(modal).not.toBeVisible();
    });
});
