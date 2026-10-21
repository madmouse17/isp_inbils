import { formatNumber } from '@/lib/format';

/**
 * A length of time given in minutes, the way the reports say it: `45 menit`, `2 jam 15 menit`, `35 jam`; `-` when there
 * is none. Hours and minutes only, never days, so a column of them reads the same all the way down (the PDF of a
 * report writes the same figure `2 j 15 m`).
 */
export function formatMinutes(minutes: number | null | undefined): string {
    if (minutes === null || minutes === undefined || !Number.isFinite(minutes)) return '-';

    const total = Math.max(0, Math.round(minutes));
    if (total < 60) return `${formatNumber(total)} menit`;

    const hours = Math.floor(total / 60);
    const rest = total % 60;

    return rest === 0 ? `${formatNumber(hours)} jam` : `${formatNumber(hours)} jam ${rest} menit`;
}
