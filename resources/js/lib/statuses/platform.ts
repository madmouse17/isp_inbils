import type { StatusMap } from './types';

/** Status of a top-level tenant in the operator panel. */
export const PLATFORM_TENANT_STATUS = {
    active: { label: 'Aktif', tone: 'success' },
    suspended: { label: 'Ditangguhkan', tone: 'warning' },
    deletion_requested: { label: 'Pengajuan hapus', tone: 'danger' },
} satisfies StatusMap;

/** State of a tenant data export. */
export const TENANT_EXPORT_STATUS = {
    queued: { label: 'Antrean', tone: 'neutral' },
    running: { label: 'Berjalan', tone: 'info' },
    ready: { label: 'Siap diunduh', tone: 'success' },
    failed: { label: 'Gagal', tone: 'danger' },
    expired: { label: 'Kedaluwarsa', tone: 'neutral' },
} satisfies StatusMap;

/** Where the set-password mail of a first admin stands on the company page. A label leads into the time the state began ("Antre sejak 18.17"); an expired link has no time. */
export const PLATFORM_INVITATION_STATE = {
    queued: { label: 'Antre sejak', tone: 'neutral' },
    sent: { label: 'Terkirim', tone: 'info' },
    expired: { label: 'Tautan kedaluwarsa', tone: 'warning' },
    failed: { label: 'Gagal terkirim', tone: 'danger' },
} satisfies StatusMap;

/** Operator and tenant-visible audit channels. */
export const PLATFORM_LOG = {
    platform: { label: 'Platform', tone: 'neutral' },
    support: { label: 'Akses dukungan', tone: 'warning' },
} satisfies StatusMap;
