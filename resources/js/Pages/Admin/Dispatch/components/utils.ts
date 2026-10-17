import { labelOf, WORK_ITEM_STATUS } from '@/lib/statuses';
import type { DispatchItem } from '@/types';

export function getItemWhyNeedAction(item: DispatchItem): string {
    if (!item.assigned_to) {
        return item.domain === 'ticket'
            ? 'Tiket pelanggan belum memiliki petugas penanggung jawab untuk investigasi dan tindakan.'
            : 'SPK belum ditugaskan kepada teknisi lapangan.';
    }
    return `Sudah ditugaskan kepada ${item.assignee?.name ?? 'petugas'}.`;
}

export function getItemImpact(item: DispatchItem): string {
    if (item.sla_breached) {
        return 'SLA terlampaui. Berdampak pada kepuasan pelanggan dan kepatuhan layanan.';
    }
    if (item.priority === 'urgent') {
        return 'Prioritas mendesak: penugasan harus didahulukan.';
    }
    if (item.priority === 'high') {
        return 'Prioritas tinggi: ada risiko eskalasi dan keterlambatan penyelesaian.';
    }
    return 'Penanganan sesuai SLA dan alur penugasan tim.';
}

export function getNextStatusInfo(item: DispatchItem): string {
    if (!item.assigned_to) {
        return `Setelah ditugaskan, status menjadi ${labelOf(WORK_ITEM_STATUS, 'assigned')} dan item muncul di Pekerjaan saya milik petugas.`;
    }
    return `Setelah petugas menekan Mulai, status menjadi ${labelOf(WORK_ITEM_STATUS, 'in_progress')}.`;
}

/** An SPK whose assigned technician has set off and not started yet. */
export function isEnRoute(item: DispatchItem): boolean {
    return item.domain === 'spk' && item.status === 'assigned' && item.en_route_at != null;
}
