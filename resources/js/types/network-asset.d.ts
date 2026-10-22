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

/**
 * What the RADIUS server knows of a router's traffic, set by the router page only (a list leaves it null):
 * `not_synced` no client row written yet, `awaiting_restart` written but the router has sent nothing since (a FreeRADIUS
 * that has not been restarted), `live` a packet newer than the write arrived.
 */
export type NasRadiusState = 'not_synced' | 'awaiting_restart' | 'live';

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
    /** Null while RADIUS was not asked (lists), is off, or did not answer. */
    radius_state: NasRadiusState | null;
    /** ISO moment of the newest accounting packet RADIUS holds for this router; null when it never sent one. */
    last_accounting_at: string | null;
}

export interface RadiusSession {
    session_id: string;
    nas_ip: string;
    nas_label: string | null;
    framed_ip: string | null;
    /** ISO moment on the application's clock (RADIUS writes its own zone; the server converts). */
    started_at: string;
    /** ISO moment of the last accounting packet of the session. */
    last_seen_at: string;
    /** The last packet is older than 15 minutes: a router that rebooted never sends the Stop. */
    stale: boolean;
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
    /** ISO moment the router last confirmed the Disconnect of a session; stays after the flag above clears. */
    last_disconnect_at: string | null;
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
