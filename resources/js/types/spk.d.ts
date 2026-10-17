export interface WorkOrder {
    id: string;
    code: string;
    type: string;
    title: string;
    description?: string | null;
    status: string;
    customer_id?: string | null;
    subscription_id?: string | null;
    location_id?: string | null;
    assigned_to?: string | null;
    updated_at?: string | null;
    ticket_id?: string | null;
    source: string;
    priority: string;
    scheduled_date?: string | null;
    started_at?: string | null;
    completed_at?: string | null;
    result?: string | null;
    rejection_reason?: string | null;
    created_by: string;
    created_at?: string;
    customer?: { id: string; name: string; code: string } | null;
    subscription?: { id: string; code: string } | null;
    location?: { id: string; name: string; path?: string } | null;
    assignee?: { id: string; name: string } | null;
    items?: WorkOrderItem[];
    assignments?: WorkOrderAssignment[];
    evidence?: WorkOrderEvidence[];
}

export interface WorkOrderItem {
    id: string;
    work_order_id: string;
    product_id: string;
    quantity_reserved: string;
    quantity_used: string;
    note?: string | null;
    product?: { id: string; sku: string; name: string } | null;
}

export interface WorkOrderAssignment {
    id: string;
    technician_id: string;
    assigned_by: string;
    assigned_at: string;
    unassigned_at?: string | null;
    reason?: string | null;
    technician?: { id: string; name: string } | null;
}

export interface WorkOrderEvidence {
    id: string;
    type: string;
    url: string;
    file_path: string;
    original_name?: string | null;
    mime_type?: string | null;
    size_bytes?: number | null;
    caption?: string | null;
    uploaded_by: string;
    uploaded_at: string;
}
