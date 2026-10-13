import type { StatusMap } from './types';

export const WHATSAPP_STATUS = {
    queued: { label: 'Antrean', tone: 'neutral' },
    sent: { label: 'Terkirim', tone: 'info' },
    delivered: { label: 'Diterima', tone: 'success' },
    read: { label: 'Dibaca', tone: 'success' },
    failed: { label: 'Gagal', tone: 'danger' },
    skipped: { label: 'Dilewati', tone: 'warning' },
} satisfies StatusMap;

/** Why a message was not sent (`skip_reason`). `STOP` is the keyword a customer replies with to opt out. */
export const WHATSAPP_SKIP_REASON = {
    disabled: { label: 'WhatsApp perusahaan nonaktif', tone: 'neutral' },
    event_disabled: { label: 'Jenis pesan dinonaktifkan', tone: 'neutral' },
    no_consent: { label: 'Pelanggan belum setuju', tone: 'neutral' },
    opted_out: { label: 'Pelanggan berhenti (STOP)', tone: 'neutral' },
    invalid_phone: { label: 'Nomor tidak valid', tone: 'neutral' },
    budget_exceeded: { label: 'Batas pesan bulanan tercapai', tone: 'neutral' },
} satisfies StatusMap;
