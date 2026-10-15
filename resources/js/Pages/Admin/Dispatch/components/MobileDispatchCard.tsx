import { Link } from '@inertiajs/react';
import { AlertTriangle, User } from 'lucide-react';
import { Button, Card, CardContent, EnumBadge } from '@/Components/ui';
import { useCompany } from '@/hooks/useCompany';
import { formatDate, formatDateTime } from '@/lib/format';
import { PRIORITY, WORK_ITEM_DOMAIN } from '@/lib/statuses';
import type { DispatchItem } from '@/types';
import { formatAge } from '../../MyWork/components/utils';
import { getItemImpact, getItemWhyNeedAction, getNextStatusInfo } from './utils';

interface MobileDispatchCardProps {
    item: DispatchItem;
    onAssign: (item: DispatchItem) => void;
}

export function MobileDispatchCard({ item, onAssign }: MobileDispatchCardProps) {
    const company = useCompany();
    const isTicket = item.domain === 'ticket';

    return (
        <Card>
            <CardContent className="space-y-3 p-4">
                <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-1.5">
                            <span className="font-semibold wrap-break-word text-foreground">
                                {item.code}
                            </span>
                            <EnumBadge map={WORK_ITEM_DOMAIN} value={item.domain} />
                        </div>
                        <p className="mt-1 line-clamp-2 text-sm font-medium wrap-break-word text-foreground">
                            {item.title}
                        </p>
                    </div>
                    <EnumBadge map={PRIORITY} value={item.priority} className="shrink-0" />
                </div>

                <div className="space-y-1.5 rounded-md bg-muted/40 p-2.5 text-xs">
                    <div className="flex items-start justify-between gap-2">
                        <span className="shrink-0 text-muted-foreground">Pemohon:</span>
                        <span className="min-w-0 text-right font-medium wrap-break-word text-foreground">
                            {item.requester?.name ?? '-'}
                        </span>
                    </div>
                    <div className="flex items-start justify-between gap-2">
                        <span className="shrink-0 text-muted-foreground">Umur antrean:</span>
                        <span className="min-w-0 text-right font-medium wrap-break-word text-foreground">
                            {formatAge(item.age_seconds)}
                        </span>
                    </div>
                    <div className="flex items-start justify-between gap-2">
                        <span className="shrink-0 text-muted-foreground">
                            {isTicket ? 'Batas SLA:' : 'Jadwal kerja:'}
                        </span>
                        <span className="min-w-0 text-right font-medium wrap-break-word text-foreground">
                            {isTicket
                                ? formatDateTime(item.due_at ?? item.summary?.sla_deadline, company)
                                : formatDate(
                                      item.scheduled_date ?? item.summary?.scheduled_date,
                                      company,
                                  )}
                        </span>
                    </div>
                    <div className="flex items-start justify-between gap-2">
                        <span className="shrink-0 text-muted-foreground">Petugas saat ini:</span>
                        {item.assignee ? (
                            <span className="min-w-0 text-right font-medium wrap-break-word text-foreground">
                                {item.assignee.name}
                            </span>
                        ) : (
                            <span className="font-medium text-amber-700 dark:text-amber-400">
                                Belum ditugaskan
                            </span>
                        )}
                    </div>
                </div>

                {item.sla_breached && (
                    <div className="flex items-center gap-1.5 text-xs font-semibold text-destructive-text">
                        <AlertTriangle className="h-4 w-4 shrink-0" aria-hidden="true" />
                        <span>SLA terlampaui.</span>
                    </div>
                )}

                <div className="space-y-1 text-xs text-muted-foreground">
                    <p className="line-clamp-2">
                        <span className="font-medium text-foreground">Alasan: </span>
                        {getItemWhyNeedAction(item)}
                    </p>
                    <p className="line-clamp-2">
                        <span className="font-medium text-foreground">Dampak: </span>
                        {getItemImpact(item)}
                    </p>
                    <p>{getNextStatusInfo(item)}</p>
                </div>

                <div className="flex flex-wrap items-center justify-between gap-2 border-t border-border pt-1">
                    {item.actions.view && item.detail_url ? (
                        <Link
                            href={item.detail_url}
                            className="inline-flex min-h-11 items-center text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                        >
                            Lihat detail
                        </Link>
                    ) : (
                        <span />
                    )}

                    {item.actions.assign && (
                        <Button
                            type="button"
                            size="sm"
                            variant="primary"
                            leftIcon={<User className="h-3.5 w-3.5" aria-hidden="true" />}
                            onClick={() => onAssign(item)}
                        >
                            {item.assigned_to ? 'Ganti petugas' : 'Tugaskan'}
                        </Button>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}
