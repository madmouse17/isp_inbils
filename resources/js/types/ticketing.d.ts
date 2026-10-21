export interface Ticket {
    id: string;
    code: string;
    title: string;
    description?: string | null;
    source: string;
    category_id: string;
    status: string;
    priority: string;
    customer_id?: string | null;
    subscription_id?: string | null;
    network_asset_id?: string | null;
    location_id?: string | null;
    assigned_to?: string | null;
    updated_at?: string | null;
    can?: {
        start: boolean;
        close: boolean;
        resolve: boolean;
        spawn_spk: boolean;
        comment: boolean;
        /** Staff may bring this resolved or closed ticket back to work, with a reason. */
        reopen: boolean;
        /** The person may take this unassigned ticket for themselves ("Tugaskan ke saya"). */
        assign_self: boolean;
        evaluate: boolean;
    };
    spawned_spk_id?: string | null;
    sla_deadline?: string | null;
    response_deadline?: string | null;
    first_response_at?: string | null;
    first_response_source?: 'reply' | 'start' | null;
    resolved_at?: string | null;
    reopened_at?: string | null;
    closed_at?: string | null;
    resolution_note?: string | null;
    is_sla_breached: boolean;
    is_response_breached?: boolean;
    /** Show page only. */
    deadline_source?: TicketDeadlineSource;
    /** Show page only: what the customer rated the ticket in the app; null until they did. */
    customer_rating?: TicketCustomerRating | null;
    created_by: string;
    created_at?: string;
    category?: { id: string; name: string; code: string } | null;
    customer?: TicketCustomer | null;
    subscription?: { id: string; code: string } | null;
    network_asset?: { id: string; code: string; name: string } | null;
    location?: { id: string; name: string; path?: string } | null;
    assignee?: { id: string; name: string } | null;
    comments?: TicketCommentEntry[];
    attachments?: TicketAttachmentEntry[];
}

export interface TicketCommentEntry {
    id: string;
    author_id: string | null;
    body: string;
    is_internal: boolean;
    created_at: string;
    /** A name and an id: the page reads nothing more of an author, and the server sends nothing more. */
    author?: { id: string; name: string } | null;
}

export interface TicketAttachmentEntry {
    id: string;
    url: string;
    file_path: string;
    original_name?: string | null;
    mime_type?: string | null;
    size_bytes?: number | null;
    uploaded_by: string | null;
    /** What the uploader wrote with the file. */
    caption?: string | null;
    created_at: string;
    uploaded_at?: string;
}

/** The customer's rating of a finished ticket (1 to 5) and what they wrote with it. */
export interface TicketCustomerRating {
    value: number;
    comment: string | null;
    rated_at: string;
}

/** The answer of `admin.tickets.sla-preview`: what a ticket with this category, subscription and priority would be held to. */
export interface TicketSlaPreview {
    /** `null` when the category or tier sets no response target. */
    response_due: string | null;
    resolution_due: string;
    source: TicketDeadlineSource;
    calendar: '24h' | 'business';
}

/** Where the targets of a ticket come from: an SLA tier or a category (`TicketService::deadlineSource()`). */
export interface TicketDeadlineSource {
    type: 'tier' | 'category';
    name: string;
    /** The configured response target in hours, before priority urgent halves it; `null` when there is none. */
    response_hours: number | null;
    resolution_hours: number;
    /** Priority urgent: both targets count half of the configured hours, rounded up. */
    urgent_halved: boolean;
    /** What the hours are counted in: every hour, or only the open hours of the company (`business`, once it set them). */
    calendar: '24h' | 'business';
}

export interface TicketCustomerAddress {
    id: string;
    label: string;
    address: string;
    city?: string | null;
}

/**
 * The customer of a ticket as the page reads them. A list row carries the id, code and name; the ticket page adds the portal
 * account and the phone number. Holders of `customer.view` receive the whole customer record there, the addresses among it;
 * nobody else receives an address, an email, a tax id or notes.
 */
export interface TicketCustomer {
    id: string;
    /** The public id of the customer's app account; null when they have none. */
    user_id?: string | null;
    code: string;
    name: string;
    phone?: string | null;
    addresses?: TicketCustomerAddress[];
}
