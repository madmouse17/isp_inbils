/** Inventory domain types for admin stock/product pages. */

export type Id = number | string;

export type Category = {
    id: Id;
    name?: string | null;
    code?: string | null;
    unit_id?: Id | null;
    description?: string | null;
    parent_id?: Id | null;
    is_active?: boolean | null;
    unit?: { id?: Id; name?: string | null; symbol?: string | null } | null;
    [key: string]: unknown;
};

export type Product = {
    id: Id;
    name?: string | null;
    sku?: string | null;
    description?: string | null;
    category_id?: Id | null;
    unit_id?: Id | null;
    category?: {
        id?: Id;
        name?: string | null;
        unit?: { name?: string | null; symbol?: string | null } | null;
    } | null;
    unit?: { id?: Id; name?: string | null; symbol?: string | null } | null;
    sell_price?: string | number | null;
    cost_price?: string | number | null;
    min_stock?: string | number | null;
    track_stock?: boolean | null;
    is_active?: boolean | null;
    stocks?: Stock[] | null;
    movements?: StockMovement[] | null;
    [key: string]: unknown;
};

export type Unit = {
    id: Id;
    name?: string | null;
    symbol?: string | null;
    [key: string]: unknown;
};

export type Stock = {
    id: Id;
    location?: { id?: Id; name?: string | null; path?: string | null } | null;
    quantity?: number | string | null;
    reserved_quantity?: number | string | null;
    available?: number | string | null;
    [key: string]: unknown;
};

export type StockMovement = {
    id: Id;
    type?: string | null;
    quantity?: number | string | null;
    qty?: number | string | null;
    reference?: string | null;
    notes?: string | null;
    created_at?: string | null;
    [key: string]: unknown;
};

export interface InventoryProduct {
    id: Id;
    sku?: string | null;
    name: string;
    category_id?: Id | null;
    unit_id?: Id | null;
    min_stock?: number | null;
    status?: string | null;
}

export interface InventoryCategory {
    id: Id;
    name: string;
    unit_id?: Id | null;
}

export interface InventoryUnit {
    id: Id;
    name: string;
    symbol?: string | null;
}

export interface StockRow {
    id: Id;
    product_id?: Id | null;
    product?: InventoryProduct | null;
    location_id?: Id | null;
    location_name?: string | null;
    qty?: number | null;
    quantity?: number | null;
    min_stock?: number | null;
    updated_at?: string | null;
}

export interface MovementRow {
    id: Id;
    product_id?: Id | null;
    product?: InventoryProduct | null;
    type?: string | null;
    qty?: number | null;
    quantity?: number | null;
    reference?: string | null;
    notes?: string | null;
    created_at?: string | null;
    status?: string | null;
}

export type Movement = MovementRow;

export interface InventoryRequestItem {
    id: number;
    inventory_request_id: number;
    product_id: number;
    product?: {
        id: number;
        name: string;
        code?: string | null;
        type?: string;
        unit?: string | null;
    } | null;
    source_location_id?: number | null;
    source_location?: {
        id: number;
        name: string;
    } | null;
    quantity_requested: number;
    quantity_approved?: number | null;
    quantity_issued: number;
    note?: string | null;
}

export interface InventoryRequestRow {
    id: number;
    code: string;
    status: string;
    status_label: string;
    needed_at?: string | null;
    reason: string;
    approval_note?: string | null;
    rejection_reason?: string | null;
    cancellation_reason?: string | null;
    warehouse_location_id?: number | null;
    warehouse_location?: {
        id: number;
        name: string;
        code?: string | null;
    } | null;
    requested_by: number;
    requester?: {
        id: number;
        name: string;
        email?: string | null;
    } | null;
    approved_by?: number | null;
    approver?: {
        id: number;
        name: string;
    } | null;
    prepared_by?: number | null;
    preparer?: {
        id: number;
        name: string;
    } | null;
    ready_by?: number | null;
    ready_user?: {
        id: number;
        name: string;
    } | null;
    issued_by?: number | null;
    issuer?: {
        id: number;
        name: string;
    } | null;
    source_type?: string | null;
    source_id?: number | null;
    submitted_at?: string | null;
    approved_at?: string | null;
    prepared_at?: string | null;
    ready_at?: string | null;
    issued_at?: string | null;
    created_at: string;
    items?: InventoryRequestItem[];
    can?: {
        view?: boolean;
        update?: boolean;
        submit?: boolean;
        cancel?: boolean;
        approve?: boolean;
        reject?: boolean;
        prepare?: boolean;
        ready?: boolean;
        issue?: boolean;
    };
}

export interface MaterialReturnCaseRow {
    id: number;
    company_id: number;
    inventory_request_id?: number | null;
    inventory_request?: {
        id: number;
        code: string;
        status: string;
    } | null;
    work_order_id?: number | null;
    work_order?: {
        id: number;
        code?: string;
        status?: string;
    } | null;
    warehouse_location?: {
        id: number;
        name: string;
        code?: string | null;
    } | null;
    technician_id?: number | null;
    technician?: {
        id: number;
        name: string;
        email?: string | null;
    } | null;
    status: string;
    declared_by?: number | null;
    declared_at?: string | null;
    received_by?: number | null;
    received_at?: string | null;
    reconciled_by?: number | null;
    reconciled_at?: string | null;
    rejected_by?: number | null;
    rejected_at?: string | null;
    rejection_reason?: string | null;
    resolved_by?: number | null;
    resolved_at?: string | null;
    resolution_note?: string | null;
    created_at: string;
    can?: {
        declare?: boolean;
        receive?: boolean;
        resolve?: boolean;
    };
    lines?: Array<{
        id: number;
        product?: {
            id: number;
            name: string;
            sku?: string;
            unit?: string | null;
        } | null;
        issued_qty: number;
        actual_used_qty: number;
        declared_good_return_qty: number;
        declared_discrepancy_qty: number;
        accepted_good_return_qty: number;
        damaged_return_qty: number;
        lost_qty: number;
    }>;
}
