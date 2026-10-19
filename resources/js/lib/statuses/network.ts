import type { StatusMap } from './types';

export const NETWORK_ASSET_STATUS = {
    available: { label: 'Tersedia', tone: 'success' },
    installed: { label: 'Terpasang', tone: 'info' },
    maintenance: { label: 'Perawatan', tone: 'warning' },
    damaged: { label: 'Rusak', tone: 'danger' },
    retired: { label: 'Dipensiunkan', tone: 'neutral' },
} satisfies StatusMap;

export const NETWORK_ASSET_TYPE = {
    router: { label: 'Router', tone: 'neutral' },
    switch: { label: 'Switch', tone: 'neutral' },
    olt: { label: 'OLT', tone: 'neutral' },
    onu_ont: { label: 'ONU/ONT', tone: 'neutral' },
    radio: { label: 'Radio', tone: 'neutral' },
    antenna: { label: 'Antena', tone: 'neutral' },
    fiber: { label: 'Fiber', tone: 'neutral' },
    odp: { label: 'ODP', tone: 'neutral' },
    odc: { label: 'ODC', tone: 'neutral' },
    rack: { label: 'Rak', tone: 'neutral' },
    power: { label: 'Perangkat daya', tone: 'neutral' },
    other: { label: 'Lainnya', tone: 'neutral' },
} satisfies StatusMap;

export const NETWORK_ASSET_OWNERSHIP = {
    owned: { label: 'Milik sendiri', tone: 'neutral' },
    leased: { label: 'Sewa', tone: 'neutral' },
    customer_provided: { label: 'Milik pelanggan', tone: 'neutral' },
} satisfies StatusMap;

/** The kind of account a customer gets on the router. */
export const NETWORK_SERVICE_TYPE = {
    pppoe: { label: 'PPPoE', tone: 'neutral' },
    hotspot: { label: 'Hotspot', tone: 'neutral' },
} satisfies StatusMap;

/** Whether the router holds what the account says it should (`network_accounts.sync_status`). */
export const NETWORK_SYNC_STATUS = {
    pending: { label: 'Menunggu', tone: 'warning' },
    synced: { label: 'Tersinkron', tone: 'success' },
    failed: { label: 'Gagal', tone: 'danger' },
} satisfies StatusMap;

/** What the account should be on the router, computed from the subscription; not stored. */
export const NETWORK_DESIRED_STATE = {
    enabled: { label: 'Aktif', tone: 'success' },
    isolated: { label: 'Diisolir', tone: 'warning' },
    absent: { label: 'Dihapus', tone: 'neutral' },
} satisfies StatusMap;

/** Why an account is isolated: its subscription is suspended, or the customer is blocked. */
export const NETWORK_ISOLATED_BECAUSE = {
    suspended: { label: 'Ditangguhkan', tone: 'warning' },
    blocked: { label: 'Diblokir', tone: 'danger' },
} satisfies StatusMap;

/** Whether a NAS device was written to RADIUS: derived from `radius_synced_at` and `last_error`, not stored. */
export const NAS_RADIUS_STATE = {
    synced: { label: 'Tertulis ke RADIUS', tone: 'success' },
    error: { label: 'Gagal ditulis', tone: 'danger' },
    waiting: { label: 'Menunggu ditulis', tone: 'warning' },
} satisfies StatusMap;

/** A session is open: pass `session !== null`. */
export const NETWORK_ONLINE_STATE = {
    true: { label: 'Online', tone: 'success' },
    false: { label: 'Offline', tone: 'neutral' },
} satisfies StatusMap;

/** What a panel port shows; derived from `PortState`, not stored. Order = colour priority, highest first. */
export const PORT_STATE = {
    unregistered: { label: 'Belum terdaftar', tone: 'neutral' },
    admin_down: { label: 'Dinonaktifkan', tone: 'danger' },
    isolir: { label: 'Diisolir', tone: 'warning' },
    online: { label: 'Online', tone: 'success' },
    offline: { label: 'Offline', tone: 'neutral' },
    occupied: { label: 'Terpakai', tone: 'info' },
    free: { label: 'Kosong', tone: 'neutral' },
} satisfies StatusMap;
