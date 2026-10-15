/** What the server keeps of a technician's check-in; their own coordinates never reach the page. */
export interface CheckInRecord {
    status?: string | null;
    distance_m?: number | null;
    accuracy_m?: number | string | null;
    reason?: string | null;
}

const REASON: Record<string, string> = {
    permission_denied: 'izin lokasi ditolak',
    permission_denied_forever: 'izin lokasi ditolak permanen',
    location_disabled: 'lokasi dimatikan',
    no_fix: 'lokasi tidak terdeteksi',
    timeout: 'waktu habis',
};

/** One line for a check-in: how far from the site, or why there is no location. `Belum ada check-in` for none. */
export function checkInText(record: CheckInRecord | null | undefined): string {
    if (!record) return 'Belum ada check-in';

    const hasAccuracy =
        record.accuracy_m !== null && record.accuracy_m !== undefined && record.accuracy_m !== '';
    const accuracy = hasAccuracy
        ? ` (akurasi ±${Number(record.accuracy_m).toLocaleString('id-ID', { maximumFractionDigits: 1 })} m)`
        : '';

    if (record.status === 'recorded') {
        return record.distance_m !== null && record.distance_m !== undefined
            ? `${Number(record.distance_m).toLocaleString('id-ID')} m dari lokasi${accuracy}`
            : `Tercatat, lokasi tujuan belum punya koordinat untuk dibandingkan${accuracy}`;
    }

    const reason = record.reason ?? '';

    return `Lokasi tidak tersedia (${Object.hasOwn(REASON, reason) ? REASON[reason] : 'tanpa alasan'})`;
}
