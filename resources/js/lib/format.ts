import { format as formatPattern } from 'date-fns';
import type { Company } from '@/types';
import { localeId, phpToDateFns } from './dateFormat';
import { isoToLocalDate } from './dateRange';

export function formatRupiah(amount: number | string, company?: Company | null): string {
    const value = typeof amount === 'string' ? Number(amount) : amount;
    const currency = company?.currency ?? 'IDR';
    const locale = currency === 'IDR' ? 'id-ID' : 'en-US';

    return new Intl.NumberFormat(locale, {
        style: 'currency',
        currency,
        maximumFractionDigits: currency === 'IDR' ? 0 : 2,
    }).format(Number.isFinite(value) ? value : 0);
}

export function formatMoney(
    value: number | string | null | undefined,
    companyOrCurrency?: Company | string | null,
): string {
    if (typeof companyOrCurrency === 'string') {
        return formatRupiah(value ?? 0, { currency: companyOrCurrency } as Company);
    }

    return formatRupiah(value ?? 0, companyOrCurrency ?? null);
}

export function formatNumber(value: number | string): string {
    const number = typeof value === 'string' ? Number(value) : value;

    return new Intl.NumberFormat('id-ID').format(Number.isFinite(number) ? number : 0);
}

/** A bare ISO day is a calendar day: `new Date('2026-10-13')` is midnight UTC, the 12th west of Greenwich. */
function toDate(value: string | number | Date): Date | null {
    if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) {
        return isoToLocalDate(value);
    }

    const date = value instanceof Date ? value : new Date(value);

    return Number.isNaN(date.getTime()) ? null : date;
}

/** In the company `date_format` (`13 Okt 2026` for `d M Y`); `13/10/2026` when it has none or an unsupported one. */
export function formatDate(
    value: string | Date | null | undefined,
    company?: Company | null,
): string {
    if (!value) return '-';

    const date = toDate(value);
    if (!date) return '-';

    return formatPattern(date, phpToDateFns(company?.settings?.date_format), { locale: localeId });
}

/** The date as `formatDate` writes it, then the 24-hour time in the browser's clock. */
export function formatDateTime(
    value: string | number | Date | null | undefined,
    company?: Company | null,
): string {
    if (value == null || value === '') return '-';

    const date = toDate(value);
    if (!date) return '-';

    return formatPattern(date, `${phpToDateFns(company?.settings?.date_format)} HH:mm`, {
        locale: localeId,
    });
}
