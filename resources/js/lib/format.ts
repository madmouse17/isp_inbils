import type { Company } from '@/types';

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

export function formatNumber(value: number | string): string {
    const number = typeof value === 'string' ? Number(value) : value;

    return new Intl.NumberFormat('id-ID').format(Number.isFinite(number) ? number : 0);
}

export function formatDate(
    value: string | Date | null | undefined,
    company?: Company | null,
): string {
    if (!value) return '-';

    const date = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(date.getTime())) return '-';

    const dateFormat = company?.settings?.date_format;
    const format = typeof dateFormat === 'string' ? dateFormat : 'd/m/Y';
    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const year = String(date.getFullYear());

    return format.replace('d', day).replace('m', month).replace('Y', year);
}
