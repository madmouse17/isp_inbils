import { Link } from '@inertiajs/react';
import { formatDateTime, formatNumber } from '@/lib/format';
import type { SlaTicketItem } from '@/types/dashboard';
import { Badge, type BadgeVariant } from '@/Components/ui/Badge';

interface SlaTicketListProps {
    items: SlaTicketItem[];
    /** Full count of breached tickets, which can exceed the items shown. */
    total: number;
}

const priorities: Record<string, { label: string; tone: BadgeVariant }> = {
    urgent: { label: 'Mendesak', tone: 'danger' },
    high: { label: 'Tinggi', tone: 'warning' },
    medium: { label: 'Sedang', tone: 'info' },
    low: { label: 'Rendah', tone: 'neutral' },
};

export function SlaTicketList({ items, total }: SlaTicketListProps) {
    return (
        <div className="border-t border-border">
            <ul className="divide-y divide-border">
                {items.map((item) => {
                    // Falls back to the raw value, so a priority the server adds later still renders.
                    const priority = priorities[item.priority] ?? {
                        label: item.priority,
                        tone: 'neutral',
                    };

                    return (
                        <li key={item.id}>
                            <Link
                                href={item.href}
                                className="space-y-1 block px-5 py-3 transition-colors hover:bg-accent/30 focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-hidden focus-visible:ring-inset motion-reduce:transition-none"
                            >
                                <div className="flex items-center justify-between gap-3">
                                    <p className="truncate text-sm font-medium text-foreground">
                                        {item.code}
                                    </p>
                                    <Badge
                                        variant={priority.tone}
                                        className="shrink-0 whitespace-nowrap"
                                    >
                                        {priority.label}
                                    </Badge>
                                </div>
                                <p className="truncate text-sm text-foreground">{item.title}</p>
                                <p className="text-xs text-muted-foreground">
                                    Batas SLA {formatDateTime(item.sla_deadline)}
                                </p>
                            </Link>
                        </li>
                    );
                })}
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
