import { Link } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Play, Send, XCircle } from 'lucide-react';
import { Button, Card, CardContent, EnumBadge } from '@/Components/ui';
import { useCompany } from '@/hooks/useCompany';
import { formatDate, formatDateTime } from '@/lib/format';
import { PRIORITY, WORK_ITEM_DOMAIN, WORK_ITEM_STATUS } from '@/lib/statuses';
import type { MyWorkItem } from '@/types';
import type { ActionType } from './ActionConfirmDialog';
import { formatAge, getItemImpact, getItemNextStatus, getItemWhyNeedAction } from './utils';

interface MobileMyWorkCardProps {
    item: MyWorkItem;
    onActionConfirm: (item: MyWorkItem, action: ActionType) => void;
    onResolve: (item: MyWorkItem) => void;
}

export function MobileMyWorkCard({ item, onActionConfirm, onResolve }: MobileMyWorkCardProps) {
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
                        <span className="shrink-0 text-muted-foreground">Pelanggan:</span>
                        <span className="min-w-0 text-right font-medium wrap-break-word text-foreground">
                            {item.customer?.name ?? '-'}
                        </span>
                    </div>
                    <div className="flex items-start justify-between gap-2">
                        <span className="shrink-0 text-muted-foreground">Status:</span>
                        <EnumBadge map={WORK_ITEM_STATUS} value={item.status} />
                    </div>
                    <div className="flex items-start justify-between gap-2">
                        <span className="shrink-0 text-muted-foreground">Umur:</span>
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
                </div>

                {item.sla_breached && (
                    <div className="flex items-center gap-1.5 text-xs font-semibold text-destructive-text">
                        <AlertTriangle className="h-4 w-4 shrink-0" aria-hidden="true" />
                        <span>SLA terlampaui. Segera tangani.</span>
                    </div>
                )}

                <div className="space-y-1 text-xs text-muted-foreground">
                    <p className="line-clamp-2">
                        <span className="font-medium text-foreground">Instruksi: </span>
                        {getItemWhyNeedAction(item)}
                    </p>
                    <p className="line-clamp-2">
                        <span className="font-medium text-foreground">Dampak: </span>
                        {getItemImpact(item)}
                    </p>
                    <p>{getItemNextStatus(item)}</p>
                </div>

                <div className="flex flex-wrap items-center justify-between gap-2 border-t border-border pt-2">
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

                    <div className="flex w-full flex-wrap items-center justify-end gap-1.5 sm:w-auto">
                        {item.actions.start && (
                            <Button
                                type="button"
                                size="sm"
                                variant="primary"
                                leftIcon={<Play className="h-3.5 w-3.5" aria-hidden="true" />}
                                onClick={() => onActionConfirm(item, 'start')}
                            >
                                Mulai kerja
                            </Button>
                        )}

                        {item.actions.resolve && (
                            <Button
                                type="button"
                                size="sm"
                                variant="primary"
                                leftIcon={
                                    <CheckCircle2 className="h-3.5 w-3.5" aria-hidden="true" />
                                }
                                onClick={() => onResolve(item)}
                            >
                                Selesaikan
                            </Button>
                        )}

                        {item.actions.submit && (
                            <Button
                                type="button"
                                size="sm"
                                variant="primary"
                                leftIcon={<Send className="h-3.5 w-3.5" aria-hidden="true" />}
                                onClick={() => onActionConfirm(item, 'submit')}
                            >
                                Ajukan review
                            </Button>
                        )}

                        {item.actions.close && (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                leftIcon={<XCircle className="h-3.5 w-3.5" aria-hidden="true" />}
                                onClick={() => onActionConfirm(item, 'close')}
                            >
                                Tutup tiket
                            </Button>
                        )}
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}
