import type { StatusMap } from './types';

/**
 * Where an announcement or a promo banner stands (`Announcement::STATUSES`). The server works it out
 * from the publish time, `is_active` and the period, so the page never computes it.
 */
export const ANNOUNCEMENT_STATUS = {
    draft: { label: 'Draf', tone: 'neutral' },
    scheduled: { label: 'Terjadwal', tone: 'info' },
    live: { label: 'Tayang', tone: 'success' },
    ended: { label: 'Selesai', tone: 'neutral' },
    inactive: { label: 'Nonaktif', tone: 'neutral' },
} satisfies StatusMap;

/** What an announcement is about (`Announcement::KINDS`). The app puts outage and maintenance first, in the warning tone. */
export const ANNOUNCEMENT_KIND = {
    info: { label: 'Informasi', tone: 'info' },
    outage: { label: 'Gangguan', tone: 'warning' },
    maintenance: { label: 'Pemeliharaan', tone: 'warning' },
} satisfies StatusMap;

/** Who may see an announcement or a banner (`Announcement::AUDIENCES`). */
export const ANNOUNCEMENT_AUDIENCE = {
    all: { label: 'Semua pelanggan', tone: 'neutral' },
    targeted: { label: 'Pelanggan tertentu', tone: 'neutral' },
} satisfies StatusMap;

/** What a tap on a banner does (`link_kind`). */
export const BANNER_LINK_KIND = {
    none: { label: 'Tanpa tautan', tone: 'neutral' },
    app: { label: 'Halaman di aplikasi', tone: 'neutral' },
    url: { label: 'Tautan web', tone: 'neutral' },
} satisfies StatusMap;

/** The app screens a banner may open (`PromoBanner::APP_ROUTES`). */
export const BANNER_APP_ROUTE = {
    invoices: { label: 'Tagihan', tone: 'neutral' },
    subscriptions: { label: 'Langganan', tone: 'neutral' },
    ticket_create: { label: 'Buat tiket', tone: 'neutral' },
    visits: { label: 'Kunjungan', tone: 'neutral' },
} satisfies StatusMap;
