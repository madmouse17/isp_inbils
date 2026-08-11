export type {
    CustomerAddress,
    CustomerContact,
    ServiceSubscription,
    Customer,
    Brand,
    Warehouse,
    Location,
    Ticket,
    NetworkAsset,
    ServicePackage,
    SLATier,
    SpeedProfile,
    BandwidthProfile,
    SPK,
    Paginated as DomainPaginated,
    TicketPriority,
    TicketStatus,
    InvoiceStatus,
    SpkStatus,
    NetworkAssetStatus,
    ServicePackageStatus,
} from './models';
export type {
    Category as InventoryCategory,
    Product as InventoryProduct,
    Unit as InventoryUnit,
    Stock as InventoryStock,
    StockMovement as InventoryStockMovement,
    StockRow,
    MovementRow,
    Movement,
} from './inventory';
export type {
    NetworkAsset as InventoryNetworkAsset,
    NetworkAssetStatus as InventoryNetworkAssetStatus,
} from './network-asset.d';
export type * from './billing.d';
export type * from './document.d';
export type * from './number-sequence.d';
export type * from './organization.d';
export type * from './spk.d';

export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
    company_id?: number | null;
    is_active?: boolean;
    roles?: string[];
    permissions?: string[];
}

export interface Company {
    id: number;
    name: string;
    code: string;
    logo?: string | null;
    address?: string | null;
    phone?: string | null;
    email?: string | null;
    website?: string | null;
    currency: string;
    timezone: string;
    settings?: Record<string, unknown>;
    is_active?: boolean;
}

export interface Flash {
    success?: string | null;
    error?: string | null;
    warning?: string | null;
}

export type PageProps<T extends Record<string, unknown> = Record<string, unknown>> = T & {
    auth: {
        user: User | null;
    };
    company: Company | null;
    flash: Flash;
    app: {
        name: string;
        locale: string;
    };
};

export interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    path?: string;
}

export interface Paginated<T> {
    data: T[];
    meta: PaginationMeta;
    links?: {
        first?: string | null;
        last?: string | null;
        prev?: string | null;
        next?: string | null;
    };
}

/** Canonical entity id used across list/detail routes. */
export type Id = number | string;
