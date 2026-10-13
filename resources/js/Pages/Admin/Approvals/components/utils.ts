import type { BadgeProps } from '@/Components/ui';

export function formatAge(seconds: number | null): string {
    if (seconds == null) return '-';

    if (seconds < 60) {
        return `${Math.max(0, Math.floor(seconds))} dtk yang lalu`;
    }

    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) {
        return `${minutes} mnt yang lalu`;
    }

    const hours = Math.floor(minutes / 60);
    if (hours < 24) {
        return `${hours} jam yang lalu`;
    }

    const days = Math.floor(hours / 24);
    return `${days} hari yang lalu`;
}

export function getPriorityVariant(priority: string): NonNullable<BadgeProps['variant']> {
    switch (priority?.toLowerCase()) {
        case 'urgent':
            return 'destructive';
        case 'high':
            return 'warning';
        case 'medium':
            return 'info';
        case 'low':
        default:
            return 'secondary';
    }
}

export function getPriorityLabel(priority: string): string {
    switch (priority?.toLowerCase()) {
        case 'urgent':
            return 'Urgent';
        case 'high':
            return 'Tinggi';
        case 'medium':
            return 'Sedang';
        case 'low':
            return 'Rendah';
        default:
            return priority || '-';
    }
}

export function getDomainLabel(domain?: string | null): string {
    switch (domain?.toLowerCase()) {
        case 'inventory_request':
        case 'inventory':
            return 'Permintaan Barang';
        case 'spk':
            return 'SPK';
        default:
            return domain || '-';
    }
}

export function getDomainVariant(domain?: string | null): NonNullable<BadgeProps['variant']> {
    switch (domain?.toLowerCase()) {
        case 'inventory_request':
        case 'inventory':
            return 'info';
        case 'spk':
            return 'secondary';
        default:
            return 'outline';
    }
}

export function getStatusLabel(status?: string | null): string {
    switch (status?.toLowerCase()) {
        case 'waiting_review':
            return 'Menunggu Review';
        case 'submitted':
            return 'Diajukan';
        case 'approved_reserved':
            return 'Disetujui (Reservasi)';
        case 'rejected':
            return 'Ditolak';
        default:
            return status || '-';
    }
}

export function getStatusVariant(status?: string | null): NonNullable<BadgeProps['variant']> {
    switch (status?.toLowerCase()) {
        case 'waiting_review':
            return 'warning';
        case 'submitted':
            return 'info';
        case 'approved_reserved':
            return 'success';
        case 'rejected':
            return 'destructive';
        default:
            return 'secondary';
    }
}

export function getItemCompositeId(item: {
    id: number | string;
    domain?: string;
    composite_id?: string;
}): string {
    return item.composite_id || `${item.domain || 'unknown'}:${item.id}`;
}
