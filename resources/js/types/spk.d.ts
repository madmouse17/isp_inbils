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

/**
 * What is left to do on a completed installation SPK (`SpkApprovalImpact::nextSteps`). Each part only reaches the people
 * who may see it: without `billing.view` the invoice is not sent and `can_view_invoice` is false.
 */
export interface SpkNextSteps {
    /** The invoice the approval left (a live one first, else the cancelled one). */
    invoice: {
        id: string;
        number: string;
        status: string;
        total: string;
        due_date: string | null;
    } | null;
    can_view_invoice: boolean;
    /** No invoice yet and the SPK bills something (the fee or the materials): one can still be made. */
    billable: boolean;
    can_send_invoice: boolean;
    can_create_invoice: boolean;
    /** The subscription's PPPoE account. */
    network_account: { username: string } | null;
    /** The viewer may open network accounts, and the company serves RADIUS or the account exists: else the row is no step. */
    can_view_network_account: boolean;
    can_manage_network_account: boolean;
}
