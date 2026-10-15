import { labelOf, WORK_ITEM_STATUS } from '@/lib/statuses';
import type { MyWorkItem } from '@/types';

/**
 * The status filter of the work assigned to me, in the server's own groups (`in_progress` covers a ticket
 * on progress too, `resolved` is what waits for me to act), so the filter and the summary chips say the same.
 */
export const MY_WORK_STATUS_FILTER = [
    { value: 'assigned', label: 'Belum dimulai' },
    { value: 'in_progress', label: 'Sedang dikerjakan' },
    { value: 'resolved', label: 'Menunggu tindakan' },
] as const;

/** How long ago, in the largest whole unit. Shared by Dispatch, My Work and Approvals. */
export function formatAge(seconds: number | null | undefined): string {
    if (seconds == null || seconds < 0) return '-';
    if (seconds < 60) return '< 1 menit lalu';
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes} menit lalu`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours} jam lalu`;
    const days = Math.floor(hours / 24);
    if (days < 30) return `${days} hari lalu`;
    const months = Math.floor(days / 30);
    return `${months} bulan lalu`;
}

export function getItemWhyNeedAction(item: MyWorkItem): string {
    switch (item.status) {
        case 'assigned':
            return 'Tugas ini sudah dialokasikan kepada Anda. Tekan Mulai untuk mengonfirmasi pelaksanaan.';
        case 'on_progress':
            return 'Tiket sedang Anda tangani. Tulis catatan penyelesaian, lalu selesaikan tiket setelah masalahnya tuntas.';
        case 'in_progress':
            return 'SPK sedang Anda kerjakan di lapangan. Lengkapi material dan bukti, lalu ajukan untuk review.';
        case 'resolved':
            return 'Penanganan tiket sudah selesai. Tutup tiket jika tidak ada kendala lanjutan.';
        default:
            return 'Item ini membutuhkan tindakan sesuai status alur kerjanya.';
    }
}

/** The status the item moves to next, as the badge would say it. */
export function getItemNextStatus(item: MyWorkItem): string {
    const next = (status: string) => `Berikutnya: ${labelOf(WORK_ITEM_STATUS, status)}`;

    switch (item.status) {
        case 'assigned':
            return next('in_progress');
        case 'on_progress':
            return next('resolved');
        case 'in_progress':
            return next('waiting_review');
        case 'resolved':
            return next('closed');
        default:
            return '-';
    }
}

export function getItemImpact(item: MyWorkItem): string {
    if (item.sla_breached) {
        return 'SLA terlampaui. Segera selesaikan dan tulis catatan penanganan.';
    }
    if (item.priority === 'urgent') {
        return 'Prioritas mendesak: berdampak langsung pada layanan pelanggan.';
    }
    if (item.priority === 'high') {
        return 'Prioritas tinggi: perlu ditangani segera agar SLA tidak terlampaui.';
    }
    return 'Penanganan standar sesuai target waktu layanan.';
}
