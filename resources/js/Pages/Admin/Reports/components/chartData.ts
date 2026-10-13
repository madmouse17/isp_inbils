import type { Series } from '@/types';

/** One bar or slice: the shape the bar and donut wrappers both take. */
export type ChartRow = { name: string; value: number };

const CODE_LABELS: Record<string, string> = {
    // Invoice status
    draft: 'Draf',
    sent: 'Terkirim',
    partial: 'Dibayar sebagian',
    paid: 'Lunas',
    overdue: 'Lewat jatuh tempo',
    cancelled: 'Dibatalkan',
    // SPK type
    installation: 'Pemasangan',
    maintenance: 'Perawatan',
    upgrade_service: 'Peningkatan layanan',
    relocation: 'Relokasi',
    // Network asset status
    available: 'Tersedia',
    installed: 'Terpasang',
    damaged: 'Rusak',
    retired: 'Dipensiunkan',
};

/** Indonesian name of a status or type the server sends as a code; an unknown code reads as plain words. */
export function codeLabel(code: string): string {
    const known = CODE_LABELS[code];
    if (known) return known;

    const words = code.replace(/_/g, ' ');

    return words.charAt(0).toUpperCase() + words.slice(1);
}

/** A `{ name: count }` map of the payload as rows. PHP sends an empty map as `[]`. */
export function countRows(
    counts: Record<string, number> | undefined,
    label: (name: string) => string = (name) => name,
): ChartRow[] {
    return Object.entries(counts ?? {}).map(([name, count]) => ({
        name: label(name),
        value: Number(count),
    }));
}

/** Biggest rows first. Past `limit` rows the smallest fold into one, so a long list stays a readable bar chart. */
export function topRows(rows: ChartRow[], limit: number): ChartRow[] {
    const ranked = [...rows].sort((a, b) => b.value - a.value);
    if (ranked.length <= limit) return ranked;

    const rest = ranked.slice(limit - 1);

    return [
        ...ranked.slice(0, limit - 1),
        { name: 'Lainnya', value: rest.reduce((sum, row) => sum + row.value, 0) },
    ];
}

const PERIOD_UNITS = { day: 'hari', week: 'minggu', month: 'bulan' } as const;

/** The bucket size of a series in words: "hari", "minggu" or "bulan". */
export function periodUnit(series: Series): string {
    return PERIOD_UNITS[series.granularity];
}
