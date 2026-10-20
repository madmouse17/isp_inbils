import type {
    NETWORK_ASSET_OWNERSHIP,
    NETWORK_ASSET_STATUS,
    NETWORK_ASSET_TYPE,
    NETWORK_DESIRED_STATE,
    NETWORK_SERVICE_TYPE,
    NETWORK_SYNC_STATUS,
} from '@/lib/statuses/network';

/* The value sets come from the status maps (`lib/statuses/network.ts`), so a new value is added in one place. */
export type NetworkAssetStatus = keyof typeof NETWORK_ASSET_STATUS;
export type NetworkAssetType = keyof typeof NETWORK_ASSET_TYPE;
export type NetworkAssetOwnership = keyof typeof NETWORK_ASSET_OWNERSHIP;

export interface NetworkAssetInstallation {
    id: string;
    location_id?: string | null;
    customer_id?: string | null;
    subscription_id?: string | null;
    spk_id?: string | null;
    installed_by?: string | null;
    installed_at?: string | null;
    removed_at?: string | null;
    removal_reason?: string | null;
    location?: { id: string; code: string; name: string; path: string } | null;
}

export interface NetworkAsset {
    id: string;
    code: string;
    name: string;
    asset_type: NetworkAssetType;
    serial_number?: string | null;
    mac_address?: string | null;
    ip_address?: string | null;
    management_ip?: string | null;
    location_id?: string | null;
    customer_id?: string | null;
    subscription_id?: string | null;
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
    location?: { id: string; code: string; name: string; path: string } | null;
    customer?: { id: string; code: string; name: string } | null;
    subscription?: { id: string; code: string } | null;
    installations?: NetworkAssetInstallation[];
}

/* RADIUS NAS clients and network accounts. Shapes follow the props contract in
 * .scratch/freeradius/spec.md. No type here can hold a RADIUS shared secret or a subscriber secret. */

export type NetworkServiceType = keyof typeof NETWORK_SERVICE_TYPE;
export type NetworkSyncStatus = keyof typeof NETWORK_SYNC_STATUS;
export type NetworkDesiredState = keyof typeof NETWORK_DESIRED_STATE;

export interface NasDevice {
    id: string;
    /** Null only when the router asset itself was removed. */
    network_asset: {
        id: string;
        code: string;
        name: string;
        management_ip: string | null;
        location: { id: string; name: string; type: string } | null;
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
    subscription: { id: string; code: string } | null;
}

export interface NetworkAccount {
    id: string;
    service_type: NetworkServiceType;
    /** Full RADIUS username, `local@realm`. */
    username: string;
    sync_status: NetworkSyncStatus;
    last_error: string | null;
    provisioned_group: string | null;
    provisioned_at: string | null;
    /** A Disconnect for the open session is owed: the router has not confirmed it. */
    disconnect_pending: boolean;
    /** ISO moment the Disconnect first became owed; a retry keeps it. Null once the router confirmed. */
    pending_disconnect_at: string | null;
}

/** The router asset a NAS router is registered on (Edit shows it; Create searches `routerAssetOptions`). */
export interface RouterAssetOption {
    id: string;
    code: string;
    name: string;
    management_ip: string | null;
    location_name: string | null;
}

export interface NetworkAccountSubscription {
    id: string;
    code: string;
    status: string;
    blocked_at: string | null;
    customer: { id: string; name: string; code: string } | null;
    speed_profile: { name: string; radius_profile_name: string | null } | null;
    serving_pop: { id: string; name: string } | null;
}
