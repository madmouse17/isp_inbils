import type { StatusMap } from './types';

/** `is_active` of a user, organization, vehicle, location, document type or ticket category. Pass the boolean itself. */
export const ACTIVE_STATE = {
    true: { label: 'Aktif', tone: 'success' },
    false: { label: 'Nonaktif', tone: 'neutral' },
} satisfies StatusMap;

/** Priority of a work order and of a ticket: one set, one meaning. */
export const PRIORITY = {
    low: { label: 'Rendah', tone: 'neutral' },
    medium: { label: 'Sedang', tone: 'info' },
    high: { label: 'Tinggi', tone: 'warning' },
    urgent: { label: 'Mendesak', tone: 'danger' },
} satisfies StatusMap;
