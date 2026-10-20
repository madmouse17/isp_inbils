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
    };
    spawned_spk_id?: string | null;
    sla_deadline?: string | null;
    first_response_at?: string | null;
    first_response_source?: 'reply' | 'start' | null;
    resolved_at?: string | null;
    reopened_at?: string | null;
    closed_at?: string | null;
    resolution_note?: string | null;
    is_sla_breached: boolean;
    created_by: string;
    created_at?: string;
    category?: { id: string; name: string; code: string } | null;
    customer?: { id: string; name: string; code: string } | null;
    subscription?: { id: string; code: string } | null;
    network_asset?: { id: string; code: string; name: string } | null;
    location?: { id: string; name: string; path?: string } | null;
    assignee?: { id: string; name: string } | null;
    comments?: TicketCommentEntry[];
    attachments?: TicketAttachmentEntry[];
}

export interface TicketCommentEntry {
    id: string;
    author_id: string;
    body: string;
    is_internal: boolean;
    created_at: string;
    author?: { id: string; name: string } | null;
}

export interface TicketAttachmentEntry {
    id: string;
    url: string;
    file_path: string;
    original_name?: string | null;
    mime_type?: string | null;
    size_bytes?: number | null;
    uploaded_by: string;
    created_at: string;
}
