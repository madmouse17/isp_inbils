import type { StatusMap } from './types';

/** A stock movement is a kind, not a state; an adjustment is the one that needs a second look. */
export const STOCK_MOVEMENT_TYPE = {
    receive: { label: 'Masuk', tone: 'neutral' },
    issue: { label: 'Keluar', tone: 'neutral' },
    transfer: { label: 'Transfer', tone: 'neutral' },
    adjustment: { label: 'Penyesuaian', tone: 'warning' },
    reserve: { label: 'Dicadangkan', tone: 'neutral' },
    release: { label: 'Cadangan dilepas', tone: 'neutral' },
    return: { label: 'Pengembalian', tone: 'neutral' },
} satisfies StatusMap;

export const STOCK_ADJUSTMENT_STATUS = {
    pending: { label: 'Menunggu', tone: 'warning' },
    approved: { label: 'Disetujui', tone: 'success' },
    rejected: { label: 'Ditolak', tone: 'danger' },
} satisfies StatusMap;

/** Permintaan barang: from the technician's draft to the goods handed over. */
export const INVENTORY_REQUEST_STATUS = {
    draft: { label: 'Draf', tone: 'neutral' },
    submitted: { label: 'Menunggu persetujuan', tone: 'warning' },
    approved_reserved: { label: 'Disetujui', tone: 'info' },
    preparing: { label: 'Disiapkan', tone: 'info' },
    ready_for_pickup: { label: 'Siap diambil', tone: 'info' },
    issued: { label: 'Diserahkan', tone: 'success' },
    rejected: { label: 'Ditolak', tone: 'danger' },
    cancelled: { label: 'Dibatalkan', tone: 'neutral' },
} satisfies StatusMap;

/** What a request was raised for (`source_type`). */
export const INVENTORY_REQUEST_SOURCE = {
    work_order: { label: 'SPK', tone: 'neutral' },
    ticket: { label: 'Tiket', tone: 'neutral' },
} satisfies StatusMap;

/** The return of unused material: the technician declares, the warehouse receives, a difference is settled. */
export const MATERIAL_RETURN_STATUS = {
    awaiting_declaration: { label: 'Menunggu deklarasi teknisi', tone: 'info' },
    awaiting_receipt: { label: 'Menunggu penerimaan gudang', tone: 'warning' },
    correction_required: { label: 'Perlu koreksi', tone: 'danger' },
    exception_open: { label: 'Selisih perlu penyelesaian', tone: 'danger' },
    reconciled: { label: 'Diterima', tone: 'success' },
    resolved: { label: 'Selisih diselesaikan', tone: 'success' },
} satisfies StatusMap;

export const MATERIAL_RETURN_DISCREPANCY = {
    damaged: { label: 'Rusak', tone: 'danger' },
    lost: { label: 'Hilang', tone: 'danger' },
    other: { label: 'Lainnya', tone: 'neutral' },
} satisfies StatusMap;

export const PRODUCT_TYPE = {
    consumable: { label: 'Habis pakai', tone: 'neutral' },
    asset: { label: 'Aset', tone: 'neutral' },
    service: { label: 'Layanan', tone: 'neutral' },
} satisfies StatusMap;
