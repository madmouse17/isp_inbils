export interface Invoice {
    id: number;
    number: string;
    type: string;
    source: string;
    customer_id: number;
    subscription_id?: number | null;
    work_order_id?: number | null;
    issue_date: string;
    due_date: string;
    billing_period_start?: string | null;
    billing_period_end?: string | null;
    status: string;
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
    customer?: { id: number; name: string; code: string } | null;
    subscription?: { id: number; code: string } | null;
    items?: InvoiceItemEntry[];
    payments?: PaymentEntry[];
}

export interface InvoiceItemEntry {
    id: number;
    invoice_id: number;
    product_id?: number | null;
    description: string;
    quantity: string;
    unit_price: string;
    discount_amount: string;
    tax_rate: string;
    line_total: string;
}

export interface PaymentEntry {
    id: number;
    invoice_id: number;
    amount: string;
    method: string;
    reference?: string | null;
    paid_at: string;
    received_by: number;
    notes?: string | null;
    cancelled_at?: string | null;
    cancel_reason?: string | null;
}
