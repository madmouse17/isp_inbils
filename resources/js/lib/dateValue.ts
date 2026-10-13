import { format, isValid, parse } from 'date-fns';
import { localeId } from './dateFormat';
import { MAX_YEAR, MIN_YEAR, formatDay, isoToLocalDate, parseDay } from './dateRange';

/*
 * The text of one picked value: a time of day, a date with a time, a month. A day alone is
 * `formatDay`/`parseDay` in dateRange.ts. Values are what the server takes (`HH:mm`, `Y-m-d H:i`,
 * `Y-m`), the text is what a person reads and types.
 */

const REFERENCE_DAY = new Date(2000, 0, 1);
const pad2 = (value: number) => String(value).padStart(2, '0');

/** Written `HH:mm`, 24 hours. A person may type `8:30` or `08.30`; a database may add seconds. */
const TIME_TEXT = /^(\d{1,2})[:.](\d{2})(?::\d{2})?$/;

/** `HH:mm` from what was typed or stored; null when it is not a time of day. */
export function parseTime(text: string | null | undefined): string | null {
    const match = text ? TIME_TEXT.exec(text.trim()) : null;
    if (!match) return null;

    const [hours, minutes] = [Number(match[1]), Number(match[2])];

    return hours <= 23 && minutes <= 59 ? `${pad2(hours)}:${pad2(minutes)}` : null;
}

/** Every half hour of the day, `00:00` to `23:30`: the list the time popover offers. */
export const TIME_SLOTS: readonly string[] = Array.from(
    { length: 48 },
    (_, index) => `${pad2(Math.floor(index / 2))}:${index % 2 === 0 ? '00' : '30'}`,
);

/** The slot a time falls in, so a typed `08:15` still lands on `08:00` in the list. */
export function slotAtOrBefore(time: string): string {
    return TIME_SLOTS.findLast((slot) => slot <= time) ?? TIME_SLOTS[0];
}

const DAY_TIME_VALUE = /^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})(?::\d{2}(?:\.\d+)?)?$/;

/** The calendar day and the `HH:mm` of a `Y-m-d H:i` value; null when either is not real. */
export function splitDayTime(
    value: string | null | undefined,
): { day: string; time: string } | null {
    const match = value ? DAY_TIME_VALUE.exec(value) : null;
    const time = match ? parseTime(match[2]) : null;

    return match && time && isoToLocalDate(match[1]) ? { day: match[1], time } : null;
}

/** `Y-m-d H:i`, the form every date-time field sends. */
export function joinDayTime(day: string, time: string): string {
    return `${day} ${time}`;
}

/** `13 Okt 2026 08:30` in the company format; empty for anything that is not a `Y-m-d H:i` value. */
export function formatDayTime(value: string | null | undefined, dateFnsFormat: string): string {
    const parts = splitDayTime(value);

    return parts ? `${formatDay(parts.day, dateFnsFormat)} ${parts.time}` : '';
}

/** What was typed (`13 Okt 2026 08:30`, the time last) as a `Y-m-d H:i` value; null when it is not one. */
export function parseDayTime(text: string, dateFnsFormat: string): string | null {
    const match = /^(.*\S)\s+(\d{1,2}[:.]\d{2})$/.exec(text.trim());
    const day = match ? parseDay(match[1], dateFnsFormat) : null;
    const time = match ? parseTime(match[2]) : null;

    return day && time ? joinDayTime(day, time) : null;
}

const ISO_MONTH = /^(\d{4})-(\d{2})$/;

/** `yyyy-mm` for a year and a month from 1 to 12. */
export function monthToIso(year: number, month: number): string {
    return `${String(year).padStart(4, '0')}-${pad2(month)}`;
}

/** The first day of an ISO `yyyy-mm` month at local midnight; null for anything else, `2026-13` included. */
export function isoToMonthDate(iso: string | null | undefined): Date | null {
    const match = iso ? ISO_MONTH.exec(iso) : null;
    if (!match) return null;

    const [year, month] = [Number(match[1]), Number(match[2])];

    return month >= 1 && month <= 12 && year >= MIN_YEAR ? new Date(year, month - 1, 1) : null;
}

/** `Oktober 2026`: always the full name, whatever the company writes its days like. */
export function formatMonth(iso: string | null | undefined): string {
    const date = isoToMonthDate(iso);

    return date ? format(date, 'MMMM yyyy', { locale: localeId }) : '';
}

/** `Januari` to `Desember`, in the order of the year. */
export const MONTH_NAMES: readonly string[] = Array.from({ length: 12 }, (_, month) =>
    format(new Date(2000, month, 1), 'MMMM', { locale: localeId }),
);

/** Year and month (1 to 12) of `10/2026`, `2026-10`, `Oktober 2026` or `Okt 2026`; null otherwise. */
function monthParts(text: string): [number, number] | null {
    const monthFirst = /^(\d{1,2})[-/.](\d{4})$/.exec(text);
    if (monthFirst) return [Number(monthFirst[2]), Number(monthFirst[1])];

    const yearFirst = /^(\d{4})[-/.](\d{1,2})$/.exec(text);
    if (yearFirst) return [Number(yearFirst[1]), Number(yearFirst[2])];

    for (const pattern of ['MMMM yyyy', 'MMM yyyy']) {
        const date = parse(text, pattern, REFERENCE_DAY, { locale: localeId });
        if (isValid(date)) return [date.getFullYear(), date.getMonth() + 1];
    }

    return null;
}

/** What was typed as an ISO `yyyy-mm`; null when it is not a month in a four-digit year. */
export function parseMonth(text: string): string | null {
    const parts = monthParts(text.trim());
    if (!parts) return null;

    const [year, month] = parts;

    return month >= 1 && month <= 12 && year >= MIN_YEAR && year <= MAX_YEAR
        ? monthToIso(year, month)
        : null;
}
