/* WhatsApp notifications: the props the customer pages and the company settings page receive.
 * Shapes follow .scratch/whatsapp-notifications/issues/02-backend-consent-and-company-settings.md.
 * No full phone number appears in any of them: messages carry the last four digits only. */

export type WhatsAppStatus = 'queued' | 'sent' | 'delivered' | 'read' | 'failed' | 'skipped';

/** `customer.data.whatsapp` on the customer show and edit pages. */
export interface CustomerWhatsApp {
    /** Consent given and not withdrawn. */
    opted_in: boolean;
    opted_in_at: string | null;
    opted_out_at: string | null;
    /** The phone on file is an Indonesian mobile number the channel can send to. */
    reachable: boolean;
}

/** One row of the `whatsappMessages` prop on the customer show page (latest 20, newest first). */
export interface WhatsAppMessageRow {
    id: string;
    event: string;
    subject_type: string | null;
    subject_id: string | null;
    status: WhatsAppStatus;
    skip_reason: string | null;
    error_code: string | null;
    recipient_last4: string | null;
    created_at: string;
    delivered_at: string | null;
    read_at: string | null;
    failed_at: string | null;
}

/** The `whatsapp` prop on the company settings page. `configured` is a boolean: no credential reaches the browser. */
export interface CompanyWhatsApp {
    configured: boolean;
    /** Messages of the current calendar month in the company timezone. */
    usage: { sent: number; delivered: number; failed: number; billable: number };
    events: { key: string; label: string; audience: 'customer' | 'technician' }[];
}
