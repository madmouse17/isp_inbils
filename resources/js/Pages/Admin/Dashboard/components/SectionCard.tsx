import { Link } from '@inertiajs/react';
import { Badge, type BadgeVariant } from '@/Components/ui/Badge';
import { Card } from '@/Components/ui';
import { formatNumber, formatRupiah } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Company } from '@/types';
import type { DashboardSection, DashboardSeverity } from '@/types/dashboard';
import { SlaTicketList } from './SlaTicketList';

interface SectionCardProps {
    section: DashboardSection;
    company: Company | null;
    className?: string;
}

interface Detail {
    label: string;
    value: string;
}

interface SeverityStyle {
    /** Words next to the color, so severity never rests on color alone. */
    chip: { label: string; tone: BadgeVariant } | null;
    count: string;
}

const severityStyle: Record<DashboardSeverity, SeverityStyle> = {
    neutral: { chip: null, count: 'text-foreground' },
    info: { chip: null, count: 'text-foreground' },
    warning: {
        chip: { label: 'Perlu tindakan', tone: 'warning' },
        count: 'text-amber-700 dark:text-amber-400',
    },
    danger: {
        chip: { label: 'Segera tangani', tone: 'danger' },
        count: 'text-red-700 dark:text-red-400',
    },
};

function detailsOf(section: DashboardSection, company: Company | null): Detail[] {
    switch (section.key) {
        case 'my_work':
            return [
                { label: 'Belum dimulai', value: formatNumber(section.breakdown.assigned) },
                { label: 'Dikerjakan', value: formatNumber(section.breakdown.in_progress) },
                {
                    label: 'Menunggu tindakan',
                    value: formatNumber(section.breakdown.waiting_action),
                },
            ];
        case 'dispatch_unassigned':
            return [
                { label: 'Tiket', value: formatNumber(section.breakdown.tickets) },
                { label: 'SPK', value: formatNumber(section.breakdown.spk) },
            ];
        case 'warehouse_requests':
            return section.breakdown.queue === null
                ? []
                : [{ label: 'Seluruh antrean', value: formatNumber(section.breakdown.queue) }];
        case 'return_queue':
            return [{ label: 'Pengecualian', value: formatNumber(section.breakdown.exceptions) }];
        case 'overdue_invoices':
            return [{ label: 'Terutang', value: formatRupiah(section.outstanding, company) }];
        case 'draft_invoices':
            return [{ label: 'Nilai', value: formatRupiah(section.total, company) }];
        default:
            return [];
    }
}

export function SectionCard({ section, company, className }: SectionCardProps) {
    const { count } = section;
    const style = severityStyle[section.severity];
    const details = count ? detailsOf(section, company) : [];

    return (
        <Card className={cn('flex min-w-0 flex-col', className)}>
            <Link
                href={section.href}
                className="block flex-1 rounded-lg p-5 transition-colors hover:bg-accent/30 focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-hidden focus-visible:ring-inset motion-reduce:transition-none"
            >
                <div className="flex items-start justify-between gap-3">
                    <h3 className="text-sm font-semibold text-foreground">{section.title}</h3>
                    {style.chip && (
                        <Badge variant={style.chip.tone} className="shrink-0 whitespace-nowrap">
                            {style.chip.label}
                        </Badge>
                    )}
                </div>
                <p
                    className={cn(
                        'mt-2 text-3xl font-bold tabular-nums',
                        count === 0 ? 'text-muted-foreground' : style.count,
                    )}
                >
                    {formatNumber(count)}
                </p>
                {count === 0 && (
                    <p className="mt-1 text-xs text-muted-foreground">
                        Tidak ada yang perlu ditangani.
                    </p>
                )}
                {details.length > 0 && (
                    <dl className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                        {details.map(({ label, value }) => (
                            <div key={label} className="flex gap-1">
                                <dt>{label}</dt>
                                <dd className="font-medium text-foreground tabular-nums">
                                    {value}
                                </dd>
                            </div>
                        ))}
                    </dl>
                )}
                {section.key === 'approvals' && section.maker_blocked > 0 && (
                    <p className="mt-3 text-xs text-muted-foreground">
                        {formatNumber(section.maker_blocked)} SPK menunggu admin (Anda pembuatnya)
                    </p>
                )}
            </Link>
            {section.key === 'sla_breached' && section.items.length > 0 && (
                <SlaTicketList items={section.items} total={section.count} />
            )}
        </Card>
    );
}
