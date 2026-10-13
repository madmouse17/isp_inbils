import { format, isValid, parse } from 'date-fns';
import { localeId } from './dateFormat';

/** A filter range as ISO `yyyy-mm-dd` strings; `null` is an open end. */
export interface DateRange {
    from: string | null;
    to: string | null;
}

export type DateRangePresetId =
    'today' | 'last7' | 'last30' | 'thisMonth' | 'lastMonth' | 'thisYear';

export const DATE_RANGE_PRESETS: ReadonlyArray<{ id: DateRangePresetId; label: string }> = [
    { id: 'today', label: 'Hari ini' },
    { id: 'last7', label: '7 hari terakhir' },
    { id: 'last30', label: '30 hari terakhir' },
    { id: 'thisMonth', label: 'Bulan ini' },
    { id: 'lastMonth', label: 'Bulan lalu' },
    { id: 'thisYear', label: 'Tahun ini' },
];

export type DateRangeParse =
    { ok: true; range: DateRange } | { ok: false; reason: 'format' | 'order' };

const ISO_DAY = /^(\d{4})-(\d{2})-(\d{2})$/;
/** An en or em dash, or a hyphen with spaces around it (a bare hyphen belongs to `Y-m-d`). */
const RANGE_SEPARATOR = /\s*[–—]\s*|\s+-\s+/;
/** A typed two-digit year parses as year 26 AD; four digits only, inside MySQL's `date` range. */
const MIN_YEAR = 1000;
const MAX_YEAR = 9999;
const REFERENCE_DAY = new Date(2000, 0, 1);

/** The calendar day of an ISO `yyyy-mm-dd`, at local midnight; null for anything else, `2026-02-31` included. */
export function isoToLocalDate(iso: string | null | undefined): Date | null {
    const match = iso ? ISO_DAY.exec(iso) : null;
    if (!match) return null;

    const [year, month, day] = [Number(match[1]), Number(match[2]), Number(match[3])];
    const date = new Date(year, month - 1, day);

    return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day
        ? date
        : null;
}

/** The ISO `yyyy-mm-dd` of a local calendar day. */
export function localDateToIso(date: Date): string {
    const pad = (value: number, width: number) => String(value).padStart(width, '0');

    return `${pad(date.getFullYear(), 4)}-${pad(date.getMonth() + 1, 2)}-${pad(date.getDate(), 2)}`;
}

/** Today's ISO date on the company clock, not the browser's. */
export function todayInZone(timeZone: string | null | undefined, now: Date = new Date()): string {
    try {
        const parts = new Intl.DateTimeFormat('en-US', {
            timeZone: timeZone ?? undefined,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        }).formatToParts(now);
        const part = (type: string) => parts.find((entry) => entry.type === type)?.value ?? '';

        return `${part('year')}-${part('month')}-${part('day')}`;
    } catch {
        // An unknown zone name throws; the browser clock is the best guess left.
        return localDateToIso(now);
    }
}

function shiftDays(iso: string, days: number): string {
    const [year, month, day] = iso.split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, day + days)).toISOString().slice(0, 10);
}

/** A preset as ISO dates. Counts run through today, and the open periods end today, not on a future day. */
export function presetRange(id: DateRangePresetId, today: string): DateRange {
    const monthStart = `${today.slice(0, 7)}-01`;

    switch (id) {
        case 'today':
            return { from: today, to: today };
        case 'last7':
            return { from: shiftDays(today, -6), to: today };
        case 'last30':
            return { from: shiftDays(today, -29), to: today };
        case 'thisMonth':
            return { from: monthStart, to: today };
        case 'lastMonth': {
            const to = shiftDays(monthStart, -1);

            return { from: `${to.slice(0, 7)}-01`, to };
        }
        case 'thisYear':
            return { from: `${today.slice(0, 4)}-01-01`, to: today };
    }
}

/** `13 Okt 2026 – 20 Okt 2026`; an open end leaves its side empty (`13 Okt 2026 –`). */
export function formatRange(range: DateRange, dateFnsFormat: string): string {
    const day = (iso: string | null) => {
        const date = isoToLocalDate(iso);

        return date ? format(date, dateFnsFormat, { locale: localeId }) : '';
    };
    const [from, to] = [day(range.from), day(range.to)];

    if (from && to) return `${from} – ${to}`;
    if (from) return `${from} –`;

    return to ? `– ${to}` : '';
}

/** One typed day in the company format; null when it is not a real day. */
function parseDay(text: string, dateFnsFormat: string): string | null {
    // Typing "13 Oktober 2026" into a `d M Y` field is as natural as "13 Okt 2026".
    const monthWidths = dateFnsFormat.includes('MMMM')
        ? [dateFnsFormat, dateFnsFormat.replace('MMMM', 'MMM')]
        : dateFnsFormat.includes('MMM')
          ? [dateFnsFormat, dateFnsFormat.replace('MMM', 'MMMM')]
          : [dateFnsFormat];

    for (const candidate of monthWidths) {
        const date = parse(text, candidate, REFERENCE_DAY, { locale: localeId });
        const year = date.getFullYear();

        if (isValid(date) && year >= MIN_YEAR && year <= MAX_YEAR) return localDateToIso(date);
    }

    return null;
}

/**
 * What a user typed into the range field, in the company format. A lone day is that day, a dangling
 * separator leaves an end open (`13/10/2026 -` is from that day on), blank clears the range.
 */
export function parseRange(text: string, dateFnsFormat: string): DateRangeParse {
    const trimmed = text.trim();
    if (trimmed === '') return { ok: true, range: { from: null, to: null } };

    const parts = ` ${trimmed} `.split(RANGE_SEPARATOR).map((part) => part.trim());
    if (parts.length > 2) return { ok: false, reason: 'format' };

    const days: Array<string | null> = [];
    for (const part of parts.length === 1 ? [parts[0], parts[0]] : parts) {
        const iso = part === '' ? null : parseDay(part, dateFnsFormat);
        if (part !== '' && iso === null) return { ok: false, reason: 'format' };
        days.push(iso);
    }

    const [from, to] = days;
    // ISO strings sort like the days they name.
    if (from && to && from > to) return { ok: false, reason: 'order' };

    return { ok: true, range: { from, to } };
}
