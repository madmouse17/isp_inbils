import { expect, test } from './support/fixtures';
import { inertiaProps, loginAs } from './support/demo';
import { typeDateRange } from './support/dateRange';

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
            page.getByRole('main').getByRole('heading', { name: 'Log audit' }),
        ).toBeVisible();

        // Verify Filter Card & Controls
        await expect(page.getByText('Filter log', { exact: true })).toBeVisible();
        await expect(page.getByPlaceholder('Cari deskripsi, modul, aksi, pelaku...')).toBeVisible();
        await expect(page.getByRole('combobox', { name: 'Modul' })).toBeVisible();
        await expect(page.getByRole('combobox', { name: 'Pengguna', exact: true })).toBeVisible();
        const rangeField = page.getByLabel('Periode', { exact: true });
        await expect(rangeField).toBeVisible();

        // Verify Quick Presets (inside the range calendar popover)
        await page.getByRole('button', { name: 'Buka kalender' }).click();
        const calendar = page.getByRole('dialog', { name: 'Pilih rentang tanggal' });
        for (const preset of [
            'Hari ini',
            '7 hari terakhir',
            '30 hari terakhir',
            'Bulan ini',
            'Bulan lalu',
            'Tahun ini',
        ]) {
            await expect(calendar.getByRole('button', { name: preset, exact: true })).toBeVisible();
        }
        await page.keyboard.press('Escape');
        await expect(calendar).toBeHidden();

        // Verify Action Buttons
        await expect(
            page.getByRole('button', { name: 'Reset filter', exact: true }).first(),
        ).toBeVisible();
        await expect(page.getByRole('button', { name: 'Terapkan filter' })).toBeVisible();

        // Verify Table Column Headers (Desktop)
        await expect(page.getByRole('columnheader', { name: 'Waktu' })).toBeVisible();
        await expect(page.getByRole('columnheader', { name: 'Modul' })).toBeVisible();
        // The sentence replaced the raw action column
        await expect(page.getByRole('columnheader', { name: 'Aksi' })).toHaveCount(0);
        await expect(page.getByRole('columnheader', { name: 'Deskripsi' })).toBeVisible();
        await expect(page.getByRole('columnheader', { name: 'Pelaku' })).toBeVisible();
        await expect(page.getByRole('columnheader', { name: 'Subjek' })).toBeVisible();
        await expect(page.getByRole('columnheader', { name: 'Detail' })).toBeVisible();

        const detailButtons = page.getByRole('button', { name: /Lihat detail log aktivitas/ });
        await expect(detailButtons.first()).toBeVisible();
        const firstLogName = await detailButtons.first().getAttribute('aria-label');
        const previousPage = page.getByRole('button', { name: 'Sebelumnya', exact: true });
        await expect(previousPage).toBeDisabled();
        await page.getByRole('button', { name: 'Berikutnya', exact: true }).click();
        await expect(page).toHaveURL(/[?&]page=2(?:&|$)/);
        await expect(previousPage).toBeEnabled();
        await expect(detailButtons.first()).toBeVisible();
        await expect(detailButtons.first()).not.toHaveAttribute('aria-label', firstLogName!);
        await previousPage.click();
        await expect(page).toHaveURL(/[?&]page=1(?:&|$)/);
        await expect(previousPage).toBeDisabled();
        await expect(detailButtons.first()).toHaveAttribute('aria-label', firstLogName!);

        // An inverted typed range is rejected inline and never reaches the URL
        await typeDateRange(page, rangeField, '2026-09-10', '2026-09-01');
        await expect(
            page
                .getByRole('alert')
                .filter({ hasText: 'Tanggal akhir tidak boleh sebelum tanggal mulai.' }),
        ).toBeVisible();

        // Clear the range with the picker's clear button
        await typeDateRange(page, rangeField, '2026-09-01', '2026-09-10');
        await page.getByRole('button', { name: 'Hapus rentang tanggal' }).click();
        await expect(
            page.getByText('Tanggal akhir tidak boleh sebelum tanggal mulai.'),
        ).toBeHidden();
        await expect(rangeField).toHaveValue('');

        // Test search input interaction: submit filter and assert URL & dataset changes
        const searchInput = page.getByPlaceholder('Cari deskripsi, modul, aksi, pelaku...');
        await searchInput.fill('customer');
        await page.getByRole('button', { name: 'Terapkan filter' }).click();
        await expect(page).toHaveURL(/search=customer/);
        // Search may match module, event, description, or actor.
        const customerRows = page.locator('tbody tr');
        await expect(customerRows.first()).toBeVisible();
        for (const row of await customerRows.all()) {
            const searchedCells = await row
                .locator('td:nth-child(n+2):nth-child(-n+4)')
                .allInnerTexts();
            expect(searchedCells.join(' ')).toMatch(/customer|pelanggan/i);
        }

        // Test search by causer (pelaku): submit filter and assert dataset is narrowed to causer rows
        await searchInput.fill('Demo Admin');
        await page.getByRole('button', { name: 'Terapkan filter' }).click();
        await expect(page).toHaveURL(/search=Demo(\+|%20)Admin/);
        const causerCells = page.locator('tbody tr td:nth-child(4)');
        await expect(causerCells.first()).toBeVisible();
        const causerTexts = await causerCells.allInnerTexts();
        for (const text of causerTexts) {
            expect(text).toContain('Demo Admin');
        }

        // Clear search filter and re-apply to restore full dataset
        await searchInput.fill('');
        await page.getByRole('button', { name: 'Terapkan filter' }).click();
        await expect(page).toHaveURL(/\/admin\/reports\/audit-log/);
        await expect(page).not.toHaveURL(/search=/);
        await expect(detailButtons.first()).toHaveAttribute('aria-label', firstLogName!);

        // Test sorting: clicking sortable header 'Modul' sorts ascending
        const moduleHeader = page.getByRole('columnheader', { name: 'Modul' });
        await moduleHeader.getByRole('button').click();
        await expect(page).toHaveURL(/sort=log_name/);

        const ascModuleCells = page.locator('tbody tr td:nth-child(2)');
        await expect(ascModuleCells.first()).toBeVisible();
        // The server sorts on the raw module name while the cells show its label, so the order is read from the
        // rows the page renders, one cell each.
        const moduleNames = async () => {
            const { props } = await inertiaProps(page, page.url());

            return ((props?.data?.data ?? []) as { log_name: string }[]).map((row) => row.log_name);
        };
        const ascNames = await moduleNames();
        expect(ascNames.length, 'rows to compare').toBeGreaterThan(1);
        await expect(ascModuleCells).toHaveCount(ascNames.length);
        expect(ascNames, 'Modul ascending').toEqual([...ascNames].sort());

        // Clicking sortable header 'Modul' again toggles sort direction to descending
        await moduleHeader.getByRole('button').click();
        await expect(page).toHaveURL(/direction=desc/);

        const descModuleCells = page.locator('tbody tr td:nth-child(2)');
        await expect(descModuleCells.first()).toBeVisible();
        const descNames = await moduleNames();
        await expect(descModuleCells).toHaveCount(descNames.length);
        expect(descNames, 'Modul descending').toEqual([...descNames].sort().reverse());
        expect(descNames[0]).not.toEqual(ascNames[0]);

        // The sentence is worded server-side, so its order is no order of the stored codes: the header is not a sort
        const descriptionHeader = page.getByRole('columnheader', { name: 'Deskripsi' });
        await expect(descriptionHeader.getByRole('button')).toHaveCount(0);
        // Every entry reads as a sentence the server worded: no snake_case code in the description cells
        const descriptionCells = page.locator('tbody tr td:nth-child(3)');
        await expect(descriptionCells.first()).toBeVisible();
        for (const text of await descriptionCells.allInnerTexts()) {
            expect(text, 'description is a sentence, not an event code').not.toMatch(
                /\b[a-z]+_[a-z_]+\b/,
            );
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
            name: selectedLogName!.replace('Lihat detail log aktivitas', 'Detail log aktivitas'),
            exact: true,
        });
        await expect(modal).toBeVisible();
        await expect(modal.getByText('Ringkasan', { exact: true })).toBeVisible();
        await expect(modal.getByText('Properti dan perubahan nilai')).toBeVisible();

        // Close modal
        await modal.getByRole('button', { name: 'Tutup' }).first().click();
        await expect(modal).not.toBeVisible();
    });
});
