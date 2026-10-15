import type { StatusMap } from './types';

export const EMPLOYEE_STATUS = {
    active: { label: 'Aktif', tone: 'success' },
    inactive: { label: 'Nonaktif', tone: 'neutral' },
    terminated: { label: 'Berhenti', tone: 'neutral' },
} satisfies StatusMap;

export const ORGANIZATION_TYPE = {
    company: { label: 'Perusahaan', tone: 'neutral' },
    branch: { label: 'Cabang', tone: 'neutral' },
    area: { label: 'Area', tone: 'neutral' },
    unit: { label: 'Unit', tone: 'neutral' },
    team: { label: 'Tim', tone: 'neutral' },
} satisfies StatusMap;

/** A login's second factor: on, started but not confirmed yet (profile page only), or off. */
export const TWO_FACTOR_STATE = {
    enabled: { label: 'Aktif', tone: 'success' },
    pending: { label: 'Belum selesai', tone: 'warning' },
    off: { label: 'Nonaktif', tone: 'neutral' },
} satisfies StatusMap;
