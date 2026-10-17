import type { IP_ADDRESS_STATUS, IP_PREFIX_USAGE, IP_VERSION } from '@/lib/statuses/ipam';

/** Props of the IPAM (Modules/Ipam) and ODN (Modules/NetworkAsset) pages; the controllers in those modules are the source of truth. */

/* The value sets come from the status maps (`lib/statuses/ipam.ts`), so a new value is added in one place. */
export type IpFamily = keyof typeof IP_VERSION;

export type IpUsage = keyof typeof IP_PREFIX_USAGE;

export type IpAddressStatus = keyof typeof IP_ADDRESS_STATUS;

/** A prefix as IpPrefixUtilisationQuery formats it. `size` and `free` are exact decimal strings (IPv6 sizes overflow a JS number). */
export interface PrefixRow {
    id: string;
    parent_id: string | null;
    depth: number;
    cidr: string;
    family: IpFamily;
    usage: IpUsage;
    location: { id: string; code: string } | null;
    vlan: { id: string; vid: number; name: string } | null;
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
    parent: { id: string; cidr: string } | null;
}

export interface PopOption {
    id: string;
    code: string;
    name: string;
}

export interface VlanOption {
    id: string;
    vid: number;
    name: string;
    location_code: string | null;
}

export interface IpAddressHolder {
    type: 'subscription' | 'asset';
    id: string;
    label: string;
    href: string;
}

export interface IpAddressRow {
    id: string;
    address: string;
    status: IpAddressStatus;
    /** Null for a reservation. */
    holder: IpAddressHolder | null;
    note: string | null;
    assigned_at: string | null;
}

export interface VlanRow {
    id: string;
    vid: number;
    name: string;
    location: { id: string; code: string } | null;
    description: string | null;
    prefixes_count: number;
}

/** What the subscription page lists under "Alamat IP". */
export interface SubscriptionIpAddress {
    id: string;
    family: IpFamily;
    address: string;
    prefix_cidr: string | null;
    status: IpAddressStatus;
}

/** A customer static pool a subscription can draw from; `free` is null for IPv6, where the address is typed. */
export interface StaticPool {
    id: string;
    cidr: string;
    family: IpFamily;
    free: string | null;
}

export type OdnAssetType = 'olt' | 'odc' | 'odp';

export interface OdpRow {
    asset_id: string;
    code: string;
    name: string;
    location_path: string | null;
    capacity: number;
    used: number;
    free: number;
    percent: number;
}

export interface PonPortSummary {
    port_id: string;
    olt_asset_id: string;
    olt_code: string;
    number: number;
    label: string | null;
    subscribers: number;
}

export interface UnmappedSubscription {
    subscription_id: string;
    code: string;
    customer_name: string | null;
    serving_pop_code: string | null;
    status: string;
}

export interface OdnAsset {
    id: string;
    code: string;
    name: string;
    asset_type: OdnAssetType;
    location_path: string | null;
}

export interface PonPort {
    id: string;
    number: number;
    label: string | null;
    splitter_id: string | null;
    subscribers: number;
}

export interface SplitterPort {
    id: string;
    number: number;
    label: string | null;
    subscription: { id: string; code: string; customer_name: string | null } | null;
    feeds_splitter_id: string | null;
}

export interface Splitter {
    id: string;
    label: string | null;
    outputs: number;
    input: { port_id: string; label: string } | null;
    ports: SplitterPort[];
}

/**
 * A free port as `SearchOptions` sends it: the id, what the list shows and, under it, where the port is.
 * `dropPortOptions` (an ODP output a customer drop can take, under the serving POP) and `inputPortOptions`
 * (an upstream port a splitter input can connect to) both have this shape.
 */
export interface PortOption {
    value: string;
    label: string;
    description: string | null;
}

export type DropPortOption = PortOption;

/** The ODP output port a subscription's drop sits on. */
export interface DropPort {
    port_id: string;
    number: number;
    odp_asset_id: string;
    odp_code: string;
    odp_name: string;
    location_path: string | null;
}
