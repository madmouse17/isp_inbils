export interface WorkOrder {
    id: number;
    code: string;
    type: string;
    title: string;
    description?: string | null;
    status: string;
    customer_id?: number | null;
    subscription_id?: number | null;
    location_id?: number | null;
    assigned_to?: number | null;
    ticket_id?: number | null;
    source: string;
    priority: string;
    scheduled_date?: string | null;
    started_at?: string | null;
    completed_at?: string | null;
    result?: string | null;
    rejection_reason?: string | null;
    created_by: number;
    created_at?: string;
    customer?: { id: number; name: string; code: string } | null;
    subscription?: { id: number; code: string } | null;
    location?: { id: number; name: string; path?: string } | null;
    assignee?: { id: number; name: string } | null;
    items?: WorkOrderItem[];
    assignments?: WorkOrderAssignment[];
    evidence?: WorkOrderEvidence[];
}

export interface WorkOrderItem {
    id: number;
    work_order_id: number;
    product_id: number;
    quantity_reserved: string;
    quantity_used: string;
    note?: string | null;
    product?: { id: number; sku: string; name: string } | null;
}

export interface WorkOrderAssignment {
    id: number;
    technician_id: number;
    assigned_by: number;
    assigned_at: string;
    unassigned_at?: string | null;
    reason?: string | null;
    technician?: { id: number; name: string } | null;
}

export interface WorkOrderEvidence {
    id: number;
    type: string;
    file_path: string;
    original_name?: string | null;
    mime_type?: string | null;
    size_bytes?: number | null;
    caption?: string | null;
    uploaded_by: number;
    uploaded_at: string;
}
