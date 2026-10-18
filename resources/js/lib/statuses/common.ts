import type { StatusMap } from './types';

/** `is_active` of a user, organization, vehicle, location, document type or ticket category. Pass the boolean itself. */
export const ACTIVE_STATE = {
    true: { label: 'Aktif', tone: 'success' },
    false: { label: 'Nonaktif', tone: 'neutral' },
} satisfies StatusMap;

/** Where a master or soft-deletable record stands in its lifecycle. Read it with `lifecycleStateOf`. */
export const LIFECYCLE_STATE = {
    aktif: { label: 'Aktif', tone: 'success' },
    nonaktif: { label: 'Nonaktif', tone: 'neutral' },
    dihapus: { label: 'Dihapus', tone: 'neutral' },
} satisfies StatusMap;

/** Trashed wins over inactive; a row with no `is_active` (a transactional record) counts as active. */
export function lifecycleStateOf(row: {
    is_active?: boolean;
    deleted_at?: string | null;
}): keyof typeof LIFECYCLE_STATE {
    if (row.deleted_at) return 'dihapus';

    return row.is_active === false ? 'nonaktif' : 'aktif';
}

/** Priority of a work order and of a ticket: one set, one meaning. */
export const PRIORITY = {
    low: { label: 'Rendah', tone: 'neutral' },
    medium: { label: 'Sedang', tone: 'info' },
    high: { label: 'Tinggi', tone: 'warning' },
    urgent: { label: 'Mendesak', tone: 'danger' },
} satisfies StatusMap;
