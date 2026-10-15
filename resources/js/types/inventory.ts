/** Inventory domain types for the admin product, category, unit and request pages. They mirror the resources in `Modules/Inventory/app/Http/Resources`. */

export type Id = number | string;

export interface Unit {
    id: Id;
    name: string;
    symbol: string;
}

export interface Category {
    id: Id;
    parent_id: Id | null;
    unit_id: Id | null;
    name: string;
    code: string;
    description: string | null;
    is_active: boolean;
    unit?: Unit | null;
    children_count?: number;
}

export interface Stock {
    id: Id;
    product_id: Id;
    location_id: Id;
    quantity: number | string;
    reserved_quantity: number | string;
    available: number | string;
    location?: { id: Id; name: string; code?: string | null; path?: string | null } | null;
}

export interface StockMovement {
    id: Id;
    movement_type: string;
    quantity: number | string;
    balance_after: number | string;
    note: string | null;
    created_at: string | null;
}

export interface Product {
    id: Id;
    sku: string;
    name: string;
    description: string | null;
    category_id: Id | null;
    unit_id: Id | null;
    type: string;
    track_stock: boolean;
    sell_price: string | number | null;
    cost_price: string | number | null;
    min_stock: string | number;
    is_active: boolean;
    category?: Category | null;
    unit?: Unit | null;
    stocks?: Stock[];
    movements?: StockMovement[];
}

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
