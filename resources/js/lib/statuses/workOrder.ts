import type { StatusMap } from './types';

/** SPK. `generated` has a code and waits for a technician; `rejected` is work sent back for rework. */
export const WORK_ORDER_STATUS = {
    draft: { label: 'Draf', tone: 'neutral' },
    generated: { label: 'Menunggu penugasan', tone: 'warning' },
    assigned: { label: 'Ditugaskan', tone: 'info' },
    in_progress: { label: 'Dikerjakan', tone: 'info' },
    waiting_review: { label: 'Menunggu review', tone: 'warning' },
    completed: { label: 'Selesai', tone: 'success' },
    rejected: { label: 'Ditolak', tone: 'danger' },
    cancelled: { label: 'Dibatalkan', tone: 'neutral' },
} satisfies StatusMap;

export const WORK_ORDER_TYPE = {
    installation: { label: 'Instalasi', tone: 'neutral' },
    maintenance: { label: 'Perawatan', tone: 'neutral' },
    upgrade_service: { label: 'Peningkatan layanan', tone: 'neutral' },
    relocation: { label: 'Pindah lokasi', tone: 'neutral' },
} satisfies StatusMap;
