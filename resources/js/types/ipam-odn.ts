/** Props of the IPAM (Modules/Ipam) and ODN (Modules/NetworkAsset) pages; the controllers in those modules are the source of truth. */

export type IpFamily = 4 | 6;

export type IpUsage = 'container' | 'customer_static' | 'customer_dynamic' | 'infrastructure';

export type IpAddressStatus = 'assigned' | 'reserved';

/** A prefix as IpPrefixUtilisationQuery formats it. `size` and `free` are exact decimal strings (IPv6 sizes overflow a JS number). */
export interface PrefixRow {
    id: number;
    parent_id: number | null;
    depth: number;
    cidr: string;
    family: IpFamily;
    usage: IpUsage;
    location: { id: number; code: string } | null;
    vlan: { id: number; vid: number; name: string } | null;
    description: string | null;
    size: string;
    usable: string | null;
    assigned: number;
    reserved: number;
    free: string | null;
    /** Share used (address pools) or covered by child prefixes (containers); null where it is not measured. */
    percent: number | null;
}

export interface PrefixDetail extends PrefixRow {
    parent: { id: number; cidr: string } | null;
}

export interface PopOption {
    id: number;
    code: string;
    name: string;
}

export interface VlanOption {
    id: number;
    vid: number;
    name: string;
    location_code: string | null;
}

export interface IpAddressHolder {
    type: 'subscription' | 'asset';
    id: number;
    label: string;
    href: string;
}

export interface IpAddressRow {
    id: number;
    address: string;
    status: IpAddressStatus;
    /** Null for a reservation. */
    holder: IpAddressHolder | null;
    note: string | null;
    assigned_at: string | null;
}

export interface VlanRow {
    id: number;
    vid: number;
    name: string;
    location: { id: number; code: string } | null;
    description: string | null;
    prefixes_count: number;
}

/** What the subscription page lists under "IP address". */
export interface SubscriptionIpAddress {
    id: number;
    family: IpFamily;
    address: string;
    prefix_cidr: string | null;
    status: IpAddressStatus;
}

/** A customer static pool a subscription can draw from; `free` is null for IPv6, where the address is typed. */
export interface StaticPool {
    id: number;
    cidr: string;
    family: IpFamily;
    free: string | null;
}

export type OdnAssetType = 'olt' | 'odc' | 'odp';

export interface OdpRow {
    asset_id: number;
    code: string;
    name: string;
    location_path: string | null;
    capacity: number;
    used: number;
    free: number;
    percent: number;
}

export interface PonPortSummary {
    port_id: number;
    olt_asset_id: number;
    olt_code: string;
    number: number;
    label: string | null;
    subscribers: number;
}

export interface UnmappedSubscription {
    subscription_id: number;
    code: string;
    customer_name: string | null;
    serving_pop_code: string | null;
    status: string;
}

export interface OdnAsset {
    id: number;
    code: string;
    name: string;
    asset_type: OdnAssetType;
    location_path: string | null;
}

export interface PonPort {
    id: number;
    number: number;
    label: string | null;
    splitter_id: number | null;
    subscribers: number;
}

export interface SplitterPort {
    id: number;
    number: number;
    label: string | null;
    subscription: { id: number; code: string; customer_name: string | null } | null;
    feeds_splitter_id: number | null;
}

export interface Splitter {
    id: number;
    label: string | null;
    outputs: number;
    input: { port_id: number; label: string } | null;
    ports: SplitterPort[];
}

/** A free upstream port a splitter input can be connected to. */
export interface InputOption {
    port_id: number;
    label: string;
}

/** The ODP output port a subscription's drop sits on. */
export interface DropPort {
    port_id: number;
    number: number;
    odp_asset_id: number;
    odp_code: string;
    odp_name: string;
    location_path: string | null;
}

/** A free ODP output port offered at onboarding and on the subscription page. */
export interface DropPortOption {
    port_id: number;
    label: string;
    odp_code: string;
    location_path: string;
    free_count: number;
}
