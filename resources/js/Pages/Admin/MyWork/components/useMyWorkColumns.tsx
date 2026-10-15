import { Link } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Play, Send, XCircle } from 'lucide-react';
import type { DataTableColumn } from '@/Components/composite/DataTable';
import { Button, EnumBadge } from '@/Components/ui';
import { useCompany } from '@/hooks/useCompany';
import { formatDate, formatDateTime } from '@/lib/format';
import { PRIORITY, WORK_ITEM_DOMAIN, WORK_ITEM_STATUS } from '@/lib/statuses';
import type { MyWorkItem } from '@/types';
import type { ActionType } from './ActionConfirmDialog';
import { formatAge, getItemNextStatus } from './utils';

interface MyWorkColumnHandlers {
    onConfirm: (item: MyWorkItem, action: ActionType) => void;
    onResolve: (item: MyWorkItem) => void;
}

/** The columns of the list of work assigned to me, the same on My Work and on the Dispatch tab of the same name. */
export function useMyWorkColumns({
    onConfirm,
    onResolve,
}: MyWorkColumnHandlers): DataTableColumn<MyWorkItem>[] {
    const company = useCompany();

    return [
        {
            key: 'code',
            header: 'Kode',
            sortable: true,
            sortKey: 'code',
            cell: (row) => (
                <div className="space-y-1">
                    {row.actions.view && row.detail_url ? (
                        <Link
                            href={row.detail_url}
                            className="font-semibold text-primary hover:underline focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-hidden"
                        >
                            {row.code}
                        </Link>
                    ) : (
                        <span className="font-semibold text-foreground">{row.code}</span>
                    )}
                    <div>
                        <EnumBadge map={WORK_ITEM_DOMAIN} value={row.domain} />
                    </div>
                </div>
            ),
        },
        {
            key: 'title',
            header: 'Judul dan pelanggan',
            sortable: true,
            sortKey: 'title',
            cell: (row) => (
                <div className="space-y-1 max-w-xs">
                    <span className="line-clamp-2 text-sm font-medium text-foreground">
                        {row.title}
                    </span>
                    <div className="text-xs text-muted-foreground">
                        Pelanggan:{' '}
                        <span className="font-medium text-foreground">
                            {row.customer?.name ?? '-'}
                        </span>
                    </div>
                    {row.sla_breached && (
                        <div className="flex items-center gap-1 text-xs font-semibold text-destructive-text">
                            <AlertTriangle className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            <span>SLA terlampaui</span>
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'priority',
            header: 'Prioritas dan umur',
            sortable: true,
            sortKey: 'priority',
            cell: (row) => (
                <div className="space-y-1">
                    <EnumBadge map={PRIORITY} value={row.priority} />
                    <p className="text-xs text-muted-foreground">{formatAge(row.age_seconds)}</p>
                </div>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            sortable: true,
            sortKey: 'status',
            cell: (row) => (
                <div className="space-y-1">
                    <EnumBadge map={WORK_ITEM_STATUS} value={row.status} />
                    <p className="text-xs text-muted-foreground">{getItemNextStatus(row)}</p>
                </div>
            ),
        },
        {
            key: 'due_at',
            header: 'Batas atau jadwal',
            cell: (row) => (
                <div className="space-y-0.5 text-xs">
                    {row.domain === 'ticket' ? (
                        <>
                            <span className="text-muted-foreground">SLA: </span>
                            <span className="font-medium text-foreground">
                                {formatDateTime(row.due_at ?? row.summary?.sla_deadline, company)}
                            </span>
                        </>
                    ) : (
                        <>
                            <span className="text-muted-foreground">Jadwal: </span>
                            <span className="font-medium text-foreground">
                                {formatDate(
                                    row.scheduled_date ?? row.summary?.scheduled_date,
                                    company,
                                )}
                            </span>
                        </>
                    )}
                </div>
            ),
        },
        {
            key: 'actions',
            header: 'Tindakan',
            className: 'text-right',
            cell: (row) => (
                <div className="flex flex-wrap items-center justify-end gap-1.5">
                    {row.actions.start && (
                        <Button
                            type="button"
                            size="sm"
                            variant="primary"
                            leftIcon={<Play className="h-3.5 w-3.5" aria-hidden="true" />}
                            onClick={() => onConfirm(row, 'start')}
                        >
                            Mulai
                        </Button>
                    )}
                    {row.actions.resolve && (
                        <Button
                            type="button"
                            size="sm"
                            variant="primary"
                            leftIcon={<CheckCircle2 className="h-3.5 w-3.5" aria-hidden="true" />}
                            onClick={() => onResolve(row)}
                        >
                            Selesaikan
                        </Button>
                    )}
                    {row.actions.submit && (
                        <Button
                            type="button"
                            size="sm"
                            variant="primary"
                            leftIcon={<Send className="h-3.5 w-3.5" aria-hidden="true" />}
                            onClick={() => onConfirm(row, 'submit')}
                        >
                            Ajukan review
                        </Button>
                    )}
                    {row.actions.close && (
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            leftIcon={<XCircle className="h-3.5 w-3.5" aria-hidden="true" />}
                            onClick={() => onConfirm(row, 'close')}
                        >
                            Tutup
                        </Button>
                    )}
                </div>
            ),
        },
    ];
}
