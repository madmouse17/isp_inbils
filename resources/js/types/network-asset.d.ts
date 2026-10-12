export type NetworkAssetStatus = 'available' | 'installed' | 'maintenance' | 'damaged' | 'retired';
export type NetworkAssetType =
    | 'router'
    | 'switch'
    | 'olt'
    | 'onu_ont'
    | 'radio'
    | 'antenna'
    | 'fiber'
    | 'odp'
    | 'odc'
    | 'rack'
    | 'power'
    | 'other';
export type NetworkAssetOwnership = 'owned' | 'leased' | 'customer_provided';

export interface NetworkAssetInstallation {
    id: number;
    location_id?: number | null;
    customer_id?: number | null;
    subscription_id?: number | null;
    spk_id?: number | null;
    installed_by?: number | null;
    installed_at?: string | null;
    removed_at?: string | null;
    removal_reason?: string | null;
    location?: { id: number; code: string; name: string; path: string } | null;
}

export interface NetworkAsset {
    id: number;
    code: string;
    name: string;
    asset_type: NetworkAssetType;
    serial_number?: string | null;
    mac_address?: string | null;
    ip_address?: string | null;
    management_ip?: string | null;
    location_id?: number | null;
    customer_id?: number | null;
    subscription_id?: number | null;
    status: NetworkAssetStatus;
    ownership?: NetworkAssetOwnership | null;
    vendor?: string | null;
    model?: string | null;
    purchase_date?: string | null;
    purchase_price?: string | null;
    warranty_expiry?: string | null;
    notes?: string | null;
    installed_at?: string | null;
    retired_at?: string | null;
    location?: { id: number; code: string; name: string; path: string } | null;
    customer?: { id: number; code: string; name: string } | null;
    subscription?: { id: number; code: string } | null;
    installations?: NetworkAssetInstallation[];
}

/* RADIUS NAS clients and network accounts. Shapes follow the props contract in
 * .scratch/freeradius/spec.md. No type here can hold a RADIUS shared secret or a subscriber secret. */

export type NetworkServiceType = 'pppoe' | 'hotspot';
export type NetworkSyncStatus = 'pending' | 'synced' | 'failed';
export type NetworkDesiredState = 'absent' | 'enabled' | 'isolated';

export interface NasDevice {
    id: number;
    /** Null only when the router asset itself was removed. */
    network_asset: {
        id: number;
        code: string;
        name: string;
        management_ip: string | null;
        location: { id: number; name: string; type: string } | null;
    } | null;
    /** Source address FreeRADIUS sees (`nas.nasname`). */
    nasname: string;
    /** The company realm, written to `nas.shortname`. */
    shortname: string;
    coa_port: number;
    has_radius_secret: boolean;
    radius_synced_at: string | null;
    last_error: string | null;
}

export interface RadiusSession {
    session_id: string;
    nas_ip: string;
    nas_label: string | null;
    framed_ip: string | null;
    started_at: string;
    updated_at: string | null;
}

/** An open session on the NAS router page, with the account it belongs to when Inbils knows it. */
export interface NasDeviceSession extends RadiusSession {
    username: string;
    subscription: { id: number; code: string } | null;
}

export interface NetworkAccount {
    id: number;
    service_type: NetworkServiceType;
    /** Full RADIUS username, `local@realm`. */
    username: string;
    sync_status: NetworkSyncStatus;
    last_error: string | null;
    provisioned_group: string | null;
    provisioned_at: string | null;
    disconnect_pending: boolean;
}

export interface RouterAssetOption {
    id: number;
    code: string;
    name: string;
    management_ip: string | null;
    location_name: string | null;
}

export interface NetworkAccountSubscription {
    id: number;
    code: string;
    status: string;
    blocked_at: string | null;
    customer: { id: number; name: string } | null;
    speed_profile: { name: string; radius_profile_name: string | null } | null;
    serving_pop: { id: number; name: string } | null;
}
