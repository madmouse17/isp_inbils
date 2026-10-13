import { Link } from '@inertiajs/react';
import { CheckCircle2, AlertTriangle, Send, Play, XCircle } from 'lucide-react';
import { Badge, Button, Card, CardContent } from '@/Components/ui';
import type { MyWorkItem } from '@/types';
import type { ActionType } from './ActionConfirmDialog';
import {
    formatAge,
    formatDate,
    getDomainLabel,
    getDomainVariant,
    getItemImpact,
    getItemNextStatus,
    getItemWhyNeedAction,
    getPriorityLabel,
    getPriorityVariant,
    getStatusLabel,
    getStatusVariant,
} from './utils';

interface MobileMyWorkCardProps {
    item: MyWorkItem;
    onActionConfirm: (item: MyWorkItem, action: ActionType) => void;
    onResolve: (item: MyWorkItem) => void;
}

export function MobileMyWorkCard({ item, onActionConfirm, onResolve }: MobileMyWorkCardProps) {
    const isTicket = item.domain === 'ticket';

    return (
        <Card className="border-border bg-card shadow-xs">
            <CardContent className="space-y-3 p-4">
                {/* Header: Code, Domain & Priority */}
                <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-1.5">
                            <span className="font-semibold wrap-break-word text-foreground">
                                {item.code}
                            </span>
                            <Badge
                                variant={getDomainVariant(item.domain)}
                                className="text-xs font-semibold"
                            >
                                {getDomainLabel(item.domain)}
                            </Badge>
                        </div>
                        <p className="mt-1 line-clamp-2 text-sm font-medium wrap-break-word text-foreground">
                            {item.title}
                        </p>
                    </div>
                    <Badge
                        variant={getPriorityVariant(item.priority)}
                        className="shrink-0 text-xs font-semibold"
                    >
                        {getPriorityLabel(item.priority)}
                    </Badge>
                </div>

                {/* Context Details */}
                <div className="space-y-1.5 rounded-md bg-muted/40 p-2.5 text-xs">
                    <div className="flex items-start justify-between gap-2">
                        <span className="shrink-0 text-muted-foreground">Pelanggan:</span>
                        <span className="min-w-0 text-right font-medium wrap-break-word text-foreground">
                            {item.customer?.name ?? '-'}
                        </span>
                    </div>
                    <div className="flex items-start justify-between gap-2">
                        <span className="shrink-0 text-muted-foreground">Status:</span>
                        <Badge variant={getStatusVariant(item.status)}>
                            {getStatusLabel(item.status)}
                        </Badge>
                    </div>
                    <div className="flex items-start justify-between gap-2">
                        <span className="shrink-0 text-muted-foreground">Umur:</span>
                        <span className="min-w-0 text-right font-medium wrap-break-word text-foreground">
                            {formatAge(item.age_seconds)}
                        </span>
                    </div>
                    <div className="flex items-start justify-between gap-2">
                        <span className="shrink-0 text-muted-foreground">
                            {isTicket ? 'Batas SLA:' : 'Jadwal Kerja:'}
                        </span>
                        <span className="min-w-0 text-right font-medium wrap-break-word text-foreground">
                            {isTicket
                                ? formatDate(item.due_at ?? item.summary?.sla_deadline)
                                : formatDate(item.scheduled_date ?? item.summary?.scheduled_date)}
                        </span>
                    </div>
                </div>

                {/* Breach Warning if any */}
                {item.sla_breached && (
                    <div className="flex items-center gap-1.5 text-xs font-semibold text-destructive-text">
                        <AlertTriangle className="h-4 w-4 shrink-0" />
                        <span>SLA Terlampaui! Segera tangani masalah ini.</span>
                    </div>
                )}

                {/* Why need action, impact, and next status */}
                <div className="space-y-1 text-xs text-muted-foreground">
                    <p className="line-clamp-2">
                        <span className="font-medium text-foreground">Instruksi: </span>
                        {getItemWhyNeedAction(item)}
                    </p>
                    <p className="line-clamp-2">
                        <span className="font-medium text-foreground">Dampak: </span>
                        {getItemImpact(item)}
                    </p>
                    <p className="text-xs text-muted-foreground">{getItemNextStatus(item)}</p>
                </div>

                {/* Centered Actions */}
                <div className="flex flex-wrap items-center justify-between gap-2 border-t border-border pt-2">
                    {item.actions.view && item.detail_url ? (
                        <Link
                            href={item.detail_url}
                            className="inline-flex min-h-11 items-center text-xs font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                        >
                            Lihat Detail
                        </Link>
                    ) : (
                        <span />
                    )}

                    <div className="flex w-full flex-wrap items-center justify-end gap-1.5 sm:w-auto">
                        {item.actions.start && (
                            <Button
                                type="button"
                                size="sm"
                                className="min-h-11"
                                variant="default"
                                onClick={() => onActionConfirm(item, 'start')}
                            >
                                <Play className="mr-1.5 h-3.5 w-3.5" />
                                Mulai Kerja
                            </Button>
                        )}

                        {item.actions.resolve && (
                            <Button
                                type="button"
                                size="sm"
                                className="min-h-11"
                                variant="default"
                                onClick={() => onResolve(item)}
                            >
                                <CheckCircle2 className="mr-1.5 h-3.5 w-3.5" />
                                Selesaikan
                            </Button>
                        )}

                        {item.actions.submit && (
                            <Button
                                type="button"
                                size="sm"
                                className="min-h-11"
                                variant="default"
                                onClick={() => onActionConfirm(item, 'submit')}
                            >
                                <Send className="mr-1.5 h-3.5 w-3.5" />
                                Ajukan Review
                            </Button>
                        )}

                        {item.actions.close && (
                            <Button
                                type="button"
                                size="sm"
                                className="min-h-11"
                                variant="outline"
                                onClick={() => onActionConfirm(item, 'close')}
                            >
                                <XCircle className="mr-1.5 h-3.5 w-3.5" />
                                Tutup Tiket
                            </Button>
                        )}
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}
