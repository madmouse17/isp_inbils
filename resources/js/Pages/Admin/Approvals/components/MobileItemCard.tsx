import { Link } from '@inertiajs/react';
import { Button, Card, CardContent, EnumBadge } from '@/Components/ui';
import { useCompany } from '@/hooks/useCompany';
import { formatDate } from '@/lib/format';
import {
    APPROVAL_TYPE,
    labelOf,
    PRIORITY,
    WORK_ITEM_DOMAIN,
    WORK_ITEM_STATUS,
} from '@/lib/statuses';
import type { ApprovalInboxItem } from '@/types';
import { formatAge } from '../../MyWork/components/utils';
import { materialsLine } from './utils';

interface MobileItemCardProps {
    item: ApprovalInboxItem;
    onReview: (item: ApprovalInboxItem) => void;
}

export function MobileItemCard({ item, onReview }: MobileItemCardProps) {
    const company = useCompany();
    const isActionable = item.actions.approve || item.actions.reject;
    const isInventory = item.domain === 'inventory_request';

    return (
        <Card>
            <CardContent className="space-y-3 p-4">
                <div className="flex items-start justify-between gap-2">
                    <div className="space-y-1">
                        {item.actions?.view && item.detail_url ? (
                            <Link
                                href={item.detail_url}
                                className="font-semibold text-primary-text hover:underline focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-hidden"
                            >
                                {item.code}
                            </Link>
                        ) : (
                            <span className="font-semibold text-foreground">{item.code}</span>
                        )}
                        <div className="flex items-center gap-1.5">
                            <EnumBadge map={WORK_ITEM_DOMAIN} value={item.domain} />
                            <EnumBadge map={WORK_ITEM_STATUS} value={item.status} />
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-1.5">
                        <EnumBadge map={PRIORITY} value={item.priority} />
                    </div>
                </div>

                <div>
                    <p className="line-clamp-2 text-sm font-medium text-foreground">{item.title}</p>
                    <p className="mt-1 text-xs text-muted-foreground">
                        Jenis: {labelOf(APPROVAL_TYPE, item.type)}
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground">
                        Pemohon:{' '}
                        <span className="font-medium text-foreground">
                            {item.requester?.name ?? '-'}
                        </span>
                    </p>
                </div>

                <div className="space-y-1.5 rounded-md border border-border/60 bg-muted/40 p-2.5 text-xs">
                    <div>
                        <span className="text-muted-foreground">Dampak material: </span>
                        <span className="font-medium text-foreground">
                            {materialsLine(item.summary.materials ?? []) || item.summary.label}
                        </span>
                    </div>
                    <p className="text-muted-foreground">{item.summary.count} jenis barang</p>
                    <div className="border-t border-border/40 pt-1.5 text-muted-foreground">
                        <p>
                            Diajukan:{' '}
                            <span className="font-medium text-foreground">
                                {formatDate(item.submitted_at, company)}
                            </span>{' '}
                            ({formatAge(item.age_seconds)})
                        </p>
                        <p className="mt-0.5">
                            {isInventory ? 'Tanggal dibutuhkan: ' : 'Jadwal pengerjaan: '}
                            <span className="font-medium text-foreground">
                                {formatDate(item.scheduled_date, company)}
                            </span>
                        </p>
                    </div>
                </div>

                <div className="flex items-center gap-2 pt-1">
                    {isActionable ? (
                        <Button
                            type="button"
                            size="sm"
                            variant="primary"
                            onClick={() => onReview(item)}
                            className="flex-1"
                        >
                            Tinjau
                        </Button>
                    ) : (
                        <p className="flex-1 rounded-md border border-border bg-muted/40 px-2 py-2 text-center text-xs text-muted-foreground">
                            Perlu penyetuju lain
                        </p>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}
