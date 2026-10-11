import {
    ChatBubbleLeftRightIcon,
    CheckCircleIcon,
    ClipboardDocumentListIcon,
    ClockIcon,
} from '@heroicons/react/24/outline';
import { Card, CardContent } from '@/Components/ui';
import type { DispatchSummary } from '@/types';

interface SummaryCardsProps {
    summary: DispatchSummary;
    activeStatus?: string;
    onSelectStatus?: (status: string) => void;
}

export function SummaryCards({ summary, activeStatus, onSelectStatus }: SummaryCardsProps) {
    return (
        <div
            className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4"
            role="region"
            aria-label="Ringkasan Antrean Penugasan"
        >
            <Card
                className={`cursor-pointer border-border bg-card shadow-xs transition-colors hover:border-primary/40 motion-reduce:transition-none ${
                    activeStatus === 'unassigned' || !activeStatus ? 'ring-1 ring-primary/30' : ''
                }`}
                onClick={() => onSelectStatus?.('unassigned')}
            >
                <CardContent className="flex items-center justify-between p-5">
                    <div className="space-y-1">
                        <p className="text-xs font-medium tracking-wider text-muted-foreground uppercase">
                            Belum Ditugaskan
                        </p>
                        <p className="text-2xl font-bold tracking-tight text-foreground">
                            {summary.unassigned}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            Tiket & SPK menunggu alokasi petugas
                        </p>
                    </div>
                    <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-amber-500/10 text-amber-600 dark:bg-amber-500/20 dark:text-amber-400">
                        <ClockIcon className="h-6 w-6" aria-hidden="true" />
                    </div>
                </CardContent>
            </Card>

            <Card
                className="cursor-pointer border-border bg-card shadow-xs transition-colors hover:border-primary/40 motion-reduce:transition-none"
                onClick={() => onSelectStatus?.('unassigned')}
            >
                <CardContent className="flex items-center justify-between p-5">
                    <div className="space-y-1">
                        <p className="text-xs font-medium tracking-wider text-muted-foreground uppercase">
                            Tiket Belum Ditugaskan
                        </p>
                        <p className="text-2xl font-bold tracking-tight text-foreground">
                            {summary.ticket_unassigned}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            Tiket pelanggan terbuka tanpa penanggung jawab
                        </p>
                    </div>
                    <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-blue-500/10 text-blue-600 dark:bg-blue-500/20 dark:text-blue-400">
                        <ChatBubbleLeftRightIcon className="h-6 w-6" aria-hidden="true" />
                    </div>
                </CardContent>
            </Card>

            <Card
                className="cursor-pointer border-border bg-card shadow-xs transition-colors hover:border-primary/40 motion-reduce:transition-none"
                onClick={() => onSelectStatus?.('unassigned')}
            >
                <CardContent className="flex items-center justify-between p-5">
                    <div className="space-y-1">
                        <p className="text-xs font-medium tracking-wider text-muted-foreground uppercase">
                            SPK Belum Ditugaskan
                        </p>
                        <p className="text-2xl font-bold tracking-tight text-foreground">
                            {summary.spk_unassigned}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            Perintah kerja tanpa alokasi teknisi
                        </p>
                    </div>
                    <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-purple-500/10 text-purple-600 dark:bg-purple-500/20 dark:text-purple-400">
                        <ClipboardDocumentListIcon className="h-6 w-6" aria-hidden="true" />
                    </div>
                </CardContent>
            </Card>

            <Card
                className={`cursor-pointer border-border bg-card shadow-xs transition-colors hover:border-primary/40 motion-reduce:transition-none ${
                    activeStatus === 'assigned' ? 'ring-1 ring-primary/30' : ''
                }`}
                onClick={() => onSelectStatus?.('assigned')}
            >
                <CardContent className="flex items-center justify-between p-5">
                    <div className="space-y-1">
                        <p className="text-xs font-medium tracking-wider text-muted-foreground uppercase">
                            Sudah Ditugaskan
                        </p>
                        <p className="text-2xl font-bold tracking-tight text-foreground">
                            {summary.assigned}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            Telah dialokasikan dan siap dikerjakan
                        </p>
                    </div>
                    <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-400">
                        <CheckCircleIcon className="h-6 w-6" aria-hidden="true" />
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}
