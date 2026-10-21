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
} from './inventory';
export type {
    NetworkAsset as InventoryNetworkAsset,
    NetworkAssetStatus as InventoryNetworkAssetStatus,
} from './network-asset.d';
export type * from './billing.d';
export type * from './document.d';
export type * from './number-sequence.d';
export type * from './organization.d';
export type * from './reporting';
export type * from './spk.d';

export interface User {
    id: string;
    name: string;
    email: string;
    email_verified_at?: string;
    /** The company the user acts in now, not necessarily where they land at login. */
    company_id?: string | null;
    home_company_id?: string | null;
    is_active?: boolean;
    permissions?: string[];
}

/** The map view the company's maps open on, resolved server-side (company setting, system default, Indonesia). */
export interface CompanyMap {
    lat: number;
    lng: number;
    zoom: number;
    city_code: string | null;
    city_name: string | null;
}

export interface Company {
    id: string;
    name: string;
    code: string;
    logo?: string | null;
    address?: string | null;
    phone?: string | null;
    email?: string | null;
    website?: string | null;
    whatsapp?: string | null;
    support_hours?: string | null;
    brand_color?: string | null;
    currency: string;
    timezone: string;
    settings?: Record<string, unknown>;
    map?: CompanyMap;
    is_active?: boolean;
}

/** A company the user may switch into. `is_default` is the one they land in at login, `is_current` the one they act in now. */
export interface CompanyMembership {
    id: string;
    name: string;
    code: string;
    is_current: boolean;
    is_default: boolean;
}

/** Where the company the user acts in sits in the group, as the server shares it. */
export interface CompanyHierarchy {
    parent_company_id: string | null;
    /** The Head of the effective company; null for a Head or a standalone company. `can_switch`: the user may go there. */
    parent: { id: string; name: string; can_switch: boolean } | null;
    /** `report.consolidated.view` and the effective company is a Head with an active subsidiary. */
    can_consolidate: boolean;
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
    /** Hierarchy of the effective company; null when the user has none. */
    companyHierarchy?: CompanyHierarchy | null;
    /** The companies the user may switch into; empty unless `canSwitch`. */
    companies?: CompanyMembership[];
    /** True when the user may switch (`company.switch`, read in the company they are in now) and belongs to two or more companies, or is away from home and may go back. */
    canSwitch?: boolean;
    flash: Flash;
    workQueues?: {
        myWorkEligible?: boolean;
        approvalPending?: number | null;
        dispatchUnassigned?: number | null;
        myWorkPending?: number | null;
    };
    canMyWork?: boolean;
    /** Shared by the Reporting module: true when the user may open Technician Performance. */
    canTechnicianReport?: boolean;
    /** True when the user lacks `app.web` in the current company: they work in the Android app, or have no web access yet. */
    appOnly?: boolean;
    /** True for an admin without two-factor authentication while the company does not require it yet: the layout asks, and the admin may postpone. */
    twoFactorPrompt?: boolean;
    /** True only where the `admin.components` route exists (local/testing). */
    developerGalleryEnabled?: boolean;
    notifications?: {
        unreadCount?: number;
    };
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

/** Canonical entity id used across list/detail routes: the record's public ULID. */
export type Id = string;

/** What an approval moves, one product: its quantity in its own unit (null when the product has none). */
export interface ApprovalSummaryMaterial {
    product: string;
    quantity: number;
    unit: string | null;
}

export interface ApprovalInboxItem {
    id: string;
    domain: string;
    composite_id?: string;
    code: string;
    type: string;
    title: string;
    priority: string;
    status: string;
    requester: { id: string; name: string } | null;
    assigned_to: string | null;
    submitted_at: string | null;
    age_seconds: number | null;
    scheduled_date: string | null;
    summary: {
        label: string;
        count: number;
        /** One line per product. Never added together: 18 m of cable and 2 pcs of connectors are not 20 of anything. */
        materials: ApprovalSummaryMaterial[];
    };
    actions: {
        approve: boolean;
        reject: boolean;
        view: boolean;
    };
    detail_url: string | null;
}

export interface ApprovalInboxSummary {
    waiting_for_me: number;
    processed: number;
}

export interface DispatchItemRequester {
    id: string;
    name: string;
    code?: string;
}

export interface DispatchItemAssignee {
    id: string;
    name: string;
}

export interface DispatchItemSummary {
    category?: string | null;
    customer_name?: string | null;
    sla_deadline?: string | null;
    type?: string | null;
    scheduled_date?: string | null;
    items_count?: number;
}

export interface DispatchItemActions {
    assign: boolean;
    view: boolean;
}

export interface DispatchItem {
    id: string;
    domain: string;
    composite_id: string;
    code: string;
    type?: string;
    title: string;
    priority: string;
    status: string;
    requester: DispatchItemRequester | null;
    assigned_to: string | null;
    assignee: DispatchItemAssignee | null;
    submitted_at: string | null;
    /** SPK rows only: when the assigned technician set off (ISO-8601); the rows of tickets have no such key. */
    en_route_at?: string | null;
    age_seconds: number | null;
    due_at?: string | null;
    sla_breached?: boolean;
    scheduled_date?: string | null;
    updated_at: string | null;
    summary: DispatchItemSummary;
    actions: DispatchItemActions;
    detail_url: string | null;
    assign_url: string | null;
}

export interface DispatchSummary {
    unassigned: number;
    ticket_unassigned: number;
    spk_unassigned: number;
    assigned: number;
}

export interface MyWorkItemCustomer {
    id: string;
    name: string;
    code?: string;
}

export interface MyWorkItemSummary {
    category?: string | null;
    customer_name?: string | null;
    sla_deadline?: string | null;
    type?: string | null;
    scheduled_date?: string | null;
    items_count?: number;
}

export interface MyWorkItemActions {
    start?: boolean;
    resolve?: boolean;
    close?: boolean;
    submit?: boolean;
    view: boolean;
}

export interface MyWorkItem {
    id: string;
    domain: string;
    composite_id: string;
    code: string;
    type?: string;
    title: string;
    priority: string;
    status: string;
    customer: MyWorkItemCustomer | null;
    assigned_to: string;
    submitted_at: string | null;
    age_seconds: number | null;
    due_at?: string | null;
    sla_breached?: boolean;
    scheduled_date?: string | null;
    updated_at: string | null;
    summary: MyWorkItemSummary;
    actions: MyWorkItemActions;
    detail_url: string | null;
}

export interface MyWorkSummary {
    total: number;
    assigned: number;
    in_progress: number;
    waiting_action: number;
}

export interface QueueDomainOption {
    value: string;
    label: string;
}

export interface QueueUserOption {
    id: string;
    name: string;
    /** Open tickets plus active SPKs the user handles now; the handler pickers show it as "{n} pekerjaan aktif". */
    load: number;
}
