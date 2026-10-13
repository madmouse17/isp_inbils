import type { StatusMap } from './types';

export const SUBSCRIPTION_STATUS = {
    pending: { label: 'Menunggu', tone: 'warning' },
    active: { label: 'Aktif', tone: 'success' },
    suspended: { label: 'Ditangguhkan', tone: 'warning' },
    terminated: { label: 'Berhenti', tone: 'neutral' },
} satisfies StatusMap;
