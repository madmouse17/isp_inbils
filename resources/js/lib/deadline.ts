export type DeadlineTone = 'neutral' | 'warning' | 'danger';

const MINUTE_MS = 60_000;
const HOUR_MINUTES = 60;
const DAY_MINUTES = 24 * HOUR_MINUTES;

/** A span as its two largest units, a unit of zero left out: `< 1 mnt`, `41 mnt`, `2 j 5 mnt`, `3 j`, `2 hr 1 j`. */
function formatSpan(minutes: number): string {
    if (minutes < 1) return '< 1 mnt';
    if (minutes < HOUR_MINUTES) return `${minutes} mnt`;

    if (minutes < DAY_MINUTES) {
        const hours = Math.floor(minutes / HOUR_MINUTES);
        const rest = minutes % HOUR_MINUTES;

        return rest === 0 ? `${hours} j` : `${hours} j ${rest} mnt`;
    }

    const days = Math.floor(minutes / DAY_MINUTES);
    const hours = Math.floor((minutes % DAY_MINUTES) / HOUR_MINUTES);

    return hours === 0 ? `${days} hr` : `${days} hr ${hours} j`;
}

/**
 * How a target stands at `nowMs`: `sisa 2 j 5 mnt` while it has time left (a started minute counts), `lewat 12 mnt`
 * once it has passed (whole minutes). It is `warning` when `soonMinutes` or less is left and `danger` once passed.
 */
export function deadlineState(
    deadline: string,
    nowMs: number,
    soonMinutes = 30,
): { tone: DeadlineTone; label: string } {
    const leftMs = Date.parse(deadline) - nowMs;

    if (leftMs <= 0) {
        return { tone: 'danger', label: `lewat ${formatSpan(Math.floor(-leftMs / MINUTE_MS))}` };
    }

    const left = Math.ceil(leftMs / MINUTE_MS);

    return { tone: left <= soonMinutes ? 'warning' : 'neutral', label: `sisa ${formatSpan(left)}` };
}

/**
 * The hours a ticket target was counted from, as the ticket page explains them: `6 jam`, or with priority urgent
 * `6 jam ÷ 2 karena Mendesak = 3 jam` (an odd number rounds up, which the text says).
 */
export function targetHoursText(baseHours: number, urgentHalved: boolean): string {
    if (!urgentHalved) return `${baseHours} jam`;

    const used = Math.ceil(baseHours / 2);
    const rounded = baseHours % 2 === 1 ? ' (dibulatkan ke atas)' : '';

    return `${baseHours} jam ÷ 2 karena Mendesak = ${used} jam${rounded}`;
}
