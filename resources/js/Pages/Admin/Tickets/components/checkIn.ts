/** What the server keeps of a technician's check-in; their own coordinates never reach the page. */
export interface CheckInRecord {
    status?: string | null;
    distance_m?: number | null;
    accuracy_m?: number | string | null;
    reason?: string | null;
    /** The start was farther from the site than the company allows. A check-in made before that limit has no such key. */
    far?: boolean | null;
    /** What the technician wrote when they started far from the site. */
    far_reason?: string | null;
}

const REASON: Record<string, string> = {
    permission_denied: 'izin lokasi ditolak',
    permission_denied_forever: 'izin lokasi ditolak permanen',
    location_disabled: 'lokasi dimatikan',
    no_fix: 'lokasi tidak terdeteksi',
    timeout: 'waktu habis',
};

/** `820 m` below a kilometre, `119,3 km` from it, as the server words it (`FieldCheckIn::distanceText`). */
export function distanceText(metres: number): string {
    return metres < 1000
        ? `${Number(metres).toLocaleString('id-ID')} m`
        : `${(metres / 1000).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} km`;
}

/**
 * One line for a check-in: how far from the site, and why when the start was far; or why there is no location.
 * `Belum ada check-in` for none.
 */
export function checkInText(record: CheckInRecord | null | undefined): string {
    if (!record) return 'Belum ada check-in';

    const hasAccuracy =
        record.accuracy_m !== null && record.accuracy_m !== undefined && record.accuracy_m !== '';
    const accuracy = hasAccuracy
        ? ` (akurasi ±${Number(record.accuracy_m).toLocaleString('id-ID', { maximumFractionDigits: 1 })} m)`
        : '';

    if (record.status === 'recorded') {
        const far = record.far_reason ? `. Jauh dari lokasi: ${record.far_reason}` : '';

        return record.distance_m !== null && record.distance_m !== undefined
            ? `${distanceText(Number(record.distance_m))} dari lokasi${accuracy}${far}`
            : `Tercatat, lokasi tujuan belum punya koordinat untuk dibandingkan${accuracy}`;
    }

    const reason = record.reason ?? '';

    return `Lokasi tidak tersedia (${Object.hasOwn(REASON, reason) ? REASON[reason] : 'tanpa alasan'})`;
}
