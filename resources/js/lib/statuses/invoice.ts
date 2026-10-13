import type { StatusMap } from './types';

/** `sent` is an invoice issued and not paid yet; it turns `overdue` by the daily job. */
export const INVOICE_STATUS = {
    draft: { label: 'Draf', tone: 'neutral' },
    sent: { label: 'Belum dibayar', tone: 'info' },
    partial: { label: 'Sebagian', tone: 'warning' },
    paid: { label: 'Lunas', tone: 'success' },
    overdue: { label: 'Lewat jatuh tempo', tone: 'danger' },
    cancelled: { label: 'Dibatalkan', tone: 'neutral' },
} satisfies StatusMap;

export const INVOICE_TYPE = {
    one_time: { label: 'Sekali bayar', tone: 'neutral' },
    recurring: { label: 'Berlangganan', tone: 'neutral' },
} satisfies StatusMap;

export const INVOICE_SOURCE = {
    spk: { label: 'SPK', tone: 'neutral' },
    subscription: { label: 'Langganan', tone: 'neutral' },
    manual: { label: 'Manual', tone: 'neutral' },
} satisfies StatusMap;
