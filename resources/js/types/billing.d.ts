import type { INVOICE_SOURCE, INVOICE_STATUS, INVOICE_TYPE, PAYMENT_METHOD } from '@/lib/statuses';
import type { PaymentAttemptEntry } from './portal';

export interface Invoice {
    id: string;
    number: string;
    type: keyof typeof INVOICE_TYPE;
    source: keyof typeof INVOICE_SOURCE;
    customer_id: string;
    subscription_id?: string | null;
    work_order_id?: string | null;
    issue_date: string;
    due_date: string;
    billing_period_start?: string | null;
    billing_period_end?: string | null;
    status: keyof typeof INVOICE_STATUS;
    subtotal: string;
    tax_amount: string;
    discount_amount: string;
    total: string;
    paid_amount: string;
    sisa: string;
    notes?: string | null;
    sent_at?: string | null;
    cancelled_at?: string | null;
    cancel_reason?: string | null;
    created_at?: string;
    customer?: { id: string; name: string; code: string } | null;
    subscription?: { id: string; code: string } | null;
    items?: InvoiceItemEntry[];
    payments?: PaymentEntry[];
}

export interface InvoiceItemEntry {
    id: string;
    invoice_id: string;
    product_id?: string | null;
    description: string;
    quantity: string;
    unit_price: string;
    discount_amount: string;
    tax_rate: string;
    line_total: string;
}

export interface PaymentEntry {
    id: string;
    invoice_id: string;
    amount: string;
    method: keyof typeof PAYMENT_METHOD;
    reference?: string | null;
    paid_at: string;
    /** Null for gateway payments: no human received them. */
    received_by: string | null;
    notes?: string | null;
    cancelled_at?: string | null;
    cancel_reason?: string | null;
}

/** What the signed-in user may do on one invoice page; the server decides each one. */
export interface InvoiceCan {
    /** Add or remove an item: `billing.update`, or `billing.create` while the invoice is a draft. */
    update: boolean;
    send: boolean;
    cancel: boolean;
    record_payment: boolean;
    reverse_payment: boolean;
    /** Mark an online payment that could not be booked as returned to the customer. */
    refund: boolean;
}

/** How finance closed an online payment as returned to the customer, outside Inbils. */
export interface PaymentAttemptRefund {
    at: string | null;
    /** The name of whoever marked it; null once that user is gone. */
    by: string | null;
    reference: string | null;
    note: string | null;
}

/** An online payment attempt on the invoice page; `refund` is set only while its status is `refunded`. */
export interface InvoicePaymentAttempt extends PaymentAttemptEntry {
    refund: PaymentAttemptRefund | null;
}

/** The aging buckets of Tunggakan, by the keys `BillingService::receivables()` uses for its columns. */
export type ReceivableBucket = 'current' | 'b1_30' | 'b31_60' | 'b61_90' | 'b90_plus';

/** One open invoice in the "Per tagihan" list of Tunggakan (`ReceivableInvoicesQuery::row()`). */
export interface ReceivableInvoice {
    id: string;
    number: string;
    /** Named as the invoice printed it. */
    customer: { id: string; code: string; name: string } | null;
    status: keyof typeof INVOICE_STATUS;
    issue_date: string;
    due_date: string;
    /** Days past the due date by the company's calendar; 0 until the due date has passed. */
    days_overdue: number;
    bucket: ReceivableBucket;
    total: string;
    paid_amount: string;
    sisa: string;
    /** False when the customer was deleted: nobody is left to remind. */
    can_remind: boolean;
    /** A reminder already went today, so the server would refuse another one. */
    reminded_today: boolean;
}

/** The filters of that list as the server read them; a blank one is null. */
export interface ReceivableInvoiceFilters {
    search: string | null;
    bucket: ReceivableBucket | null;
    due_from: string | null;
    due_to: string | null;
}
