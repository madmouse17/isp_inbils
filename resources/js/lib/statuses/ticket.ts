import type { StatusMap } from './types';

export const TICKET_STATUS = {
    open: { label: 'Terbuka', tone: 'info' },
    assigned: { label: 'Ditugaskan', tone: 'info' },
    on_progress: { label: 'Dikerjakan', tone: 'info' },
    resolved: { label: 'Diselesaikan', tone: 'success' },
    closed: { label: 'Ditutup', tone: 'neutral' },
} satisfies StatusMap;

export const TICKET_SOURCE = {
    customer: { label: 'Pelanggan', tone: 'neutral' },
    noc: { label: 'NOC', tone: 'neutral' },
    internal: { label: 'Internal', tone: 'neutral' },
} satisfies StatusMap;
