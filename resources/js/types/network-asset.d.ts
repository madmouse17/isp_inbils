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
