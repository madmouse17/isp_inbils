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

/* NAS routers and network accounts (MikroTik provisioning). Shapes follow the props contract in
 * .scratch/mikrotik-radius/spec.md. No type here can hold a router or subscriber password. */

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
    api_port: number;
    api_username: string;
    has_api_password: boolean;
    isolir_profile: string;
    /** The per-router write flag: off = probes and reads only. */
    provisioning_enabled: boolean;
    routeros_version: string | null;
    last_checked_at: string | null;
    last_seen_at: string | null;
    unreachable_since: string | null;
    last_error: string | null;
    unmanaged_count: number;
    accounts_count: number;
    accounts_failed_count: number;
}

export interface NetworkAccount {
    id: number;
    nas_device_id: number;
    nas_label: string;
    service_type: NetworkServiceType;
    username: string;
    sync_status: NetworkSyncStatus;
    last_error: string | null;
    provisioned_profile: string | null;
    provisioned_at: string | null;
    online: boolean;
    last_online_at: string | null;
    current_address: string | null;
}

/** A network account row on the NAS router page (the resource adds its subscription). */
export interface NasDeviceAccount extends NetworkAccount {
    subscription: { id: number; code: string; customer_name: string | null };
}

export interface RouterAssetOption {
    id: number;
    code: string;
    name: string;
    management_ip: string | null;
    location_name: string | null;
}

/** `suggested`: the router at the subscription's serving POP. */
export interface NasOption {
    id: number;
    label: string;
    suggested: boolean;
}

export interface NetworkAccountSubscription {
    id: number;
    code: string;
    status: string;
    customer: { id: number; name: string } | null;
    speed_profile: { name: string; radius_profile_name: string | null } | null;
    serving_pop: { id: number; name: string } | null;
}
