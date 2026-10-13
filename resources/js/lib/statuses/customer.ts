import type { StatusMap } from './types';

/** Derived from the customer's subscriptions (`CustomerStatusRecalculator`), never set by hand. */
export const CUSTOMER_STATUS = {
    prospective: { label: 'Prospek', tone: 'info' },
    active: { label: 'Aktif', tone: 'success' },
    inactive: { label: 'Nonaktif', tone: 'neutral' },
    suspended: { label: 'Ditangguhkan', tone: 'warning' },
    terminated: { label: 'Berhenti', tone: 'neutral' },
} satisfies StatusMap;

/** Stored capitalised, as the form sends it. */
export const CUSTOMER_TYPE = {
    Individual: { label: 'Perorangan', tone: 'neutral' },
    Company: { label: 'Perusahaan', tone: 'neutral' },
} satisfies StatusMap;
