/** Shared domain models for residual admin UI. */

type Id = number | string;

export type CustomerAddress = {
    id: Id;
    label?: string | null;
    address?: string | null;
    city?: string | null;
    postal_code?: string | null;
    notes?: string | null;
    is_installation_point?: boolean | null;
    is_primary?: boolean | null;
    [key: string]: unknown;
};

export type CustomerContact = {
    id: Id;
    name?: string | null;
    position?: string | null;
    phone?: string | null;
    email?: string | null;
    notes?: string | null;
    is_primary?: boolean | null;
    [key: string]: unknown;
};

export type ServiceSubscription = {
    id: Id;
    code?: string | null;
    status?: string | null;
    customer_id?: Id | null;
    service_package_id?: Id | null;
    installation_address_id?: Id | null;
    billing_day?: number | string | null;
    mrc_amount?: number | string | null;
    otc_installation_fee?: number | string | null;
    contract_months?: number | null;
    package?: { name?: string | null } | null;
    customer?: { id?: Id; name?: string | null } | null;
    installation_address?: { label?: string | null } | null;
    serving_pop?: { name?: string | null } | null;
    notes?: string | null;
    activation_date?: string | null;
    expiration_date?: string | null;
    next_invoice_date?: string | null;
    terminated_at?: string | null;
    terminated_reason?: string | null;
    [key: string]: unknown;
};

export type Customer = {
    id: Id;
    name?: string | null;
    email?: string | null;
    phone?: string | null;
    code?: string | null;
    status?: string | null;
    addresses?: CustomerAddress[];
    contacts?: CustomerContact[];
    subscriptions?: ServiceSubscription[];
    type?: string | null;
    tax_id?: string | null;
    contact_person?: string | null;
    notes?: string | null;
    is_active?: boolean | null;
    [key: string]: unknown;
};

export type Category = {
    id: Id;
    name?: string | null;
    code?: string | null;
    unit_id?: Id | null;
    [key: string]: unknown;
};

export type Brand = {
    id: Id;
    name?: string | null;
    [key: string]: unknown;
};

export type Unit = {
    id: Id;
    name?: string | null;
    symbol?: string | null;
    [key: string]: unknown;
};

export type Warehouse = {
    id: Id;
    name?: string | null;
    code?: string | null;
    [key: string]: unknown;
};

export type Location = {
    id: Id;
    name?: string | null;
    code?: string | null;
    type?: string | null;
    parent_id?: Id | null;
    [key: string]: unknown;
};

export type Product = {
    id: Id;
    name?: string | null;
    sku?: string | null;
    code?: string | null;
    unit?: string | Unit | null;
    unit_id?: Id | null;
    category_id?: Id | null;
    brand_id?: Id | null;
    stock?: number | string | null;
    qty?: number | string | null;
    sell_price?: number | string | null;
    cost_price?: number | string | null;
    is_active?: boolean | null;
    [key: string]: unknown;
};

export type Ticket = {
    id: Id;
    number?: string | null;
    subject?: string | null;
    title?: string | null;
    status?: string | null;
    priority?: string | null;
    category?: string | null;
    customer?: Customer | string | null;
    assignee?: { id?: Id; name?: string | null } | string | null;
    created_at?: string | null;
    updated_at?: string | null;
    [key: string]: unknown;
};

export type Stock = {
    id: Id;
    product?: Product | string | null;
    product_id?: Id | null;
    warehouse?: Warehouse | string | null;
    location_id?: Id | null;
    location_name?: string | null;
    quantity?: number | string | null;
    qty?: number | string | null;
    reserved?: number | string | null;
    available?: number | string | null;
    min_stock?: number | null;
    updated_at?: string | null;
    [key: string]: unknown;
};

export type StockMovement = {
    id: Id;
    type?: string | null;
    product?: Product | string | null;
    product_id?: Id | null;
    quantity?: number | string | null;
    qty?: number | string | null;
    reference?: string | null;
    notes?: string | null;
    created_at?: string | null;
    status?: string | null;
    [key: string]: unknown;
};

export type NetworkAsset = {
    id: Id;
    name?: string | null;
    code?: string | null;
    type?: string | null;
    status?: string | null;
    location?: string | Location | null;
    ip_address?: string | null;
    [key: string]: unknown;
};

export type ServicePackage = {
    id: Id;
    name?: string | null;
    code?: string | null;
    price?: number | string | null;
    status?: string | null;
    bandwidth_profile?: string | null;
    speed_profile?: string | null;
    [key: string]: unknown;
};

export type SLATier = {
    id: Id;
    name?: string | null;
    code?: string | null;
    response_hours?: number | string | null;
    resolve_hours?: number | string | null;
    status?: string | null;
    [key: string]: unknown;
};

export type SpeedProfile = {
    id: Id;
    name?: string | null;
    code?: string | null;
    download?: number | string | null;
    upload?: number | string | null;
    status?: string | null;
    [key: string]: unknown;
};

export type BandwidthProfile = {
    id: Id;
    name?: string | null;
    code?: string | null;
    download?: number | string | null;
    upload?: number | string | null;
    status?: string | null;
    [key: string]: unknown;
};

export type SPK = {
    id: Id;
    number?: string | null;
    spk_number?: string | null;
    status?: string | null;
    customer?: Customer | string | null;
    scheduled_at?: string | null;
    created_at?: string | null;
    [key: string]: unknown;
};

export interface Paginated<T> {
    data: T[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from: number | null;
        to: number | null;
        path?: string;
    };
    links?: {
        first?: string | null;
        last?: string | null;
        prev?: string | null;
        next?: string | null;
    };
}

export type TicketPriority = 'low' | 'normal' | 'high' | 'urgent';
export type TicketStatus = 'open' | 'in_progress' | 'resolved' | 'closed';
export type InvoiceStatus = 'draft' | 'sent' | 'paid' | 'overdue' | 'cancelled';
export type SpkStatus = 'draft' | 'open' | 'closed' | 'cancelled';
export type NetworkAssetStatus = 'active' | 'inactive' | 'maintenance';
export type ServicePackageStatus = 'active' | 'inactive';
