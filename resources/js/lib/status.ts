export type StatusBadgeVariant = 'default' | 'secondary' | 'destructive' | 'outline';

export function customerStatusVariant(status: string): StatusBadgeVariant {
    return status === 'active' ? 'default' : 'secondary';
}
