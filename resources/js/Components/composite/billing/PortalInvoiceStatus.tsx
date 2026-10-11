import {
    CheckCircleIcon,
    ClockIcon,
    ExclamationTriangleIcon,
    XCircleIcon,
} from '@heroicons/react/24/outline';
import { Badge } from '@/Components/ui';

const statuses = {
    sent: { label: 'Belum dibayar', variant: 'warning', Icon: ClockIcon },
    partial: { label: 'Dibayar sebagian', variant: 'info', Icon: ClockIcon },
    overdue: { label: 'Lewat jatuh tempo', variant: 'danger', Icon: ExclamationTriangleIcon },
    paid: { label: 'Lunas', variant: 'success', Icon: CheckCircleIcon },
    cancelled: { label: 'Dibatalkan', variant: 'secondary', Icon: XCircleIcon },
} as const;

/** Invoice state for the customer: always an icon and a word, never colour alone. */
export function PortalInvoiceStatus({ status }: { status: string }) {
    const known = statuses[status as keyof typeof statuses];

    if (!known) {
        return <Badge variant="outline">{status}</Badge>;
    }

    return (
        <Badge variant={known.variant} className="gap-1.5">
            <known.Icon className="size-4" aria-hidden="true" />
            {known.label}
        </Badge>
    );
}

/** Invoices a customer can still pay: the same set the server accepts. */
export const payableStatuses: readonly string[] = ['sent', 'partial', 'overdue'];
