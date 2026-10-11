import {
    CheckCircleIcon,
    ClockIcon,
    DocumentTextIcon,
    ExclamationTriangleIcon,
} from '@heroicons/react/24/outline';
import { Link } from '@inertiajs/react';
import { Card, CardContent } from '@/Components/ui';
import type { ApprovalInboxSummary } from '@/types';

interface SummaryCardsProps {
    summary: ApprovalInboxSummary;
    domain?: string;
}

export function SummaryCards({ summary, domain }: SummaryCardsProps) {
    const isInventory = domain === 'inventory_request';
    const isSpk = domain === 'spk';

    const waitingSubtitle = isInventory
        ? 'Permintaan barang menunggu review Anda'
        : isSpk
          ? 'SPK menunggu review Anda'
          : 'Item antrean menunggu review Anda';

    return (
        <div
            className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4"
            role="region"
            aria-label="Ringkasan Antrean Approval"
        >
            <Card className="border-border bg-card shadow-xs transition-colors hover:border-primary/40 motion-reduce:transition-none">
                <CardContent className="flex items-center justify-between p-5">
                    <div className="space-y-1">
                        <p className="text-xs font-medium tracking-wider text-muted-foreground uppercase">
                            Menunggu Saya
                        </p>
                        <p className="text-2xl font-bold tracking-tight text-foreground">
                            {summary.waiting_for_me}
                        </p>
                        <p className="text-xs text-muted-foreground">{waitingSubtitle}</p>
                    </div>
                    <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-primary/10 text-primary dark:bg-primary/20">
                        <ClockIcon className="h-6 w-6" aria-hidden="true" />
                    </div>
                </CardContent>
            </Card>

            <Card className="border-border bg-card shadow-xs">
                <CardContent className="flex items-center justify-between p-5">
                    <div className="space-y-1">
                        <p className="text-xs font-medium tracking-wider text-muted-foreground uppercase">
                            Mendesak / Terlambat
                        </p>
                        <p className="text-2xl font-bold tracking-tight text-muted-foreground">—</p>
                        <p className="text-xs text-muted-foreground">
                            Tidak ada batas waktu aktif (tanpa SLA palsu)
                        </p>
                    </div>
                    <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                        <ExclamationTriangleIcon className="h-6 w-6" aria-hidden="true" />
                    </div>
                </CardContent>
            </Card>

            <Card className="border-border bg-card shadow-xs">
                <CardContent className="flex items-center justify-between p-5">
                    <div className="space-y-1">
                        <p className="text-xs font-medium tracking-wider text-muted-foreground uppercase">
                            Diproses
                        </p>
                        <p className="text-2xl font-bold tracking-tight text-foreground">
                            {summary.processed}
                        </p>
                        <p className="text-xs text-muted-foreground">Status selesai atau ditolak</p>
                    </div>
                    <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-400">
                        <CheckCircleIcon className="h-6 w-6" aria-hidden="true" />
                    </div>
                </CardContent>
            </Card>

            <Card className="border-border bg-card shadow-xs">
                <CardContent className="flex items-center justify-between p-5">
                    <div className="space-y-1">
                        <p className="text-xs font-medium tracking-wider text-muted-foreground uppercase">
                            Riwayat Kanonikal
                        </p>
                        <div className="flex items-baseline gap-2">
                            <p className="text-2xl font-bold tracking-tight text-foreground">
                                {isInventory ? 'Permintaan' : isSpk ? 'SPK' : 'Modul'}
                            </p>
                        </div>
                        {isInventory && (
                            <Link
                                href="/admin/inventory/requests"
                                className="inline-flex items-center text-xs font-medium text-primary hover:underline"
                            >
                                Buka modul Permintaan Barang &rarr;
                            </Link>
                        )}
                        {isSpk && (
                            <Link
                                href="/admin/spk"
                                className="inline-flex items-center text-xs font-medium text-primary hover:underline"
                            >
                                Buka modul SPK &rarr;
                            </Link>
                        )}
                        {!isInventory && !isSpk && (
                            <div className="flex flex-col gap-0.5">
                                <Link
                                    href="/admin/spk"
                                    className="inline-flex items-center text-xs font-medium text-primary hover:underline"
                                >
                                    Modul SPK &rarr;
                                </Link>
                                <Link
                                    href="/admin/inventory/requests"
                                    className="inline-flex items-center text-xs font-medium text-primary hover:underline"
                                >
                                    Modul Permintaan Barang &rarr;
                                </Link>
                            </div>
                        )}
                    </div>
                    <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-secondary text-secondary-foreground">
                        <DocumentTextIcon className="h-6 w-6" aria-hidden="true" />
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}
