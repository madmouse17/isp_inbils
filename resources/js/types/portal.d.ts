/** Customer portal and online payment contracts, mirroring PortalInvoiceResource / PaymentAttemptResource. */

export interface PortalInvoiceItem {
    id: number;
    description: string;
    quantity: string;
    unit_price: string;
    discount_amount: string;
    tax_rate: string;
    line_total: string;
}

export interface PortalInvoicePayment {
    id: number;
    amount: string;
    method: string;
    paid_at: string | null;
}

export interface PortalInvoice {
    id: number;
    number: string;
    issue_date: string | null;
    due_date: string | null;
    status: string;
    subtotal: string;
    tax_amount: string;
    discount_amount: string;
    total: string;
    paid_amount: string;
    sisa: string;
    items?: PortalInvoiceItem[];
    payments?: PortalInvoicePayment[];
}

/** Whether the Pay button may be shown and used, and the customer-facing reason when it may not. */
export interface PortalPaymentState {
    enabled: boolean;
    can_pay: boolean;
    blocked_reason: string | null;
}

export type PaymentAttemptStatus = 'pending' | 'paid' | 'failed' | 'expired' | 'exception';

/** The customer's view of an attempt: never the token, the page URL or the internal reason. */
export interface PortalPaymentAttempt {
    status: PaymentAttemptStatus;
    amount: string;
    settled_at: string | null;
    invoice: { id: number; number: string };
}

/** The staff view of an attempt on the invoice page. */
export interface PaymentAttemptEntry {
    id: number;
    order_id: string;
    status: PaymentAttemptStatus;
    status_reason: string | null;
    amount: string;
    transaction_id: string | null;
    payment_type: string | null;
    payment_id: number | null;
    expires_at: string | null;
    settled_at: string | null;
    created_at: string | null;
}
