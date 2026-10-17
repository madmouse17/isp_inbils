/** Online payment attempt contracts, mirroring PaymentAttemptResource. */
import type { PAYMENT_ATTEMPT_STATUS } from '@/lib/statuses';

export type PaymentAttemptStatus = keyof typeof PAYMENT_ATTEMPT_STATUS;

/** The customer's view of an attempt: never the token, the page URL or the internal reason. */
export interface PortalPaymentAttempt {
    status: PaymentAttemptStatus;
    amount: string;
    settled_at: string | null;
    invoice: { id: string; number: string };
}

/** The staff view of an attempt on the invoice page. */
export interface PaymentAttemptEntry {
    id: string;
    order_id: string;
    status: PaymentAttemptStatus;
    status_reason: string | null;
    amount: string;
    transaction_id: string | null;
    payment_type: string | null;
    payment_id: string | null;
    expires_at: string | null;
    settled_at: string | null;
    created_at: string | null;
}
