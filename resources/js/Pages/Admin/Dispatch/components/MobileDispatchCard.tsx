import {
    ArrowTopRightOnSquareIcon,
    ExclamationTriangleIcon,
    UserIcon,
} from '@heroicons/react/24/outline';
import { Badge, Button, Card, CardContent } from '@/Components/ui';
import type { DispatchItem } from '@/types';
import {
    formatAge,
    formatDate,
    getDomainLabel,
    getDomainVariant,
    getItemImpact,
    getItemWhyNeedAction,
    getNextStatusInfo,
    getPriorityLabel,
    getPriorityVariant,
} from './utils';

interface MobileDispatchCardProps {
    item: DispatchItem;
    onAssign: (item: DispatchItem) => void;
}

export function MobileDispatchCard({ item, onAssign }: MobileDispatchCardProps) {
    const isTicket = item.domain === 'ticket';

    return (
        <Card className="border-border bg-card shadow-sm">
            <CardContent className="space-y-3 p-4">
                {/* Header: Code, Domain & Priority */}
                <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-1.5">
                            <span className="break-words font-semibold text-foreground">
                                {item.code}
                            </span>
                            <Badge
                                variant={getDomainVariant(item.domain)}
                                className="text-[10px] font-semibold uppercase"
                            >
                                {getDomainLabel(item.domain)}
                            </Badge>
                        </div>
                        <p className="mt-1 line-clamp-2 break-words text-sm font-medium text-foreground">
                            {item.title}
                        </p>
                    </div>
                    <Badge
                        variant={getPriorityVariant(item.priority)}
                        className="shrink-0 text-[10px] font-semibold uppercase"
                    >
                        {getPriorityLabel(item.priority)}
                    </Badge>
                </div>

                {/* Context Details */}
                <div className="space-y-1.5 rounded-md bg-muted/40 p-2.5 text-xs">
                    <div className="flex items-start justify-between gap-2">
                        <span className="shrink-0 text-muted-foreground">Pemohon:</span>
                        <span className="min-w-0 break-words text-right font-medium text-foreground">
                            {item.requester?.name ?? '—'}
                        </span>
                    </div>
                    <div className="flex items-start justify-between gap-2">
                        <span className="shrink-0 text-muted-foreground">Umur Antrean:</span>
                        <span className="min-w-0 break-words text-right font-medium text-foreground">
                            {formatAge(item.age_seconds)}
                        </span>
                    </div>
                    <div className="flex items-start justify-between gap-2">
                        <span className="shrink-0 text-muted-foreground">
                            {isTicket ? 'Batas SLA:' : 'Jadwal Kerja:'}
                        </span>
                        <span className="min-w-0 break-words text-right font-medium text-foreground">
                            {isTicket
                                ? formatDate(item.due_at ?? item.summary?.sla_deadline)
                                : formatDate(item.scheduled_date ?? item.summary?.scheduled_date)}
                        </span>
                    </div>
                    <div className="flex items-start justify-between gap-2">
                        <span className="shrink-0 text-muted-foreground">Petugas Saat Ini:</span>
                        {item.assignee ? (
                            <span className="min-w-0 break-words text-right font-medium text-foreground">
                                {item.assignee.name}
                            </span>
                        ) : (
                            <Badge
                                variant="outline"
                                className="border-amber-300 text-[10px] text-amber-600 dark:text-amber-400"
                            >
                                Belum Ditugaskan
                            </Badge>
                        )}
                    </div>
                </div>

                {/* Breach Warning if any */}
                {item.sla_breached && (
                    <div className="flex items-center gap-1.5 text-xs font-semibold text-destructive">
                        <ExclamationTriangleIcon className="h-4 w-4 shrink-0" />
                        <span>SLA Terlampaui!</span>
                    </div>
                )}

                {/* Why need action, impact, and next status */}
                <div className="space-y-1 text-xs text-muted-foreground">
                    <p className="line-clamp-2">
                        <span className="font-medium text-foreground">Alasan: </span>
                        {getItemWhyNeedAction(item)}
                    </p>
                    <p className="line-clamp-2">
                        <span className="font-medium text-foreground">Dampak: </span>
                        {getItemImpact(item)}
                    </p>
                    <p className="text-[11px] text-muted-foreground/80">
                        {getNextStatusInfo(item)}
                    </p>
                </div>

                {/* Actions */}
                <div className="flex flex-wrap items-center justify-between gap-2 border-t border-border pt-1">
                    {item.actions.view && item.detail_url ? (
                        <a
                            href={item.detail_url}
                            className="inline-flex min-h-11 items-center text-xs font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            <ArrowTopRightOnSquareIcon className="mr-1 h-3.5 w-3.5" />
                            Lihat Detail
                        </a>
                    ) : (
                        <span />
                    )}

                    {item.actions.assign && (
                        <Button
                            type="button"
                            size="sm"
                            variant="default"
                            onClick={() => onAssign(item)}
                            className="min-h-11"
                        >
                            <UserIcon className="mr-1.5 h-3.5 w-3.5" />
                            {item.assigned_to ? 'Ganti Petugas' : 'Tugaskan'}
                        </Button>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}
