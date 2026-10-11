import { StatusBadge } from '@/Components/composite';

export type CustomerStatus = 'prospective' | 'active' | 'inactive' | 'suspended' | 'terminated';

const STATUS: Record<
    CustomerStatus,
    { label: string; variant: 'info' | 'success' | 'muted' | 'warning' | 'danger' }
> = {
    prospective: { label: 'Prospective', variant: 'info' },
    active: { label: 'Active', variant: 'success' },
    suspended: { label: 'Suspended', variant: 'warning' },
    inactive: { label: 'Inactive', variant: 'muted' },
    terminated: { label: 'Terminated', variant: 'danger' },
};

export const CUSTOMER_STATUS_OPTIONS = (Object.keys(STATUS) as CustomerStatus[]).map((value) => ({
    value,
    label: STATUS[value].label,
}));

export function CustomerStatusBadge({ status }: { status?: string | null }) {
    const entry = STATUS[status as CustomerStatus];

    return entry ? <StatusBadge variant={entry.variant}>{entry.label}</StatusBadge> : null;
}
