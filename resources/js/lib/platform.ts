const BYTE_UNITS = ['B', 'KB', 'MB', 'GB', 'TB'] as const;

export function formatBytes(value: number | null | undefined): string {
    if (value == null || !Number.isFinite(value) || value <= 0) return '0 B';

    let size = value;
    let unit = 0;

    while (size >= 1024 && unit < BYTE_UNITS.length - 1) {
        size /= 1024;
        unit += 1;
    }

    return `${new Intl.NumberFormat('id-ID', {
        maximumFractionDigits: size < 10 && unit > 0 ? 1 : 0,
    }).format(size)} ${BYTE_UNITS[unit]}`;
}

export function totalRows(rowCounts: Record<string, number> | null | undefined): number {
    return Object.values(rowCounts ?? {}).reduce((total, count) => total + count, 0);
}

export function classBasename(value: string | null | undefined): string {
    if (!value) return '-';

    return value.split('\\').at(-1) ?? value;
}

export function isThrottleError(message: string | undefined): boolean {
    return message?.startsWith('Terlalu banyak') === true;
}

/** What a 429 tells the operator. `Retry-After` is a whole number of seconds, or missing. */
export function throttleMessage(retryAfter: unknown): string {
    const seconds = Number(retryAfter);

    return Number.isFinite(seconds) && seconds > 0
        ? `Terlalu banyak percobaan. Coba lagi dalam ${Math.ceil(seconds)} detik.`
        : 'Terlalu banyak percobaan. Coba lagi nanti.';
}

/** A property of an audit entry as one line of text: scalars as they are, lists and maps as compact JSON, `-` for none. */
export function formatPropertyValue(value: unknown): string {
    if (value === null || value === undefined || value === '') return '-';
    if (typeof value === 'string') return value;
    if (typeof value === 'boolean') return value ? 'Ya' : 'Tidak';
    if (typeof value === 'number') return String(value);

    return JSON.stringify(value);
}
