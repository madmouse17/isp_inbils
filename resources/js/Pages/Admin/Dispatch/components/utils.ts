import type { DispatchItem } from '@/types';

export function getDomainLabel(domain: string): string {
    switch (domain) {
        case 'ticket':
            return 'Tiket';
        case 'spk':
            return 'SPK';
        default:
            return domain.toUpperCase();
    }
}

export function getDomainVariant(
    domain: string,
): 'default' | 'secondary' | 'outline' | 'destructive' {
    switch (domain) {
        case 'ticket':
            return 'secondary';
        case 'spk':
            return 'outline';
        default:
            return 'outline';
    }
}

export function getPriorityLabel(priority: string): string {
    switch (priority?.toLowerCase()) {
        case 'critical':
            return 'Kritis';
        case 'high':
            return 'Tinggi';
        case 'medium':
            return 'Sedang';
        case 'low':
            return 'Rendah';
        default:
            return priority ? priority.charAt(0).toUpperCase() + priority.slice(1) : '—';
    }
}

export function getPriorityVariant(
    priority: string,
): 'default' | 'secondary' | 'outline' | 'destructive' {
    switch (priority?.toLowerCase()) {
        case 'critical':
            return 'destructive';
        case 'high':
            return 'destructive';
        case 'medium':
            return 'default';
        case 'low':
            return 'secondary';
        default:
            return 'outline';
    }
}

export function getStatusLabel(status: string, _domain?: string): string {
    switch (status?.toLowerCase()) {
        case 'open':
            return 'Belum Ditugaskan';
        case 'assigned':
            return 'Sudah Ditugaskan';
        case 'on_progress':
        case 'in_progress':
            return 'Sedang Dikerjakan';
        case 'resolved':
            return 'Selesai Ditangani';
        case 'closed':
            return 'Ditutup';
        case 'generated':
        case 'draft':
            return 'Draf / Menunggu Penugasan';
        case 'rejected':
            return 'Ditolak';
        case 'waiting_review':
            return 'Menunggu Review';
        case 'completed':
            return 'Selesai';
        default:
            return status ? status.replace(/_/g, ' ') : '—';
    }
}

export function getStatusVariant(
    status: string,
): 'default' | 'secondary' | 'outline' | 'destructive' {
    switch (status?.toLowerCase()) {
        case 'open':
        case 'generated':
        case 'draft':
            return 'secondary';
        case 'assigned':
            return 'default';
        case 'on_progress':
        case 'in_progress':
            return 'default';
        case 'resolved':
        case 'completed':
            return 'secondary';
        case 'rejected':
            return 'destructive';
        default:
            return 'outline';
    }
}

export function formatAge(seconds: number | null | undefined): string {
    if (seconds == null || seconds < 0) return '—';
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

export function formatDate(dateString: string | null | undefined): string {
    if (!dateString) return '—';
    try {
        const d = new Date(dateString);
        if (Number.isNaN(d.getTime())) return dateString;
        return d.toLocaleDateString('id-ID', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    } catch {
        return dateString;
    }
}

export function formatDateTime(dateString: string | null | undefined): string {
    if (!dateString) return '—';
    try {
        const d = new Date(dateString);
        if (Number.isNaN(d.getTime())) return dateString;
        return d.toLocaleString('id-ID', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    } catch {
        return dateString;
    }
}

export function getItemWhyNeedAction(item: DispatchItem): string {
    if (!item.assigned_to) {
        return item.domain === 'ticket'
            ? 'Tiket pelanggan belum memiliki petugas penanggung jawab untuk proses investigasi/tindakan.'
            : 'Perintah kerja (SPK) belum ditugaskan kepada teknisi lapangan pelaksana.';
    }
    return `Sudah dialokasikan ke ${item.assignee?.name ?? 'petugas'}.`;
}

export function getItemImpact(item: DispatchItem): string {
    if (item.sla_breached) {
        return 'SLA Terlampaui! Berdampak langsung terhadap kepuasan pelanggan dan indikator kepatuhan layanan.';
    }
    if (item.priority?.toLowerCase() === 'critical') {
        return 'Dampak Kritis: Operasional layanan terganggu, penugasan darurat wajib diprioritaskan.';
    }
    if (item.priority?.toLowerCase() === 'high') {
        return 'Dampak Tinggi: Risiko eskalasi dan potensi keterlambatan penyelesaian pelanggan.';
    }
    return 'Dampak Operasional: Penanganan sesuai SLA dan alur standar penugasan tim.';
}

export function getNextStatusInfo(item: DispatchItem): string {
    if (!item.assigned_to) {
        return "Status berikutnya: 'assigned' (muncul di antrean Pekerjaan Saya teknisi terkait).";
    }
    return "Status berikutnya: 'on_progress' / 'in_progress' setelah teknisi menekan Mulai Kerja.";
}
