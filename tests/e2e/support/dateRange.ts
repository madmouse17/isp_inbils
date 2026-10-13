import type { Locator, Page } from '@playwright/test';

const MONTHS_SHORT = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'Mei',
    'Jun',
    'Jul',
    'Agu',
    'Sep',
    'Okt',
    'Nov',
    'Des',
];
const MONTHS_LONG = [
    'Januari',
    'Februari',
    'Maret',
    'April',
    'Mei',
    'Juni',
    'Juli',
    'Agustus',
    'September',
    'Oktober',
    'November',
    'Desember',
];

/** The company `date_format` (PHP tokens) the page was rendered with. */
export async function companyDateFormat(page: Page): Promise<string> {
    return page.evaluate(() => {
        const raw = document.getElementById('app')?.getAttribute('data-page');
        const props = raw ? JSON.parse(raw).props : {};
        return (props?.company?.settings?.date_format as string | undefined) ?? 'd/m/Y';
    });
}

/** Render an ISO `yyyy-mm-dd` day in a PHP date format (tokens d j m n Y y M F). */
export function formatIsoAs(iso: string, phpFormat: string): string {
    const [year, month, day] = iso.split('-');
    const monthIndex = Number(month) - 1;
    const tokens: Record<string, string> = {
        d: day,
        j: String(Number(day)),
        m: month,
        n: String(Number(month)),
        Y: year,
        y: year.slice(-2),
        M: MONTHS_SHORT[monthIndex],
        F: MONTHS_LONG[monthIndex],
    };
    return phpFormat.replace(/[djmnYyMF]/g, (token) => tokens[token]);
}

/** The text a user types into the range field for `from`/`to` in the company format. */
export async function typedRange(
    page: Page,
    from: string | null,
    to: string | null,
): Promise<string> {
    const format = await companyDateFormat(page);
    return `${from ? formatIsoAs(from, format) : ''} - ${to ? formatIsoAs(to, format) : ''}`;
}

/** Type a range into the picker's text field and commit it with Tab. */
export async function typeDateRange(
    page: Page,
    field: Locator,
    from: string | null,
    to: string | null,
): Promise<void> {
    await field.fill(await typedRange(page, from, to));
    await field.press('Tab');
}
