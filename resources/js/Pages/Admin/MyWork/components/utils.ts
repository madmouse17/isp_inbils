import type { MyWorkItem } from '@/types';

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
            return priority ? priority.charAt(0).toUpperCase() + priority.slice(1) : '-';
    }
}

export function getPriorityVariant(
    priority: string,
): 'default' | 'secondary' | 'outline' | 'destructive' {
    switch (priority?.toLowerCase()) {
        case 'critical':
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

export function getStatusLabel(status: string): string {
    switch (status?.toLowerCase()) {
        case 'assigned':
            return 'Ditugaskan (Siap Mulai)';
        case 'on_progress':
        case 'in_progress':
            return 'Sedang Dikerjakan';
        case 'resolved':
            return 'Selesai Ditangani';
        case 'closed':
            return 'Ditutup';
        default:
            return status ? status.replace(/_/g, ' ') : '-';
    }
}

export function getStatusVariant(
    status: string,
): 'default' | 'secondary' | 'outline' | 'destructive' {
    switch (status?.toLowerCase()) {
        case 'assigned':
            return 'secondary';
        case 'on_progress':
        case 'in_progress':
            return 'default';
        case 'resolved':
            return 'secondary';
        case 'closed':
            return 'outline';
        default:
            return 'outline';
    }
}

export function formatAge(seconds: number | null | undefined): string {
    if (seconds == null || seconds < 0) return '-';
    if (seconds < 60) return '< 1 menit lalu';
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes} menit lalu`;
    const hours = Math.floor(seconds / 3600);
    if (hours < 24) return `${hours} jam lalu`;
    const days = Math.floor(hours / 24);
    if (days < 30) return `${days} hari lalu`;
    const months = Math.floor(days / 30);
    return `${months} bulan lalu`;
}

export function formatDate(dateString: string | null | undefined): string {
    if (!dateString) return '-';
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

export function getItemWhyNeedAction(item: MyWorkItem): string {
    switch (item.status) {
        case 'assigned':
            return 'Tugas telah dialokasikan kepada Anda. Tekan Mulai Kerja untuk mengonfirmasi pelaksanaan.';
        case 'on_progress':
            return 'Tiket sedang Anda tangani. Masukkan catatan resolusi dan selesaikan tiket setelah investigasi tuntas.';
        case 'in_progress':
            return 'SPK sedang dalam pengerjaan lapangan Anda. Tuntaskan material dan submit untuk peninjauan (review).';
        case 'resolved':
            return 'Penanganan tiket telah selesai. Konfirmasikan penutupan tiket (close) jika tidak ada kendala lanjutan.';
        default:
            return 'Item ini membutuhkan tindakan operasional sesuai dengan status alur kerja saat ini.';
    }
}

export function getItemNextStatus(item: MyWorkItem): string {
    switch (item.status) {
        case 'assigned':
            return "Status berikutnya: 'on_progress' (Tiket) / 'in_progress' (SPK)";
        case 'on_progress':
            return "Status berikutnya: 'resolved' (Tiket selesai ditangani)";
        case 'in_progress':
            return "Status berikutnya: 'waiting_review' (Menunggu approval supervisor/reviewer)";
        case 'resolved':
            return "Status berikutnya: 'closed' (Tiket ditutup permanen)";
        default:
            return '-';
    }
}

export function getItemImpact(item: MyWorkItem): string {
    if (item.sla_breached) {
        return 'SLA Terlampaui! Segera selesaikan dan berikan catatan penanganan.';
    }
    if (item.priority?.toLowerCase() === 'critical') {
        return 'Prioritas Kritis: Berdampak langsung pada kelangsungan layanan pelanggan.';
    }
    if (item.priority?.toLowerCase() === 'high') {
        return 'Prioritas Tinggi: Memerlukan penanganan segera untuk mencegah keterlambatan SLA.';
    }
    return 'Penanganan standar operasional sesuai target waktu layanan.';
}
