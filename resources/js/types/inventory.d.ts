export interface Category {
    id: number;
    parent_id?: number | null;
    unit_id?: number | null;
    name: string;
    code: string;
    description?: string | null;
    is_active: boolean;
    unit?: Unit | null;
    children_count?: number;
}

export interface Unit {
    id: number;
    name: string;
    symbol: string;
}

export interface Product {
    id: number;
    sku: string;
    name: string;
    description?: string | null;
    category_id: number;
    unit_id: number;
    type?: string | null;
    track_stock: boolean;
    sell_price?: string | null;
    cost_price?: string | null;
    min_stock?: string | number | null;
    is_active: boolean;
    category?: Category | null;
    unit?: Unit | null;
    stocks?: Stock[];
    movements?: StockMovement[];
}

export interface Stock {
    id: number;
    product_id: number;
    location_id: number;
    quantity: string | number;
    reserved_quantity: string | number;
    available: string | number;
    location?: { id: number; code: string; name: string; path: string } | null;
}

export interface StockMovement {
    id: number;
    product_id: number;
    from_location_id?: number | null;
    to_location_id?: number | null;
    movement_type: string;
    quantity: string | number;
    balance_after?: string | number | null;
    reserved_after?: string | number | null;
    reference_type?: string | null;
    reference_id?: number | null;
    note?: string | null;
    created_by?: number | null;
    created_at?: string;
    product?: Product | null;
}

export interface FindResult {
    id: number;
    sku: string;
    name: string;
    stocks: {
        location_name: string;
        location_path: string;
        quantity: string | number;
        reserved: string | number;
        available: string | number;
    }[];
}
