import type { INVOICE_SOURCE, INVOICE_STATUS, INVOICE_TYPE, PAYMENT_METHOD } from '@/lib/statuses';

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
