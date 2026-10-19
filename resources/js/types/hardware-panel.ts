import type { RemoteOption } from '@/Components/composite';
import type { NetworkAssetType } from '@/types/network-asset';

export const PANEL_ASSET_TYPES = [
    'router',
    'switch',
    'olt',
    'onu_ont',
    'odc',
    'odp',
    'rack',
] as const satisfies readonly NetworkAssetType[];

export type PortKind = 'rj45' | 'sfp' | 'sc' | 'rj11';
export type FixtureKind = 'power' | 'console' | 'usb' | 'wlan' | 'reset';

export interface PortBlock {
    group: string;
    kind: PortKind;
    count: number;
    from?: number;
    stack?: 1 | 2;
    order?: 'row' | 'column';
    name?: string;
    first?: 0 | 1;
    slot?: number;
    title?: string;
    speed?: string;
    role?: 'pon' | 'splitter';
}
export interface FixtureBlock {
    fixture: FixtureKind;
    title?: string;
}
export type PanelBlock = PortBlock | FixtureBlock;
export interface PanelLayout {
    rows: PanelBlock[][];
}

export type PortRole = 'port' | 'pon' | 'splitter_out' | 'splitter_in';

/** Only true flags are sent. */
export interface PortState {
    occupied?: true;
    online?: true;
    isolir?: true;
    admin_down?: true;
    unregistered?: true;
}

export interface PanelPort {
    /** public_id of the asset_ports or odn_ports row; null for a model slot without a register row or a splitter input. */
    id: string | null;
    /** 1-based within the group. */
    number: number;
    /** Vendor name, e.g. "ether1", "gpon-olt_1/1/1", "0/1/0". */
    name: string;
    label?: string;
    state: PortState;
    link?: { asset: string; port: string };
    drop?: { subscription: string; customer: string };
    behind?: { total: number; online: number };
}

export interface PanelGroup {
    key: string;
    kind: PortKind;
    role: PortRole;
    label: string;
    ports: PanelPort[];
}

export interface PanelTemplate {
    key: string | null;
    vendor: string | null;
    model: string | null;
    height_u: number | null;
    layout: PanelLayout;
}

export interface PanelOnline {
    known: boolean;
    reason?: 'disabled' | 'unavailable';
}

export interface TemplateOption {
    key: string;
    label: string;
    height_u: number | null;
}

export interface RackItem {
    id: string;
    code: string;
    name: string;
    type: NetworkAssetType;
    unit: number;
    height: number;
    layout: PanelLayout | null;
}

export interface PanelPayload {
    asset: { id: string; code: string; type: NetworkAssetType };
    /** null: no model yet (picker), or a rack. */
    template: PanelTemplate | null;
    groups: PanelGroup[];
    online: PanelOnline;
    /**
     * The catalogue rows of the type. A router, switch, OLT or ONT keeps them once it has a model ("Ganti model");
     * an ODP gets them only while it has no splitter; a rack and an ODC never do.
     */
    picker?: { options: TemplateOption[]; suggested: string | null };
    /** Only for racks. */
    rack?: { height_u: number | null; items: RackItem[] };
    /** True when ODN-backed groups were left out for lack of odn.view. */
    odn_hidden?: true;
}

export interface PortDetail {
    id: string;
    source: 'asset' | 'odn';
    name: string;
    label: string | null;
    note: string | null;
    admin_down: boolean;
    link: {
        asset: { id: string; code: string; name: string };
        port: { id: string; name: string };
    } | null;
    feeds: { asset: { id: string; code: string }; splitter: string } | null;
    drop: {
        subscription: {
            id: string;
            code: string;
            status: 'pending' | 'active' | 'suspended' | 'terminated';
            blocked: boolean;
        };
        customer: { id: string; name: string };
        package: string | null;
        /** null = unknown (RADIUS off or unreachable). */
        session: { online: boolean; since: string | null } | null;
    } | null;
    behind: { total: number; online: number } | null;
}

export interface TraceHop {
    /** null only for the customer end without a registered ONT. */
    asset: { id: string; code: string; name: string; type: NetworkAssetType } | null;
    /** Caption under the hop: "gpon-olt_1/1/3", "ODC-01 · 1:8 · 2", the customer name. */
    label: string;
    /** null = drawn as a labelled box. */
    layout: PanelLayout | null;
    lit: { group: string; number: number } | null;
}

export interface TracePath {
    subscription: { id: string; code: string; customer: string };
    state: PortState;
    online: PanelOnline;
    /** OLT first, customer last; empty when no drop is recorded. */
    hops: TraceHop[];
}

/** Colour priority, highest first; keys of PORT_STATE in lib/statuses/network.ts. */
export type PortStateKey =
    'unregistered' | 'admin_down' | 'isolir' | 'online' | 'offline' | 'occupied' | 'free';

export interface AssetPanelPageProps {
    panel?: PanelPayload;
    portDetail?: PortDetail | null;
    linkAssetOptions?: RemoteOption[];
    linkPortOptions?: RemoteOption[];
    dropSubscriptionOptions?: RemoteOption[];
    rackAssetOptions?: RemoteOption[];
    tracePath?: TracePath | null;
}
