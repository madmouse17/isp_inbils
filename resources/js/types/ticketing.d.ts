export interface Ticket {
    id: number; code: string; title: string; description?: string | null;
    source: string; category_id: number; status: string; priority: string;
    customer_id?: number | null; subscription_id?: number | null;
    network_asset_id?: number | null; location_id?: number | null;
    assigned_to?: number | null; spawned_spk_id?: number | null;
    sla_deadline?: string | null; first_response_at?: string | null;
    resolved_at?: string | null; closed_at?: string | null;
    resolution_note?: string | null; is_sla_breached: boolean;
    created_by: number; created_at?: string;
    category?: { id: number; name: string; code: string } | null;
    customer?: { id: number; name: string; code: string } | null;
    subscription?: { id: number; code: string } | null;
    network_asset?: { id: number; code: string; name: string } | null;
    location?: { id: number; name: string; path?: string } | null;
    assignee?: { id: number; name: string } | null;
    comments?: TicketCommentEntry[];
    attachments?: TicketAttachmentEntry[];
}

export interface TicketCommentEntry {
    id: number; author_id: number; body: string; is_internal: boolean; created_at: string;
    author?: { id: number; name: string } | null;
}

export interface TicketAttachmentEntry {
    id: number; file_path: string; original_name?: string | null;
    mime_type?: string | null; size_bytes?: number | null;
    uploaded_by: number; created_at: string;
}
