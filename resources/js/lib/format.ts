import { format as formatPattern } from 'date-fns';
import type { Company } from '@/types';
import { localeId, phpToDateFns } from './dateFormat';
import { isoToLocalDate } from './dateRange';

/** The company currency as a formatter: id-ID for rupiah, en-US for any other currency. */
function currencyFormatter(
    company: Company | null | undefined,
    fractionDigits?: number,
): Intl.NumberFormat {
    const currency = company?.currency ?? 'IDR';

    return new Intl.NumberFormat(currency === 'IDR' ? 'id-ID' : 'en-US', {
        style: 'currency',
        currency,
        ...(fractionDigits === undefined
            ? { maximumFractionDigits: 2 }
            : { minimumFractionDigits: fractionDigits, maximumFractionDigits: fractionDigits }),
    });
}

/**
 * `Rp 147.702` for a whole amount, `Rp 147.701,62` when the stored value has cents. A stored amount is
 * never rounded for display, so a balance from before whole-rupiah billing reads as it is stored.
 * Another currency keeps its two decimals.
 */
export function formatRupiah(amount: number | string, company?: Company | null): string {
    const parsed = typeof amount === 'string' ? Number(amount) : amount;
    const value = Number.isFinite(parsed) ? parsed : 0;
    const hasCents = Math.round(Math.abs(value) * 100) % 100 !== 0;
    const isRupiah = (company?.currency ?? 'IDR') === 'IDR';
    const fractionDigits = isRupiah ? (hasCents ? 2 : 0) : undefined;

    return currencyFormatter(company, fractionDigits).format(value);
}

/**
 * A rupiah figure in the short Indonesian scale for a summary card: `Rp 950 rb`, `Rp 1,2 jt`, `Rp 3,4 M`,
 * `Rp 1,5 T`. It rounds to one decimal, so a card that shows it also gives the exact `formatRupiah` text.
 */
export function formatRupiahCompact(amount: number | string, company?: Company | null): string {
    const value = typeof amount === 'string' ? Number(amount) : amount;
    const currency = company?.currency ?? 'IDR';
    const locale = currency === 'IDR' ? 'id-ID' : 'en-US';

    return new Intl.NumberFormat(locale, {
        style: 'currency',
        currency,
        notation: 'compact',
        maximumFractionDigits: 1,
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

/** `4,8` for 4.8: the Indonesian decimal comma, `digits` decimals at most, no trailing zeros; `-` when there is no number. */
export function formatDecimal(value: number | string | null | undefined, digits = 1): string {
    if (value === null || value === undefined) return '-';
    if (typeof value === 'string' && value.trim() === '') return '-';

    const number = typeof value === 'string' ? Number(value) : value;

    // `signDisplay: 'negative'` keeps a small negative that rounds to nothing from printing `-0`.
    return Number.isFinite(number)
        ? new Intl.NumberFormat('id-ID', {
              maximumFractionDigits: digits,
              signDisplay: 'negative',
          }).format(number)
        : '-';
}

/** `63,6%`: `formatDecimal` with the percent sign; a missing value stays a bare `-`. */
export function formatPercent(value: number | string | null | undefined, digits = 1): string {
    const text = formatDecimal(value, digits);

    return text === '-' ? text : `${text}%`;
}

/** What a person has typed into a money field, read the way the company writes money. */
export interface TypedRupiah {
    /** The amount; null while no digit is typed. */
    value: number | null;
    /** What the field shows for it: grouped thousands, then the decimal mark and the cents typed so far. */
    text: string;
}

/** A `decimal(15,2)` column holds 13 whole digits; more would also lose precision as a number. */
const MAX_WHOLE_DIGITS = 13;

/**
 * Reads typed or pasted money. Rupiah groups thousands with `.` and starts the cents with `,`
 * (`147.701,62`); another currency writes it the other way round. Every other character is dropped
 * (`Rp`, spaces), a `.` in rupiah is always a thousands mark, and at most two cents are kept, so
 * editing a stored amount that has cents never turns them into whole digits.
 */
export function typedRupiah(raw: string, company?: Company | null): TypedRupiah {
    const isRupiah = (company?.currency ?? 'IDR') === 'IDR';
    const [group, decimal] = isRupiah ? ['.', ','] : [',', '.'];
    const [whole, ...fraction] = raw.replace(/[^\d.,]/g, '').split(decimal);
    const wholeDigits = whole
        .replaceAll(group, '')
        .replace(/^0+(?=\d)/, '')
        .slice(0, MAX_WHOLE_DIGITS);
    const cents = fraction.length > 0 ? fraction.join('').replaceAll(group, '').slice(0, 2) : null;

    if (wholeDigits === '' && !cents) return { value: null, text: '' };

    const integer = Number(wholeDigits || '0');

    return {
        value: Number(cents ? `${integer}.${cents}` : integer),
        text:
            currencyFormatter(company, 0).format(integer) +
            (cents === null ? '' : `${decimal}${cents}`),
    };
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
