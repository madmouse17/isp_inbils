import type { StatusMap } from './types';

/** `sent` is an invoice issued and not paid yet; it turns `overdue` by the daily job. */
export const INVOICE_STATUS = {
    draft: { label: 'Draf', tone: 'neutral' },
    sent: { label: 'Belum dibayar', tone: 'info' },
    partial: { label: 'Dibayar sebagian', tone: 'warning' },
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

/**
 * The status a screen shows for an invoice. Only the daily job turns `sent` and `partial` into `overdue`, so for some hours
 * after midnight a list would call an already late invoice "Belum dibayar" while Tunggakan and the apps count it late (F5-D13).
 * A screen reads the due date instead. `today` is the company's calendar day as `Y-m-d` (`todayInZone`).
 */
export function effectiveInvoiceStatus(
    status: string,
    dueDate: string | null | undefined,
    today: string,
): string {
    return (status === 'sent' || status === 'partial') &&
        dueDate != null &&
        dueDate.slice(0, 10) < today
        ? 'overdue'
        : status;
}
