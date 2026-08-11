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
