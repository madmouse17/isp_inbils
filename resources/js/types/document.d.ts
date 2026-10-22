export interface DocumentType {
    id: string;
    name: string;
    code: string;
    applies_to?: string | null;
    is_required: boolean;
    expiry_days?: number | null;
    is_active: boolean;
}

export interface MediaItem {
    id: string;
    file_name: string;
    mime_type?: string | null;
    size: number;
    collection: string;
    document_type?: string | null;
    document_type_name?: string | null;
    uploaded_by?: string | null;
    expires_at?: string | null;
    expiry?: 'expiring' | 'expired' | null;
    url?: string;
    created_at?: string;
}
