import { Badge, Button, Card, CardContent } from '@/Components/ui';
import type { ApprovalInboxItem } from '@/types';
import {
    formatAge,
    getDomainLabel,
    getDomainVariant,
    getPriorityLabel,
    getPriorityVariant,
    getStatusLabel,
    getStatusVariant,
} from './utils';

interface MobileItemCardProps {
    item: ApprovalInboxItem;
    onReview: (item: ApprovalInboxItem) => void;
}

export function MobileItemCard({ item, onReview }: MobileItemCardProps) {
    const isActionable = item.actions.approve || item.actions.reject;
    const isInventory = item.domain === 'inventory_request';

    return (
        <Card className="border-border bg-card shadow-sm transition-colors hover:border-primary/30 motion-reduce:transition-none">
            <CardContent className="space-y-3 p-4">
                <div className="flex items-start justify-between gap-2">
                    <div className="space-y-1">
                        {item.actions?.view && item.detail_url ? (
                            <a
                                href={item.detail_url}
                                className="font-semibold text-primary hover:underline focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                            >
                                {item.code}
                            </a>
                        ) : (
                            <span className="font-semibold text-foreground">{item.code}</span>
                        )}
                        <div className="flex items-center gap-1.5">
                            <Badge variant={getDomainVariant(item.domain)}>
                                {getDomainLabel(item.domain)}
                            </Badge>
                            <Badge variant={getStatusVariant(item.status)}>
                                {getStatusLabel(item.status)}
                            </Badge>
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-1.5">
                        <Badge variant={getPriorityVariant(item.priority)}>
                            {getPriorityLabel(item.priority)}
                        </Badge>
                        <Badge variant="outline">{item.type}</Badge>
                    </div>
                </div>

                <div>
                    <p className="line-clamp-2 text-sm font-medium text-foreground">{item.title}</p>
                    <p className="mt-1 text-xs text-muted-foreground">
                        Pemohon:{' '}
                        <span className="font-medium text-foreground">
                            {item.requester?.name ?? `#${item.requester?.id ?? '—'}`}
                        </span>
                    </p>
                </div>

                <div className="space-y-1.5 rounded-md border border-border/60 bg-muted/40 p-2.5 text-xs">
                    <div>
                        <span className="text-muted-foreground">Dampak Material: </span>
                        <span className="font-medium text-foreground">{item.summary.label}</span>
                    </div>
                    <p className="text-muted-foreground">
                        {item.summary.count} jenis barang · kuantitas total {item.summary.quantity}{' '}
                        unit
                    </p>
                    <div className="border-t border-border/40 pt-1.5 text-muted-foreground">
                        <p>
                            Diajukan:{' '}
                            <span className="font-medium text-foreground">
                                {item.submitted_at
                                    ? new Date(item.submitted_at).toLocaleDateString('id-ID')
                                    : '—'}
                            </span>{' '}
                            ({formatAge(item.age_seconds)})
                        </p>
                        <p className="mt-0.5">
                            {isInventory ? 'Tanggal dibutuhkan: ' : 'Jadwal pengerjaan: '}
                            <span className="font-medium text-foreground">
                                {item.scheduled_date || '—'}
                            </span>
                        </p>
                    </div>
                </div>

                <div className="flex items-center gap-2 pt-1">
                    {isActionable ? (
                        <Button
                            type="button"
                            size="sm"
                            variant="default"
                            onClick={() => onReview(item)}
                            className="flex-1"
                        >
                            Tinjau
                        </Button>
                    ) : (
                        <div className="flex-1 rounded border border-muted bg-muted/20 px-2 py-1 text-center text-xs italic text-muted-foreground">
                            Maker-checker guard
                        </div>
                    )}
                    {item.actions?.view && item.detail_url && (
                        <Button asChild size="sm" variant="outline" className="flex-1">
                            <a href={item.detail_url}>Lihat detail</a>
                        </Button>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}
