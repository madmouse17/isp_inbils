import { resolveStatus, toneColor } from '@/lib/statuses';
import type { StatusMap } from '@/lib/statuses';
import type { Series, SeriesPoint } from '@/types';

/** One bar or slice: the shape the bar and donut wrappers both take. */
export type ChartRow = { name: string; value: number };

/** A donut slice that stands for a state: `color` is the tone of that state, so a status reads the same here as in a table. */
export type StatusSlice = ChartRow & { color: string };

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

/**
 * A `{ code: count }` map of one domain's values as donut slices, in the order the payload lists
 * them: named by the status map and coloured by its tone. A code the map lacks reads as itself, neutral.
 */
export function statusSlices(
    counts: Record<string, number> | undefined,
    map: StatusMap,
): StatusSlice[] {
    return Object.entries(counts ?? {}).map(([code, count]) => {
        const status = resolveStatus(map, code);

        return {
            name: status?.label ?? code,
            value: Number(count),
            color: toneColor(status?.tone ?? 'neutral'),
        };
    });
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

/** The last calendar day (ISO) of the bucket that starts on `bucket`. */
function bucketEnd(bucket: string, granularity: Series['granularity']): string {
    const end = new Date(`${bucket}T00:00:00Z`);

    if (granularity === 'week') end.setUTCDate(end.getUTCDate() + 6);
    // Day 0 of the next month is the last day of this one.
    else if (granularity === 'month') end.setUTCMonth(end.getUTCMonth() + 1, 0);

    return end.toISOString().slice(0, 10);
}

/**
 * The points of a series, with "(berjalan)" after the label of the bucket that still holds `today`
 * (the company's calendar day, ISO): a day, week or month in progress has not collected all its
 * figures, so its dip is not a decline.
 */
export function markRunning(series: Series, today: string): SeriesPoint[] {
    return series.points.map((point) =>
        point.bucket <= today && today <= bucketEnd(point.bucket, series.granularity)
            ? { ...point, label: `${point.label} (berjalan)` }
            : point,
    );
}
