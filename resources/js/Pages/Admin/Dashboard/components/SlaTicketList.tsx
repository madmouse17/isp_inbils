import { Link } from '@inertiajs/react';
import { EnumBadge } from '@/Components/ui';
import { useCompany } from '@/hooks/useCompany';
import { formatDateTime, formatNumber } from '@/lib/format';
import { PRIORITY } from '@/lib/statuses';
import type { SlaTicketItem } from '@/types/dashboard';

interface SlaTicketListProps {
    items: SlaTicketItem[];
    /** Full count of breached tickets, which can exceed the items shown. */
    total: number;
}

export function SlaTicketList({ items, total }: SlaTicketListProps) {
    const company = useCompany();

    return (
        <div className="border-t border-border">
            <ul className="divide-y divide-border">
                {items.map((item) => (
                    <li key={item.id}>
                        <Link
                            href={item.href}
                            className="space-y-1 block px-5 py-3 transition-colors hover:bg-accent/30 focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-hidden focus-visible:ring-inset motion-reduce:transition-none"
                        >
                            <div className="flex items-center justify-between gap-3">
                                <p className="truncate text-sm font-medium text-foreground">
                                    {item.code}
                                </p>
                                <EnumBadge
                                    map={PRIORITY}
                                    value={item.priority}
                                    className="shrink-0 whitespace-nowrap"
                                />
                            </div>
                            <p className="truncate text-sm text-foreground">{item.title}</p>
                            <p className="text-xs text-muted-foreground">
                                Batas SLA {formatDateTime(item.sla_deadline, company)}
                            </p>
                        </Link>
                    </li>
                ))}
            </ul>
            {total > items.length && (
                <p className="border-t border-border px-5 py-3 text-xs text-muted-foreground">
                    Menampilkan {items.length} dari {formatNumber(total)} tiket, batas SLA terlama
                    lebih dulu.
                </p>
            )}
        </div>
    );
}
