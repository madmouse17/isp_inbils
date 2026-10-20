/** Inventory domain types for the admin product, category, unit and request pages. They mirror the resources in `Modules/Inventory/app/Http/Resources`. */

export type Id = string;

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
    id: string;
    inventory_request_id: string;
    product_id: string;
    product?: {
        id: string;
        name: string;
        code?: string | null;
        type?: string;
        unit?: string | null;
    } | null;
    source_location_id?: string | null;
    source_location?: {
        id: string;
        name: string;
    } | null;
    quantity_requested: number;
    quantity_approved?: number | null;
    quantity_issued: number;
    note?: string | null;
}

export interface InventoryRequestRow {
    id: string;
    code: string;
    status: string;
    needed_at?: string | null;
    reason: string;
    approval_note?: string | null;
    rejection_reason?: string | null;
    cancellation_reason?: string | null;
    warehouse_location_id?: string | null;
    warehouse_location?: {
        id: string;
        name: string;
        code?: string | null;
    } | null;
    requested_by: string;
    requester?: {
        id: string;
        name: string;
        email?: string | null;
    } | null;
    approved_by?: string | null;
    approver?: {
        id: string;
        name: string;
    } | null;
    prepared_by?: string | null;
    preparer?: {
        id: string;
        name: string;
    } | null;
    ready_by?: string | null;
    ready_user?: {
        id: string;
        name: string;
    } | null;
    issued_by?: string | null;
    issuer?: {
        id: string;
        name: string;
    } | null;
    source_type?: string | null;
    source_id?: string | null;
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
    id: string;
    company_id: string;
    inventory_request_id?: string | null;
    inventory_request?: {
        id: string;
        code: string;
        status: string;
    } | null;
    work_order_id?: string | null;
    work_order?: {
        id: string;
        code?: string;
        status?: string;
    } | null;
    warehouse_location?: {
        id: string;
        name: string;
        code?: string | null;
    } | null;
    technician_id?: string | null;
    technician?: {
        id: string;
        name: string;
        email?: string | null;
    } | null;
    status: string;
    declared_by?: string | null;
    declared_at?: string | null;
    received_by?: string | null;
    received_at?: string | null;
    reconciled_by?: string | null;
    reconciled_at?: string | null;
    rejected_by?: string | null;
    rejected_at?: string | null;
    rejection_reason?: string | null;
    resolved_by?: string | null;
    resolved_at?: string | null;
    resolution_note?: string | null;
    created_at: string;
    can?: {
        declare?: boolean;
        receive?: boolean;
        resolve?: boolean;
    };
    lines?: Array<{
        id: string;
        product?: {
            id: string;
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
        other_discrepancy_qty: number;
        /** What the technician said the difference was (`damaged`, `lost`, `other`); null when there is none. */
        discrepancy_code?: string | null;
        discrepancy_reason?: string | null;
    }>;
}
