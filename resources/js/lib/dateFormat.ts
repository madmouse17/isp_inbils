import type { Locale } from 'date-fns';
import { id } from 'date-fns/locale/id';

/** PHP `date()` tokens a company `date_format` may use, with their date-fns equivalents. */
const PHP_TO_DATE_FNS: Readonly<Record<string, string>> = {
    d: 'dd',
    j: 'd',
    m: 'MM',
    n: 'M',
    Y: 'yyyy',
    y: 'yy',
    M: 'MMM',
    F: 'MMMM',
    D: 'EEE',
    l: 'EEEE',
};

/** Used for a company format holding a token this module cannot map. */
export const FALLBACK_DATE_FORMAT = 'dd/MM/yyyy';

/**
 * Turns a PHP date format (`d M Y`) into a date-fns one (`dd MMM yyyy`).
 * shortcut: only the tokens above; any other letter, a quote or a backslash, or a format without a
 * day, a month and a year, gives the fallback. Add a token here when a company needs one.
 */
export function phpToDateFns(phpFormat: unknown): string {
    if (typeof phpFormat !== 'string') return FALLBACK_DATE_FORMAT;

    let mapped = '';
    for (const char of phpFormat) {
        if (Object.hasOwn(PHP_TO_DATE_FNS, char)) {
            mapped += PHP_TO_DATE_FNS[char];
        } else if (/[A-Za-z'\\]/.test(char)) {
            return FALLBACK_DATE_FORMAT;
        } else {
            mapped += char;
        }
    }

    return /d/.test(mapped) && /M/.test(mapped) && /y/.test(mapped) ? mapped : FALLBACK_DATE_FORMAT;
}

const MONTHS_ABBREVIATED = [
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
] as const;

/**
 * date-fns abbreviates August as "Agt"; Intl, CLDR and the Carbon labels the backend sends say
 * "Agu". Formatting and parsing use "Agu", and parsing still accepts "Agt".
 */
export const localeId: Locale = {
    ...id,
    localize: {
        ...id.localize,
        month: (month, options) =>
            options?.width === 'abbreviated'
                ? MONTHS_ABBREVIATED[month]
                : id.localize.month(month, options),
    },
    match: {
        ...id.match,
        month: (text, options) =>
            id.match.month(
                options?.width === 'wide' ? text : text.replace(/^agu(?!stus)/i, 'agt'),
                options,
            ),
    },
};
