import type { StatusMap } from './types';

/** Staff record the first four; `midtrans` is written only by the payment gateway. */
export const PAYMENT_METHOD = {
    cash: { label: 'Tunai', tone: 'neutral' },
    transfer: { label: 'Transfer', tone: 'neutral' },
    cheque: { label: 'Cek', tone: 'neutral' },
    other: { label: 'Lainnya', tone: 'neutral' },
    midtrans: { label: 'Midtrans', tone: 'neutral' },
} satisfies StatusMap;

/** A payment has no status column: it is `cancelled` when `cancelled_at` is set, otherwise `active`. */
export const PAYMENT_STATE = {
    active: { label: 'Aktif', tone: 'success' },
    cancelled: { label: 'Dibatalkan', tone: 'neutral' },
} satisfies StatusMap;

/**
 * A customer's attempt to pay through Midtrans. `exception` is a refund, a chargeback or a payment billing refused.
 * `cancelled_locally` is a page Inbils closed because the invoice was paid or cancelled while it was open.
 * `refunded` is an exception finance closed after the money went back to the customer outside Inbils.
 */
export const PAYMENT_ATTEMPT_STATUS = {
    pending: { label: 'Menunggu', tone: 'warning' },
    paid: { label: 'Lunas', tone: 'success' },
    failed: { label: 'Gagal', tone: 'danger' },
    expired: { label: 'Kedaluwarsa', tone: 'neutral' },
    exception: { label: 'Perlu dicek', tone: 'danger' },
    cancelled_locally: { label: 'Ditutup', tone: 'neutral' },
    refunded: { label: 'Dikembalikan', tone: 'neutral' },
} satisfies StatusMap;
