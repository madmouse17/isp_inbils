import {
    BriefcaseIcon,
    CheckCircleIcon,
    ClockIcon,
    WrenchScrewdriverIcon,
} from '@heroicons/react/24/outline';
import { Button, Card, CardContent } from '@/Components/ui';
import type { MyWorkSummary } from '@/types';

interface SummaryCardsProps {
    summary: MyWorkSummary;
    activeStatus?: string;
    onSelectStatus?: (status: string) => void;
}

export function SummaryCards({ summary, activeStatus, onSelectStatus }: SummaryCardsProps) {
    const cards = [
        {
            key: '',
            label: 'Total Tugas Saya',
            value: summary.total,
            description: 'Seluruh tiket & SPK yang dialokasikan ke Anda',
            icon: <BriefcaseIcon className="h-6 w-6" aria-hidden="true" />,
            iconClassName: 'bg-primary/10 text-primary dark:bg-primary/20',
        },
        {
            key: 'assigned',
            label: 'Belum Dimulai',
            value: summary.assigned,
            description: 'Tugas baru yang siap Anda kerjakan',
            icon: <ClockIcon className="h-6 w-6" aria-hidden="true" />,
            iconClassName:
                'bg-amber-500/10 text-amber-600 dark:bg-amber-500/20 dark:text-amber-400',
        },
        {
            key: 'in_progress',
            label: 'Sedang Dikerjakan',
            value: summary.in_progress,
            description: 'Dalam tahap investigasi atau pengerjaan aktif',
            icon: <WrenchScrewdriverIcon className="h-6 w-6" aria-hidden="true" />,
            iconClassName: 'bg-blue-500/10 text-blue-600 dark:bg-blue-500/20 dark:text-blue-400',
        },
        {
            key: 'resolved',
            label: 'Menunggu Tindakan Lanjutan',
            value: summary.waiting_action,
            description: 'Tiket terselesaikan / SPK siap diajukan review',
            icon: <CheckCircleIcon className="h-6 w-6" aria-hidden="true" />,
            iconClassName:
                'bg-emerald-500/10 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-400',
        },
    ];

    return (
        <div
            className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4"
            role="region"
            aria-label="Ringkasan Pekerjaan Saya"
        >
            {cards.map((card) => {
                const isActive = card.key === '' ? !activeStatus : activeStatus === card.key;

                return (
                    <Card key={card.key || 'all'} className="border-border bg-card shadow-sm">
                        <CardContent className="p-0">
                            <Button
                                type="button"
                                variant="ghost"
                                className={`h-full min-h-32 w-full justify-between whitespace-normal rounded-lg p-5 text-left hover:border-primary/40 hover:bg-transparent motion-reduce:transition-none ${
                                    isActive ? 'ring-1 ring-primary/30' : ''
                                }`}
                                aria-label={`Filter ${card.label}: ${card.value} tugas`}
                                aria-pressed={isActive}
                                onClick={() => onSelectStatus?.(card.key)}
                            >
                                <span className="space-y-1">
                                    <span className="block text-xs font-medium uppercase tracking-wider text-muted-foreground">
                                        {card.label}
                                    </span>
                                    <span className="block text-2xl font-bold tracking-tight text-foreground">
                                        {card.value}
                                    </span>
                                    <span className="block text-xs font-normal text-muted-foreground">
                                        {card.description}
                                    </span>
                                </span>
                                <span
                                    className={`flex h-12 w-12 shrink-0 items-center justify-center rounded-lg ${card.iconClassName}`}
                                >
                                    {card.icon}
                                </span>
                            </Button>
                        </CardContent>
                    </Card>
                );
            })}
        </div>
    );
}
